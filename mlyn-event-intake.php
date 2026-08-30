<?php
/**
 * Plugin Name:       Mlýn Event Intake
 * Description:       A restricted monthly event-intake workflow for The Events Calendar.
 * Version:           0.6.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  the-events-calendar, mlyn-event
 * Author:            Velký mlýn
 * License:           GPL-2.0-or-later
 * Text Domain:       mlyn-event-intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEI_VERSION', '0.6.0' );
define( 'MEI_FILE', __FILE__ );
define( 'MEI_DIR', plugin_dir_path( __FILE__ ) );

require_once MEI_DIR . 'src/class-database.php';
require_once MEI_DIR . 'src/class-tec-sync.php';
require_once MEI_DIR . 'src/class-plugin.php';

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=mei-events' ) ),
				esc_html__( 'Event intake', 'mlyn-event-intake' )
			)
		);
		return $links;
	}
);

register_activation_hook( __FILE__, array( 'MEI\\Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		MEI\Plugin::instance();
	}
);
