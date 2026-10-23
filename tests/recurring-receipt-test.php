<?php
/**
 * The receipt for a scheduled recurring charge: the cancel link the donor
 * needs, and a next-payment date they can rely on.
 *
 * The bug these cover: the hourly worker claims a due subscription before
 * charging it, which parks the row in 'processing', and the receipt used to be
 * composed inside the payment confirmation -- before the claim was released.
 * Both the cancel button and the next-payment row were gated on status
 * 'active', so every scheduled receipt went out without either, while the
 * activation email (sent after the row is active) had them. A donor noticing
 * that their second weekly receipt had no way to stop the donation is a
 * support ticket at best and a chargeback at worst.
 *
 * Run from the plugin folder: php tests/recurring-receipt-test.php
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

/** The gateway's answer to a saved-card charge, set per test. */
$GLOBALS['test_charge_result'] = array();

function pspg_charge_saved_card( string $bank_id, array $args ): array {
	$GLOBALS['test_charge_calls'][] = $args;

	return (array) $GLOBALS['test_charge_result'];
}

function receipt_reset(): void {
	global $wpdb;
	test_reset_options();
	test_reset_transients();
	test_reset_posts();
	test_reset_mail();
	$wpdb->reset_log();
	$wpdb->rows        = array();
	$wpdb->vars        = array();
	$wpdb->result_sets = array();
	$GLOBALS['test_charge_calls']  = array();
	$GLOBALS['test_charge_result'] = array( 'ok' => true, 'status' => 'paid', 'pspg_order_id' => 555, 'transaction_id' => '900123456789' );

	// A weekly donor who signed up a fortnight ago; today's charge is their
	// second, which is the one that arrived without a cancel link.
	test_set_now( '2026-09-17 08:55:00' );
}

/** @param array<string, mixed> $overrides */
function receipt_subscription( array $overrides = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                   => 7,
				'donor_id'             => 3,
				'campaign_id'          => 0,
				'bank_id'              => 'acba',
				'amount'               => '1.00',
				'currency'             => 'EUR',
				'interval_key'         => 'weekly',
				'status'               => 'active',
				'binding_id'           => 'binding-1',
				'email'                => 'donor@example.test',
				'language'             => 'en',
				'failed_payment_count' => 0,
				'is_anonymous'         => 0,
				'created_at'           => '2026-09-03 08:55:00',
				'next_payment_at'      => '2026-09-17 08:55:00',
			),
			$overrides
		)
	);
}

function receipt_donor(): object {
	return test_row(
		array(
			'id'         => 3,
			'email'      => 'donor@example.test',
			'first_name' => 'Joe',
			'last_name'  => 'D',
			'language'   => 'en',
		)
	);
}

/** @param array<string, mixed> $overrides */
function receipt_donation( array $overrides = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                    => 101,
				'donor_id'              => 3,
				'subscription_id'       => 7,
				'campaign_id'           => 0,
				'amount'                => '1.00',
				'currency'              => 'EUR',
				'type'                  => 'recurring',
				'status'                => 'pending',
				'transaction_reference' => '',
				'language'              => 'en',
				'created_at'            => '2026-09-17 08:55:00',
			),
			$overrides
		)
	);
}

/** The one email the test sent. */
function receipt_mail(): string {
	$mail = test_sent_mail();

	return (string) ( $mail[0]['message'] ?? '' );
}

