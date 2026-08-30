<?php

namespace MEI;

use RuntimeException;
use Throwable;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TEC_Sync {
	private $database;

	public function __construct( Database $database ) {
		$this->database = $database;
	}

	public function is_available(): bool {
		return function_exists( 'tribe_events' ) && post_type_exists( 'tribe_events' ) && function_exists( 'mlyn_event_set_occupancy' ) && function_exists( 'mlyn_event_set_image_focal_point' );
	}

	public function import_profile( int $profile_id, array $settings ): array {
		if ( ! $this->is_available() ) {
			throw new RuntimeException( __( 'Plugin The Events Calendar není aktivní.', 'mlyn-event-intake' ) );
		}

		$summary = array(
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);
		$now     = current_time( 'mysql' );

		foreach ( $this->database->get_profile_rows( $profile_id ) as $row ) {
			try {
				if ( $row['deleted_at'] ) {
					if ( ! $row['tec_event_id'] ) {
						++$summary['skipped'];
						continue;
					}
					$post = get_post( $row['tec_event_id'] );
					if ( $post && 'trash' !== $post->post_status ) {
						if ( ! wp_trash_post( $post->ID ) ) {
							throw new RuntimeException( __( 'Propojenou akci se nepodařilo přesunout do koše.', 'mlyn-event-intake' ) );
						}
					}
					$this->database->mark_synced( $row['id'], $row['tec_event_id'], 'deleted', true );
					++$summary['deleted'];
					continue;
				}

				if ( $row['end_at'] < $now ) {
					++$summary['skipped'];
					continue;
				}

				$args = $this->build_args( $row, $settings );
				$hash = hash(
					'sha256',
					wp_json_encode(
						array(
							'sync_revision'  => 4,
							'event'         => $args,
							'currency_code' => $settings['currency_code'],
							'event_status'  => $settings['event_status'],
							'occupancy'     => array(
								'capacity'         => $row['capacity'],
								'available_places' => $row['available_places'],
								'note'             => $row['occupancy_note'],
							),
							'focal_point'   => array(
								'x' => $row['focal_x'],
								'y' => $row['focal_y'],
							),
						)
					)
				);
				if ( $row['tec_event_id'] && hash_equals( (string) $row['sync_hash'], $hash ) && get_post( $row['tec_event_id'] ) ) {
					++$summary['skipped'];
					continue;
				}

				if ( $row['tec_event_id'] && get_post( $row['tec_event_id'] ) ) {
					$event_id = $this->update_event( $row['tec_event_id'], $args );
					++$summary['updated'];
				} else {
					$args['status'] = 'draft';
					$event_id       = $this->create_event( $args );
					++$summary['created'];
				}

				$this->apply_supported_extras( $event_id, $row, $settings );
				$this->database->mark_synced( $row['id'], $event_id, $hash );
			} catch ( Throwable $exception ) {
				$this->database->mark_error( $row['id'], $exception->getMessage() );
				++$summary['errors'];
			}
		}

		return $summary;
	}

	private function create_event( array $args ): int {
		$event = tribe_events()->set_args( $args )->create();
		$id    = $event instanceof WP_Post ? $event->ID : (int) $event;
		if ( ! $id ) {
			throw new RuntimeException( __( 'Akci se nepodařilo vytvořit v kalendáři.', 'mlyn-event-intake' ) );
		}
		return $id;
	}

	private function update_event( int $event_id, array $args ): int {
		unset( $args['status'] );
		$result = tribe_events()->where( 'id', $event_id )->set_args( $args )->save();
		if ( $result instanceof WP_Error ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		if ( ! get_post( $event_id ) ) {
			throw new RuntimeException( __( 'Propojená akce už v kalendáři neexistuje.', 'mlyn-event-intake' ) );
		}
		return $event_id;
	}

	private function build_args( array $row, array $settings ): array {
		$args = array(
			'title'                => $row['title'],
			'description'          => $row['content'],
			'excerpt'              => $row['excerpt'],
			'start_date'           => $row['start_at'],
			'end_date'             => $row['end_at'],
			'all_day'              => $row['all_day'],
			'currency_symbol'      => $settings['currency_symbol'],
			'currency_position'    => $settings['currency_position'],
			'timezone'             => wp_timezone_string(),
			'url'                  => $row['website'],
			'venue'                => $row['venue_id'],
			'organizer'            => $row['organizer_id'] ? array( $row['organizer_id'] ) : array(),
			'image'                => $row['image_id'],
			'tag'                  => $row['tag_ids'],
			'category'             => $row['category_ids'],
			'hide_from_upcoming'   => $settings['hide_from_upcoming'],
			'sticky'               => $settings['sticky'],
			'featured'             => $settings['featured'],
			'show_map'             => true,
			'show_map_link'        => true,
		);
		if ( '' !== $row['cost'] ) {
			$args['cost'] = (float) $row['cost'];
		}
		return $args;
	}

	private function apply_supported_extras( int $event_id, array $row, array $settings ): void {
		wp_set_object_terms( $event_id, $row['tag_ids'], 'post_tag', false );
		wp_set_object_terms( $event_id, $row['category_ids'], 'tribe_events_cat', false );
		update_post_meta( $event_id, '_EventCurrencySymbol', $settings['currency_symbol'] );
		update_post_meta(
			$event_id,
			'_EventCurrencyPosition',
			'postfix' === $settings['currency_position'] ? 'suffix' : 'prefix'
		);
		update_post_meta( $event_id, '_EventCurrencyCode', $settings['currency_code'] );
		if ( '' === $row['cost'] ) {
			delete_post_meta( $event_id, '_EventCost' );
		}

		if ( 'scheduled' === $settings['event_status'] ) {
			delete_post_meta( $event_id, '_tribe_events_status' );
			delete_post_meta( $event_id, '_tribe_events_status_reason' );
		} else {
			update_post_meta( $event_id, '_tribe_events_status', $settings['event_status'] );
		}

		if ( $row['image_id'] ) {
			set_post_thumbnail( $event_id, $row['image_id'] );
		} else {
			delete_post_thumbnail( $event_id );
		}

		$capacity  = '' === (string) $row['capacity'] ? null : (int) $row['capacity'];
		$available = '' === (string) $row['available_places'] ? null : (int) $row['available_places'];
		$result    = mlyn_event_set_occupancy( $event_id, $capacity, $available, (string) $row['occupancy_note'], false );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}

		$focal_x = '' === (string) $row['focal_x'] ? null : (int) $row['focal_x'];
		$focal_y = '' === (string) $row['focal_y'] ? null : (int) $row['focal_y'];
		$result  = mlyn_event_set_image_focal_point( $event_id, $focal_x, $focal_y, false );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
	}
}
