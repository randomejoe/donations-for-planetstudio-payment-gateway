<?php
/**
 * Plugin Name:       Donations for PlanetStudio Payment Gateway
 * Description:       Donation form, campaigns with goals and deadlines, and recurring donations on saved cards, paid through PlanetStudio Payment Gateway.
 * Version:           1.0.0
 * Author:            Joe Davtian
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       donations-for-planetstudio-payment-gateway
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  planetstudio-payment-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PSPGD_VERSION', '1.0.0' );
define( 'PSPGD_DB_VERSION', '2' );
define( 'PSPGD_FILE', __FILE__ );
define( 'PSPGD_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSPGD_URL', plugin_dir_url( __FILE__ ) );

require_once PSPGD_DIR . 'includes/class-pspgd-clock.php';
require_once PSPGD_DIR . 'includes/class-pspgd-settings.php';
require_once PSPGD_DIR . 'includes/class-pspgd-installer.php';
require_once PSPGD_DIR . 'includes/class-pspgd-repository.php';
require_once PSPGD_DIR . 'includes/class-pspgd-stats.php';
require_once PSPGD_DIR . 'includes/class-pspgd-pspg.php';
require_once PSPGD_DIR . 'includes/class-pspgd-exchange-rates.php';
require_once PSPGD_DIR . 'includes/class-pspgd-i18n.php';
require_once PSPGD_DIR . 'includes/class-pspgd-campaigns.php';
require_once PSPGD_DIR . 'includes/class-pspgd-subscriptions.php';
require_once PSPGD_DIR . 'includes/class-pspgd-notifications.php';
require_once PSPGD_DIR . 'includes/class-pspgd-recurring.php';
require_once PSPGD_DIR . 'includes/class-pspgd-reconciliation.php';
require_once PSPGD_DIR . 'includes/class-pspgd-privacy.php';
require_once PSPGD_DIR . 'includes/class-pspgd-security.php';
require_once PSPGD_DIR . 'includes/public/class-pspgd-form.php';
require_once PSPGD_DIR . 'includes/admin/class-pspgd-admin.php';

register_activation_hook( __FILE__, array( 'PSPGD_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PSPGD_Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		// Translations of the admin screens come from translate.wordpress.org
		// and WordPress loads them itself. Donor-facing wording is in the
		// locale packs under includes/locales/.
		PSPGD_Installer::maybe_upgrade();
		PSPGD_Admin::register_capability();

		if ( is_admin() ) {
			PSPGD_Admin::init();
		}

		PSPGD_Form::init();
		PSPGD_Campaigns::init();
		PSPGD_PSPG::init();
		PSPGD_Exchange_Rates::init();
		PSPGD_Recurring::init();
		PSPGD_Reconciliation::init();
		PSPGD_Privacy::init();
		PSPGD_Subscriptions::init();
	}
);
