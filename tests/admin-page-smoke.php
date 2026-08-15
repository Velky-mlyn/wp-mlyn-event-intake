<?php

// Run with: wp eval-file wp-content/plugins/mlyn-event-intake/tests/admin-page-smoke.php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
if ( ! $admins ) {
	throw new RuntimeException( 'No administrator is available for the admin-page smoke test.' );
}

wp_set_current_user( (int) $admins[0] );
$sanitize_profile = new ReflectionMethod( MEI\Plugin::class, 'sanitize_profile_settings' );
$nullable_profile = $sanitize_profile->invoke( MEI\Plugin::instance(), array( 'default_cost' => '' ) );
$free_profile     = $sanitize_profile->invoke( MEI\Plugin::instance(), array( 'default_cost' => '0' ) );
if ( '' !== $nullable_profile['default_cost'] || '0' !== $free_profile['default_cost'] ) {
	throw new RuntimeException( 'Profile fee defaults do not distinguish blank from zero.' );
}
$profile_id = wp_insert_post(
	array(
		'post_type'   => 'mlyn_event_profile',
		'post_status' => 'publish',
		'post_title'  => 'MEI disposable UI profile',
	),
	true
);

if ( is_wp_error( $profile_id ) ) {
	throw new RuntimeException( $profile_id->get_error_message() );
}

try {
	$_GET['profile_id'] = $profile_id;
	$_GET['month']      = ( new DateTimeImmutable( 'first day of next month', wp_timezone() ) )->format( 'Y-m' );
	$recovery_key       = 'mei_recovery_' . get_current_user_id() . '_' . $profile_id . '_' . str_replace( '-', '_', $_GET['month'] );
	set_transient(
		$recovery_key,
		array(
			array(
				'uuid'         => wp_generate_uuid4(),
				'title'        => 'Recovered unsaved row',
				'content'      => '<p>Recovered content.</p>',
				'excerpt'      => 'Recovered excerpt.',
				'start'        => ( new DateTimeImmutable( 'first day of +2 months 09:00:00', wp_timezone() ) )->format( 'Y-m-d\\TH:i' ),
				'end'          => ( new DateTimeImmutable( 'first day of +2 months 11:00:00', wp_timezone() ) )->format( 'Y-m-d\\TH:i' ),
				'cost'         => '0',
				'venue_id'     => 0,
				'organizer_id' => 0,
				'tag_ids'      => array(),
				'category_ids' => array(),
				'image_id'     => 0,
			),
		),
		MINUTE_IN_SECONDS
	);
	ob_start();
	MEI\Plugin::instance()->render_events_page();
	$html = ob_get_clean();
	foreach ( array( 'MEI disposable UI profile', 'Poslední úprava:', 'Rubriky akce', 'Importovat všechny změněné aktivní akce', 'mei-row-template', 'Recovered unsaved row', '>Architektura<', '>Knihovna<', 'placeholder="Bez údaje"' ) as $expected ) {
		if ( false === strpos( $html, $expected ) ) {
			throw new RuntimeException( 'Admin page output is missing: ' . $expected );
		}
	}
	if ( ! preg_match( '/<select id="mei-month-select">(.*?)<\/select>/s', $html, $month_select ) || 24 !== substr_count( $month_select[1], '<option ' ) ) {
		throw new RuntimeException( 'The responsive month selector does not contain exactly 24 months.' );
	}
	if ( ! preg_match( '/<nav class="mei-month-tabs".*?<\/nav>/s', $html, $month_tabs ) || 24 !== substr_count( $month_tabs[0], '<a ' ) ) {
		throw new RuntimeException( 'The wrapping desktop month navigation does not contain exactly 24 months.' );
	}
	if ( false !== get_transient( $recovery_key ) ) {
		throw new RuntimeException( 'Recovered form data was not consumed after rendering.' );
	}
	echo "MEI admin-page smoke test passed.\n";
} finally {
	wp_delete_post( $profile_id, true );
}
