<?php
/**
 * The figures above the donation log.
 *
 * Run from the plugin folder: php tests/stats-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-stats.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

/** @param array<string, mixed> $fields */
function pledge( array $fields ): object {
	return test_row( array_merge( array( 'currency' => 'AMD', 'interval_key' => 'monthly', 'total' => '1', 'amount' => '0' ), $fields ) );
}

run_tests(
	array(
		'a weekly pledge is expressed as a monthly figure' => static function (): void {
			$committed = PSPGD_Stats::monthly_committed(
				array( pledge( array( 'interval_key' => 'weekly', 'amount' => '1000' ) ) )
			);

			assert_same( 4330.0, $committed['AMD'], 'A weekly donor is worth about 4.33 payments a month.' );
		},

		'monthly and weekly pledges add up within one currency' => static function (): void {
			$committed = PSPGD_Stats::monthly_committed(
				array(
					pledge( array( 'interval_key' => 'monthly', 'amount' => '5000' ) ),
					pledge( array( 'interval_key' => 'weekly', 'amount' => '1000' ) ),
				)
			);

			assert_same( 9330.0, $committed['AMD'], 'Both kinds of pledge contribute to the same monthly figure.' );
		},

		'committed value is never added across currencies' => static function (): void {
			$committed = PSPGD_Stats::monthly_committed(
				array(
					pledge( array( 'currency' => 'AMD', 'amount' => '5000' ) ),
					pledge( array( 'currency' => 'USD', 'amount' => '25' ) ),
					pledge( array( 'currency' => 'EUR', 'amount' => '30' ) ),
				)
			);

			assert_same( 5000.0, $committed['AMD'], 'AMD stands alone.' );
			assert_same( 25.0, $committed['USD'], 'USD stands alone.' );
			assert_same( 30.0, $committed['EUR'], 'EUR stands alone.' );
			assert_same( 3, count( $committed ), 'Three currencies must stay three figures.' );
		},

		'a pledge with no currency is ignored rather than mislabelled' => static function (): void {
			$committed = PSPGD_Stats::monthly_committed( array( pledge( array( 'currency' => '', 'amount' => '99' ) ) ) );

			assert_same( array(), $committed, 'An amount with no currency cannot be shown as any currency.' );
		},

		'completion rate counts only attempts that finished' => static function (): void {
			assert_same(
				75.0,
				PSPGD_Stats::completion_rate( array( 'paid' => 3, 'failed' => 1, 'pending' => 10 ) ),
				'A donation still in flight has not failed, so it must not drag the rate down.'
			);
		},

		'abandoned attempts count against the rate' => static function (): void {
			assert_same(
				50.0,
				PSPGD_Stats::completion_rate( array( 'paid' => 2, 'abandoned' => 2 ) ),
				'Someone who left the payment page is a donation that did not complete.'
			);
		},

		'nothing finished yet shows as no rate rather than zero' => static function (): void {
			assert_same(
				null,
				PSPGD_Stats::completion_rate( array( 'pending' => 4 ) ),
				'A brand new site has not failed at anything; 0% would be a lie.'
			);
		},

		'a perfect record reads as 100%' => static function (): void {
			assert_same( 100.0, PSPGD_Stats::completion_rate( array( 'paid' => 7 ) ), 'No failures means nothing to subtract.' );
		},

		'the snapshot is served from cache on the second call' => static function (): void {
			global $wpdb;
			test_reset_transients();
			$wpdb->reset_log();

			PSPGD_Stats::snapshot();
			$first = count( $wpdb->reads );
			PSPGD_Stats::snapshot();

			assert_same( $first, count( $wpdb->reads ), 'Loading the screen twice must not re-run every aggregate.' );
			assert_true( $first > 0, 'The first call must actually query something.' );
		},

		'a donation changing state clears the cache' => static function (): void {
			global $wpdb;
			test_reset_transients();
			$wpdb->reset_log();

			PSPGD_Stats::snapshot();
			PSPGD_Stats::flush();
			$before = count( $wpdb->reads );
			PSPGD_Stats::snapshot();

			assert_true( count( $wpdb->reads ) > $before, 'A confirmed payment must show up without waiting for the cache to expire.' );
		},

		'money is grouped by currency and limited to paid donations' => static function (): void {
			global $wpdb;
			test_reset_transients();
			$wpdb->reset_log();

			PSPGD_Stats::snapshot();

			assert_contains( 'GROUP BY currency', $wpdb->reads[0], 'Totals are per currency.' );
			assert_contains( "status = 'paid'", $wpdb->reads[0], 'Pending and failed money is not money raised.' );
		},

		'test-mode subscriptions are left out of the committed figure' => static function (): void {
			global $wpdb;
			test_reset_transients();
			$wpdb->reset_log();

			PSPGD_Stats::snapshot();

			$recurring_query = '';
			foreach ( $wpdb->reads as $read ) {
				if ( false !== strpos( $read, 'interval_key' ) ) {
					$recurring_query = $read;
				}
			}

			assert_contains( 'is_test = 0', $recurring_query, 'A simulated subscription is not committed income.' );
		},

		'test donations are left out of the money figures' => static function (): void {
			global $wpdb;
			test_reset_transients();
			$wpdb->reset_log();

			PSPGD_Stats::snapshot();

			assert_contains( 'is_test = 0', $wpdb->reads[0], 'A donation taken while test mode was on was never charged, so it is not money raised. See test-mode-test.php for the rest.' );
		},
	)
);
