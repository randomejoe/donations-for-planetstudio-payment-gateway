<?php
/**
 * Donations left claimed in `processing` by a confirmation that died.
 *
 * The rules these cover:
 *  - reconciliation returns a stuck donation to pending and asks the bank
 *    about it in the same run, so money the bank took is still confirmed
 *    and receipted, once;
 *  - one that never reached the bank is only released, to be abandoned
 *    with the rest;
 *  - one a confirmation claimed again in the meantime is left to it.
 *
 * Run from the plugin folder: php tests/stuck-donation-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-stats.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-notifications.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-reconciliation.php';

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

$GLOBALS['test_sync_result'] = array();
$GLOBALS['test_sync_calls']  = array();

/** @return array<string, mixed> */
function pspg_create_payment( string $bank_id, array $args ): array {
	return array();
}

/** @return object|null */
function pspg_get_bank( string $bank_id ) {
	return null;
}

/** @return array<string, mixed> */
function pspg_sync_status( string $bank_id, array $args ): array {
	$GLOBALS['test_sync_calls'][] = $args;
	return (array) $GLOBALS['test_sync_result'];
}

function stuck_reset(): void {
	global $wpdb;
	test_reset_options( array( 'pspgd_bank_id' => 'arca_acba' ) );
	test_reset_transients();
	test_reset_mail();
	test_reset_filters();
	test_set_timezone( 'UTC' );
	$wpdb->reset_log();
	$wpdb->rows          = array();
	$wpdb->vars          = array();
	$wpdb->result_sets   = array();
	$wpdb->update_result = 1;
	$wpdb->query_result  = 1;
	$GLOBALS['test_sync_result'] = array();
	$GLOBALS['test_sync_calls']  = array();
	test_set_now( '2026-09-17 09:00:00' );
}

/** @param array<string, mixed> $overrides */
function stuck_donation( array $overrides = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                    => 101,
				'donor_id'              => 3,
				'subscription_id'       => 0,
				'campaign_id'           => 0,
				'pspg_order_id'         => 555,
				'bank_id'               => 'arca_acba',
				'bank_order_id'         => 'a1b2c3d4-0000-4000-8000-000000000101',
				'amount'                => '5000.00',
				'currency'              => 'AMD',
				'type'                  => 'one_time',
				'status'                => 'processing',
				'transaction_reference' => '',
				'language'              => 'en',
				'is_test'               => 0,
				'is_anonymous'          => 0,
				'created_at'            => '2026-09-17 08:00:00',
				'updated_at'            => '2026-09-17 08:01:00',
			),
			$overrides
		)
	);
}

function stuck_donor(): object {
	return test_row(
		array(
			'id'         => 3,
			'email'      => 'donor@example.test',
			'first_name' => 'Ani',
			'last_name'  => 'Sargsyan',
			'language'   => 'en',
		)
	);
}

/** @return string[] */
function stuck_event_types(): array {
	global $wpdb;

	$types = array();
	foreach ( $wpdb->inserts as $insert ) {
		if ( false !== strpos( (string) $insert['table'], 'pspgd_events' ) ) {
			$types[] = (string) $insert['data']['event_type'];
		}
	}

	return $types;
}

/** @return string[] */
function stuck_status_writes(): array {
	global $wpdb;

	$statuses = array();
	foreach ( $wpdb->updates as $update ) {
		if ( false !== strpos( (string) $update['table'], 'pspgd_donations' ) && isset( $update['data']['status'] ) ) {
			$statuses[] = (string) $update['data']['status'];
		}
	}

	return $statuses;
}

run_tests(
	array(
		'a stuck donation the bank took is released, confirmed and receipted' => static function (): void {
			global $wpdb;
			stuck_reset();
			$GLOBALS['test_sync_result'] = array(
				'ok'             => true,
				'status'         => 'paid',
				'transaction_id' => '600123456789',
				'bank_ids'       => array( 'orderId' => 'a1b2c3d4-0000-4000-8000-000000000101' ),
			);
			// The first read is the stuck-donation sweep; the confirmation then
			// finds the released row by its order, and the receipt its donor.
			$wpdb->result_sets = array( array( stuck_donation() ) );
			$wpdb->rows        = array( stuck_donation( array( 'status' => 'pending' ) ), stuck_donor() );

			PSPGD_Reconciliation::run();

			assert_contains( "SET status = 'pending'", (string) ( $wpdb->queries[0] ?? '' ), 'It is released first.' );
			assert_contains( "updated_at <= '2026-09-17 08:45:00'", (string) ( $wpdb->queries[0] ?? '' ), 'Only once it has been stuck for 15 minutes.' );
			assert_same( 1, count( $GLOBALS['test_sync_calls'] ), 'The bank is asked about it in the same run, not twenty minutes later.' );
			assert_same( array( 'processing', 'paid' ), stuck_status_writes(), 'Then confirmed through the normal guarded path.' );
			assert_same( 1, count( test_sent_mail() ), 'The donor gets the receipt that never went out.' );
			assert_same( 1, count( test_fired_actions( 'pspgd_donation_paid' ) ), 'And integrations hear about it once.' );
			$events = stuck_event_types();
			assert_true( in_array( 'payment_claim_recovered', $events, true ), 'The release is logged.' );
			assert_true( in_array( 'reconcile_recovered', $events, true ), 'And so is the recovered payment.' );
		},

		'a stuck donation that never reached the bank is only released' => static function (): void {
			global $wpdb;
			stuck_reset();
			$wpdb->result_sets = array( array( stuck_donation( array( 'pspg_order_id' => null ) ) ) );

			PSPGD_Reconciliation::run();

			assert_contains( "SET status = 'pending'", (string) ( $wpdb->queries[0] ?? '' ), 'Back to pending, where the abandonment sweep finds it after a day.' );
			assert_same( 0, count( $GLOBALS['test_sync_calls'] ), 'There is no order to ask the bank about.' );
			assert_same( 0, count( test_sent_mail() ), 'Nobody is told anything.' );
		},

		'a donation claimed again since the sweep read it is left alone' => static function (): void {
			global $wpdb;
			stuck_reset();
			$wpdb->query_result = 0;
			$wpdb->result_sets  = array( array( stuck_donation() ) );

			PSPGD_Reconciliation::run();

			assert_same( 0, count( $GLOBALS['test_sync_calls'] ), 'The path that holds the claim settles it.' );
			assert_false( in_array( 'payment_claim_recovered', stuck_event_types(), true ), 'And nothing is logged as recovered.' );
		},
	)
);
