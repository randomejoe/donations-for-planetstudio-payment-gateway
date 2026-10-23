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
 * On a multisite network this runs for every site, each by its own setting.
 *
 * If WP_UNINSTALL_PLUGIN is not defined, this file was accessed directly
 * (not via WordPress's uninstall flow) — refuse to run.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// WordPress does not load the plugin to uninstall it, so the classes that
// name the events are loaded here; they only declare classes.
require_once __DIR__ . '/includes/class-pspgd-installer.php';
require_once __DIR__ . '/includes/class-pspgd-exchange-rates.php';
require_once __DIR__ . '/includes/class-pspgd-recurring.php';
require_once __DIR__ . '/includes/class-pspgd-reconciliation.php';
require_once __DIR__ . '/includes/class-pspgd-campaigns.php';

if ( is_multisite() ) {
	// Each site on a network has its own tables, settings and jobs, and its
	// own answer to whether its donation records should go.
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pspgd_site_id ) {
		switch_to_blog( (int) $pspgd_site_id );
		PSPGD_Installer::uninstall_site();
		restore_current_blog();
	}
} else {
	PSPGD_Installer::uninstall_site();
}
