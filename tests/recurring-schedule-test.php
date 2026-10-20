<?php
/**
 * Retry cadence and charge-date anchoring for recurring donations.
 *
 * Run from the plugin folder: php tests/recurring-schedule-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';

/** @param array<string, mixed> $fields */
function subscription( array $fields ): object {
	return test_row(
		array_merge(
			array(
				'id'                   => 7,
				'interval_key'         => 'monthly',
				'created_at'           => '2026-01-04 09:00:00',
				'failed_payment_count' => 0,
			),
			$fields
		)
	);
}

run_tests(
	array(
		'a failed charge is retried in days, not a whole interval' => static function (): void {
			test_set_now( '2026-08-23 12:00:00' );

			assert_same( '2026-08-26 12:00:00', PSPGD_Recurring::retry_at( 0 ), 'The first retry must come three days later.' );
			assert_same( '2026-08-30 12:00:00', PSPGD_Recurring::retry_at( 1 ), 'The second retry must come a week later.' );
			assert_same( '2026-09-06 12:00:00', PSPGD_Recurring::retry_at( 2 ), 'The third retry must come a fortnight later.' );
		},

		'the retry ladder cannot run off its end' => static function (): void {
			test_set_now( '2026-08-23 12:00:00' );

			assert_same(
				PSPGD_Recurring::retry_at( 2 ),
				PSPGD_Recurring::retry_at( 9 ),
				'A failure count beyond the ladder must reuse the last rung rather than error.'
			);
		},

		'a successful charge returns the donor to their signup date' => static function (): void {
			// Signed up on the 4th, failed on the 4th, recovered on the 7th.
			test_set_now( '2026-02-07 15:30:00' );

			assert_same(
				'2026-03-04 09:00:00',
				PSPGD_Recurring::next_anniversary( subscription( array() ) ),
				'A recovered subscription must go back to the 4th, not drift to the 7th.'
			);
		},

		'repeated retries never drift the charge date' => static function (): void {
			test_set_now( '2026-05-19 23:00:00' );

			assert_same(
				'2026-06-04 09:00:00',
				PSPGD_Recurring::next_anniversary( subscription( array() ) ),
				'However many retries happened, the anchor is still the signup date.'
			);
		},

		'a month-end signup is clamped instead of spilling into the next month' => static function (): void {
			test_set_now( '2026-02-01 00:00:00' );

			assert_same(
				'2026-02-28 12:00:00',
				PSPGD_Recurring::next_anniversary(
					subscription( array( 'created_at' => '2026-01-31 12:00:00' ) )
				),
				'A 31st signup must land on the last day of February, not the 3rd of March.'
			);
		},

		'a month-end signup returns to its own day once the month is long enough' => static function (): void {
			test_set_now( '2026-03-01 00:00:00' );

			assert_same(
				'2026-03-31 12:00:00',
				PSPGD_Recurring::next_anniversary(
					subscription( array( 'created_at' => '2026-01-31 12:00:00' ) )
				),
				'Clamping in a short month must not permanently move the donor to the 28th.'
			);
		},

		'weekly subscriptions advance a week at a time from signup' => static function (): void {
			test_set_now( '2026-08-05 09:00:00' );

			assert_same(
				'2026-08-10 10:00:00',
				PSPGD_Recurring::next_anniversary(
					subscription( array( 'interval_key' => 'weekly', 'created_at' => '2026-08-03 10:00:00' ) )
				),
				'A weekly donor stays on their signup weekday.'
			);
		},

		'an unusable signup date falls back to one interval from now' => static function (): void {
			test_set_now( '2026-08-23 12:00:00' );

			assert_same(
				'2026-09-23 12:00:00',
				PSPGD_Recurring::next_anniversary(
					subscription( array( 'created_at' => 'not a date' ) )
				),
				'A bad anchor must still produce a usable next charge date.'
			);
		},
	)
);
