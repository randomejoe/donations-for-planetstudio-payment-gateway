<?php
/**
 * The main plugin file loads every class the plugin calls.
 *
 * The other tests require the classes they need one by one, so a class the
 * main file forgets still passes all of them -- and then fatals on the live
 * site the first time it is reached. This reads the main file the way PHP
 * would and checks it against what the code actually uses.
 *
 * Run from the plugin folder: php tests/plugin-load-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

/** The includes/ paths the main plugin file requires, in order. @return string[] */
function load_test_required_files(): array {
	$main = (string) file_get_contents( dirname( __DIR__ ) . '/donations-for-planetstudio-payment-gateway.php' );
	preg_match_all( "/require_once PSPGD_DIR \. '([^']+)'/", $main, $matches );

	return $matches[1];
}

/** Every PHP file under includes/, except locale packs, which are loaded on demand. @return string[] */
function load_test_include_files(): array {
	$root  = dirname( __DIR__ ) . '/';
	$files = array();
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'includes', FilesystemIterator::SKIP_DOTS ) );

	foreach ( $items as $file ) {
		$relative = substr( (string) $file, strlen( $root ) );
		if ( '.php' === substr( $relative, -4 ) && 0 !== strpos( $relative, 'includes/locales/' ) ) {
			$files[] = $relative;
		}
	}

	sort( $files );

	return $files;
}

run_tests(
	array(
		'the main plugin file requires every class file' => static function (): void {
			$required = load_test_required_files();

			foreach ( load_test_include_files() as $file ) {
				assert_true( in_array( $file, $required, true ), $file . ' is never required by the main plugin file.' );
			}
		},

		'every class the plugin calls is declared in a file the main file loads' => static function (): void {
			$root     = dirname( __DIR__ ) . '/';
			$declared = array();
			foreach ( load_test_required_files() as $file ) {
				assert_true( is_file( $root . $file ), 'The main plugin file requires ' . $file . ', which does not exist.' );
				preg_match_all( '/^\s*(?:final\s+)?class\s+(PSPGD_\w+)/m', (string) file_get_contents( $root . $file ), $matches );
				$declared = array_merge( $declared, $matches[1] );
			}

			$sources = array( 'donations-for-planetstudio-payment-gateway.php', 'uninstall.php' );
			$sources = array_merge( $sources, load_test_include_files() );
			foreach ( $sources as $file ) {
				preg_match_all( '/\b(PSPGD_[A-Z]\w*)\s*::|new\s+(PSPGD_[A-Z]\w*)/', (string) file_get_contents( $root . $file ), $matches );
				foreach ( array_filter( array_merge( $matches[1], $matches[2] ) ) as $class ) {
					assert_true( in_array( $class, $declared, true ), $file . ' calls ' . $class . ', which the main plugin file never loads.' );
				}
			}
		},

		'the test database knows every table the installer creates' => static function (): void {
			$schema = test_installer_schema();

			assert_same( array( 'pspgd_donors', 'pspgd_donations', 'pspgd_subscriptions', 'pspgd_events' ), array_keys( $schema ), 'If the installer cannot be read, no test checks its columns.' );
			assert_true( in_array( 'binding_id', $schema['pspgd_subscriptions'], true ), 'Columns are read from each CREATE TABLE.' );
		},

		'a donation and its bank order are written to columns the installer creates' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			$donation_id = PSPGD_Repository::create_donation(
				array(
					'donor_id' => 3,
					'amount'   => '5000',
					'currency' => 'AMD',
					'bank_id'  => 'arca_acba',
					'type'     => 'one_time',
				)
			);
			PSPGD_Repository::attach_pspg_order( $donation_id, 55, 'bank-order-1' );

			assert_same( 101, $donation_id, 'The donation row is written.' );
			assert_same( 'bank-order-1', $wpdb->updates[0]['data']['bank_order_id'], 'The bank order it was registered under is stored with it.' );
		},

		'a recurring sign-up writes only columns the installer creates' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			$subscription_id = PSPGD_Repository::create_subscription(
				array(
					'donor_id'     => 3,
					'email'        => 'donor@example.test',
					'amount'       => '5000',
					'currency'     => 'AMD',
					'bank_id'      => 'arca_acba',
					'interval_key' => 'monthly',
				)
			);
			PSPGD_Repository::set_subscription_client_id( $subscription_id, 'pspgd-subscription-' . $subscription_id );

			assert_same( 101, $subscription_id, 'The subscription row is written.' );
			assert_same( 'pspgd-subscription-101', $wpdb->updates[0]['data']['client_id'], 'The client id the card is bound under is stored with it.' );
		},

		'deleting the plugin with its data removes every option it stores' => static function (): void {
			$root = dirname( __DIR__ ) . '/';
			require_once $root . 'includes/class-pspgd-installer.php';
			$method    = new ReflectionMethod( 'PSPGD_Installer', 'uninstall_site' );
			$lines     = file( (string) $method->getFileName() );
			$uninstall = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

			foreach ( array_merge( array( 'donations-for-planetstudio-payment-gateway.php' ), load_test_include_files() ) as $file ) {
				// Options written directly, and those the settings screens
				// collect in an $options map before saving.
				preg_match_all( "/(?:(?:get|update|add)_option\\(\\s*|\\\$options\\[\\s*)'(pspgd_[a-z_]+)'/", (string) file_get_contents( $root . $file ), $matches );
				foreach ( array_unique( $matches[1] ) as $option ) {
					// Exchange-rate caches are removed by prefix.
					if ( 0 === strpos( $option, 'pspgd_exchange_rate_' ) ) {
						continue;
					}
					assert_contains( "'" . $option . "'", $uninstall, $file . ' stores ' . $option . ', which uninstalling leaves behind.' );
				}
			}
		},

		'an update is applied on the next request, not only in wp-admin' => static function (): void {
			$main = (string) file_get_contents( dirname( __DIR__ ) . '/donations-for-planetstudio-payment-gateway.php' );

			assert_contains( 'PSPGD_Installer::maybe_upgrade();', $main, 'The tables are brought up to date wherever the plugin loads.' );
			assert_not_contains( "'admin_init'", $main, 'A donor must not wait for an administrator to log in.' );
		},
	)
);
