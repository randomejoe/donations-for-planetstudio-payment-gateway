<?php
/**
 * Regression tests for recurring-charge retry accounting.
 *
 * Run from the plugin folder: php tests/recurring-failure-count-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

$tests = array(
	'first failed charge starts the consecutive-failure count at one' => static function (): void {
		global $wpdb;
		$wpdb->queries = array();

		PSPGD_Repository::reschedule_subscription( 42, '2026-08-30 12:00:00', false );

		assert_same( 1, count( $wpdb->queries ), 'A failed charge must update the subscription.' );
		assert_same( true, false !== strpos( $wpdb->queries[0], 'failed_payment_count = failed_payment_count + 1' ), 'The first failure must be recorded by incrementing the persisted count.' );
	},
	'additional failed charges increment rather than reset the count' => static function (): void {
		global $wpdb;
		$wpdb->queries = array();

		PSPGD_Repository::reschedule_subscription( 42, '2026-08-30 12:00:00', false );

		assert_same(
			true,
			false !== strpos( $wpdb->queries[0], 'failed_payment_count = failed_payment_count + 1' ),
			'A later failure must retain prior failures so the cancellation threshold can be reached.'
		);
	},
	'a successful charge resets the consecutive-failure count' => static function (): void {
		global $wpdb;
		$wpdb->updates = array();

		PSPGD_Repository::reschedule_subscription( 42, '2026-09-23 12:00:00', true );

		assert_same( 0, $wpdb->updates[0]['data']['failed_payment_count'], 'A successful charge must clear the retry streak.' );
	},
);

$failures = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $error ) {
		$failures++;
		echo "FAIL: {$name}\n{$error->getMessage()}\n";
	}
}

exit( $failures > 0 ? 1 : 0 );
