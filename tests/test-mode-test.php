<?php
/**
 * Test mode must not leak into anything that reads as money.
 *
 * Donations taken while test mode is on are never charged -- the payment is
 * simulated in PSPGD_PSPG and stamped with a 'pspgd-test-'
 * reference -- but until 0.9.3 the donations table had no marker for them, so
 * they sat in the figures above the donation log and in campaign totals as
 * though someone had given the money. A run of test gifts read as a very good
 * month, and one could close a campaign for real donors.
 *
 * Run from the plugin folder: php tests/test-mode-test.php
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

function pspg_charge_saved_card( string $bank_id, array $args ): array {
	return (array) $GLOBALS['test_charge_result'];
}

function tm_reset(): void {
	global $wpdb;
	test_reset_options();
	test_reset_transients();
	test_reset_mail();
	$wpdb->reset_log();
	$wpdb->rows        = array();
	$wpdb->vars        = array();
	$wpdb->result_sets = array();
	$GLOBALS['test_charge_result'] = array( 'ok' => false, 'message' => 'declined' );
	test_set_now( '2026-09-17 08:55:00' );
}

/** @param array<string, mixed> $overrides */
function tm_subscription( array $overrides = array() ): object {
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
				'binding_id'           => 'pspgd-test-binding-7',
				'email'                => 'donor@example.test',
				'language'             => 'en',
				'failed_payment_count' => 0,
				'is_anonymous'         => 0,
				'is_test'              => 0,
				'created_at'           => '2026-09-03 08:55:00',
				'next_payment_at'      => '2026-09-17 08:55:00',
			),
			$overrides
		)
	);
}

/** The row written to the donations table. @return array<string, mixed> */
function tm_donation_insert(): array {
	global $wpdb;

	foreach ( $wpdb->inserts as $insert ) {
		if ( false !== strpos( (string) $insert['table'], 'pspgd_donations' ) ) {
			return (array) $insert['data'];
		}
	}

	return array();
}

/** Every statement in this run that read the donations table. @return string[] */
function tm_donation_reads(): array {
	global $wpdb;

	return array_values(
		array_filter(
			$wpdb->reads,
			static function ( string $read ): bool {
				return false !== strpos( $read, 'pspgd_donations' );
			}
		)
	);
}

run_tests(
	array(
		// -------------------------------------------------------------
		// Marking them on the way in
		// -------------------------------------------------------------

		'a donation taken while test mode is on is recorded as a test' => static function (): void {
			tm_reset();
			update_option( 'pspgd_test_mode', '1' );

			PSPGD_Repository::create_donation( array( 'amount' => '1.00', 'currency' => 'EUR' ) );

			assert_same( 1, (int) tm_donation_insert()['is_test'], 'Every donation row is created through here, so the site setting is the fallback.' );
		},

		'a real donation is not marked' => static function (): void {
			tm_reset();

			PSPGD_Repository::create_donation( array( 'amount' => '1.00', 'currency' => 'EUR' ) );

			assert_same( 0, (int) tm_donation_insert()['is_test'], 'With test mode off, money given is money raised.' );
		},

		'what the caller says wins over the site setting' => static function (): void {
			tm_reset();
			update_option( 'pspgd_test_mode', '1' );

			PSPGD_Repository::create_donation( array( 'amount' => '1.00', 'currency' => 'EUR', 'is_test' => false ) );

			assert_same( 0, (int) tm_donation_insert()['is_test'], 'A caller that knows better than the option -- an import, a backfill -- is believed.' );
		},

		'a charge against a simulated binding records a simulated donation' => static function (): void {
			tm_reset();

			PSPGD_Recurring::charge_once( tm_subscription( array( 'is_test' => 1 ) ), 'recurring_cron' );

			assert_same( 1, (int) tm_donation_insert()['is_test'], 'Test mode may have been switched off since the donor signed up; the saved card is still a simulated one.' );
		},

		// -------------------------------------------------------------
		// Keeping them out of the figures
		// -------------------------------------------------------------

		'every figure above the donation log leaves test donations out' => static function (): void {
			tm_reset();

			PSPGD_Stats::snapshot();

			$reads = tm_donation_reads();
			assert_same( 3, count( $reads ), 'Money, active supporters and outcomes: three reads of the donations table.' );
			foreach ( $reads as $read ) {
				assert_contains( 'is_test = 0', $read, 'A simulated payment is not money raised, not a supporter, and not an outcome to rate the form by.' );
			}
		},

		'the completion rate is not flattered by test payments' => static function (): void {
			tm_reset();

			PSPGD_Stats::snapshot();

			$outcomes = '';
			foreach ( tm_donation_reads() as $read ) {
				if ( false !== strpos( $read, 'GROUP BY status' ) ) {
					$outcomes = $read;
				}
			}

			assert_contains( 'WHERE is_test = 0', $outcomes, 'Test donations are always paid on the first attempt, so counting them would report a completion rate no real form achieves.' );
		},

		'a test donation cannot move a campaign progress bar' => static function (): void {
			global $wpdb;
			tm_reset();

			PSPGD_Repository::campaign_progress( array( 12 ) );

			assert_contains( 'is_test = 0', $wpdb->reads[0], 'The bar is public, and closing a campaign is decided on this total.' );
		},

		'a test donation is not listed as a supporter' => static function (): void {
			global $wpdb;
			tm_reset();

			PSPGD_Repository::campaign_donors( array( 12 ) );

			assert_contains( 'd.is_test = 0', $wpdb->reads[0], 'The supporters list is on the campaign page, under the donors who really gave.' );
		},
	)
);
