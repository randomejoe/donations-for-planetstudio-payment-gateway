<?php
/**
 * What a bank result means, and what happens to a donation because of it.
 *
 * The rules these cover:
 *  - only an explicit "paid" is money received; `ok` only means the request
 *    to the bank went through, and a decline arrives with ok=true;
 *  - a declined scheduled charge is retried and the donor told, while a
 *    charge whose outcome is unknown is neither retried nor counted;
 *  - a report about a different bank order, or for a different amount, is
 *    never booked as this donation's payment;
 *  - refunds made in PSPG mark the donation refunded.
 *
 * Run from the plugin folder: php tests/payment-outcome-test.php
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

$GLOBALS['test_charge_result'] = array();
$GLOBALS['test_banks']         = array();

function pspg_charge_saved_card( string $bank_id, array $args ): array {
	return (array) $GLOBALS['test_charge_result'];
}

/** A stand-in for a PSPG bank: only supports() is asked. */
final class Outcome_Test_Bank {
	/** @var array<string, bool> */
	private $supports;

	/** @param array<string, bool> $supports */
	public function __construct( array $supports ) {
		$this->supports = $supports;
	}

	/** @return array<string, bool> */
	public function supports(): array {
		return $this->supports;
	}
}

/** @return object|null */
function pspg_get_bank( string $bank_id ) {
	return $GLOBALS['test_banks'][ $bank_id ] ?? null;
}

function outcome_reset(): void {
	global $wpdb;
	test_reset_options();
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
	$GLOBALS['test_charge_result'] = array();
	$GLOBALS['test_banks']         = array();
	test_set_now( '2026-09-17 08:55:00' );
}

/** @param array<string, mixed> $overrides */
function outcome_donation( array $overrides = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                    => 101,
				'donor_id'              => 3,
				'subscription_id'       => 0,
				'campaign_id'           => 0,
				'bank_id'               => 'arca_acba',
				'bank_order_id'         => 'a1b2c3d4-0000-4000-8000-000000000101',
				'amount'                => '5000.00',
				'currency'              => 'AMD',
				'type'                  => 'one_time',
				'status'                => 'pending',
				'transaction_reference' => '',
				'language'              => 'en',
				'is_test'               => 0,
				'is_anonymous'          => 0,
				'created_at'            => '2026-09-17 08:50:00',
			),
			$overrides
		)
	);
}

function outcome_donor(): object {
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

/** @param array<string, mixed> $overrides */
function outcome_subscription( array $overrides = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                   => 7,
				'donor_id'             => 3,
				'campaign_id'          => 0,
				'bank_id'              => 'arca_acba',
				'client_id'            => '',
				'amount'               => '5000.00',
				'currency'             => 'AMD',
				'interval_key'         => 'monthly',
				'status'               => 'processing',
				'binding_id'           => 'binding-1',
				'email'                => 'donor@example.test',
				'language'             => 'en',
				'failed_payment_count' => 0,
				'is_anonymous'         => 0,
				'is_test'              => 0,
				'created_at'           => '2026-08-17 08:55:00',
				'next_payment_at'      => '2026-09-17 08:55:00',
			),
			$overrides
		)
	);
}

/**
 * An ArCa order status report as PSPG passes it on.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function outcome_arca_report( string $status, array $raw = array(), string $order_id = 'a1b2c3d4-0000-4000-8000-000000000101' ): array {
	return array(
		'ok'          => true,
		'status'      => $status,
		'bank_status' => 'paid' === $status ? 2 : 6,
		'bank_ids'    => array(
			'primary' => $order_id,
			'orderId' => $order_id,
		),
		'raw'         => array_merge(
			array(
				'orderId'    => $order_id,
				'amount'     => 500000,
				'currency'   => '051',
				'authRefNum' => '600123456789',
			),
			$raw
		),
	);
}

/** @return array<int, array<string, mixed>> Updates to the donations table that set a status. */
function outcome_status_writes(): array {
	global $wpdb;

	return array_values(
		array_filter(
			$wpdb->updates,
			static function ( array $update ): bool {
				return false !== strpos( (string) $update['table'], 'pspgd_donations' ) && isset( $update['data']['status'] );
			}
		)
	);
}

/** @return string[] */
function outcome_event_types(): array {
	global $wpdb;

	$types = array();
	foreach ( $wpdb->inserts as $insert ) {
		if ( false !== strpos( (string) $insert['table'], 'pspgd_events' ) ) {
			$types[] = (string) $insert['data']['event_type'];
		}
	}

	return $types;
}

