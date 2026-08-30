<?php

namespace MEI;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Database {
	private const VERSION = '2';
	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'mei_event_rows';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'mei_event_rows';
		$previous_version = (string) get_option( 'mei_db_version', '' );
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			profile_id bigint(20) unsigned NOT NULL,
			event_month char(7) NOT NULL,
			title text NOT NULL,
			content longtext NOT NULL,
			excerpt text NOT NULL,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			all_day tinyint(1) unsigned NOT NULL DEFAULT 0,
			venue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			website text NOT NULL,
			cost varchar(32) NOT NULL DEFAULT '',
			capacity bigint(20) unsigned NULL DEFAULT NULL,
			available_places bigint(20) unsigned NULL DEFAULT NULL,
			occupancy_note text NOT NULL,
			tag_ids longtext NOT NULL,
			category_ids longtext NOT NULL,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tec_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sync_hash char(64) NOT NULL DEFAULT '',
			sync_status varchar(20) NOT NULL DEFAULT 'changed',
			sync_error text NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			imported_at datetime NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY profile_month (profile_id,event_month),
			KEY profile_sync (profile_id,sync_status),
			KEY tec_event_id (tec_event_id)
		) {$charset_collate};";

		dbDelta( $sql );
		if ( version_compare( $previous_version, '2', '<' ) ) {
			self::backfill_occupancy( $table );
		}
		update_option( 'mei_db_version', self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== (string) get_option( 'mei_db_version', '' ) ) {
			self::install();
		}
	}

	private static function backfill_occupancy( string $table ): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, tec_event_id FROM {$table} WHERE tec_event_id > 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$event_id = (int) $row['tec_event_id'];
			$values   = array();
			if ( metadata_exists( 'post', $event_id, '_mlyn_event_capacity' ) ) {
				$values['capacity'] = max( 0, (int) get_post_meta( $event_id, '_mlyn_event_capacity', true ) );
			}
			if ( metadata_exists( 'post', $event_id, '_mlyn_event_available_places' ) ) {
				$values['available_places'] = max( 0, (int) get_post_meta( $event_id, '_mlyn_event_available_places', true ) );
			}
			if ( metadata_exists( 'post', $event_id, '_mlyn_event_occupancy_note' ) ) {
				$values['occupancy_note'] = sanitize_textarea_field( get_post_meta( $event_id, '_mlyn_event_occupancy_note', true ) );
			}
			if ( $values ) {
				$wpdb->update( $table, $values, array( 'id' => (int) $row['id'] ) );
			}
		}
	}

	public function get_month_rows( int $profile_id, string $month, bool $include_deleted = false ): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE profile_id = %d AND event_month = %s",
			$profile_id,
			$month
		);
		if ( ! $include_deleted ) {
			$sql .= ' AND deleted_at IS NULL';
		}
		$sql .= ' ORDER BY start_at ASC, title ASC, id ASC';
		return array_map( array( $this, 'hydrate' ), $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function get_profile_rows( int $profile_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE profile_id = %d ORDER BY start_at ASC, id ASC", $profile_id ),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	public function save_month( int $profile_id, string $month, array $rows, int $user_id ): int {
		global $wpdb;
		$now       = current_time( 'mysql' );
		$existing  = $this->get_month_rows( $profile_id, $month );
		$by_uuid   = array_column( $existing, null, 'uuid' );
		$submitted = array();
		$changes   = 0;

		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $rows as $row ) {
				$uuid               = $row['uuid'];
				$submitted[ $uuid ] = true;
				$record             = $this->to_record( $row, $profile_id, $month, $user_id, $now );

				if ( isset( $by_uuid[ $uuid ] ) ) {
					$old = $by_uuid[ $uuid ];
					if ( $this->editable_hash( $old ) === $this->editable_hash( $record ) ) {
						continue;
					}
					$record['sync_status'] = 'changed';
					$record['sync_error']  = '';
					$result = $wpdb->update( $this->table, $record, array( 'id' => (int) $old['id'] ) );
				} else {
					$record['uuid']        = $uuid;
					$record['created_by']  = $user_id;
					$record['created_at']  = $now;
					$record['sync_status'] = 'changed';
					$record['sync_error']  = '';
					$result = $wpdb->insert( $this->table, $record );
				}

				if ( false === $result ) {
					throw new RuntimeException( __( 'Řádky akcí se nepodařilo uložit.', 'mlyn-event-intake' ) );
				}
				++$changes;
			}

			$current = current_time( 'mysql' );
			foreach ( $existing as $old ) {
				if ( isset( $submitted[ $old['uuid'] ] ) || $old['end_at'] < $current ) {
					continue;
				}
				if ( $old['tec_event_id'] ) {
					$result = $wpdb->update(
						$this->table,
						array(
							'deleted_at'  => $now,
							'updated_at'  => $now,
							'updated_by'  => $user_id,
							'sync_status' => 'changed',
							'sync_error'  => '',
						),
						array( 'id' => (int) $old['id'] )
					);
				} else {
					$result = $wpdb->delete( $this->table, array( 'id' => (int) $old['id'] ), array( '%d' ) );
				}
				if ( false === $result ) {
					throw new RuntimeException( __( 'Odstraněný řádek akce se nepodařilo uložit.', 'mlyn-event-intake' ) );
				}
				++$changes;
			}

			$wpdb->query( 'COMMIT' );
		} catch ( RuntimeException $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}

		return $changes;
	}

	public function mark_synced( int $id, int $event_id, string $hash, bool $deleted = false ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			array(
				'tec_event_id' => $event_id,
				'sync_hash'    => $hash,
				'sync_status'  => $deleted ? 'deleted' : 'synced',
				'sync_error'   => '',
				'imported_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	public function mark_error( int $id, string $message ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			array(
				'sync_status' => 'error',
				'sync_error'  => $message,
			),
			array( 'id' => $id )
		);
	}

	public function update_occupancy_by_event( int $event_id, ?int $capacity, ?int $available, string $note, int $user_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			array(
				'capacity'         => $capacity,
				'available_places' => $available,
				'occupancy_note'   => $note,
				'updated_by'       => $user_id,
				'updated_at'       => current_time( 'mysql' ),
				'sync_status'      => 'changed',
				'sync_error'       => '',
			),
			array(
				'tec_event_id' => $event_id,
				'deleted_at'   => null,
			)
		);
	}

	private function to_record( array $row, int $profile_id, string $month, int $user_id, string $now ): array {
		return array(
			'profile_id'    => $profile_id,
			'event_month'   => $month,
			'title'         => $row['title'],
			'content'       => $row['content'],
			'excerpt'       => $row['excerpt'],
			'start_at'      => $row['start_at'],
			'end_at'        => $row['end_at'],
			'all_day'       => $row['all_day'] ? 1 : 0,
			'venue_id'      => $row['venue_id'],
			'organizer_id'  => $row['organizer_id'],
			'website'       => $row['website'],
			'cost'          => $row['cost'],
			'capacity'      => '' === $row['capacity'] ? null : (int) $row['capacity'],
			'available_places' => '' === $row['available_places'] ? null : (int) $row['available_places'],
			'occupancy_note' => $row['occupancy_note'],
			'tag_ids'       => wp_json_encode( array_values( $row['tag_ids'] ) ),
			'category_ids'  => wp_json_encode( array_values( $row['category_ids'] ) ),
			'image_id'      => $row['image_id'],
			'updated_by'    => $user_id,
			'updated_at'    => $now,
			'deleted_at'    => null,
		);
	}

	private function editable_hash( array $row ): string {
		$keys = array( 'title', 'content', 'excerpt', 'start_at', 'end_at', 'all_day', 'venue_id', 'organizer_id', 'website', 'cost', 'capacity', 'available_places', 'occupancy_note', 'tag_ids', 'category_ids', 'image_id' );
		$data = array();
		foreach ( $keys as $key ) {
			$data[ $key ] = $row[ $key ] ?? null;
		}
		foreach ( array( 'tag_ids', 'category_ids' ) as $key ) {
			if ( is_string( $data[ $key ] ) ) {
				$data[ $key ] = json_decode( $data[ $key ], true ) ?: array();
			}
			$data[ $key ] = array_values( array_map( 'intval', (array) $data[ $key ] ) );
			sort( $data[ $key ] );
		}
		$data['all_day']      = (int) (bool) $data['all_day'];
		$data['venue_id']     = (int) $data['venue_id'];
		$data['organizer_id'] = (int) $data['organizer_id'];
		$data['image_id']     = (int) $data['image_id'];
		$data['cost']         = (string) $data['cost'];
		$data['capacity']     = null === $data['capacity'] || '' === $data['capacity'] ? '' : (string) (int) $data['capacity'];
		$data['available_places'] = null === $data['available_places'] || '' === $data['available_places'] ? '' : (string) (int) $data['available_places'];
		$data['occupancy_note'] = (string) $data['occupancy_note'];
		return hash( 'sha256', wp_json_encode( $data ) );
	}

	private function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['profile_id']     = (int) $row['profile_id'];
		$row['all_day']        = (bool) $row['all_day'];
		$row['venue_id']       = (int) $row['venue_id'];
		$row['organizer_id']   = (int) $row['organizer_id'];
		$row['image_id']       = (int) $row['image_id'];
		$row['tec_event_id']   = (int) $row['tec_event_id'];
		$row['capacity']       = null === $row['capacity'] ? '' : (string) (int) $row['capacity'];
		$row['available_places'] = null === $row['available_places'] ? '' : (string) (int) $row['available_places'];
		$row['occupancy_note'] = (string) $row['occupancy_note'];
		$row['tag_ids']        = array_map( 'intval', json_decode( $row['tag_ids'], true ) ?: array() );
		$row['category_ids']   = array_map( 'intval', json_decode( $row['category_ids'], true ) ?: array() );
		return $row;
	}
}
