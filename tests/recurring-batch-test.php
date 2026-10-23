<?php
/**
 * How many charges one recurring run takes on.
 *
 * The rules these cover:
 *  - a run charges every due subscription, a batch at a time, not just the
 *    first ten;
 *  - it stops starting charges once its time budget is spent, and schedules
 *    a follow-up run instead of waiting an hour;
 *  - the first charge always goes through, however slow;
 *  - a subscription that cannot be claimed is not read over and over;
 *  - only one follow-up run waits at a time.
 *
 * Run from the plugin folder: php tests/recurring-batch-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-stats.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-cancel-links.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

$GLOBALS['batch_charges']            = array();
$GLOBALS['batch_seconds_per_charge'] = 0;

/**
 * Every charge comes back without an outcome, which touches nothing but
 * the subscription's date, and takes as long as the test says.
 */
function pspg_charge_saved_card( string $bank_id, array $args ): array {
	$GLOBALS['batch_charges'][] = $args;
	test_set_now( test_now() + (int) $GLOBALS['batch_seconds_per_charge'] );

	return array( 'ok' => false, 'error' => 'http_error' );
}

function batch_reset(): void {
	global $wpdb;
	test_reset_options();
	test_reset_transients();
	test_reset_mail();
	test_reset_cron();
	$wpdb->reset_log();
	$wpdb->rows          = array();
	$wpdb->vars          = array();
	$wpdb->result_sets   = array();
	$wpdb->update_result = 1;
	$wpdb->query_result  = 1;
	$GLOBALS['batch_charges']            = array();
	$GLOBALS['batch_seconds_per_charge'] = 0;
	test_set_now( '2026-09-17 09:00:00' );
}

/**
 * Due subscriptions with consecutive ids.
 *
 * @return object[]
 */
function batch_due( int $first_id, int $count ): array {
	$rows = array();
	for ( $id = $first_id; $id < $first_id + $count; $id++ ) {
		$rows[] = test_row(
			array(
				'id'                   => $id,
				'donor_id'             => 3,
				'campaign_id'          => 0,
				'bank_id'              => 'arca_acba',
				'client_id'            => '',
				'amount'               => '5000.00',
				'currency'             => 'AMD',
				'interval_key'         => 'monthly',
				'status'               => 'active',
				'binding_id'           => 'binding-' . $id,
				'language'             => 'en',
				'failed_payment_count' => 0,
				'is_anonymous'         => 0,
				'is_test'              => 0,
				'created_at'           => '2026-08-17 08:55:00',
				'next_payment_at'      => '2026-09-17 08:55:00',
			)
		);
	}

	return $rows;
}

/** How many times the due subscriptions were read. */
function batch_due_reads(): int {
	global $wpdb;

	return count(
		array_filter(
			$wpdb->reads,
			static function ( string $read ): bool {
				return false !== strpos( $read, 'pspgd_subscriptions' ) && false !== strpos( $read, 'next_payment_at <=' );
			}
		)
	);
}

run_tests(
	array(
		'a run charges every due subscription, not just the first ten' => static function (): void {
			global $wpdb;
			batch_reset();
			$wpdb->result_sets = array( batch_due( 1, 10 ), batch_due( 11, 10 ), batch_due( 21, 5 ) );

			PSPGD_Recurring::process_due_subscriptions();

			assert_same( 25, count( $GLOBALS['batch_charges'] ), 'Twenty-five due, twenty-five charged.' );
			assert_same( 3, batch_due_reads(), 'A batch at a time, stopping at the first one that is not full.' );
			assert_false( wp_next_scheduled( PSPGD_Recurring::CONTINUE_HOOK ), 'Nothing is left, so no follow-up run.' );
		},

		'a run stops starting charges when its time is up, and comes back in a minute' => static function (): void {
			global $wpdb;
			batch_reset();
			$GLOBALS['batch_seconds_per_charge'] = 3;
			$wpdb->result_sets = array( batch_due( 1, 10 ), batch_due( 11, 10 ) );

			PSPGD_Recurring::process_due_subscriptions();

			assert_same( 7, count( $GLOBALS['batch_charges'] ), 'Charges start at 0, 3, ... 18 seconds; at 21 the 20-second budget is spent.' );
			$next = wp_next_scheduled( PSPGD_Recurring::CONTINUE_HOOK );
			assert_true( false !== $next && $next > time(), 'The rest are charged by a follow-up run, not an hour later.' );
			assert_false( isset( $GLOBALS['test_cron_recurrence'][ PSPGD_Recurring::CONTINUE_HOOK ] ), 'It runs once; the hourly schedule stays as it was.' );
		},

		'the first charge of a run goes through however slow it is' => static function (): void {
			global $wpdb;
			batch_reset();
			$GLOBALS['batch_seconds_per_charge'] = 30;
			$wpdb->result_sets = array( batch_due( 1, 2 ) );

			PSPGD_Recurring::process_due_subscriptions();

			assert_same( 1, count( $GLOBALS['batch_charges'] ), 'One charge, so a slow bank cannot stop the schedule altogether.' );
			assert_true( false !== wp_next_scheduled( PSPGD_Recurring::CONTINUE_HOOK ), 'And the second is left to the follow-up run.' );
		},

		'a subscription that cannot be claimed is not read over and over' => static function (): void {
			global $wpdb;
			batch_reset();
			$wpdb->update_result = 0;
			$wpdb->result_sets   = array( batch_due( 1, 10 ), batch_due( 1, 10 ), batch_due( 1, 10 ) );

			PSPGD_Recurring::process_due_subscriptions();

			assert_same( 0, count( $GLOBALS['batch_charges'] ), 'Nothing claimed, nothing charged.' );
			assert_same( 2, batch_due_reads(), 'A batch with nothing new in it ends the run.' );
			assert_false( wp_next_scheduled( PSPGD_Recurring::CONTINUE_HOOK ), 'And no follow-up is scheduled for rows another worker holds.' );
		},

		'only one follow-up run waits at a time' => static function (): void {
			batch_reset();
			$GLOBALS['test_cron'][ PSPGD_Recurring::CONTINUE_HOOK ] = 1789000000;

			PSPGD_Recurring::schedule_continuation();

			assert_same( 1789000000, wp_next_scheduled( PSPGD_Recurring::CONTINUE_HOOK ), 'The waiting one is kept.' );
		},
	)
);
