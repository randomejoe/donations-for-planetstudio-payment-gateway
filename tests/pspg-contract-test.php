<?php
/**
 * Everything this plugin calls in PSPG exists in a real copy of PSPG.
 *
 * The other tests stand in for PSPG, so a PSPG update that renames a hook or
 * a function passes all of them. This one reads the names from this plugin's
 * own code -- the pspg_ hooks it listens to, and the functions, classes and
 * methods it checks for before calling -- and looks for each in PSPG's
 * source. A hook can't be checked at runtime the way missing_pspg_parts()
 * checks functions, so this is the only place a renamed one shows up.
 *
 * It needs an unpacked PSPG plugin folder, named by PSPGD_PSPG_SOURCE; CI
 * downloads the latest release from WordPress.org. Without it, the test is
 * skipped.
 *
 * Run from the plugin folder:
 * PSPGD_PSPG_SOURCE=/path/to/planetstudio-payment-gateway php tests/pspg-contract-test.php
 */

require_once __DIR__ . '/bootstrap.php';

$source = (string) getenv( 'PSPGD_PSPG_SOURCE' );
if ( '' === $source ) {
	echo "SKIP: pspg-contract-test.php needs PSPGD_PSPG_SOURCE, an unpacked PSPG plugin folder.\n";
	exit( 0 );
}
if ( ! is_dir( $source ) ) {
	echo "FAIL: PSPGD_PSPG_SOURCE is not a folder: {$source}\n";
	exit( 1 );
}

/** PHP files under a folder, path => contents. @return array<string, string> */
function contract_php_files( string $dir ): array {
	$files = array();
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $items as $file ) {
		if ( '.php' === substr( (string) $file, -4 ) ) {
			$files[ (string) $file ] = (string) file_get_contents( (string) $file );
		}
	}

	return $files;
}

/** Unique first capture groups of a pattern across files. @param array<string, string> $files @return string[] */
function contract_names( array $files, string $pattern ): array {
	$names = array();
	foreach ( $files as $text ) {
		preg_match_all( $pattern, $text, $matches );
		$names = array_merge( $names, $matches[1] );
	}

	return array_values( array_unique( $names ) );
}

/** The PSPG file that declares a class or interface, or ''. @param array<string, string> $pspg */
function contract_declaring_file( array $pspg, string $type ): string {
	foreach ( $pspg as $path => $text ) {
		if ( preg_match( '/^\s*(?:final\s+|abstract\s+)?(?:class|interface)\s+' . preg_quote( $type, '/' ) . '\b/m', $text ) ) {
			return $path;
		}
	}

	return '';
}

/** @param array<string, string> $pspg */
function contract_has_method( array $pspg, string $type, string $method ): bool {
	$file = contract_declaring_file( $pspg, $type );

	return '' !== $file && 1 === preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/', $pspg[ $file ] );
}

/** The return type PSPG declares for one of its methods, or ''. @param array<string, string> $pspg */
function contract_return_type( array $pspg, string $type, string $method ): string {
	$file = contract_declaring_file( $pspg, $type );
	if ( '' === $file || ! preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\([^)]*\)\s*:\s*\??([A-Za-z_]+)/', $pspg[ $file ], $match ) ) {
		return '';
	}

	return $match[1];
}

$ours = contract_php_files( dirname( __DIR__ ) . '/includes' );
$pspg = contract_php_files( $source );
$all  = implode( "\n", $pspg );

preg_match( "/define\(\s*'PSPG_VERSION',\s*'([^']+)'/", $all, $version );
echo 'PSPG ' . ( $version[1] ?? 'version unknown' ) . " from {$source}\n";

run_tests(
	array(
		'every pspg_ hook this plugin listens to is fired by PSPG' => static function () use ( $ours, $all ): void {
			$hooks = contract_names( $ours, "/add_(?:action|filter)\(\s*'(pspg_[a-z0-9_]+)'/" );
			assert_true( count( $hooks ) >= 6, 'The hooks must be found in this plugin before they can be checked.' );

			foreach ( $hooks as $hook ) {
				assert_true(
					1 === preg_match( '/(?:do_action|apply_filters)\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $all ),
					"PSPG no longer fires {$hook}, so this plugin's handler for it never runs."
				);
			}
		},

		'every pspg_ function this plugin checks for is defined by PSPG' => static function () use ( $ours, $all ): void {
			$functions = contract_names( $ours, "/function_exists\(\s*'(pspg_[a-z0-9_]+)'/" );
			assert_true( count( $functions ) >= 5, 'The functions must be found in this plugin before they can be checked.' );

			foreach ( $functions as $function ) {
				assert_true( 1 === preg_match( '/function\s+' . preg_quote( $function, '/' ) . '\s*\(/', $all ), "PSPG no longer defines {$function}()." );
			}
		},

		'every PSPG class and static method this plugin checks for exists' => static function () use ( $ours, $pspg ): void {
			foreach ( contract_names( $ours, "/(?:class|interface)_exists\(\s*'(PSPG_[A-Za-z0-9_]+)'/" ) as $type ) {
				assert_true( '' !== contract_declaring_file( $pspg, $type ), "PSPG no longer declares {$type}." );
			}

			foreach ( $ours as $text ) {
				preg_match_all( "/method_exists\(\s*'(PSPG_[A-Za-z0-9_]+)',\s*'([a-z0-9_]+)'/", $text, $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) {
					assert_true( contract_has_method( $pspg, $match[1], $match[2] ), "PSPG's {$match[1]} no longer has {$match[2]}()." );
				}
			}
		},

		'the PSPG services this plugin reaches through pspg_core() have the methods it calls' => static function () use ( $pspg ): void {
			// The same map as PSPGD_PSPG::missing_pspg_parts().
			foreach ( array( 'config' => 'get', 'banks' => 'all', 'orders' => 'get' ) as $service => $method ) {
				$type = contract_return_type( $pspg, 'PSPG_Core', $service );
				assert_true( '' !== $type, "PSPG_Core no longer has a typed {$service}() method." );
				assert_true( contract_has_method( $pspg, $type, $method ), "PSPG's {$type}, from pspg_core()->{$service}(), no longer has {$method}()." );
			}
		},
	)
);