run_tests(
	array(
		// -------------------------------------------------------------
		// The gate itself
		// -------------------------------------------------------------

		'a receipt composed while the charge is in flight still carries the cancel link' => static function (): void {
			global $wpdb;
			receipt_reset();
			$wpdb->rows = array( receipt_donor(), receipt_subscription( array( 'status' => 'processing' ) ) );

			PSPGD_Emails::send_receipt(
				receipt_donation( array( 'status' => 'paid', 'transaction_reference' => '900123456789' ) )
			);

			assert_same( 1, count( test_sent_mail() ), 'The donor gets a receipt for a payment taken from their card.' );
			assert_contains( 'pspgd_cancel=7', receipt_mail(), "'processing' is the worker's claim on the row, not a donation the donor has stopped -- the link must be there." );
			assert_contains( 'Cancel recurring donation', receipt_mail(), 'And the button has to say what it does.' );
		},

		'a receipt sent before the schedule has moved on offers no next date' => static function (): void {
			global $wpdb;
			receipt_reset();
			$wpdb->rows = array( receipt_donor(), receipt_subscription( array( 'status' => 'processing' ) ) );

			PSPGD_Emails::send_receipt( receipt_donation( array( 'status' => 'paid' ) ) );

			assert_not_contains( 'Next payment', receipt_mail(), 'next_payment_at is still the charge being confirmed; offering it as the next one reads as a second payment due.' );
		},

		'a cancelled schedule gets no cancel link' => static function (): void {
			global $wpdb;
			receipt_reset();
			$wpdb->rows = array( receipt_donor(), receipt_subscription( array( 'status' => 'cancelled', 'next_payment_at' => '' ) ) );

			PSPGD_Emails::send_receipt( receipt_donation( array( 'status' => 'paid' ) ) );

			assert_same( 1, count( test_sent_mail() ), 'A final payment is still receipted.' );
			assert_not_contains( 'pspgd_cancel=', receipt_mail(), 'There is nothing left to cancel, so offering it would only confuse.' );
		},

		// -------------------------------------------------------------
		// The whole scheduled charge
		// -------------------------------------------------------------

		'a scheduled charge sends one receipt, after the schedule has moved on' => static function (): void {
			global $wpdb;
			receipt_reset();
			$wpdb->rows = array(
				// Read by the payment confirmation, mid-charge.
				receipt_donation(),
				// Re-read once the schedule has been rescheduled.
				receipt_donation( array( 'status' => 'paid', 'transaction_reference' => '900123456789' ) ),
				receipt_donor(),
				receipt_subscription( array( 'next_payment_at' => '2026-09-24 08:55:00' ) ),
			);

			$charged = PSPGD_Recurring::charge_once( receipt_subscription(), 'recurring_cron' );

			assert_true( $charged, 'The gateway accepted the charge.' );
			assert_same( 1, count( test_sent_mail() ), 'One charge, one receipt: the confirmation defers to this send instead of composing its own.' );
			assert_contains( 'pspgd_cancel=7', receipt_mail(), 'This is the email a donor goes looking in when they want to stop.' );
			assert_contains( 'Next payment', receipt_mail(), 'Composed after rescheduling, so there is a real date to give.' );
			assert_contains( '2026-09-24', receipt_mail(), 'And it is the next anniversary, not the charge just taken.' );
			assert_contains( '900123456789', receipt_mail(), 'The donation row is re-read, so the bank reference is there to match against a statement.' );
			assert_contains( 'Your recurring donation was received', receipt_mail(), 'A scheduled payment reads differently from a donation just made.' );
		},

		'the schedule is released and moved on before the receipt is composed' => static function (): void {
			global $wpdb;
			receipt_reset();
			$wpdb->rows = array(
				receipt_donation(),
				receipt_donation( array( 'status' => 'paid' ) ),
				receipt_donor(),
				receipt_subscription( array( 'next_payment_at' => '2026-09-24 08:55:00' ) ),
			);

			PSPGD_Recurring::charge_once( receipt_subscription(), 'recurring_cron' );

			$rescheduled = array_values(
				array_filter(
					$wpdb->updates,
					static function ( array $update ): bool {
						return isset( $update['data']['next_payment_at'] ) && array_key_exists( 'claimed_at', $update['data'] );
					}
				)
			);
			assert_same( 1, count( $rescheduled ), 'The claim is released exactly once.' );
			assert_same( 'active', (string) $rescheduled[0]['data']['status'], 'And the donor is back on an active schedule.' );
			assert_same( '2026-09-24 08:55:00', (string) $rescheduled[0]['data']['next_payment_at'], 'Anchored to the signup day, not a week from the charge.' );
		},

		'a failed charge sends no receipt' => static function (): void {
			global $wpdb;
			receipt_reset();
			$GLOBALS['test_charge_result'] = array( 'ok' => false, 'message' => 'declined' );

			$charged = PSPGD_Recurring::charge_once( receipt_subscription(), 'recurring_cron' );

			assert_false( $charged, 'A declined card is not a donation.' );
			$receipts = array_values(
				array_filter(
					test_sent_mail(),
					static function ( array $mail ): bool {
						return false !== strpos( (string) $mail['subject'], 'Your Weekly donation' );
					}
				)
			);
			assert_same( 0, count( $receipts ), 'Thanking a donor for a payment that was refused is worse than saying nothing.' );
		},

		// -------------------------------------------------------------
		// The fallback path
		// -------------------------------------------------------------

		'a payment confirmed outside its charge run still receipts the donor' => static function (): void {
			global $wpdb;
			receipt_reset();
			$wpdb->rows = array(
				receipt_donation(),
				receipt_donor(),
				receipt_subscription(),
			);

			// Reconciliation re-checking a charge whose own run never reported
			// an outcome: nothing defers the receipt here, so it must be sent.
			PSPGD_PSPG::handle_confirmed_payment(
				555,
				array( 'ok' => true, 'status' => 'paid', 'transaction_id' => '900123456789' ),
				array( 'channel' => 'reconcile' )
			);

			assert_same( 1, count( test_sent_mail() ), 'A donor whose charge was confirmed late must not be left without a receipt.' );
			assert_contains( 'pspgd_cancel=7', receipt_mail(), 'With the cancel link, wherever the confirmation came from.' );
		},
	)
);
