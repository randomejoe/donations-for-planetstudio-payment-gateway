<?php
/**
 * The version and requirements agree everywhere they are written.
 *
 * A release states its version in four places and its requirements in
 * three, all by hand. WordPress reads the plugin header, WordPress.org reads
 * readme.txt's Stable tag, and the code reads PSPGD_VERSION; when they
 * disagree, updates are offered for the wrong version or not at all. See
 * RELEASING.md.
 *
 * Run from the plugin folder: php tests/version-test.php
 */

require_once __DIR__ . '/bootstrap.php';

/** A "Name: value" header line from a file's text, or ''. */
function version_test_header( string $text, string $name ): string {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':[ \t]*(.+)$/mi', $text, $match ) ? trim( $match[1] ) : '';
}

function version_test_read( string $file ): string {
	return (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
}

$main    = version_test_read( 'donations-for-planetstudio-payment-gateway.php' );
$readme  = version_test_read( 'readme.txt' );
$version = version_test_header( $main, 'Version' );

run_tests(
	array(
		'the plugin header carries a plain X.Y.Z version' => static function () use ( $version ): void {
			assert_true( 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ), 'The tag, the zip name and WordPress.org all expect X.Y.Z, found "' . $version . '".' );
		},

		'PSPGD_VERSION matches the plugin header' => static function () use ( $main, $version ): void {
			preg_match( "/define\( 'PSPGD_VERSION', '([^']*)' \)/", $main, $match );
			assert_same( $version, $match[1] ?? '', 'Asset URLs carry PSPGD_VERSION, so a stale one keeps old CSS and JS in browser caches.' );
		},

		'readme.txt Stable tag matches the plugin header' => static function () use ( $readme, $version ): void {
			assert_same( $version, version_test_header( $readme, 'Stable tag' ), 'WordPress.org serves the version named by Stable tag.' );
		},

		'readme.txt has a changelog entry for this version' => static function () use ( $readme, $version ): void {
			$changelog = (string) strstr( $readme, '== Changelog ==' );
			assert_contains( '= ' . $version . ' =', $changelog, 'Every release says what changed.' );
		},

		'the requirements in readme.txt match the plugin header' => static function () use ( $main, $readme ): void {
			foreach ( array( 'Requires at least', 'Requires PHP', 'Requires Plugins' ) as $name ) {
				assert_true( '' !== version_test_header( $main, $name ), 'The plugin header must state ' . $name . '.' );
				assert_same( version_test_header( $main, $name ), version_test_header( $readme, $name ), $name . ' must say the same in both files.' );
			}
		},

		'the GitHub README states the same requirements' => static function () use ( $main, $readme ): void {
			$github = version_test_read( 'README.md' );
			$tested = version_test_header( $readme, 'Tested up to' );

			assert_contains( 'WordPress ' . version_test_header( $main, 'Requires at least' ) . ' or later (tested up to ' . $tested . ')', $github, 'README.md must show the current WordPress range.' );
			assert_contains( 'PHP ' . version_test_header( $main, 'Requires PHP' ) . ' or later', $github, 'README.md must show the current PHP minimum.' );
		},
	)
);
