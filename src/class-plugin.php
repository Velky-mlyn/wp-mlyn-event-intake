<?php

namespace MEI;

use DateTimeImmutable;
use RuntimeException;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private const PROFILE_TYPE     = 'mlyn_event_profile';
	private const ROLE             = 'mlyn_event_organizer';
	private const CAP_EVENTS       = 'manage_mlyn_event_intake';
	private const CAP_IMPORT       = 'import_mlyn_event_intake';
	private const CAP_PROFILES     = 'manage_mlyn_event_profiles';
	private const PROFILE_USER     = '_mei_user_id';
	private const PROFILE_SETTINGS = '_mei_profile_settings';
	private const OPTION_SETTINGS  = 'mei_settings';

	private static $instance;
	private $database;
	private $sync;
	private $page_hook = '';
	private $recovered_form = false;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		Database::install();
		add_role(
			self::ROLE,
			__( 'Event Organizer', 'mlyn-event-intake' ),
			array(
				'read'                  => true,
				self::CAP_EVENTS        => true,
			)
		);
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( self::CAP_EVENTS );
			$administrator->add_cap( self::CAP_IMPORT );
			$administrator->add_cap( self::CAP_PROFILES );
		}
		self::instance()->register_profile_type();
		flush_rewrite_rules( false );
	}

	private function __construct() {
		Database::maybe_upgrade();
		$this->database = new Database();
		$this->sync     = new TEC_Sync( $this->database );

		add_action( 'init', array( $this, 'register_profile_type' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'restrict_organizer_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'redirect_organizer_dashboard' ) );
		add_action( 'admin_bar_menu', array( $this, 'restrict_admin_bar' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'add_meta_boxes_' . self::PROFILE_TYPE, array( $this, 'register_profile_meta_boxes' ) );
		add_action( 'save_post_' . self::PROFILE_TYPE, array( $this, 'save_profile' ), 10, 2 );
		add_action( 'mlyn_event_occupancy_updated', array( $this, 'sync_event_occupancy_to_intake' ), 10, 2 );
		add_action( 'mlyn_event_image_focal_point_updated', array( $this, 'sync_event_focal_point_to_intake' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
		add_action( 'admin_post_mei_save_events', array( $this, 'save_events' ) );
		add_action( 'admin_post_mei_import_events', array( $this, 'import_events' ) );
		add_action( 'admin_post_mei_save_settings', array( $this, 'save_global_settings' ) );
	}

	public function register_profile_type(): void {
		$capabilities = array(
			'edit_post'              => self::CAP_PROFILES,
			'read_post'              => self::CAP_PROFILES,
			'delete_post'            => self::CAP_PROFILES,
			'edit_posts'             => self::CAP_PROFILES,
			'edit_others_posts'      => self::CAP_PROFILES,
			'publish_posts'          => self::CAP_PROFILES,
			'read_private_posts'     => self::CAP_PROFILES,
			'delete_posts'           => self::CAP_PROFILES,
			'delete_private_posts'   => self::CAP_PROFILES,
			'delete_published_posts' => self::CAP_PROFILES,
			'delete_others_posts'    => self::CAP_PROFILES,
			'edit_private_posts'     => self::CAP_PROFILES,
			'edit_published_posts'   => self::CAP_PROFILES,
			'create_posts'           => self::CAP_PROFILES,
		);
		register_post_type(
			self::PROFILE_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Organizer Profiles', 'mlyn-event-intake' ),
					'singular_name' => __( 'Organizer Profile', 'mlyn-event-intake' ),
					'add_new'       => __( 'Add profile', 'mlyn-event-intake' ),
					'add_new_item'  => __( 'Add organizer profile', 'mlyn-event-intake' ),
					'edit_item'     => __( 'Edit organizer profile', 'mlyn-event-intake' ),
					'menu_name'     => __( 'Organizer Profiles', 'mlyn-event-intake' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'mei-events',
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capabilities'        => $capabilities,
				'map_meta_cap'        => false,
			)
		);
	}

	public function sync_event_occupancy_to_intake( int $event_id, array $occupancy ): void {
		$this->database->update_occupancy_by_event(
			$event_id,
			isset( $occupancy['capacity'] ) ? (int) $occupancy['capacity'] : null,
			isset( $occupancy['available_places'] ) ? (int) $occupancy['available_places'] : null,
			(string) ( $occupancy['note'] ?? '' ),
			get_current_user_id()
		);
	}

	public function sync_event_focal_point_to_intake( int $event_id, array $point ): void {
		$this->database->update_focal_point_by_event(
			$event_id,
			! empty( $point['specified'] ) ? (int) $point['x'] : null,
			! empty( $point['specified'] ) ? (int) $point['y'] : null,
			get_current_user_id()
		);
	}

	public function register_admin_menu(): void {
		$this->page_hook = add_menu_page(
			__( 'Příprava akcí', 'mlyn-event-intake' ),
			__( 'Příprava akcí', 'mlyn-event-intake' ),
			self::CAP_EVENTS,
			'mei-events',
			array( $this, 'render_events_page' ),
			'dashicons-calendar-alt',
			25
		);
		add_submenu_page(
			'mei-events',
			__( 'Příprava akcí', 'mlyn-event-intake' ),
			__( 'Akce', 'mlyn-event-intake' ),
			self::CAP_EVENTS,
			'mei-events',
			array( $this, 'render_events_page' )
		);
		add_submenu_page(
			'mei-events',
			__( 'Event Intake Settings', 'mlyn-event-intake' ),
			__( 'Settings', 'mlyn-event-intake' ),
			self::CAP_PROFILES,
			'mei-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function restrict_organizer_menu(): void {
		if ( ! $this->is_restricted_organizer() ) {
			return;
		}
		global $menu;
		foreach ( $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'mei-events' !== $slug ) {
				remove_menu_page( $slug );
			}
		}
	}

	public function redirect_organizer_dashboard(): void {
		if ( ! $this->is_restricted_organizer() || wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		if ( 'index.php' === $pagenow ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mei-events' ) );
			exit;
		}
	}

	public function restrict_admin_bar( $bar ): void {
		if ( ! $this->is_restricted_organizer() ) {
			return;
		}
		foreach ( array( 'wp-logo', 'site-name', 'updates', 'comments', 'new-content', 'search' ) as $node ) {
			$bar->remove_node( $node );
		}
	}

	private function is_restricted_organizer(): bool {
		$user = wp_get_current_user();
		return in_array( self::ROLE, (array) $user->roles, true ) && ! current_user_can( self::CAP_PROFILES );
	}

	public function enqueue_admin_assets( string $hook ): void {
		$screen = get_current_screen();
		$is_profile = $screen && self::PROFILE_TYPE === $screen->post_type;
		if ( $hook !== $this->page_hook && ! $is_profile ) {
			return;
		}
		wp_enqueue_style( 'mei-admin', plugins_url( 'assets/admin.css', MEI_FILE ), array(), MEI_VERSION );
		if ( $hook !== $this->page_hook ) {
			return;
		}
		wp_enqueue_editor();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_script( 'mei-admin', plugins_url( 'assets/admin.js', MEI_FILE ), array( 'wp-components', 'wp-element' ), MEI_VERSION, true );
		wp_localize_script(
			'mei-admin',
			'meiAdmin',
			array(
				'confirmRemove' => __( 'Opravdu chcete odstranit tento řádek? Propojená akce bude při příštím importu přesunuta do koše.', 'mlyn-event-intake' ),
				'contentTitle'  => __( 'Text akce', 'mlyn-event-intake' ),
				'untitled'      => __( 'Akce bez názvu', 'mlyn-event-intake' ),
				'focalTitle'    => __( 'Výřez obrázku na detailu akce', 'mlyn-event-intake' ),
				'focalHelp'     => __( 'Přesuňte bod na nejdůležitější část obrázku. Výřez se používá pouze v horním banneru detailu akce.', 'mlyn-event-intake' ),
				'monthError'    => __( 'Řádek %1$d: začátek akce musí být v měsíci %2$s.', 'mlyn-event-intake' ),
				'locale'        => str_replace( '_', '-', get_user_locale() ),
				'sortAscending'   => __( 'Aktivovat pro vzestupné řazení.', 'mlyn-event-intake' ),
				'sortDescending'  => __( 'Aktivovat pro sestupné řazení.', 'mlyn-event-intake' ),
				'sortedAscending' => __( 'Aktuálně seřazeno vzestupně.', 'mlyn-event-intake' ),
				'sortedDescending' => __( 'Aktuálně seřazeno sestupně.', 'mlyn-event-intake' ),
			)
		);
	}

	public function register_profile_meta_boxes(): void {
		add_meta_box(
			'mei-profile-user',
			__( 'WordPress user', 'mlyn-event-intake' ),
			array( $this, 'render_profile_user_box' ),
			self::PROFILE_TYPE,
			'side',
			'high'
		);
		add_meta_box(
			'mei-profile-defaults',
			__( 'Event defaults', 'mlyn-event-intake' ),
			array( $this, 'render_profile_defaults_box' ),
			self::PROFILE_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'mei-profile-allowed',
			__( 'Allowed selector values', 'mlyn-event-intake' ),
			array( $this, 'render_profile_allowed_box' ),
			self::PROFILE_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'mei-profile-admin-fields',
			__( 'Administrator-controlled event fields', 'mlyn-event-intake' ),
			array( $this, 'render_profile_admin_box' ),
			self::PROFILE_TYPE,
			'normal',
			'default'
		);
	}

	public function render_profile_user_box( WP_Post $post ): void {
		$user_id = (int) get_post_meta( $post->ID, self::PROFILE_USER, true );
		wp_nonce_field( 'mei_save_profile', 'mei_profile_nonce' );
		$users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		?>
		<label class="screen-reader-text" for="mei-profile-user-id"><?php esc_html_e( 'Mapped user', 'mlyn-event-intake' ); ?></label>
		<select id="mei-profile-user-id" name="mei_user_id" class="widefat">
			<option value="0"><?php esc_html_e( '— Select one user —', 'mlyn-event-intake' ); ?></option>
			<?php foreach ( $users as $user ) : ?>
				<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $user_id, $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'A user can belong to only one organizer profile.', 'mlyn-event-intake' ); ?></p>
		<?php
	}

	public function render_profile_defaults_box( WP_Post $post ): void {
		$settings   = $this->get_profile_settings( $post->ID );
		$venues     = $this->get_linked_posts( 'tribe_venue' );
		$organizers = $this->get_linked_posts( 'tribe_organizer' );
		$tags       = $this->get_terms( 'post_tag' );
		$categories = $this->get_terms( 'tribe_events_cat' );
		?>
		<div class="mei-profile-grid">
			<label><span><?php esc_html_e( 'All-day event', 'mlyn-event-intake' ); ?></span><input type="checkbox" name="mei_profile[default_all_day]" value="1" <?php checked( $settings['default_all_day'] ); ?>></label>
			<label><span><?php esc_html_e( 'Fee amount', 'mlyn-event-intake' ); ?></span><input type="number" name="mei_profile[default_cost]" min="0" step="0.01" value="<?php echo esc_attr( $settings['default_cost'] ); ?>"><small><?php esc_html_e( 'Leave empty to hide the fee. Enter 0 for a free event.', 'mlyn-event-intake' ); ?></small></label>
			<label><span><?php esc_html_e( 'Výchozí kapacita', 'mlyn-event-intake' ); ?></span><input type="number" name="mei_profile[default_capacity]" min="0" step="1" value="<?php echo esc_attr( $settings['default_capacity'] ); ?>"><small><?php esc_html_e( 'Použije se pouze při vytvoření nové akce. Prázdné pole kapacitu neuvede.', 'mlyn-event-intake' ); ?></small></label>
			<label><span><?php esc_html_e( 'Výchozí počet volných míst', 'mlyn-event-intake' ); ?></span><input type="number" name="mei_profile[default_available_places]" min="0" step="1" value="<?php echo esc_attr( $settings['default_available_places'] ); ?>"><small><?php esc_html_e( 'Nula označí novou akci jako obsazenou i bez uvedené kapacity.', 'mlyn-event-intake' ); ?></small></label>
			<?php $this->render_single_select( 'default_venue', __( 'Location', 'mlyn-event-intake' ), $venues, $settings['default_venue'] ); ?>
			<?php $this->render_single_select( 'default_organizer', __( 'Organizer', 'mlyn-event-intake' ), $organizers, $settings['default_organizer'] ); ?>
			<?php $this->render_multi_select( 'default_tags', __( 'Štítky', 'mlyn-event-intake' ), $tags, $settings['default_tags'] ); ?>
			<?php $this->render_multi_select( 'default_categories', __( 'Rubriky akce', 'mlyn-event-intake' ), $categories, $settings['default_categories'] ); ?>
		</div>
		<?php
	}

	public function render_profile_allowed_box( WP_Post $post ): void {
		$settings = $this->get_profile_settings( $post->ID );
		?>
		<p><?php esc_html_e( 'Only selected values are available in the organizer table. Leave a list empty to allow all values. Defaults are automatically included.', 'mlyn-event-intake' ); ?></p>
		<div class="mei-profile-grid">
			<?php $this->render_multi_select( 'allowed_venues', __( 'Locations', 'mlyn-event-intake' ), $this->get_linked_posts( 'tribe_venue' ), $settings['allowed_venues'] ); ?>
			<?php $this->render_multi_select( 'allowed_organizers', __( 'Organizers', 'mlyn-event-intake' ), $this->get_linked_posts( 'tribe_organizer' ), $settings['allowed_organizers'] ); ?>
			<?php $this->render_multi_select( 'allowed_tags', __( 'Štítky', 'mlyn-event-intake' ), $this->get_terms( 'post_tag' ), $settings['allowed_tags'] ); ?>
			<?php $this->render_multi_select( 'allowed_categories', __( 'Rubriky akce', 'mlyn-event-intake' ), $this->get_terms( 'tribe_events_cat' ), $settings['allowed_categories'] ); ?>
		</div>
		<?php
	}

	public function render_profile_admin_box( WP_Post $post ): void {
		$settings = $this->get_profile_settings( $post->ID );
		?>
		<div class="mei-profile-grid">
			<label><span><?php esc_html_e( 'Currency symbol', 'mlyn-event-intake' ); ?></span><input type="text" name="mei_profile[currency_symbol]" maxlength="12" value="<?php echo esc_attr( $settings['currency_symbol'] ); ?>"></label>
			<label><span><?php esc_html_e( 'Currency position', 'mlyn-event-intake' ); ?></span><select name="mei_profile[currency_position]"><option value="prefix" <?php selected( $settings['currency_position'], 'prefix' ); ?>><?php esc_html_e( 'Before amount', 'mlyn-event-intake' ); ?></option><option value="postfix" <?php selected( $settings['currency_position'], 'postfix' ); ?>><?php esc_html_e( 'After amount', 'mlyn-event-intake' ); ?></option></select></label>
			<label><span><?php esc_html_e( 'Currency ISO code', 'mlyn-event-intake' ); ?></span><input type="text" name="mei_profile[currency_code]" maxlength="3" value="<?php echo esc_attr( $settings['currency_code'] ); ?>"></label>
			<label><span><?php esc_html_e( 'Event status', 'mlyn-event-intake' ); ?></span><select name="mei_profile[event_status]"><option value="scheduled" <?php selected( $settings['event_status'], 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'mlyn-event-intake' ); ?></option><option value="canceled" <?php selected( $settings['event_status'], 'canceled' ); ?>><?php esc_html_e( 'Canceled', 'mlyn-event-intake' ); ?></option><option value="postponed" <?php selected( $settings['event_status'], 'postponed' ); ?>><?php esc_html_e( 'Postponed', 'mlyn-event-intake' ); ?></option></select></label>
		</div>
		<div class="mei-checkboxes">
			<label><input type="checkbox" name="mei_profile[hide_from_upcoming]" value="1" <?php checked( $settings['hide_from_upcoming'] ); ?>> <?php esc_html_e( 'Skrýt ve výpisu (Akce)', 'mlyn-event-intake' ); ?></label>
			<label><input type="checkbox" name="mei_profile[sticky]" value="1" <?php checked( $settings['sticky'] ); ?>> <?php esc_html_e( 'Zvýraznit v měsíčním přehledu', 'mlyn-event-intake' ); ?></label>
			<label><input type="checkbox" name="mei_profile[featured]" value="1" <?php checked( $settings['featured'] ); ?>> <?php esc_html_e( 'Doporučená akce', 'mlyn-event-intake' ); ?></label>
		</div>
		<?php
	}

	private function render_single_select( string $key, string $label, array $options, int $selected ): void {
		?>
		<label><span><?php echo esc_html( $label ); ?></span><select name="mei_profile[<?php echo esc_attr( $key ); ?>]"><option value="0"><?php esc_html_e( '— None —', 'mlyn-event-intake' ); ?></option><?php foreach ( $options as $id => $name ) : ?><option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $selected, $id ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
		<?php
	}

	private function render_multi_select( string $key, string $label, array $options, array $selected ): void {
		?>
		<label><span><?php echo esc_html( $label ); ?></span><select name="mei_profile[<?php echo esc_attr( $key ); ?>][]" multiple size="6"><?php foreach ( $options as $id => $name ) : ?><option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( in_array( (int) $id, $selected, true ) ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
		<?php
	}

	public function save_profile( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['mei_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mei_profile_nonce'] ) ), 'mei_save_profile' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP_PROFILES ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$user_id = isset( $_POST['mei_user_id'] ) ? absint( $_POST['mei_user_id'] ) : 0;
		if ( $user_id ) {
			$conflicts = get_posts(
				array(
					'post_type'      => self::PROFILE_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'post__not_in'   => array( $post_id ),
					'meta_key'       => self::PROFILE_USER,
					'meta_value'     => $user_id,
					'fields'         => 'ids',
				)
			);
			if ( $conflicts ) {
				add_filter( 'redirect_post_location', static function ( string $location ): string { return add_query_arg( 'mei_user_conflict', '1', $location ); } );
				$user_id = (int) get_post_meta( $post_id, self::PROFILE_USER, true );
			}
		}
		update_post_meta( $post_id, self::PROFILE_USER, $user_id );

		$raw      = isset( $_POST['mei_profile'] ) && is_array( $_POST['mei_profile'] ) ? wp_unslash( $_POST['mei_profile'] ) : array();
		$settings = $this->sanitize_profile_settings( $raw );
		$capacity_valid  = true;
		$available_valid = true;
		$capacity        = $this->parse_nullable_count( $raw['default_capacity'] ?? '', $capacity_valid );
		$available       = $this->parse_nullable_count( $raw['default_available_places'] ?? '', $available_valid );
		if ( ! $capacity_valid || ! $available_valid || ( null !== $capacity && null !== $available && $available > $capacity ) ) {
			$existing                             = $this->get_profile_settings( $post_id );
			$settings['default_capacity']          = $existing['default_capacity'];
			$settings['default_available_places']  = $existing['default_available_places'];
			$this->flag_occupancy_validation_error();
		}
		update_post_meta( $post_id, self::PROFILE_SETTINGS, $settings );
	}

	public function render_dependency_notice(): void {
		if ( isset( $_GET['mei_user_conflict'] ) && current_user_can( self::CAP_PROFILES ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That WordPress user is already mapped to another organizer profile.', 'mlyn-event-intake' ) . '</p></div>';
		}
		if ( isset( $_GET['mei_occupancy_invalid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Obsazenost nebyla uložena. Zadejte nezáporná celá čísla; pokud jsou vyplněna obě pole, počet volných míst nesmí překročit kapacitu.', 'mlyn-event-intake' ) . '</p></div>';
		}
		if ( $this->sync->is_available() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Mlýn Event Intake requires active Mlýn Event and The Events Calendar plugins. Saving intake rows remains available, but importing is disabled.', 'mlyn-event-intake' ) . '</p></div>';
	}

	public function render_events_page(): void {
		if ( ! current_user_can( self::CAP_EVENTS ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění k přístupu do přípravy akcí.', 'mlyn-event-intake' ) );
		}
		$profiles = $this->get_accessible_profiles();
		if ( ! $profiles ) {
			$this->render_empty_state();
			return;
		}
		$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $profiles[ $profile_id ] ) ) {
			$profile_id = (int) array_key_first( $profiles );
		}
		$months = $this->get_visible_months();
		$month  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $months[ $month ] ) ) {
			$next  = ( new DateTimeImmutable( 'first day of next month', wp_timezone() ) )->format( 'Y-m' );
			$month = isset( $months[ $next ] ) ? $next : (string) array_key_first( $months );
		}
		$profile     = get_post( $profile_id );
		$settings    = $this->get_profile_settings( $profile_id );
		$rows        = $this->database->get_month_rows( $profile_id, $month );
		$recovered   = get_transient( $this->recovery_key( $profile_id, $month ) );
		if ( is_array( $recovered ) ) {
			$rows = $this->prepare_recovered_rows( $recovered, $rows );
			$this->recovered_form = true;
			delete_transient( $this->recovery_key( $profile_id, $month ) );
		}
		$current     = current_time( 'Y-m' );
		$past_month  = $month < $current;
		$last_update = get_post_meta( $profile_id, '_mei_last_update', true );
		$last_import = get_post_meta( $profile_id, '_mei_last_import', true );
		?>
		<div class="wrap mei-wrap">
			<h1><?php esc_html_e( 'Příprava akcí', 'mlyn-event-intake' ); ?></h1>
			<?php $this->render_page_notice(); ?>
			<?php if ( current_user_can( self::CAP_PROFILES ) && count( $profiles ) > 1 ) : ?>
				<form method="get" class="mei-profile-switcher"><input type="hidden" name="page" value="mei-events"><label><?php esc_html_e( 'Profil pořadatele', 'mlyn-event-intake' ); ?> <select name="profile_id" onchange="this.form.submit()"><?php foreach ( $profiles as $id => $name ) : ?><option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $id, $profile_id ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label></form>
			<?php endif; ?>
			<h2 class="mei-profile-title"><?php echo esc_html( $profile ? $profile->post_title : '' ); ?></h2>
			<p class="mei-timestamps"><?php echo esc_html( sprintf( __( 'Poslední úprava: %1$s | Poslední import: %2$s', 'mlyn-event-intake' ), $this->format_timestamp( $last_update ), $this->format_timestamp( $last_import ) ) ); ?></p>
			<label class="mei-month-select"><span><?php esc_html_e( 'Měsíc', 'mlyn-event-intake' ); ?></span><select id="mei-month-select"><?php foreach ( $months as $key => $label ) : ?><option value="<?php echo esc_url( add_query_arg( array( 'page' => 'mei-events', 'profile_id' => $profile_id, 'month' => $key ), admin_url( 'admin.php' ) ) ); ?>" <?php selected( $key, $month ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<nav class="mei-month-tabs" aria-label="<?php esc_attr_e( 'Měsíce akcí', 'mlyn-event-intake' ); ?>"><?php foreach ( $months as $key => $label ) : ?><a class="<?php echo esc_attr( ( $key === $month ? 'is-active ' : '' ) . ( $key < $current ? 'is-past' : '' ) ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mei-events', 'profile_id' => $profile_id, 'month' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>

			<?php $this->render_event_form( $profile_id, $month, $rows, $settings, $past_month ); ?>
		</div>
		<?php $this->render_content_modal(); ?>
		<?php $this->render_focal_point_modal(); ?>
		<?php
	}

	private function render_event_form( int $profile_id, string $month, array $rows, array $settings, bool $past_month ): void {
		$allowed = $this->get_allowed_options( $settings );
		?>
		<form class="mei-events-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" data-readonly="<?php echo $past_month ? '1' : '0'; ?>" data-month="<?php echo esc_attr( $month ); ?>">
			<input type="hidden" name="action" value="mei_save_events"><input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) $profile_id ); ?>"><input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>"><?php wp_nonce_field( 'mei_save_events_' . $profile_id . '_' . $month ); ?>
			<div class="mei-table-scroll"><table class="widefat striped mei-event-table"><thead><tr>
				<th class="mei-actions-column"><span class="screen-reader-text"><?php esc_html_e( 'Akce', 'mlyn-event-intake' ); ?></span></th>
			<?php $this->render_sortable_heading( 'title', __( 'Název', 'mlyn-event-intake' ), true ); ?><th><?php esc_html_e( 'Text akce', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Krátký popis', 'mlyn-event-intake' ); ?></th><?php $this->render_sortable_heading( 'start', __( 'Začátek', 'mlyn-event-intake' ), true, true ); ?><?php $this->render_sortable_heading( 'end', __( 'Konec', 'mlyn-event-intake' ), true ); ?><th><?php esc_html_e( 'Celodenní', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Místo', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Pořadatel', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Web', 'mlyn-event-intake' ); ?></th><?php $this->render_sortable_heading( 'cost', __( 'Vstupné', 'mlyn-event-intake' ) ); ?><th><?php esc_html_e( 'Kapacita', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Volná místa', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Poznámka k obsazenosti', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Štítky', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Rubriky akce', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Obrázek', 'mlyn-event-intake' ); ?></th><th><?php esc_html_e( 'Stav', 'mlyn-event-intake' ); ?></th>
			</tr></thead><tbody id="mei-event-rows"><?php foreach ( $rows as $index => $row ) { $this->render_event_row( (string) $index, $row, $allowed, $past_month || $row['end_at'] < current_time( 'mysql' ) ); } ?></tbody></table></div>
			<?php if ( ! $past_month ) : ?><p class="mei-form-actions"><button type="button" class="button" id="mei-add-row"><?php esc_html_e( 'Přidat akci', 'mlyn-event-intake' ); ?></button> <?php submit_button( __( 'Uložit akce', 'mlyn-event-intake' ), 'primary', 'submit', false ); ?></p><?php endif; ?>
		</form>
		<script type="text/html" id="mei-row-template"><?php $this->render_event_row( '__INDEX__', $this->row_defaults( $settings ), $allowed, false ); ?></script>
		<?php if ( current_user_can( self::CAP_IMPORT ) ) : ?>
			<form class="mei-import-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="mei_import_events"><input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) $profile_id ); ?>"><input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>"><?php wp_nonce_field( 'mei_import_events_' . $profile_id ); ?><?php submit_button( __( 'Importovat všechny změněné aktivní akce', 'mlyn-event-intake' ), 'secondary', 'submit', false, array( 'onclick' => "return window.confirm('" . esc_js( __( 'Importovat všechny změněné aktivní akce tohoto profilu?', 'mlyn-event-intake' ) ) . "');" ) ); ?><p class="description"><?php esc_html_e( 'Nové akce se vytvoří jako koncepty. U již propojených akcí zůstane zachovaný jejich současný stav publikace.', 'mlyn-event-intake' ); ?></p></form>
		<?php endif; ?>
		<?php
	}

	private function render_sortable_heading( string $key, string $label, bool $required = false, bool $initial = false ): void {
		$state       = $initial ? 'ascending' : 'none';
		$indicator   = $initial ? '▲' : '↕';
		$status_text = $initial
			? __( 'Aktuálně seřazeno vzestupně. Aktivovat pro sestupné řazení.', 'mlyn-event-intake' )
			: __( 'Aktivovat pro vzestupné řazení.', 'mlyn-event-intake' );
		?>
		<th data-sort="<?php echo esc_attr( $key ); ?>" aria-sort="<?php echo esc_attr( $state ); ?>">
			<button type="button" class="mei-sort-button" title="<?php echo esc_attr( $initial ? __( 'Seřadit sestupně', 'mlyn-event-intake' ) : __( 'Seřadit vzestupně', 'mlyn-event-intake' ) ); ?>">
				<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
				<span class="mei-sort-indicator" aria-hidden="true"><?php echo esc_html( $indicator ); ?></span>
				<span class="screen-reader-text mei-sort-status"><?php echo esc_html( $status_text ); ?></span>
			</button>
		</th>
		<?php
	}

	private function render_event_row( string $index, array $row, array $allowed, bool $readonly ): void {
		$uuid      = $row['uuid'] ?? '';
		$all_day   = ! empty( $row['all_day'] );
		$start     = ! empty( $row['start_at'] ) ? ( $all_day ? substr( $row['start_at'], 0, 10 ) : str_replace( ' ', 'T', substr( $row['start_at'], 0, 16 ) ) ) : '';
		$end       = ! empty( $row['end_at'] ) ? ( $all_day ? substr( $row['end_at'], 0, 10 ) : str_replace( ' ', 'T', substr( $row['end_at'], 0, 16 ) ) ) : '';
		$disabled  = $readonly ? ' disabled' : '';
		$image_url       = ! empty( $row['image_id'] ) ? wp_get_attachment_image_url( (int) $row['image_id'], 'thumbnail' ) : '';
		$image_large_url = ! empty( $row['image_id'] ) ? wp_get_attachment_image_url( (int) $row['image_id'], 'large' ) : '';
		?>
		<tr class="mei-event-row<?php echo $readonly ? ' is-readonly' : ''; ?>" data-start="<?php echo esc_attr( $start ); ?>" data-end="<?php echo esc_attr( $end ); ?>" data-title="<?php echo esc_attr( $row['title'] ?? '' ); ?>" data-cost="<?php echo esc_attr( $row['cost'] ?? '' ); ?>">
			<td><input type="hidden" name="rows[<?php echo esc_attr( $index ); ?>][uuid]" value="<?php echo esc_attr( $uuid ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><button type="button" class="button-link-delete mei-remove-row"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Odstranit akci', 'mlyn-event-intake' ); ?>" title="<?php esc_attr_e( 'Odstranit akci', 'mlyn-event-intake' ); ?>">×</button></td>
			<td><input type="text" class="mei-title" name="rows[<?php echo esc_attr( $index ); ?>][title]" required value="<?php echo esc_attr( $row['title'] ?? '' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><textarea class="mei-content" name="rows[<?php echo esc_attr( $index ); ?>][content]" hidden<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $row['content'] ?? '' ); ?></textarea><button type="button" class="button mei-edit-content"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Upravit text', 'mlyn-event-intake' ); ?></button><span class="mei-content-excerpt"><?php echo esc_html( wp_html_excerpt( wp_strip_all_tags( $row['content'] ?? '' ), 70, '…' ) ); ?></span></td>
			<td><textarea name="rows[<?php echo esc_attr( $index ); ?>][excerpt]" rows="3"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $row['excerpt'] ?? '' ); ?></textarea></td>
			<td><input class="mei-start" type="<?php echo $all_day ? 'date' : 'datetime-local'; ?>" name="rows[<?php echo esc_attr( $index ); ?>][start]" required value="<?php echo esc_attr( $start ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><input class="mei-end" type="<?php echo $all_day ? 'date' : 'datetime-local'; ?>" name="rows[<?php echo esc_attr( $index ); ?>][end]" required value="<?php echo esc_attr( $end ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><label><input class="mei-all-day" type="checkbox" name="rows[<?php echo esc_attr( $index ); ?>][all_day]" value="1" <?php checked( $all_day ); ?><?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>> <span class="screen-reader-text"><?php esc_html_e( 'Celodenní akce', 'mlyn-event-intake' ); ?></span></label></td>
			<td><?php $this->render_row_select( $index, 'venue_id', $allowed['venues'], (int) ( $row['venue_id'] ?? 0 ), false, $readonly ); ?></td>
			<td><?php $this->render_row_select( $index, 'organizer_id', $allowed['organizers'], (int) ( $row['organizer_id'] ?? 0 ), false, $readonly ); ?></td>
			<td><input type="url" name="rows[<?php echo esc_attr( $index ); ?>][website]" value="<?php echo esc_attr( $row['website'] ?? '' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><input class="mei-cost" type="number" name="rows[<?php echo esc_attr( $index ); ?>][cost]" min="0" step="0.01" value="<?php echo esc_attr( $row['cost'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Bez údaje', 'mlyn-event-intake' ); ?>" title="<?php esc_attr_e( 'Prázdné pole skryje vstupné, 0 znamená zdarma.', 'mlyn-event-intake' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><input type="number" name="rows[<?php echo esc_attr( $index ); ?>][capacity]" min="0" step="1" value="<?php echo esc_attr( $row['capacity'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Bez údaje', 'mlyn-event-intake' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><input type="number" name="rows[<?php echo esc_attr( $index ); ?>][available_places]" min="0" step="1" value="<?php echo esc_attr( $row['available_places'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Bez údaje', 'mlyn-event-intake' ); ?>" title="<?php esc_attr_e( 'Nula označí akci jako obsazenou i bez uvedené kapacity.', 'mlyn-event-intake' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></td>
			<td><textarea name="rows[<?php echo esc_attr( $index ); ?>][occupancy_note]" rows="3" maxlength="500"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $row['occupancy_note'] ?? '' ); ?></textarea></td>
			<td><?php $this->render_row_select( $index, 'tag_ids', $allowed['tags'], $row['tag_ids'] ?? array(), true, $readonly ); ?></td>
			<td><?php $this->render_row_select( $index, 'category_ids', $allowed['categories'], $row['category_ids'] ?? array(), true, $readonly ); ?></td>
			<td class="mei-image-cell"><input type="hidden" name="rows[<?php echo esc_attr( $index ); ?>][image_id]" value="<?php echo esc_attr( (string) ( $row['image_id'] ?? 0 ) ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><input type="hidden" class="mei-focal-x" name="rows[<?php echo esc_attr( $index ); ?>][focal_x]" value="<?php echo esc_attr( $row['focal_x'] ?? '' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><input type="hidden" class="mei-focal-y" name="rows[<?php echo esc_attr( $index ); ?>][focal_y]" value="<?php echo esc_attr( $row['focal_y'] ?? '' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><div class="mei-image-preview" data-large-url="<?php echo esc_url( $image_large_url ?: $image_url ); ?>"><?php if ( $image_url ) : ?><img src="<?php echo esc_url( $image_url ); ?>" alt=""><?php endif; ?></div><input type="file" name="row_images[<?php echo esc_attr( $index ); ?>]" accept="image/jpeg,image/png,image/gif,image/webp"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><button type="button" class="button mei-edit-focal-point"<?php disabled( $readonly || ! $image_url ); ?>><?php esc_html_e( 'Nastavit výřez', 'mlyn-event-intake' ); ?></button><label><input class="mei-remove-image" type="checkbox" name="rows[<?php echo esc_attr( $index ); ?>][remove_image]" value="1"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>> <?php esc_html_e( 'Odstranit', 'mlyn-event-intake' ); ?></label></td>
			<td class="mei-sync-status"><span class="mei-status mei-status-<?php echo esc_attr( $row['sync_status'] ?? 'new' ); ?>"><?php echo esc_html( $this->status_label( $row['sync_status'] ?? 'new' ) ); ?></span><?php if ( ! empty( $row['sync_error'] ) ) : ?><small><?php echo esc_html( $row['sync_error'] ); ?></small><?php endif; ?><?php if ( ! empty( $row['tec_event_id'] ) && current_user_can( self::CAP_IMPORT ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( (int) $row['tec_event_id'] ) ); ?>"><?php esc_html_e( 'Otevřít akci', 'mlyn-event-intake' ); ?></a><?php endif; ?></td>
		</tr>
		<?php
	}

	private function render_row_select( string $index, string $key, array $options, $selected, bool $multiple, bool $readonly ): void {
		$selected = $multiple ? array_map( 'intval', (array) $selected ) : (int) $selected;
		$name     = 'rows[' . $index . '][' . $key . ']' . ( $multiple ? '[]' : '' );
		?><select name="<?php echo esc_attr( $name ); ?>"<?php echo $multiple ? ' multiple size="4"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php disabled( $readonly ); ?>><?php if ( ! $multiple ) : ?><option value="0"><?php esc_html_e( '— Žádné —', 'mlyn-event-intake' ); ?></option><?php endif; ?><?php foreach ( $options as $id => $label ) : ?><option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $multiple ? in_array( (int) $id, $selected, true ) : $selected === (int) $id ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><?php
	}

	private function render_content_modal(): void {
		?>
		<div id="mei-content-modal" class="mei-modal" hidden><div class="mei-modal-backdrop"></div><div class="mei-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mei-modal-title"><h2 id="mei-modal-title"><?php esc_html_e( 'Text akce', 'mlyn-event-intake' ); ?></h2><?php wp_editor( '', 'mei_content_editor', array( 'textarea_rows' => 14, 'media_buttons' => false, 'teeny' => false ) ); ?><p><button type="button" class="button button-primary" id="mei-content-apply"><?php esc_html_e( 'Použít text', 'mlyn-event-intake' ); ?></button> <button type="button" class="button" id="mei-content-cancel"><?php esc_html_e( 'Zrušit', 'mlyn-event-intake' ); ?></button></p></div></div>
		<?php
	}

	private function render_focal_point_modal(): void {
		?>
		<div id="mei-focal-modal" class="mei-modal" hidden><div class="mei-modal-backdrop"></div><div class="mei-modal-dialog mei-focal-dialog" role="dialog" aria-modal="true" aria-labelledby="mei-focal-title"><h2 id="mei-focal-title"><?php esc_html_e( 'Výřez obrázku na detailu akce', 'mlyn-event-intake' ); ?></h2><p><?php esc_html_e( 'Přesuňte bod na nejdůležitější část obrázku. Náhled odpovídá hornímu banneru na detailu akce.', 'mlyn-event-intake' ); ?></p><div id="mei-focal-picker-root"></div><h3><?php esc_html_e( 'Náhled banneru', 'mlyn-event-intake' ); ?></h3><div id="mei-focal-preview" class="mei-focal-preview" aria-hidden="true"></div><p><button type="button" class="button button-primary" id="mei-focal-apply"><?php esc_html_e( 'Použít výřez', 'mlyn-event-intake' ); ?></button> <button type="button" class="button" id="mei-focal-reset"><?php esc_html_e( 'Vycentrovat', 'mlyn-event-intake' ); ?></button> <button type="button" class="button" id="mei-focal-cancel"><?php esc_html_e( 'Zrušit', 'mlyn-event-intake' ); ?></button></p></div></div>
		<?php
	}

	public function save_events(): void {
		if ( ! current_user_can( self::CAP_EVENTS ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění ukládat připravované akce.', 'mlyn-event-intake' ) );
		}
		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$month      = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		check_admin_referer( 'mei_save_events_' . $profile_id . '_' . $month );
		if ( ! $this->can_access_profile( $profile_id ) || ! isset( $this->get_visible_months()[ $month ] ) || $month < current_time( 'Y-m' ) ) {
			wp_die( esc_html__( 'Tento profil pořadatele nebo měsíc nelze upravovat.', 'mlyn-event-intake' ) );
		}

		$raw = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
		try {
			$settings = $this->get_profile_settings( $profile_id );
			$rows     = $this->validate_rows( $raw, $profile_id, $month, $settings );
			$changes  = $this->database->save_month( $profile_id, $month, $rows, get_current_user_id() );
			delete_transient( $this->recovery_key( $profile_id, $month ) );
			if ( $changes ) {
				$now = current_time( 'mysql' );
				update_post_meta( $profile_id, '_mei_last_update', $now );
				$this->notify_administrators( $profile_id, $month, $changes );
			}
			$this->redirect_to_events( $profile_id, $month, 'saved', (string) $changes );
		} catch ( RuntimeException $exception ) {
			set_transient( $this->recovery_key( $profile_id, $month ), $raw, 10 * MINUTE_IN_SECONDS );
			$this->redirect_to_events( $profile_id, $month, 'error', $exception->getMessage() );
		}
	}

	private function validate_rows( array $raw_rows, int $profile_id, string $month, array $settings ): array {
		$allowed  = $this->get_allowed_options( $settings );
		$existing = array_column( $this->database->get_month_rows( $profile_id, $month ), null, 'uuid' );
		$result   = array();
		$row_number = 0;
		foreach ( $raw_rows as $file_index => $raw ) {
			$index = $row_number++;
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$uuid = isset( $raw['uuid'] ) && preg_match( '/^[a-f0-9-]{36}$/i', $raw['uuid'] ) ? strtolower( $raw['uuid'] ) : wp_generate_uuid4();
			if ( isset( $existing[ $uuid ] ) && $existing[ $uuid ]['end_at'] < current_time( 'mysql' ) ) {
				continue;
			}
			$title   = sanitize_text_field( $raw['title'] ?? '' );
			$all_day = ! empty( $raw['all_day'] );
			if ( '' === $title ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: název je povinný.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			$start = $this->parse_event_datetime( (string) ( $raw['start'] ?? '' ), $all_day, false );
			$end   = $this->parse_event_datetime( (string) ( $raw['end'] ?? '' ), $all_day, true );
			if ( ! $start || ! $end ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: zadejte platný začátek a konec.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			if ( $start->format( 'Y-m' ) !== $month ) {
				$month_label = substr( $month, 5, 2 ) . '/' . substr( $month, 0, 4 );
				throw new RuntimeException( sprintf( __( 'Řádek %1$d: začátek akce musí být v měsíci %2$s.', 'mlyn-event-intake' ), $index + 1, $month_label ) );
			}
			if ( $end < $start ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: konec nesmí předcházet začátku.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			if ( $end < new DateTimeImmutable( 'now', wp_timezone() ) ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: skončenou akci nelze přidat ani změnit.', 'mlyn-event-intake' ), $index + 1 ) );
			}

			$venue_id     = absint( $raw['venue_id'] ?? 0 );
			$organizer_id = absint( $raw['organizer_id'] ?? 0 );
			$tag_ids      = array_values( array_unique( array_map( 'absint', (array) ( $raw['tag_ids'] ?? array() ) ) ) );
			$category_ids = array_values( array_unique( array_map( 'absint', (array) ( $raw['category_ids'] ?? array() ) ) ) );
			$this->assert_allowed( $venue_id ? array( $venue_id ) : array(), array_keys( $allowed['venues'] ), $index );
			$this->assert_allowed( $organizer_id ? array( $organizer_id ) : array(), array_keys( $allowed['organizers'] ), $index );
			$this->assert_allowed( $tag_ids, array_keys( $allowed['tags'] ), $index );
			$this->assert_allowed( $category_ids, array_keys( $allowed['categories'] ), $index );

			$cost = isset( $raw['cost'] ) ? str_replace( ',', '.', trim( (string) $raw['cost'] ) ) : '';
			if ( '' !== $cost && ( ! is_numeric( $cost ) || (float) $cost < 0 ) ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: vstupné musí být prázdné, nula nebo kladné číslo.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			$capacity_valid  = true;
			$available_valid = true;
			$capacity        = $this->parse_nullable_count( $raw['capacity'] ?? '', $capacity_valid );
			$available       = $this->parse_nullable_count( $raw['available_places'] ?? '', $available_valid );
			if ( ! $capacity_valid || ! $available_valid ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: kapacita a volná místa musí být prázdná, nula nebo kladné celé číslo.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			if ( null !== $capacity && null !== $available && $available > $capacity ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: počet volných míst nesmí překročit kapacitu.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			$occupancy_note = mb_substr( sanitize_textarea_field( (string) ( $raw['occupancy_note'] ?? '' ) ), 0, 500 );
			$website = esc_url_raw( trim( (string) ( $raw['website'] ?? '' ) ) );
			if ( ! empty( $raw['website'] ) && ! $website ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: web musí být platná URL adresa.', 'mlyn-event-intake' ), $index + 1 ) );
			}

			$image_id = absint( $raw['image_id'] ?? 0 );
			if ( ! empty( $raw['remove_image'] ) ) {
				$image_id = 0;
			}
			if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: vybraný soubor není obrázek.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			$focal_valid = true;
			$focal_x     = $this->parse_nullable_percentage( $raw['focal_x'] ?? '', $focal_valid );
			$focal_y     = $this->parse_nullable_percentage( $raw['focal_y'] ?? '', $focal_valid );
			if ( ! $focal_valid || ( null === $focal_x ) !== ( null === $focal_y ) ) {
				throw new RuntimeException( sprintf( __( 'Řádek %d: bod výřezu obrázku není platný.', 'mlyn-event-intake' ), $index + 1 ) );
			}
			if ( ! $image_id && ! $this->has_row_image_upload( $file_index ) ) {
				$focal_x = null;
				$focal_y = null;
			}

			$result[] = array(
				'uuid'         => $uuid,
				'title'        => $title,
				'content'      => wp_kses_post( $raw['content'] ?? '' ),
				'excerpt'      => sanitize_textarea_field( $raw['excerpt'] ?? '' ),
				'start_at'     => $start->format( 'Y-m-d H:i:s' ),
				'end_at'       => $end->format( 'Y-m-d H:i:s' ),
				'all_day'      => $all_day,
				'venue_id'     => $venue_id,
				'organizer_id' => $organizer_id,
				'website'      => $website,
				'cost'         => '' === $cost ? '' : $this->normalize_cost( $cost ),
				'capacity'     => null === $capacity ? '' : (string) $capacity,
				'available_places' => null === $available ? '' : (string) $available,
				'occupancy_note' => $occupancy_note,
				'tag_ids'      => $tag_ids,
				'category_ids' => $category_ids,
				'image_id'     => $image_id,
				'focal_x'      => null === $focal_x ? '' : (string) $focal_x,
				'focal_y'      => null === $focal_y ? '' : (string) $focal_y,
				'_file_index'  => $file_index,
				'_row_index'   => $index,
			);
		}

		$uploaded_ids = array();
		try {
			foreach ( $result as &$row ) {
				$uploaded = $this->handle_row_image( $row['_file_index'], $row['_row_index'] );
				if ( $uploaded ) {
					if ( ! wp_attachment_is_image( $uploaded ) ) {
						$uploaded_ids[] = $uploaded;
						throw new RuntimeException( sprintf( __( 'Řádek %d: vybraný soubor není obrázek.', 'mlyn-event-intake' ), $row['_row_index'] + 1 ) );
					}
					$row['image_id'] = $uploaded;
					$uploaded_ids[]  = $uploaded;
				}
				if ( ! $row['image_id'] ) {
					$row['focal_x'] = '';
					$row['focal_y'] = '';
				}
				unset( $row['_file_index'], $row['_row_index'] );
			}
			unset( $row );
		} catch ( RuntimeException $exception ) {
			foreach ( $uploaded_ids as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
			throw $exception;
		}
		return $result;
	}

	private function has_row_image_upload( $file_index ): bool {
		return isset( $_FILES['row_images']['error'][ $file_index ] )
			&& UPLOAD_ERR_NO_FILE !== (int) $_FILES['row_images']['error'][ $file_index ];
	}

	private function handle_row_image( $file_index, int $display_index ): int {
		if ( ! isset( $_FILES['row_images']['error'][ $file_index ] ) ) {
			return 0;
		}
		$upload_error = (int) $_FILES['row_images']['error'][ $file_index ];
		if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
			return 0;
		}
		$file = array(
			'name'     => sanitize_file_name( $_FILES['row_images']['name'][ $file_index ] ),
			'type'     => sanitize_mime_type( $_FILES['row_images']['type'][ $file_index ] ),
			'tmp_name' => $_FILES['row_images']['tmp_name'][ $file_index ],
			'error'    => $upload_error,
			'size'     => (int) $_FILES['row_images']['size'][ $file_index ],
		);
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			throw new RuntimeException( sprintf( __( 'Řádek %d: nahrání obrázku se nezdařilo.', 'mlyn-event-intake' ), $display_index + 1 ) );
		}
		$_FILES['mei_row_image'] = $file;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload( 'mei_row_image', 0, array(), array( 'test_form' => false ) );
		unset( $_FILES['mei_row_image'] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( sprintf( __( 'Řádek %1$d: %2$s', 'mlyn-event-intake' ), $display_index + 1, $id->get_error_message() ) );
		}
		return (int) $id;
	}

	public function import_events(): void {
		if ( ! current_user_can( self::CAP_IMPORT ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění importovat akce.', 'mlyn-event-intake' ) );
		}
		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$month      = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : current_time( 'Y-m' );
		check_admin_referer( 'mei_import_events_' . $profile_id );
		if ( ! get_post( $profile_id ) || self::PROFILE_TYPE !== get_post_type( $profile_id ) ) {
			wp_die( esc_html__( 'Profil pořadatele nebyl nalezen.', 'mlyn-event-intake' ) );
		}
		$lock = 'mei_import_lock_' . $profile_id;
		if ( get_transient( $lock ) ) {
			$this->redirect_to_events( $profile_id, $month, 'error', __( 'Import tohoto profilu už probíhá.', 'mlyn-event-intake' ) );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		try {
			$summary = $this->sync->import_profile( $profile_id, $this->get_profile_settings( $profile_id ) );
			update_post_meta( $profile_id, '_mei_last_import', current_time( 'mysql' ) );
			$this->redirect_to_events( $profile_id, $month, 'imported', implode( ',', $summary ) );
		} catch ( RuntimeException $exception ) {
			$this->redirect_to_events( $profile_id, $month, 'error', $exception->getMessage() );
		} finally {
			delete_transient( $lock );
		}
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAP_PROFILES ) ) {
			return;
		}
		$settings = $this->get_global_settings();
		$admins   = get_users( array( 'role' => 'administrator', 'orderby' => 'display_name' ) );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Event Intake Settings', 'mlyn-event-intake' ); ?></h1><?php $this->render_page_notice(); ?><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="mei_save_settings"><?php wp_nonce_field( 'mei_save_settings' ); ?><table class="form-table" role="presentation"><tr><th scope="row"><?php esc_html_e( 'Save notifications', 'mlyn-event-intake' ); ?></th><td><label><input type="checkbox" name="notifications_enabled" value="1" <?php checked( $settings['notifications_enabled'] ); ?>> <?php esc_html_e( 'Email administrators when an intake table is changed', 'mlyn-event-intake' ); ?></label></td></tr><tr><th scope="row"><label for="mei-recipients"><?php esc_html_e( 'Notification recipients', 'mlyn-event-intake' ); ?></label></th><td><select id="mei-recipients" name="recipient_ids[]" multiple size="8"><?php foreach ( $admins as $admin ) : ?><option value="<?php echo esc_attr( (string) $admin->ID ); ?>" <?php selected( in_array( $admin->ID, $settings['recipient_ids'], true ) ); ?>><?php echo esc_html( $admin->display_name . ' (' . $admin->user_email . ')' ); ?></option><?php endforeach; ?></select></td></tr></table><?php submit_button(); ?></form></div>
		<?php
	}

	public function save_global_settings(): void {
		if ( ! current_user_can( self::CAP_PROFILES ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'mlyn-event-intake' ) );
		}
		check_admin_referer( 'mei_save_settings' );
		$settings = array(
			'notifications_enabled' => ! empty( $_POST['notifications_enabled'] ),
			'recipient_ids'         => array_values( array_unique( array_map( 'absint', (array) ( $_POST['recipient_ids'] ?? array() ) ) ) ),
		);
		update_option( self::OPTION_SETTINGS, $settings, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'mei-settings', 'mei_notice' => 'settings_saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notify_administrators( int $profile_id, string $month, int $changes ): void {
		$settings = $this->get_global_settings();
		if ( ! $settings['notifications_enabled'] || ! $settings['recipient_ids'] ) {
			return;
		}
		$emails = array();
		foreach ( $settings['recipient_ids'] as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		if ( ! $emails ) {
			return;
		}
		$profile = get_the_title( $profile_id );
		$subject = sprintf( __( '[%1$s] Event intake updated: %2$s', 'mlyn-event-intake' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $profile );
		$message = sprintf(
			__( "%1$s changed %2$d event row(s) for %3$s in month %4$s.\n\nReview the table: %5$s", 'mlyn-event-intake' ),
			wp_get_current_user()->display_name,
			$changes,
			$profile,
			$month,
			add_query_arg( array( 'page' => 'mei-events', 'profile_id' => $profile_id, 'month' => $month ), admin_url( 'admin.php' ) )
		);
		wp_mail( $emails, $subject, $message );
	}

	private function get_accessible_profiles(): array {
		$args = array(
			'post_type'      => self::PROFILE_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( ! current_user_can( self::CAP_PROFILES ) ) {
			$args['meta_key']   = self::PROFILE_USER;
			$args['meta_value'] = get_current_user_id();
		}
		$profiles = array();
		foreach ( get_posts( $args ) as $profile ) {
			$profiles[ $profile->ID ] = $profile->post_title;
		}
		return $profiles;
	}

	private function can_access_profile( int $profile_id ): bool {
		if ( current_user_can( self::CAP_PROFILES ) ) {
			return self::PROFILE_TYPE === get_post_type( $profile_id );
		}
		return get_current_user_id() === (int) get_post_meta( $profile_id, self::PROFILE_USER, true );
	}

	private function recovery_key( int $profile_id, string $month ): string {
		return 'mei_recovery_' . get_current_user_id() . '_' . $profile_id . '_' . str_replace( '-', '_', $month );
	}

	private function prepare_recovered_rows( array $submitted, array $saved_rows ): array {
		$saved_by_uuid = array_column( $saved_rows, null, 'uuid' );
		$rows          = array();
		foreach ( $submitted as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$uuid = isset( $raw['uuid'] ) && is_string( $raw['uuid'] ) ? $raw['uuid'] : '';
			$base = isset( $saved_by_uuid[ $uuid ] ) ? $saved_by_uuid[ $uuid ] : array(
				'sync_status' => 'new',
				'sync_error'  => '',
				'tec_event_id'=> 0,
			);
			$all_day = ! empty( $raw['all_day'] );
			$start    = sanitize_text_field( $raw['start'] ?? '' );
			$end      = sanitize_text_field( $raw['end'] ?? '' );
			$rows[]   = array_merge(
				$base,
				array(
					'uuid'         => $uuid,
					'title'        => (string) ( $raw['title'] ?? '' ),
					'content'      => (string) ( $raw['content'] ?? '' ),
					'excerpt'      => (string) ( $raw['excerpt'] ?? '' ),
					'start_at'     => $all_day ? $start . ' 00:00:00' : str_replace( 'T', ' ', $start ) . ':00',
					'end_at'       => $all_day ? $end . ' 23:59:59' : str_replace( 'T', ' ', $end ) . ':00',
					'all_day'      => $all_day,
					'venue_id'     => absint( $raw['venue_id'] ?? 0 ),
					'organizer_id' => absint( $raw['organizer_id'] ?? 0 ),
					'website'      => (string) ( $raw['website'] ?? '' ),
					'cost'         => (string) ( $raw['cost'] ?? '' ),
					'capacity'     => (string) ( $raw['capacity'] ?? '' ),
					'available_places' => (string) ( $raw['available_places'] ?? '' ),
					'occupancy_note' => (string) ( $raw['occupancy_note'] ?? '' ),
					'tag_ids'      => array_map( 'absint', (array) ( $raw['tag_ids'] ?? array() ) ),
					'category_ids' => array_map( 'absint', (array) ( $raw['category_ids'] ?? array() ) ),
					'image_id'     => ! empty( $raw['remove_image'] ) ? 0 : absint( $raw['image_id'] ?? 0 ),
					'focal_x'      => ! empty( $raw['remove_image'] ) ? '' : (string) ( $raw['focal_x'] ?? '' ),
					'focal_y'      => ! empty( $raw['remove_image'] ) ? '' : (string) ( $raw['focal_y'] ?? '' ),
				)
			);
		}
		return $rows;
	}

	private function get_visible_months(): array {
		$year   = (int) current_time( 'Y' );
		$months = array();
		foreach ( array( $year, $year + 1 ) as $visible_year ) {
			for ( $month = 1; $month <= 12; ++$month ) {
				$key            = sprintf( '%04d-%02d', $visible_year, $month );
				$months[ $key ] = sprintf( '%02d/%04d', $month, $visible_year );
			}
		}
		return $months;
	}

	private function get_profile_settings( int $profile_id ): array {
		$saved = get_post_meta( $profile_id, self::PROFILE_SETTINGS, true );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->profile_defaults() );
	}

	private function profile_defaults(): array {
		return array(
			'default_all_day'  => false,
			'default_cost'     => '',
			'default_capacity' => '',
			'default_available_places' => '',
			'default_venue'    => 0,
			'default_organizer'=> 0,
			'default_tags'     => array(),
			'default_categories' => array(),
			'allowed_venues'   => array(),
			'allowed_organizers' => array(),
			'allowed_tags'     => array(),
			'allowed_categories' => array(),
			'currency_symbol'  => 'Kč',
			'currency_position'=> 'postfix',
			'currency_code'    => 'CZK',
			'event_status'     => 'scheduled',
			'hide_from_upcoming' => false,
			'sticky'           => false,
			'featured'         => false,
		);
	}

	private function sanitize_profile_settings( array $raw ): array {
		$settings = $this->profile_defaults();
		foreach ( array( 'default_venue', 'default_organizer' ) as $key ) {
			$settings[ $key ] = absint( $raw[ $key ] ?? 0 );
		}
		foreach ( array( 'default_tags', 'default_categories', 'allowed_venues', 'allowed_organizers', 'allowed_tags', 'allowed_categories' ) as $key ) {
			$settings[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw[ $key ] ?? array() ) ) ) ) );
		}
		$settings['allowed_venues']     = array_values( array_unique( array_filter( array_merge( $settings['allowed_venues'], array( $settings['default_venue'] ) ) ) ) );
		$settings['allowed_organizers'] = array_values( array_unique( array_filter( array_merge( $settings['allowed_organizers'], array( $settings['default_organizer'] ) ) ) ) );
		$settings['allowed_tags']       = array_values( array_unique( array_merge( $settings['allowed_tags'], $settings['default_tags'] ) ) );
		$settings['allowed_categories'] = array_values( array_unique( array_merge( $settings['allowed_categories'], $settings['default_categories'] ) ) );
		$cost = str_replace( ',', '.', trim( (string) ( $raw['default_cost'] ?? '' ) ) );
		$settings['default_cost']       = '' === $cost ? '' : ( is_numeric( $cost ) && (float) $cost >= 0 ? $this->normalize_cost( $cost ) : '' );
		$capacity_valid                 = true;
		$available_valid                = true;
		$capacity                       = $this->parse_nullable_count( $raw['default_capacity'] ?? '', $capacity_valid );
		$available                      = $this->parse_nullable_count( $raw['default_available_places'] ?? '', $available_valid );
		$settings['default_capacity']   = $capacity_valid && null !== $capacity ? (string) $capacity : '';
		$settings['default_available_places'] = $available_valid && null !== $available ? (string) $available : '';
		$settings['default_all_day']    = ! empty( $raw['default_all_day'] );
		$settings['currency_symbol']    = sanitize_text_field( $raw['currency_symbol'] ?? 'Kč' );
		$settings['currency_position']  = in_array( $raw['currency_position'] ?? '', array( 'prefix', 'postfix' ), true ) ? $raw['currency_position'] : 'postfix';
		$settings['currency_code']      = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $raw['currency_code'] ?? 'CZK' ) ), 0, 3 ) );
		$settings['event_status']       = in_array( $raw['event_status'] ?? '', array( 'scheduled', 'canceled', 'postponed' ), true ) ? $raw['event_status'] : 'scheduled';
		foreach ( array( 'hide_from_upcoming', 'sticky', 'featured' ) as $key ) {
			$settings[ $key ] = ! empty( $raw[ $key ] );
		}
		return $settings;
	}

	private function get_allowed_options( array $settings ): array {
		return array(
			'venues'     => $this->filter_options( $this->get_linked_posts( 'tribe_venue' ), $settings['allowed_venues'] ),
			'organizers' => $this->filter_options( $this->get_linked_posts( 'tribe_organizer' ), $settings['allowed_organizers'] ),
			'tags'       => $this->filter_options( $this->get_terms( 'post_tag' ), $settings['allowed_tags'] ),
			'categories' => $this->filter_options( $this->get_terms( 'tribe_events_cat' ), $settings['allowed_categories'] ),
		);
	}

	private function filter_options( array $options, array $allowed ): array {
		if ( ! $allowed ) {
			return $options;
		}
		return array_intersect_key( $options, array_flip( array_map( 'intval', $allowed ) ) );
	}

	private function get_linked_posts( string $post_type ): array {
		$options = array();
		foreach ( get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}
		return $options;
	}

	private function get_terms( string $taxonomy ): array {
		$options = array();
		$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return $options;
		}
		foreach ( $terms as $term ) {
			$options[ $term->term_id ] = $term->name;
		}
		return $options;
	}

	private function row_defaults( array $settings ): array {
		return array(
			'uuid'          => '',
			'title'         => '',
			'content'       => '',
			'excerpt'       => '',
			'start_at'      => '',
			'end_at'        => '',
			'all_day'       => $settings['default_all_day'],
			'venue_id'      => $settings['default_venue'],
			'organizer_id'  => $settings['default_organizer'],
			'website'       => '',
			'cost'          => $settings['default_cost'],
			'capacity'      => $settings['default_capacity'],
			'available_places' => $settings['default_available_places'],
			'occupancy_note' => '',
			'tag_ids'       => $settings['default_tags'],
			'category_ids'  => $settings['default_categories'],
			'image_id'      => 0,
			'focal_x'       => '',
			'focal_y'       => '',
			'sync_status'   => 'new',
			'sync_error'    => '',
			'tec_event_id'  => 0,
		);
	}

	private function parse_event_datetime( string $value, bool $all_day, bool $is_end ): ?DateTimeImmutable {
		$format = $all_day ? '!Y-m-d' : '!Y-m-d\\TH:i';
		$date   = DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return null;
		}
		return $all_day && $is_end ? $date->setTime( 23, 59, 59 ) : $date;
	}

	private function assert_allowed( array $selected, array $allowed, int $index ): void {
		if ( array_diff( $selected, $allowed ) ) {
			throw new RuntimeException( sprintf( __( 'Řádek %d obsahuje hodnotu, která není pro tento profil povolena.', 'mlyn-event-intake' ), $index + 1 ) );
		}
	}

	private function normalize_cost( string $cost ): string {
		$normalized = number_format( (float) $cost, 2, '.', '' );
		return rtrim( rtrim( $normalized, '0' ), '.' );
	}

	private function parse_nullable_count( $raw, bool &$valid ): ?int {
		$value = trim( (string) $raw );
		$valid = true;
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d+$/', $value ) ) {
			$valid = false;
			return null;
		}
		$count = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $count ) {
			$valid = false;
			return null;
		}
		return (int) $count;
	}

	private function parse_nullable_percentage( $raw, bool &$valid ): ?int {
		$value = trim( (string) $raw );
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d{1,3}$/', $value ) || (int) $value > 100 ) {
			$valid = false;
			return null;
		}
		return (int) $value;
	}

	private function flag_occupancy_validation_error(): void {
		add_filter(
			'redirect_post_location',
			static function ( string $location ): string {
				return add_query_arg( 'mei_occupancy_invalid', '1', $location );
			}
		);
	}

	private function get_global_settings(): array {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array( 'notifications_enabled' => false, 'recipient_ids' => array() ) );
	}

	private function format_timestamp( string $timestamp ): string {
		if ( ! $timestamp ) {
			return __( 'Nikdy', 'mlyn-event-intake' );
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
		return $date ? wp_date( 'd/m/Y H:i:s', $date->getTimestamp(), wp_timezone() ) : $timestamp;
	}

	private function status_label( string $status ): string {
		$labels = array(
			'new'     => __( 'Nová', 'mlyn-event-intake' ),
			'changed' => __( 'Změněná', 'mlyn-event-intake' ),
			'synced'  => __( 'Importovaná', 'mlyn-event-intake' ),
			'error'   => __( 'Chyba', 'mlyn-event-intake' ),
			'deleted' => __( 'Odstraněná', 'mlyn-event-intake' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private function render_empty_state(): void {
		?><div class="wrap"><h1><?php esc_html_e( 'Příprava akcí', 'mlyn-event-intake' ); ?></h1><div class="notice notice-info inline"><p><?php echo current_user_can( self::CAP_PROFILES ) ? esc_html__( 'Nejprve vytvořte a publikujte profil pořadatele.', 'mlyn-event-intake' ) : esc_html__( 'Váš účet není přiřazen k profilu pořadatele. Obraťte se na správce webu.', 'mlyn-event-intake' ); ?></p></div></div><?php
	}

	private function render_page_notice(): void {
		$notice = isset( $_GET['mei_notice'] ) ? sanitize_key( $_GET['mei_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value  = isset( $_GET['mei_value'] ) ? sanitize_text_field( wp_unslash( $_GET['mei_value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saved' === $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Akce byly uloženy. Počet změněných řádků: %d.', 'mlyn-event-intake' ), (int) $value ) ) );
		} elseif ( 'settings_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'mlyn-event-intake' ) . '</p></div>';
		} elseif ( 'imported' === $notice ) {
			$parts = array_map( 'intval', explode( ',', $value ) );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Import dokončen — vytvořeno: %1$d, aktualizováno: %2$d, odstraněno: %3$d, beze změny/neaktivní: %4$d, chyby: %5$d.', 'mlyn-event-intake' ), $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0, $parts[3] ?? 0, $parts[4] ?? 0 ) ) );
		} elseif ( 'error' === $notice ) {
			printf( '<div class="notice notice-error"><p>%s</p>%s</div>', esc_html( $value ), $this->recovered_form ? '<p>' . esc_html__( 'Vyplněné řádky zůstaly zachovány. Nahrávané obrázky je nutné vybrat znovu.', 'mlyn-event-intake' ) . '</p>' : '' );
		}
	}

	private function redirect_to_events( int $profile_id, string $month, string $notice, string $value ): void {
		delete_transient( 'mei_import_lock_' . $profile_id );
		wp_safe_redirect( add_query_arg( array( 'page' => 'mei-events', 'profile_id' => $profile_id, 'month' => $month, 'mei_notice' => $notice, 'mei_value' => $value ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