run_tests(
	array(
		// -------------------------------------------------------------
		// Reading a result
		// -------------------------------------------------------------

		'only an explicit paid status is money received' => static function (): void {
			assert_same( 'paid', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'paid' ) ), 'paid is paid' );
			assert_same( 'pending', PSPGD_PSPG::normalize_status( array( 'ok' => true ) ), 'ok alone says the request went through, not that money moved' );
			assert_same( 'pending', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'registered' ) ), 'registered is an order waiting for the donor' );
			assert_same( 'pending', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'authorized' ) ), 'an authorisation is not a capture' );
			assert_same( 'pending', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'unknown', 'bank_status' => 2 ) ), 'a bare bank code is not trusted' );
		},

		'declines, voids and refunds are never payments' => static function (): void {
			assert_same( 'failed', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'failed' ) ), 'failed' );
			assert_same( 'failed', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'voided' ) ), 'voided' );
			assert_same( 'failed', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'Declined' ) ), 'any spelling of a decline' );
			assert_same( 'refunded', PSPGD_PSPG::normalize_status( array( 'ok' => true, 'status' => 'refunded' ) ), 'refunded' );
		},

		'a saved-card charge is paid, failed or unknown' => static function (): void {
			assert_same( 'paid', PSPGD_PSPG::charge_outcome( array( 'ok' => true, 'status' => 'paid' ) ), 'paid' );
			assert_same( 'failed', PSPGD_PSPG::charge_outcome( array( 'ok' => true, 'status' => 'failed' ) ), 'a decline arrives with ok=true' );
			assert_same( 'failed', PSPGD_PSPG::charge_outcome( array( 'ok' => true, 'status' => 'registered' ) ), 'an order nobody will ever finish is not money' );
			assert_same( 'failed', PSPGD_PSPG::charge_outcome( array( 'ok' => false, 'error' => 'missing_credentials' ) ), 'a request that never reached the charge took nothing' );
			assert_same( 'unknown', PSPGD_PSPG::charge_outcome( array( 'ok' => false, 'error' => 'http_error' ) ), 'a timeout may have charged the card' );
			assert_same( 'unknown', PSPGD_PSPG::charge_outcome( array( 'ok' => true, 'status' => 'authorized' ) ), 'neither paid nor declined' );
		},

		// -------------------------------------------------------------
		// Scheduled charges
		// -------------------------------------------------------------

		'a declined scheduled charge is retried and the donor told' => static function (): void {
			global $wpdb;
			outcome_reset();
			$GLOBALS['test_charge_result'] = array_merge( outcome_arca_report( 'failed' ), array( 'pspg_order_id' => 555 ) );
			$wpdb->rows = array(
				outcome_subscription( array( 'status' => 'active', 'failed_payment_count' => 1, 'next_payment_at' => '2026-09-20 08:55:00' ) ),
				outcome_donor(),
			);

			$paid = PSPGD_Recurring::charge_once( outcome_subscription(), 'recurring_cron' );

			assert_false( $paid, 'A decline is not a donation.' );
			$failure = implode( "\n", $wpdb->queries );
			assert_contains( 'failed_payment_count = failed_payment_count + 1', $failure, 'The failure is counted, so a dead card is eventually cancelled.' );
			assert_contains( "'2026-09-20 08:55:00'", $failure, 'And retried three days later, not next month.' );
			$mail = test_sent_mail();
			assert_same( 1, count( $mail ), 'The donor hears that the payment did not go through.' );
			assert_contains( 'could not process', (string) $mail[0]['subject'], 'With the failed-payment email.' );
			$writes = outcome_status_writes();
			assert_same( 'processing', (string) ( $writes[0]['data']['status'] ?? '' ), 'The decline claims the row from pending first, so a confirmation racing it wins.' );
			assert_same( 'pending', (string) ( $writes[0]['where']['status'] ?? '' ), 'Only from pending.' );
			assert_same( 'failed', (string) ( end( $writes )['data']['status'] ?? '' ), 'The donation row records the decline.' );
		},

		'a charge with no reported outcome is neither retried nor counted' => static function (): void {
			global $wpdb;
			outcome_reset();
			$GLOBALS['test_charge_result'] = array( 'ok' => false, 'error' => 'http_error', 'pspg_order_id' => 555 );

			$paid = PSPGD_Recurring::charge_once( outcome_subscription(), 'recurring_cron' );

			assert_false( $paid, 'Not confirmed, so not reported as paid.' );
			assert_same( 0, count( $wpdb->queries ), 'No failure is counted: the card may already have paid.' );
			assert_same( 0, count( outcome_status_writes() ), 'The donation stays pending for reconciliation to settle.' );
			assert_same( 0, count( test_sent_mail() ), 'Nobody is told anything that may be untrue.' );

			$moved = array_values(
				array_filter(
					$wpdb->updates,
					static function ( array $update ): bool {
						return false !== strpos( (string) $update['table'], 'pspgd_subscriptions' ) && isset( $update['data']['next_payment_at'] );
					}
				)
			);
			assert_same( '2026-10-17 08:55:00', (string) ( $moved[0]['data']['next_payment_at'] ?? '' ), 'The schedule moves to the next anniversary instead of retrying.' );
			assert_true( in_array( 'recurring_charge_unconfirmed', outcome_event_types(), true ), 'And the log says the outcome is not known yet.' );
		},

		// -------------------------------------------------------------
		// Checking a report against the donation
		// -------------------------------------------------------------

		'a report for the donation, its order and its amount is booked as paid' => static function (): void {
			global $wpdb;
			outcome_reset();
			$wpdb->rows = array( outcome_donation(), outcome_donor() );

			PSPGD_PSPG::handle_confirmed_payment( 555, outcome_arca_report( 'paid' ), array( 'channel' => 'callback' ) );

			$writes = outcome_status_writes();
			assert_same( 'paid', (string) end( $writes )['data']['status'], 'Paid.' );
			assert_same( '600123456789', (string) end( $writes )['data']['transaction_reference'], 'With the RRN a donor can find on their statement.' );
			assert_same( 1, count( test_sent_mail() ), 'And receipted.' );
			assert_same( 1, count( test_fired_actions( 'pspgd_donation_paid' ) ), 'pspgd_donation_paid fires once.' );
		},

		'a report that names another bank order is ignored' => static function (): void {
			global $wpdb;
			outcome_reset();
			$wpdb->rows = array( outcome_donation() );

			PSPGD_PSPG::handle_confirmed_payment( 555, outcome_arca_report( 'paid', array(), 'ffffffff-9999-4999-8999-999999999999' ), array( 'channel' => 'callback' ) );

			assert_same( 0, count( $wpdb->updates ), 'The donation is not touched at all: the report is about a different payment.' );
			assert_same( 0, count( test_sent_mail() ), 'Nobody is thanked for someone else\'s payment.' );
			assert_true( in_array( 'payment_order_mismatch', outcome_event_types(), true ), 'And it is logged as a security event.' );
		},

		'a payment settled for another amount is held for review, not booked' => static function (): void {
			global $wpdb;
			outcome_reset();
			update_option( 'pspgd_staff_notify_emails', 'office@example.test' );
			$wpdb->rows = array( outcome_donation(), outcome_donor() );

			// The donor chose 5,000 AMD; the bank reports 500 AMD.
			PSPGD_PSPG::handle_confirmed_payment( 555, outcome_arca_report( 'paid', array( 'amount' => 50000 ) ), array( 'channel' => 'callback' ) );

			$writes = outcome_status_writes();
			assert_same( 'review', (string) end( $writes )['data']['status'], 'Neither paid nor failed: money moved, but not the money asked for.' );
			assert_true( in_array( 'payment_amount_mismatch', outcome_event_types(), true ), 'The mismatch is logged.' );
			assert_same( 0, count( test_fired_actions( 'pspgd_donation_paid' ) ), 'Integrations are not told it was paid.' );

			$mail = test_sent_mail();
			assert_same( 1, count( $mail ), 'Only staff hear about it: no receipt quoting a sum the bank did not take.' );
			assert_same( 'office@example.test', (string) $mail[0]['to'], 'To the staff address.' );
			assert_contains( '500 AMD', (string) $mail[0]['message'], 'Showing what the bank reported.' );
		},

		'a payment in the wrong currency is held too' => static function (): void {
			global $wpdb;
			outcome_reset();
			$wpdb->rows = array( outcome_donation() );

			PSPGD_PSPG::handle_confirmed_payment( 555, outcome_arca_report( 'paid', array( 'currency' => '840' ) ), array( 'channel' => 'callback' ) );

			$writes = outcome_status_writes();
			assert_same( 'review', (string) end( $writes )['data']['status'], '5,000 USD is not 5,000 AMD.' );
		},

		'banks whose reports are not read for amounts are not compared' => static function (): void {
			$donation = outcome_donation( array( 'bank_id' => 'ameria' ) );

			assert_same( '', PSPGD_PSPG::verification_problem( $donation, outcome_arca_report( 'paid', array( 'amount' => 1 ) ) ), 'No verified reading of this bank\'s amounts, so no false alarm.' );
		},

		// -------------------------------------------------------------
		// Refunds made in PSPG
		// -------------------------------------------------------------

		'a full refund in PSPG marks the donation refunded' => static function (): void {
			global $wpdb;
			outcome_reset();
			$wpdb->rows = array( outcome_donation( array( 'status' => 'paid' ) ) );

			PSPGD_PSPG::handle_admin_refund( array(), 555, array( 'ok' => true ), array( 'ok' => true, 'status' => 'refunded' ) );

			$transition = implode( "\n", $wpdb->queries );
			assert_contains( "SET status = 'refunded'", $transition, 'Refunded.' );
			assert_contains( "status IN ('paid','review')", $transition, 'Only from paid or review, in one statement.' );
			assert_true( in_array( 'payment_refunded', outcome_event_types(), true ), 'And logged.' );
		},

		'a partial refund leaves the donation paid' => static function (): void {
			global $wpdb;
			outcome_reset();
			$wpdb->rows = array( outcome_donation( array( 'status' => 'paid' ) ) );

			PSPGD_PSPG::handle_admin_refund( array(), 555, array( 'ok' => true ), array( 'ok' => true, 'status' => 'paid' ) );

			assert_same( 0, count( $wpdb->queries ), 'There is no partial amount to record, so the status stays.' );
			assert_true( in_array( 'payment_partially_refunded', outcome_event_types(), true ), 'But the refund is in the log.' );
		},

		// -------------------------------------------------------------
		// Wallet returns, recurring support, client ids
		// -------------------------------------------------------------

		'a wallet donor comes back to the result screen' => static function (): void {
			$url = (string) PSPGD_PSPG::wallet_success_url( '', 900, array( 'source' => 'pspgd_donations', 'source_id' => '101' ) );

			assert_contains( 'pspgd_donation=101', $url, 'To this donation.' );
			assert_contains( 'pspgd_return_token=', $url, 'With the token that proves it.' );
			assert_same( '', PSPGD_PSPG::wallet_success_url( '', 900, array( 'source' => 'woocommerce', 'source_id' => '5' ) ), 'Another plugin\'s order is not ours to redirect.' );
			assert_same( 'https://shop.test/thanks', PSPGD_PSPG::wallet_success_url( 'https://shop.test/thanks', 900, array( 'source' => 'pspgd_donations', 'source_id' => '101' ) ), 'An answer already given stands.' );
			assert_contains( 'pspgd_donation_failed=1', (string) PSPGD_PSPG::wallet_fail_url( '', 900, array( 'source' => 'pspgd_donations', 'source_id' => '101' ) ), 'A failed wallet payment says so.' );
		},

		'recurring donations are offered through ArCa-network banks only' => static function (): void {
			outcome_reset();
			$capable               = new Outcome_Test_Bank( array( 'binding' => true, 'subs' => true ) );
			$GLOBALS['test_banks'] = array(
				'arca_acba' => $capable,
				'ameria'    => $capable,
				'idram'     => new Outcome_Test_Bank( array( 'binding' => false, 'subs' => false ) ),
			);

			assert_true( PSPGD_PSPG::bank_supports_recurring( 'arca_acba' ), 'ACBA, the proven path.' );
			assert_false( PSPGD_PSPG::bank_supports_recurring( 'ameria' ), 'Saved cards in PSPG, but not proven with this plugin.' );
			assert_false( PSPGD_PSPG::bank_supports_recurring( 'idram' ), 'A wallet cannot save a card.' );

			test_set_filter(
				'pspgd_bank_supports_recurring',
				static function ( bool $supported, string $bank_id, bool $capable ): bool {
					return $supported || ( 'ameria' === $bank_id && $capable );
				}
			);
			assert_true( PSPGD_PSPG::bank_supports_recurring( 'ameria' ), 'A site testing Ameriabank on staging can switch it on.' );
			assert_false( PSPGD_PSPG::bank_supports_recurring( 'idram' ), 'But not for a bank that cannot save cards.' );
		},

		'a subscription is charged under the client id it was bound with' => static function (): void {
			assert_same( 'frontline-subscription-7', PSPGD_PSPG::client_id_for( outcome_subscription( array( 'client_id' => 'frontline-subscription-7' ) ) ), 'A stored client id wins -- an imported binding keeps working.' );
			assert_same( 'pspgd-subscription-7', PSPGD_PSPG::client_id_for( outcome_subscription() ), 'Without one, the id this plugin binds under.' );
		},
	)
);
