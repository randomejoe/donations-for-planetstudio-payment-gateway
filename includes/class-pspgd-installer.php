<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_Installer {

	/**
	 * Every scheduled event this plugin creates. Deactivation and
	 * uninstall both clear exactly this list, so a job added later is
	 * cleared by adding it here.
	 *
	 * @return string[]
	 */
	public static function cron_hooks(): array {
		return array(
			PSPGD_Exchange_Rates::CRON_HOOK,
			PSPGD_Recurring::HOOK,
			PSPGD_Reconciliation::HOOK,
			PSPGD_Campaigns::CRON_HOOK,
		);
	}

	/**
	 * @param bool $network_wide Whether a network administrator activated
	 *                           it for every site on a multisite network.
	 */
	public static function activate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			// The other sites create their tables and schedule their jobs on
			// their own first request (see maybe_upgrade()). What none of them
			// can do by itself is notice the campaign post type in rewrite
			// rules it already has, so campaign pages would 404 until someone
			// re-saved permalinks. Dropping the stored rules makes WordPress
			// rebuild them, with the post type, on each site's next request.
			foreach ( self::site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				delete_option( 'rewrite_rules' );
				restore_current_blog();
			}
		}

		self::install();
		PSPGD_Campaigns::register_post_type();
		PSPGD_Exchange_Rates::schedule_daily_refresh();
		PSPGD_Campaigns::ensure_schedule();
		flush_rewrite_rules();
	}

	/**
	 * Stop every scheduled job -- on every site, when it is deactivated for
	 * a whole network, since each site keeps its own schedule. They are
	 * scheduled again on a site's first request after reactivation.
	 *
	 * @param bool $network_wide Whether it was deactivated network-wide.
	 */
	public static function deactivate( $network_wide = false ): void {
		$sites = $network_wide && is_multisite() ? self::site_ids() : array( 0 );

		foreach ( $sites as $site_id ) {
			if ( $site_id ) {
				switch_to_blog( $site_id );
			}
			foreach ( self::cron_hooks() as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			if ( $site_id ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Remove the plugin from the current site, for uninstall.php: always
	 * its scheduled jobs, and its tables, options and transients if this
	 * site asked for that in Form Settings.
	 */
	public static function uninstall_site(): void {
		global $wpdb;

		// ---------------------------------------------------------------------
		// 1. Unschedule cron events.
		// ---------------------------------------------------------------------
		foreach ( PSPGD_Installer::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( '1' !== (string) get_option( 'pspgd_delete_data_on_uninstall', '0' ) ) {
			return;
		}

		// ---------------------------------------------------------------------
		// 2. Drop custom tables.
		// ---------------------------------------------------------------------
		$tables = array(
			$wpdb->prefix . 'pspgd_donors',
			$wpdb->prefix . 'pspgd_donations',
			$wpdb->prefix . 'pspgd_subscriptions',
			$wpdb->prefix . 'pspgd_events',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables is what the box asked for.
		}

		// ---------------------------------------------------------------------
		// 3. Delete plugin options.
		// ---------------------------------------------------------------------
		$options = array(
			'pspgd_db_version',
			'pspgd_recurring_enabled',
			'pspgd_test_mode',
			'pspgd_amount_presets',
			'pspgd_max_amounts',
			'pspgd_min_amounts',
			'pspgd_event_retention_days',
			'pspgd_form_labels',
			'pspgd_email_templates',
			'pspgd_email_from_name',
			'pspgd_email_from_address',
			'pspgd_email_reply_to',
			'pspgd_email_logo_light',
			'pspgd_email_logo_dark',
			'pspgd_social_links',
			'pspgd_recurring_lock',
			'pspgd_bank_id',
			'pspgd_currencies',
			'pspgd_default_currency',
			'pspgd_goal_currency',
			'pspgd_intervals',
			'pspgd_newsletter_optin',
			'pspgd_show_supporters',
			'pspgd_payment_image_url',
			'pspgd_languages',
			'pspgd_colors',
			'pspgd_font_family',
			'pspgd_radius',
			'pspgd_client_ip_source',
			'pspgd_turnstile',
			'pspgd_staff_notify_emails',
			'pspgd_delete_data_on_uninstall',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
			// Also clear the site-option in case this ever ran network-activated.
			delete_site_option( $option );
		}

		// The back-off flag set when the rate provider is unreachable.
		delete_transient( 'pspgd_exchange_rate_retry' );

		// Every other transient expires by itself, but there is no reason to wait:
		// the form throttle counters, saved form errors and the cached figures.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_pspgd_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_pspgd_' ) . '%'
			)
		);

		// The staff capability, wherever a role editor granted it. Administrators
		// never stored it: they are given it while the plugin runs.
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( 'pspgd_manage_donations' );
		}

		// Per-currency exchange-rate cache options, e.g.
		// pspgd_exchange_rate_usd_amd_last_known. Removed by prefix, because the set
		// depends on which currencies the site offered over its lifetime.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'pspgd_exchange_rate_' ) . '%'
			)
		);

		// Campaign posts and their meta are left in place on purpose: they are
		// ordinary content an editor wrote, and deleting a plugin should not delete
		// pages. They stop being displayed once the post type is gone.

		// ---------------------------------------------------------------------
		// 4. Clear the object cache, in case an external cache (Redis/Memcached)
		//    is in use and still holds the recurring-job lock or stale option
		//    reads for any of the keys above.
		// ---------------------------------------------------------------------
		wp_cache_flush();
	}

	/** @return int[] Every site on the network. */
	private static function site_ids(): array {
		return array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
	}

	/**
	 * Bring the tables up to date after an update.
	 *
	 * Checked on every request, not only in wp-admin: WordPress updates
	 * plugins in the background, and a donor can reach a new column before
	 * any administrator has logged in to create it. The version option is
	 * autoloaded, so the check costs nothing once it matches.
	 */
	public static function maybe_upgrade(): void {
		if ( PSPGD_DB_VERSION === (string) get_option( 'pspgd_db_version', '' ) ) {
			return;
		}

		self::install();
		PSPGD_Exchange_Rates::schedule_daily_refresh();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$donors          = $wpdb->prefix . 'pspgd_donors';
		$donations       = $wpdb->prefix . 'pspgd_donations';
		$subscriptions   = $wpdb->prefix . 'pspgd_subscriptions';
		$events          = $wpdb->prefix . 'pspgd_events';

		dbDelta(
			"CREATE TABLE {$donors} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				language varchar(5) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$donations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				pspg_order_id bigint(20) unsigned DEFAULT NULL,
				bank_order_id varchar(190) NOT NULL DEFAULT '',
				bank_id varchar(80) NOT NULL DEFAULT '',
				amount decimal(18,2) NOT NULL DEFAULT 0.00,
				currency char(3) NOT NULL DEFAULT '',
				type varchar(20) NOT NULL DEFAULT 'one_time',
				status varchar(30) NOT NULL DEFAULT 'pending',
				source varchar(80) NOT NULL DEFAULT 'pspgd_donations',
				source_id varchar(80) NOT NULL DEFAULT '',
				transaction_reference varchar(190) NOT NULL DEFAULT '',
				language varchar(5) NOT NULL DEFAULT '',
				is_anonymous tinyint(1) unsigned NOT NULL DEFAULT 0,
				is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY pspg_order_id (pspg_order_id),
				KEY donor_id (donor_id),
				KEY campaign_id (campaign_id),
				KEY status (status),
				KEY status_created (status,created_at),
				KEY currency (currency),
				KEY is_test (is_test)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$subscriptions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				amount decimal(18,2) NOT NULL DEFAULT 0.00,
				currency char(3) NOT NULL DEFAULT '',
				bank_id varchar(80) NOT NULL DEFAULT '',
				binding_id varchar(190) NOT NULL DEFAULT '',
				client_id varchar(190) NOT NULL DEFAULT '',
				interval_key varchar(30) NOT NULL DEFAULT 'monthly',
				language varchar(5) NOT NULL DEFAULT '',
				status varchar(30) NOT NULL DEFAULT 'pending',
				next_payment_at datetime DEFAULT NULL,
				last_payment_at datetime DEFAULT NULL,
				failed_payment_count int(10) unsigned NOT NULL DEFAULT 0,
				is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
				claimed_at datetime DEFAULT NULL,
				is_anonymous tinyint(1) unsigned NOT NULL DEFAULT 0,
				cancelled_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY donor_id (donor_id),
				KEY campaign_id (campaign_id),
				KEY status_next_payment (status,next_payment_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(80) NOT NULL DEFAULT '',
				message text NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY object_lookup (object_type,object_id),
				KEY event_type (event_type),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		update_option( 'pspgd_db_version', PSPGD_DB_VERSION, true );
	}
}
