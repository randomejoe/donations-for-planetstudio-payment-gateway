<?php
/**
 * The donation lifecycle in PSPGD_Payments, and test mode going through it.
 *
 * The rules these cover:
 *  - a test payment is applied exactly as a real one: guarded, receipted,
 *    the cached figures dropped, its campaign checked for closing, staff
 *    told and the public hook fired;
 *  - a test payment already confirmed is not confirmed twice;
 *  - the first test payment of a recurring donation activates it on a
 *    simulated card;
 *  - a donation is only failed from pending, so a confirmation that got
 *    there first wins.
 *
 * Run from the plugin folder: php tests/payments-test.php
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

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

function payments_reset(): void {
	global $wpdb;
	test_reset_options( array( 'pspgd_test_mode' => '1' ) );
	test_reset_transients();
	test_reset_mail();
	test_reset_filters();
	test_reset_posts();
	test_set_timezone( 'UTC' );
	$wpdb->reset_log();
	$wpdb->rows          = array();
	$wpdb->vars          = array();
	$wpdb->result_sets   = array();
	$wpdb->update_result = 1;
	$wpdb->query_result  = 1;
	test_set_now( '2026-09-17 08:55:00' );
}

/** @param array<string, mixed> $overrides */
function payments_donation( array $overrides = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                    => 101,
				'donor_id'              => 3,
				'subscription_id'       => 0,
				'campaign_id'           => 0,
				'bank_id'               => 'arca_acba',
				'bank_order_id'         => '',
				'amount'                => '5000.00',
				'currency'              => 'AMD',
				'type'                  => 'one_time',
				'status'                => 'pending',
				'transaction_reference' => '',
				'language'              => 'en',
				'is_test'               => 1,
				'is_anonymous'          => 0,
				'created_at'            => '2026-09-17 08:50:00',
			),
			$overrides
		)
	);
}

