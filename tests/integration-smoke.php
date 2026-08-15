<?php

// Run with: wp eval-file wp-content/plugins/mlyn-event-intake/tests/integration-smoke.php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

global $wpdb;
$database   = new MEI\Database();
$sync       = new MEI\TEC_Sync( $database );
$profile_id = 0;
$event_id   = 0;

try {
	$assert( $sync->is_available(), 'The Events Calendar adapter is unavailable.' );
	$role = get_role( 'mlyn_event_organizer' );
	$assert( $role && ! empty( $role->capabilities['manage_mlyn_event_intake'] ), 'Organizer role or capability is missing.' );

	$profile_id = wp_insert_post(
		array(
			'post_type'   => 'mlyn_event_profile',
			'post_status' => 'publish',
			'post_title'  => 'MEI disposable integration profile',
		),
		true
	);
	$assert( ! is_wp_error( $profile_id ), 'Could not create the disposable profile.' );

	$venue_id     = (int) get_posts( array( 'post_type' => 'tribe_venue', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
	$organizer_id = (int) get_posts( array( 'post_type' => 'tribe_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
	$tag_ids      = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
	$category_ids = get_terms( array( 'taxonomy' => 'tribe_events_cat', 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
	$settings     = array(
		'currency_symbol'     => 'Kč',
		'currency_position'   => 'postfix',
		'currency_code'       => 'CZK',
		'event_status'        => 'scheduled',
		'hide_from_upcoming'  => false,
		'sticky'              => false,
		'featured'            => true,
	);
	$start        = new DateTimeImmutable( 'first day of next month 09:00:00', wp_timezone() );
	$end          = $start->modify( '+2 hours' );
	$month        = $start->format( 'Y-m' );
	$uuid         = wp_generate_uuid4();
	$row          = array(
		'uuid'         => $uuid,
		'title'        => 'MEI disposable event',
		'content'      => '<p>Initial content.</p>',
		'excerpt'      => 'Initial excerpt.',
		'start_at'     => $start->format( 'Y-m-d H:i:s' ),
		'end_at'       => $end->format( 'Y-m-d H:i:s' ),
		'all_day'      => false,
		'venue_id'     => $venue_id,
		'organizer_id' => $organizer_id,
		'website'      => 'https://example.test/event',
		'cost'         => '0',
		'tag_ids'      => array_map( 'intval', (array) $tag_ids ),
		'category_ids' => array_map( 'intval', (array) $category_ids ),
		'image_id'     => 0,
	);

	$assert( 1 === $database->save_month( $profile_id, $month, array( $row ), 1 ), 'Initial intake save did not change one row.' );
	$assert( 0 === $database->save_month( $profile_id, $month, array( $row ), 1 ), 'Saving an unchanged intake row was not idempotent.' );
	$created = $sync->import_profile( $profile_id, $settings );
	$assert( 1 === $created['created'] && 0 === $created['errors'], 'Initial import did not create exactly one event.' );
	$saved    = $database->get_month_rows( $profile_id, $month );
	$event_id = (int) $saved[0]['tec_event_id'];
	$assert( $event_id > 0 && 'draft' === get_post_status( $event_id ), 'The initial TEC event is missing or is not a draft.' );
	$assert( 'MEI disposable event' === get_the_title( $event_id ), 'The imported event title is wrong.' );
	$assert( 'Kč' === get_post_meta( $event_id, '_EventCurrencySymbol', true ), 'The imported currency symbol is wrong.' );
	$assert( 'suffix' === get_post_meta( $event_id, '_EventCurrencyPosition', true ), 'The imported currency position is wrong.' );
	$assert( 'CZK' === get_post_meta( $event_id, '_EventCurrencyCode', true ), 'The imported currency code is wrong.' );
	$assert( metadata_exists( 'post', $event_id, '_EventCost' ) && '0' === get_post_meta( $event_id, '_EventCost', true ), 'An explicit zero fee was not preserved as free.' );
	$assert( $venue_id === (int) get_post_meta( $event_id, '_EventVenueID', true ), 'The imported venue is wrong.' );
	$assert( in_array( $organizer_id, array_map( 'intval', get_post_meta( $event_id, '_EventOrganizerID', false ) ), true ), 'The imported organizer is wrong.' );
	$assert( array_map( 'intval', (array) $tag_ids ) === wp_get_object_terms( $event_id, 'post_tag', array( 'fields' => 'ids' ) ), 'The imported tags are wrong.' );
	$assert( array_map( 'intval', (array) $category_ids ) === wp_get_object_terms( $event_id, 'tribe_events_cat', array( 'fields' => 'ids' ) ), 'The imported categories are wrong.' );

	wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
	$row['title']    = 'MEI disposable event updated';
	$row['content']  = '<p>Updated content.</p>';
	$row['all_day']  = true;
	$row['start_at'] = $start->format( 'Y-m-d 00:00:00' );
	$row['end_at']   = $start->modify( '+2 days' )->format( 'Y-m-d 23:59:59' );
	$assert( 1 === $database->save_month( $profile_id, $month, array( $row ), 1 ), 'Updated intake save did not change one row.' );
	$updated = $sync->import_profile( $profile_id, $settings );
	$assert( 1 === $updated['updated'] && 0 === $updated['errors'], 'Second import did not update exactly one event.' );
	$assert( 'MEI disposable event updated' === get_the_title( $event_id ), 'The linked event title was not updated.' );
	$assert( 'publish' === get_post_status( $event_id ), 'An update did not preserve the event publication status.' );
	$assert( 'yes' === get_post_meta( $event_id, '_EventAllDay', true ), 'The updated event is not a multi-day all-day event.' );

	$settings['event_status'] = 'canceled';
	$settings['featured']     = false;
	$admin_update             = $sync->import_profile( $profile_id, $settings );
	$assert( 1 === $admin_update['updated'] && 0 === $admin_update['errors'], 'Changing administrator-controlled profile fields did not trigger an update.' );
	$assert( 'canceled' === get_post_meta( $event_id, '_tribe_events_status', true ), 'The administrator-controlled event status was not applied.' );
	$assert( 'publish' === get_post_status( $event_id ), 'An administrator-controlled update did not preserve publication status.' );

	$settings['currency_position'] = 'prefix';
	$currency_update               = $sync->import_profile( $profile_id, $settings );
	$assert( 1 === $currency_update['updated'] && 0 === $currency_update['errors'], 'Changing the currency position did not update the linked event.' );
	$assert( 'prefix' === get_post_meta( $event_id, '_EventCurrencyPosition', true ), 'The updated currency position is wrong.' );

	$row['cost'] = '';
	$assert( 1 === $database->save_month( $profile_id, $month, array( $row ), 1 ), 'Clearing the fee did not change the intake row.' );
	$nullable_cost = $sync->import_profile( $profile_id, $settings );
	$assert( 1 === $nullable_cost['updated'] && 0 === $nullable_cost['errors'], 'Clearing the fee did not update the linked event.' );
	$assert( ! metadata_exists( 'post', $event_id, '_EventCost' ), 'A blank fee did not remove the TEC cost metadata.' );

	$assert( 1 === $database->save_month( $profile_id, $month, array(), 1 ), 'Removing the intake row did not create one change.' );
	$deleted = $sync->import_profile( $profile_id, $settings );
	$assert( 1 === $deleted['deleted'] && 0 === $deleted['errors'], 'Removal import did not trash exactly one event.' );
	$assert( 'trash' === get_post_status( $event_id ), 'The linked TEC event was not moved to Trash.' );

	echo "MEI integration smoke test passed.\n";
} finally {
	if ( $event_id ) {
		wp_delete_post( $event_id, true );
	}
	if ( $profile_id ) {
		$wpdb->delete( $wpdb->prefix . 'mei_event_rows', array( 'profile_id' => $profile_id ), array( '%d' ) );
		wp_delete_post( $profile_id, true );
	}
}
