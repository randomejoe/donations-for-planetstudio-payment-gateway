<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_Installer {

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
