<?php
/** Run every focused regression test in an isolated PHP process. */
$tests = array(
	'plugin-load-test.php',
	'recurring-failure-count-test.php',
	'recurring-schedule-test.php',
	'reconciliation-guards-test.php',
	'subscription-purge-test.php',
	'donor-language-test.php',
	'email-composition-test.php',
	'recurring-receipt-test.php',
	'test-mode-test.php',
	'stats-test.php',
	'hardening-test.php',
	'campaign-progress-test.php',
	'exchange-rates-test.php',
	'campaign-closing-test.php',
	'customisation-test.php',
	'timezone-test.php',
	'payment-outcome-test.php',
	'version-test.php',
);
$failures = 0;
foreach ( $tests as $test ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $test ), $status );
	$failures += $status !== 0 ? 1 : 0;
}
exit( $failures ? 1 : 0 );
