<?php
/**
 * Deactivating or deleting the plugin leaves no scheduled job behind.
 *
 * A job whose plugin is gone still fires, every 15 minutes or every hour,
 * with nothing listening; on a site that later reinstalls the plugin it can
 * also run twice. Deactivation used to clear two of the four.
 *
 * Run from the plugin folder: php tests/lifecycle-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-installer.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';

/** Schedule every job the way the running plugin does on its first request. */
function lifecycle_schedule_everything(): void {
	test_reset_cron();
	PSPGD_Exchange_Rates::ensure_schedule();
	PSPGD_Recurring::ensure_schedule();
	PSPGD_Reconciliation::ensure_schedule();
	PSPGD_Campaigns::ensure_schedule();
}

run_tests(
	array(
		'the list of jobs is every job the plugin schedules' => static function (): void {
			lifecycle_schedule_everything();

			$scheduled = array_keys( $GLOBALS['test_cron'] );
			$listed    = PSPGD_Installer::cron_hooks();
			sort( $scheduled );
			sort( $listed );
			assert_same( $scheduled, $listed, 'Deactivation and uninstall clear the list, so every scheduled job must be on it.' );

			// A job added in some other class would not be scheduled above,
			// so the source is checked too.
			$scheduling = array();
			foreach ( glob( dirname( __DIR__ ) . '/includes/{,*/}*.php', GLOB_BRACE ) as $file ) {
				if ( false !== strpos( (string) file_get_contents( $file ), 'wp_schedule_event(' ) ) {
					$scheduling[] = basename( $file );
				}
			}
			sort( $scheduling );
			assert_same(
				array( 'class-pspgd-campaigns.php', 'class-pspgd-exchange-rates.php', 'class-pspgd-reconciliation.php', 'class-pspgd-recurring.php' ),
				$scheduling,
				'A new file that schedules a job must add it to PSPGD_Installer::cron_hooks() and to lifecycle_schedule_everything().'
			);
		},

		'deactivating clears every job' => static function (): void {
			lifecycle_schedule_everything();

			PSPGD_Installer::deactivate();

			assert_same( array(), $GLOBALS['test_cron'], 'Nothing may fire for a deactivated plugin.' );
		},

		'deleting clears every job, even when the data is kept' => static function (): void {
			lifecycle_schedule_everything();
			update_option( 'pspgd_delete_data_on_uninstall', '0' );

			define( 'WP_UNINSTALL_PLUGIN', 'donations-for-planetstudio-payment-gateway/donations-for-planetstudio-payment-gateway.php' );
			include dirname( __DIR__ ) . '/uninstall.php';

			assert_same( array(), $GLOBALS['test_cron'], 'Keeping the donation records does not mean keeping the jobs.' );
		},
	)
);