function payments_donor(): object {
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

/** @return array<int, array<string, mixed>> */
function payments_writes( string $table ): array {
	global $wpdb;

	return array_values(
		array_filter(
			$wpdb->updates,
			static function ( array $update ) use ( $table ): bool {
				return false !== strpos( (string) $update['table'], $table );
			}
		)
	);
}

/** @return string[] */
function payments_event_types(): array {
	global $wpdb;

	$types = array();
	foreach ( $wpdb->inserts as $insert ) {
		if ( false !== strpos( (string) $insert['table'], 'pspgd_events' ) ) {
			$types[] = (string) $insert['data']['event_type'];
		}
	}

	return $types;
}

/** @return array<string, mixed> */
function payments_pay_in_test_mode(): array {
	return PSPGD_PSPG::create_initial_payment(
		101,
		array(
			'amount'   => '5000.00',
			'currency' => 'AMD',
		)
	);
}

run_tests(
	array(
		// -------------------------------------------------------------
		// Test mode takes the real path
		// -------------------------------------------------------------

		'a test payment is booked as a real one would be' => static function (): void {
			global $wpdb;
			payments_reset();
			$wpdb->rows = array( payments_donation(), payments_donor() );

			$result = payments_pay_in_test_mode();

			assert_true( ! empty( $result['ok'] ) && ! empty( $result['test_mode'] ), 'The form is told the simulated payment went through.' );
			$writes = payments_writes( 'pspgd_donations' );
			assert_same( 'pending', (string) ( $writes[0]['where']['status'] ?? '' ), 'Claimed from pending, like a bank callback.' );
			assert_same( 'paid', (string) end( $writes )['data']['status'], 'Paid.' );
			assert_same( 'pspgd-test-101', (string) end( $writes )['data']['transaction_reference'], 'With a reference that says it was simulated.' );
			assert_same( 1, count( test_sent_mail() ), 'The donor gets a receipt.' );
			assert_same( 1, count( test_fired_actions( 'pspgd_donation_paid' ) ), 'pspgd_donation_paid fires once.' );
			assert_true( in_array( 'payment_paid', payments_event_types(), true ), 'And the log has the payment.' );
		},

		'a test payment drops the cached figures' => static function (): void {
			global $wpdb;
			payments_reset();
			set_transient( 'pspgd_stats', array( 'stale' => true ), 300 );
			$wpdb->rows = array( payments_donation(), payments_donor() );

			payments_pay_in_test_mode();

			assert_false( get_transient( 'pspgd_stats' ), 'The figures above the donation log are recomputed, as they are after a real payment.' );
		},

		'a test payment checks whether its campaign is finished' => static function (): void {
			global $wpdb;
			payments_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-09-01' ) );
			$wpdb->rows = array( payments_donation( array( 'campaign_id' => 12 ) ), payments_donor() );

			payments_pay_in_test_mode();

			assert_same( 'ended', (string) get_post_meta( 12, '_pspgd_campaign_closed_reason', true ), 'A campaign is closed by a test payment exactly when a real one would close it, not an hour later.' );
		},

		'a test payment already confirmed is not confirmed again' => static function (): void {
			global $wpdb;
			payments_reset();
			$wpdb->update_result = 0;
			$wpdb->rows          = array( payments_donation( array( 'status' => 'paid' ) ), payments_donor() );

			payments_pay_in_test_mode();

			assert_same( 1, count( payments_writes( 'pspgd_donations' ) ), 'Only the claim is tried, and it finds the row no longer pending.' );
			assert_same( 0, count( test_sent_mail() ), 'No second receipt.' );
			assert_same( 0, count( test_fired_actions( 'pspgd_donation_paid' ) ), 'No second hook.' );
		},

		'the first test payment of a recurring donation starts it on a simulated card' => static function (): void {
			global $wpdb;
			payments_reset();
			$wpdb->rows = array(
				payments_donation( array( 'type' => 'recurring_initial', 'subscription_id' => 7 ) ),
				test_row(
					array(
						'id'                   => 7,
						'donor_id'             => 3,
						'campaign_id'          => 0,
						'amount'               => '5000.00',
						'currency'             => 'AMD',
						'interval_key'         => 'monthly',
						'status'               => 'pending',
						'email'                => 'donor@example.test',
						'language'             => 'en',
						'failed_payment_count' => 0,
						'is_test'              => 1,
						'created_at'           => '2026-09-17 08:50:00',
						'next_payment_at'      => null,
					)
				),
				payments_donor(),
			);

			payments_pay_in_test_mode();

			$activated = payments_writes( 'pspgd_subscriptions' );
			assert_same( 'pspgd-test-binding-7', (string) ( $activated[0]['data']['binding_id'] ?? '' ), 'Bound to a simulated card, so its charges are recognisably test ones.' );
			assert_same( 'active', (string) ( $activated[0]['data']['status'] ?? '' ), 'And active.' );
		},

		// -------------------------------------------------------------
		// Failing a donation
		// -------------------------------------------------------------

		'a donation is failed only from pending' => static function (): void {
			payments_reset();

			assert_true( PSPGD_Payments::mark_failed( 101, 'browser_return_failed', 'Declined.' ), 'A pending donation is failed.' );
			$writes = payments_writes( 'pspgd_donations' );
			assert_same( 'pending', (string) ( $writes[0]['where']['status'] ?? '' ), 'Claimed from pending.' );
			assert_same( 'failed', (string) end( $writes )['data']['status'], 'Then failed.' );
			assert_true( in_array( 'browser_return_failed', payments_event_types(), true ), 'And the reason logged.' );
		},

		'a confirmation that got there first wins over a failure' => static function (): void {
			global $wpdb;
			payments_reset();
			$wpdb->update_result = 0;

			assert_false( PSPGD_Payments::mark_failed( 101, 'browser_return_failed', 'Declined.' ), 'The row is no longer pending.' );
			assert_same( 1, count( payments_writes( 'pspgd_donations' ) ), 'Nothing is written after the claim fails.' );
			assert_same( 0, count( payments_event_types() ), 'And nothing is logged.' );
		},
	)
);
