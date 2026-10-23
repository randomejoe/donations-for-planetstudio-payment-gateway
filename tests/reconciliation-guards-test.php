<?php
/**
 * The conditional writes that keep reconciliation from fighting the bank.
 *
 * Every state change here is guarded on the row's current status, so a
 * callback landing at the same moment as the cron always wins.
 *
 * Run from the plugin folder: php tests/reconciliation-guards-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

run_tests(
	array(
		'test-mode subscriptions are never picked up for charging' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::due_subscriptions();

			assert_contains( 'is_test = 0', $wpdb->reads[0], 'A simulated binding must never reach ACBA.' );
		},

		'claiming a subscription records when the claim was taken' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::claim_due_subscription(
				test_row( array( 'id' => 7, 'next_payment_at' => '2026-08-23 09:00:00' ) )
			);

			assert_same( '2026-08-23 12:00:00', $wpdb->updates[0]['data']['claimed_at'], 'An unclaimable claim cannot be spotted later without a timestamp.' );
			assert_same( 'active', $wpdb->updates[0]['where']['status'], 'Only an active subscription may be claimed.' );
			assert_same( '2026-08-23 09:00:00', $wpdb->updates[0]['where']['next_payment_at'], 'The due date is part of the claim, so two workers cannot both win.' );
		},

		'a successful charge releases the claim' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::reschedule_subscription( 7, '2026-09-04 09:00:00', true );

			assert_same( null, $wpdb->updates[0]['data']['claimed_at'], 'A finished charge must not leave the row looking claimed.' );
		},

		'a failed charge also releases the claim while incrementing failures' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::reschedule_subscription( 7, '2026-08-26 12:00:00', false );

			assert_contains( 'claimed_at = NULL', $wpdb->queries[0], 'A failed charge must not leave the row looking claimed.' );
			assert_contains( 'failed_payment_count = failed_payment_count + 1', $wpdb->queries[0], 'The retry budget must still be spent.' );
		},

		'abandoning a donation only touches one that is still pending' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::abandon_donation( 55 );

			assert_same( 'abandoned', $wpdb->updates[0]['data']['status'], 'An unconfirmed donation becomes abandoned, not failed.' );
			assert_same( 'pending', $wpdb->updates[0]['where']['status'], 'A donation confirmed in the meantime must never be overwritten.' );
		},

		'abandonment reports whether it actually changed anything' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();
			$wpdb->update_result = 0;

			assert_false(
				PSPGD_Repository::abandon_donation( 55 ),
				'A donation that was confirmed first must not be reported as abandoned.'
			);

			$wpdb->update_result = 1;
		},

		'releasing a stuck subscription leaves its due date alone' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::release_stuck_subscription( 7 );

			assert_same( 'active', $wpdb->updates[0]['data']['status'], 'A released subscription becomes due again.' );
			assert_same( null, $wpdb->updates[0]['data']['claimed_at'], 'The dead claim must be cleared.' );
			assert_false( array_key_exists( 'next_payment_at', $wpdb->updates[0]['data'] ), 'Releasing must not move the charge date the donor agreed to.' );
			assert_same( 'processing', $wpdb->updates[0]['where']['status'], 'A worker that recovered on its own must win over the reaper.' );
		},

		'only donations left in processing past the cut-off count as stuck' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::stuck_processing_donations( '2026-08-23 11:45:00', 20 );

			assert_contains( "status = 'processing'", $wpdb->reads[0], 'Only a claimed row can be stuck.' );
			assert_contains( "updated_at <= '2026-08-23 11:45:00'", $wpdb->reads[0], 'A claim taken a moment ago is a confirmation still running.' );
			assert_not_contains( 'pspg_order_id', $wpdb->reads[0], 'A row that never reached the bank is released too, so it can be abandoned.' );
		},

		'releasing a stuck donation is guarded on its status and age' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			assert_true( PSPGD_Repository::release_stuck_donation( 55, '2026-08-23 11:45:00' ), 'A stuck donation is released.' );
			assert_contains( "SET status = 'pending'", $wpdb->queries[0], 'Back to pending, where every sweep can see it.' );
			assert_contains( "status = 'processing' AND updated_at <= '2026-08-23 11:45:00'", $wpdb->queries[0], 'In one statement, so a path that claimed it since the read always wins.' );

			$wpdb->query_result = 0;
			assert_false( PSPGD_Repository::release_stuck_donation( 55, '2026-08-23 11:45:00' ), 'A row that moved on is reported as not released.' );
			$wpdb->query_result = 1;
		},

		'only donations with a bank order are re-asked about' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::stale_pending_donations( '2026-08-23 11:40:00', 20 );

			assert_contains( 'pspg_order_id IS NOT NULL', $wpdb->reads[0], 'There is nothing to ask the bank about without an order reference.' );
			assert_contains( "status = 'pending'", $wpdb->reads[0], 'Settled donations must not be re-checked.' );
		},

		'abandonment sweeps donations that never reached the bank either' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::unconfirmed_donations( '2026-08-22 12:00:00', 50 );

			assert_not_contains( 'pspg_order_id', $wpdb->reads[0], 'A donation that never got an order reference is still abandoned.' );
			assert_contains( 'created_at <=', $wpdb->reads[0], 'Abandonment is measured from when the donor started, not from the last write.' );
		},
	)
);
