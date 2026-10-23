<?php
/**
 * Uninstall handler for Donations for PlanetStudio Payment Gateway.
 *
 * WordPress runs this file ONLY when the plugin is deleted from the Plugins
 * screen (Deactivate + Delete) — never on a plain deactivate, and never on
 * update.
 *
 * Scheduled events are always cleared. Everything else -- donor records,
 * donation and recurring history, settings -- is kept unless an
 * administrator ticked "Delete all donation data when the plugin is
 * deleted" in Form Settings. Donation records are usually financial records
 * an organisation must keep, and deleting the plugin to reinstall it should
 * not quietly destroy them.
 *
 * If WP_UNINSTALL_PLUGIN is not defined, this file was accessed directly
 * (not via WordPress's uninstall flow) — refuse to run.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------
// 1. Unschedule cron events.
// ---------------------------------------------------------------------
// WordPress does not load the plugin to uninstall it, so the classes that
// name the events are loaded here; they only declare classes.
require_once __DIR__ . '/includes/class-pspgd-installer.php';
require_once __DIR__ . '/includes/class-pspgd-exchange-rates.php';
require_once __DIR__ . '/includes/class-pspgd-recurring.php';
require_once __DIR__ . '/includes/class-pspgd-reconciliation.php';
require_once __DIR__ . '/includes/class-pspgd-campaigns.php';

foreach ( PSPGD_Installer::cron_hooks() as $pspgd_hook ) {
	wp_clear_scheduled_hook( $pspgd_hook );
}

if ( '1' !== (string) get_option( 'pspgd_delete_data_on_uninstall', '0' ) ) {
	return;
}

// ---------------------------------------------------------------------
// 2. Drop custom tables.
// ---------------------------------------------------------------------
$pspgd_tables = array(
	$wpdb->prefix . 'pspgd_donors',
	$wpdb->prefix . 'pspgd_donations',
	$wpdb->prefix . 'pspgd_subscriptions',
	$wpdb->prefix . 'pspgd_events',
);

foreach ( $pspgd_tables as $pspgd_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $pspgd_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables is what the box asked for.
}

// ---------------------------------------------------------------------
// 3. Delete plugin options (single-site installs).
// ---------------------------------------------------------------------
$pspgd_options = array(
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

foreach ( $pspgd_options as $pspgd_option ) {
	delete_option( $pspgd_option );
	// Also clear the site-option in case this ever ran network-activated.
	delete_site_option( $pspgd_option );
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
foreach ( wp_roles()->role_objects as $pspgd_role ) {
	$pspgd_role->remove_cap( 'pspgd_manage_donations' );
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
