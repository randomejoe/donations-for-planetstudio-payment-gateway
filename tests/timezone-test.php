<?php
/**
 * Deadlines, retry dates and job windows in a site that is not on UTC.
 *
 * Every bank this plugin can use is in Armenia (UTC+4), and the old suite
 * only ever ran in UTC, where a shifted timestamp and a real one are the
 * same number. These tests run the same rules in Asia/Yerevan and in a zone
 * behind UTC, where they are not.
 *
 * Run from the plugin folder: php tests/timezone-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-cancel-links.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-stats.php';

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

/** Call a private static method. @return mixed */
function tz_call( string $class, string $method, ...$args ) {
	$reflection = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}

	return $reflection->invoke( null, ...$args );
}

function tz_reset( string $timezone ): void {
	test_reset_options();
	test_set_timezone( $timezone );
}

run_tests(
	array(
		'an Armenian campaign takes donations until midnight in Yerevan, not 8pm' => static function (): void {
			tz_reset( 'Asia/Yerevan' );

			// 21:00 in Yerevan on the end date.
			test_set_now( '2026-08-23 17:00:00' );
			assert_false( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'the evening of the last day is still inside the campaign' );

			// 00:00:30 the next day in Yerevan.
			test_set_now( '2026-08-23 20:00:30' );
			assert_true( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'just after local midnight the campaign has ended' );
		},

		'a campaign behind UTC does not run into the next day' => static function (): void {
			tz_reset( 'America/Los_Angeles' );

			// 2026-08-24 00:30 in Los Angeles (PDT, UTC-7).
			test_set_now( '2026-08-24 07:30:00' );
			assert_true( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'local midnight has passed, so the campaign is over' );

			// 23:30 on the end date in Los Angeles.
			test_set_now( '2026-08-24 06:30:00' );
			assert_false( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'the last evening still counts' );
		},

		'the reconciliation windows look back from the local clock' => static function (): void {
			tz_reset( 'Asia/Yerevan' );
			test_set_now( '2026-08-23 12:00:00' ); // 16:00 in Yerevan.

			assert_same( '2026-08-23 15:40:00', tz_call( 'PSPGD_Reconciliation', 'ago', 20 * MINUTE_IN_SECONDS ), 'twenty minutes ago, in local time' );
			assert_same( '2026-08-22 16:00:00', tz_call( 'PSPGD_Reconciliation', 'ago', DAY_IN_SECONDS ), 'a day ago, in local time' );
		},

		'a retry is three days from now, local time' => static function (): void {
			tz_reset( 'Asia/Yerevan' );
			test_set_now( '2026-08-23 12:00:00' );

			assert_same( '2026-08-26 16:00:00', PSPGD_Recurring::retry_at( 0 ), 'retry date in the site clock' );
		},

		'an anniversary later today is still due today' => static function (): void {
			tz_reset( 'Asia/Yerevan' );
			// 13:00 in Yerevan; the donor signed up at 15:00 local a month ago.
			test_set_now( '2026-09-23 09:00:00' );

			$subscription = (object) array(
				'interval_key' => 'monthly',
				'created_at'   => '2026-08-23 15:00:00',
			);

			assert_same( '2026-09-23 15:00:00', PSPGD_Recurring::next_anniversary( $subscription ), 'two hours from now is the next charge, not next month' );
		},

		'a receipt is dated with the day the donor gave' => static function (): void {
			tz_reset( 'Asia/Yerevan' );
			test_set_now( '2026-08-23 19:45:00' ); // 23:45 in Yerevan.

			assert_same( '2026-08-23', tz_call( 'PSPGD_Emails', 'format_date', '2026-08-23 23:30:00' ), 'late-evening donation keeps its own date' );
		},

		'this month starts on the first, local time' => static function (): void {
			tz_reset( 'Asia/Yerevan' );
			test_set_now( '2026-08-31 16:30:00' ); // 20:30 on the 31st in Yerevan.

			assert_same( '2026-08-01 00:00:00', tz_call( 'PSPGD_Stats', 'month_start' ), 'still August locally' );
		},

		'a next-payment date two hours ahead is in the future' => static function (): void {
			tz_reset( 'Asia/Yerevan' );
			test_set_now( '2026-08-23 09:00:00' ); // 13:00 in Yerevan.

			assert_true( tz_call( 'PSPGD_Emails', 'is_future', '2026-08-23 15:00:00' ), '15:00 local is still ahead of 13:00 local' );
			assert_false( tz_call( 'PSPGD_Emails', 'is_future', '2026-08-23 12:00:00' ), '12:00 local has passed' );
		},
	)
);
