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
require_once dirname( __DIR__ ) . '/includes/class-pspgd-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';

// A network of two sites, each with its own options and its own jobs, as in
// WordPress. Off unless a test switches it on.
$GLOBALS['lifecycle_multisite'] = false;
$GLOBALS['lifecycle_sites']     = array();
$GLOBALS['lifecycle_current']   = 1;
$GLOBALS['lifecycle_stack']     = array();

function is_multisite(): bool {
	return $GLOBALS['lifecycle_multisite'];
}

/** @return int[] */
function get_sites( array $args = array() ): array {
	return array_keys( $GLOBALS['lifecycle_sites'] );
}

function lifecycle_load_site( int $site_id ): void {
	$GLOBALS['lifecycle_sites'][ $GLOBALS['lifecycle_current'] ] = array( $GLOBALS['test_cron'], $GLOBALS['test_options'] );
	list( $GLOBALS['test_cron'], $GLOBALS['test_options'] ) = $GLOBALS['lifecycle_sites'][ $site_id ];
	$GLOBALS['lifecycle_current'] = $site_id;
}

function switch_to_blog( int $site_id ): bool {
	$GLOBALS['lifecycle_stack'][] = $GLOBALS['lifecycle_current'];
	lifecycle_load_site( $site_id );
	return true;
}

function restore_current_blog(): bool {
	lifecycle_load_site( (int) array_pop( $GLOBALS['lifecycle_stack'] ) );
	return true;
}

/** Two sites, both running every job, with site 1 current. */
function lifecycle_network(): void {
	$GLOBALS['lifecycle_multisite'] = true;
	$GLOBALS['lifecycle_sites']     = array( 1 => array( array(), array() ), 2 => array( array(), array() ) );
	$GLOBALS['lifecycle_current']   = 1;
	test_reset_options();
	foreach ( array( 2, 1 ) as $site_id ) {
		lifecycle_load_site( $site_id );
		lifecycle_schedule_everything();
	}
}

/** @return array<int, int> Number of jobs scheduled on each site. */
function lifecycle_jobs_per_site(): array {
	lifecycle_load_site( $GLOBALS['lifecycle_current'] );
	$counts = array();
	foreach ( $GLOBALS['lifecycle_sites'] as $site_id => $site ) {
		$counts[ $site_id ] = count( $site[0] );
	}

	return $counts;
}

/** Schedule every job the way the running plugin does on its first request. */
function lifecycle_schedule_everything(): void {
	test_reset_cron();
	PSPGD_Exchange_Rates::ensure_schedule();
	PSPGD_Recurring::ensure_schedule();
	PSPGD_Recurring::schedule_continuation();
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
				$source = (string) file_get_contents( $file );
				if ( false !== strpos( $source, 'wp_schedule_event(' ) || false !== strpos( $source, 'wp_schedule_single_event(' ) ) {
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

		'deactivating on one site of a network leaves the other sites running' => static function (): void {
			lifecycle_network();

			PSPGD_Installer::deactivate( false );

			assert_same( array( 1 => 0, 2 => count( PSPGD_Installer::cron_hooks() ) ), lifecycle_jobs_per_site(), 'Only the site it was deactivated on stops.' );
		},

		'deactivating for a whole network clears every site' => static function (): void {
			lifecycle_network();

			PSPGD_Installer::deactivate( true );

			assert_same( array( 1 => 0, 2 => 0 ), lifecycle_jobs_per_site(), 'Each site keeps its own schedule, so each is cleared.' );
			assert_same( 1, $GLOBALS['lifecycle_current'], 'And the request is back on the site it started on.' );
		},

		'deleting clears every job, even when the data is kept' => static function (): void {
			$GLOBALS['lifecycle_multisite'] = false;
			lifecycle_schedule_everything();
			update_option( 'pspgd_delete_data_on_uninstall', '0' );

			define( 'WP_UNINSTALL_PLUGIN', 'donations-for-planetstudio-payment-gateway/donations-for-planetstudio-payment-gateway.php' );
			include dirname( __DIR__ ) . '/uninstall.php';

			assert_same( array(), $GLOBALS['test_cron'], 'Keeping the donation records does not mean keeping the jobs.' );
		},

		'deleting on a network clears the jobs of every site' => static function (): void {
			lifecycle_network();
			foreach ( array( 2, 1 ) as $site_id ) {
				lifecycle_load_site( $site_id );
				update_option( 'pspgd_delete_data_on_uninstall', '0' );
			}

			include dirname( __DIR__ ) . '/uninstall.php';

			assert_same( array( 1 => 0, 2 => 0 ), lifecycle_jobs_per_site(), 'WordPress runs the uninstall once, from the network; it must reach every site.' );
		},
	)
);
