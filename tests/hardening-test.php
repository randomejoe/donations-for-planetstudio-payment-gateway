<?php
/**
 * Amount limits, the anti-bot timing token, event redaction and the
 * donation-log filters.
 *
 * Run from the plugin folder: php tests/hardening-test.php
 */

require_once __DIR__ . '/bootstrap.php';

function apply_filters( string $hook, $value ) {
	return $value;
}
function determine_locale(): string {
	return 'en_US';
}
function get_locale(): string {
	return 'en_US';
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-value';
}
function wp_create_nonce( string $action = '-1' ): string {
	return substr( hash_hmac( 'md5', $action, wp_salt( 'nonce' ) ), 0, 10 );
}

require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

run_tests(
	array(
		'a one-unit donation is below the default minimum' => static function (): void {
			test_reset_options();
			$minimums = PSPGD_I18n::min_amounts();

			assert_true( $minimums['AMD'] > 1, 'A 1 AMD donation costs more in card fees than it raises.' );
			assert_same( 500.0, $minimums['AMD'], 'The AMD floor should be a real amount.' );
			assert_same( 2.0, $minimums['USD'], 'The USD floor should be a real amount.' );
		},

		'an administrator can set their own minimums' => static function (): void {
			test_reset_options( array( 'pspgd_min_amounts' => array( 'AMD' => 2000, 'USD' => 10, 'EUR' => 10 ) ) );

			assert_same( 2000.0, PSPGD_I18n::min_amounts()['AMD'], 'A saved minimum must be used.' );
		},

		'a minimum at or above the maximum falls back instead of closing the form' => static function (): void {
			test_reset_options(
				array(
					'pspgd_min_amounts' => array( 'AMD' => 9000000 ),
					'pspgd_max_amounts' => array( 'AMD' => 5000000 ),
				)
			);

			assert_same(
				500.0,
				PSPGD_I18n::min_amounts()['AMD'],
				'A minimum above the maximum would reject every donation, so it must not be honoured.'
			);
		},

		'a nonsense minimum falls back to the default' => static function (): void {
			test_reset_options( array( 'pspgd_min_amounts' => array( 'AMD' => -5, 'USD' => 0 ) ) );

			assert_same( 500.0, PSPGD_I18n::min_amounts()['AMD'], 'A negative floor is not a floor.' );
			assert_same( 2.0, PSPGD_I18n::min_amounts()['USD'], 'A zero floor is not a floor.' );
		},

		'a donor name cannot become a spreadsheet formula in the export' => static function (): void {
			require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin-donations.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin-email-settings.php';

			foreach ( array( '=HYPERLINK("https://example.test","x")', '+1', '-2+3', '@SUM(A1)', "\t=1", "\r=1" ) as $typed ) {
				assert_same( "'" . $typed, PSPGD_Admin_Donations::csv_text( $typed ), 'A cell starting with ' . json_encode( $typed[0] ) . ' is a formula to Excel.' );
			}
			assert_same( 'Anna', PSPGD_Admin_Donations::csv_text( 'Anna' ), 'An ordinary name is left alone.' );
			assert_same( 'Ann-Marie', PSPGD_Admin_Donations::csv_text( 'Ann-Marie' ), 'Only the first character matters.' );
			assert_same( '', PSPGD_Admin_Donations::csv_text( '' ), 'An empty cell stays empty.' );
		},

		'every administrator can manage donations, and nobody else unless granted' => static function (): void {
			require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin-donations.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin-email-settings.php';

			$admin = PSPGD_Admin::grant_capability( array( 'manage_options' => true, 'edit_posts' => true ) );
			assert_true( ! empty( $admin[ PSPGD_Admin::CAPABILITY ] ), 'An administrator sees the donation log without a role editor.' );

			$editor = PSPGD_Admin::grant_capability( array( 'edit_posts' => true ) );
			assert_false( ! empty( $editor[ PSPGD_Admin::CAPABILITY ] ), 'An editor does not see donor details by default.' );

			$staff = PSPGD_Admin::grant_capability( array( 'read' => true, PSPGD_Admin::CAPABILITY => true ) );
			assert_true( ! empty( $staff[ PSPGD_Admin::CAPABILITY ] ), 'A role given the capability keeps it.' );
			assert_false( ! empty( $staff['manage_options'] ), 'And gets nothing more: the settings stay with administrators.' );
		},

		'the staff address list keeps only real addresses, once each' => static function (): void {
			require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin-donations.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin-email-settings.php';

			assert_same(
				'one@example.test, two@example.test',
				PSPGD_Admin_Email_Settings::sanitize_email_list( " one@example.test;not-an-address,\ntwo@example.test  one@example.test " ),
				'Commas, semicolons, spaces and new lines all separate addresses.'
			);
			assert_same( '', PSPGD_Admin_Email_Settings::sanitize_email_list( '' ), 'Blank means no staff emails.' );
		},

		'a freshly rendered form reads as just-opened' => static function (): void {
			require_once dirname( __DIR__ ) . '/includes/public/class-pspgd-form.php';

			$token = PSPGD_Form::form_timestamp();

			assert_same( 0, PSPGD_Form::timestamp_age( $token, (int) explode( '.', $token )[0] ), 'A form submitted instantly is zero seconds old.' );
		},

		'the form fetches its tokens instead of printing them into cacheable HTML' => static function (): void {
			require_once dirname( __DIR__ ) . '/includes/public/class-pspgd-form.php';

			$token  = PSPGD_Form::form_token();
			$issued = (int) explode( '.', $token['form_time'] )[0];

			assert_true( '' !== $token['nonce'], 'The endpoint has to hand out a nonce.' );
			assert_same( 0, PSPGD_Form::timestamp_age( $token['form_time'], $issued ), 'The pair is issued when a donor opens the form, not when the page was cached.' );

			// The regression this guards: a nonce printed into the form is
			// frozen into whatever copy of the page a cache is holding, and
			// every donor then submits the same stale one.
			$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/public/class-pspgd-form.php' );
			assert_true( false === strpos( $source, 'wp_nonce_field(' ), 'The nonce must not be rendered into the form markup.' );
		},

		'the age of a rendered form is measured, not trusted' => static function (): void {
			$token  = PSPGD_Form::form_timestamp();
			$issued = (int) explode( '.', $token )[0];

			assert_same( 45, PSPGD_Form::timestamp_age( $token, $issued + 45 ), 'Time on the form is what separates a person from a script.' );
		},

		'a tampered timestamp is refused rather than believed' => static function (): void {
			$token  = PSPGD_Form::form_timestamp();
			$issued = (int) explode( '.', $token )[0];
			$forged = ( $issued - 600 ) . '.' . explode( '.', $token )[1];

			assert_same( null, PSPGD_Form::timestamp_age( $forged ), 'Backdating the token must not buy a bot the wait it skipped.' );
		},

		'an invented or missing token is refused' => static function (): void {
			assert_same( null, PSPGD_Form::timestamp_age( '' ), 'A missing token is not a valid one.' );
			assert_same( null, PSPGD_Form::timestamp_age( '1789050000' ), 'An unsigned timestamp is not a valid token.' );
			assert_same( null, PSPGD_Form::timestamp_age( 'abc.def' ), 'Rubbish is not a valid token.' );
		},

		'bank payloads are reduced to known fields before they are stored' => static function (): void {
			$clean = PSPGD_Repository::redact_context(
				array(
					'ok'          => true,
					'orderId'     => 'bank-order-1',
					'cardholder'  => 'ALEX SMITH',
					'pan'         => '4111111111111111',
					'expiry'      => '12/29',
				)
			);

			assert_same( true, $clean['ok'], 'The outcome is worth keeping.' );
			assert_same( 'bank-order-1', $clean['orderId'], 'The order reference is worth keeping.' );
			assert_false( array_key_exists( 'cardholder', $clean ), 'A field nobody decided to keep must be dropped by default.' );
			assert_false( array_key_exists( 'pan', $clean ), 'Card data must never reach the event log.' );
			assert_false( array_key_exists( 'expiry', $clean ), 'Card data must never reach the event log.' );
		},

		'lists inside a payload keep their shape' => static function (): void {
			$clean = PSPGD_Repository::redact_context( array( 'bank_ids' => array( 'a', 'b' ) ) );

			assert_same( array( 'a', 'b' ), $clean['bank_ids'], 'A list of identifiers is not a map to be filtered by key.' );
		},

		'a very long value is truncated rather than stored whole' => static function (): void {
			$clean = PSPGD_Repository::redact_context( array( 'message' => str_repeat( 'x', 900 ) ) );

			assert_true( strlen( $clean['message'] ) < 600, 'One enormous bank message must not become one enormous row.' );
		},

		'a deeply nested payload is cut off' => static function (): void {
			$clean = PSPGD_Repository::redact_context(
				array( 'status' => array( 'status' => array( 'status' => array( 'status' => array( 'status' => 'deep' ) ) ) ) )
			);

			assert_same( null, $clean['status']['status']['status']['status'], 'Depth has to be bounded or a recursive payload can be stored forever.' );
		},

		'the log and its count filter on exactly the same conditions' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			$filters = array( 'status' => 'paid', 'currency' => 'usd', 'search' => 'anna' );
			PSPGD_Repository::list_donations( 20, 0, $filters );
			PSPGD_Repository::count_donations( $filters );

			assert_contains( "d.status = 'paid'", $wpdb->reads[0], 'The list must filter by status.' );
			assert_contains( "d.currency = 'USD'", $wpdb->reads[0], 'Currency is compared in upper case.' );
			assert_contains( 'donor.email LIKE', $wpdb->reads[0], 'Search covers the donor email.' );
			assert_contains( "d.status = 'paid'", $wpdb->reads[1], 'A count that filters differently makes paging lie.' );
		},

		'an unfiltered log asks for everything' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::list_donations( 20, 0 );

			assert_not_contains( 'WHERE', $wpdb->reads[0], 'With no filters there is nothing to narrow.' );
		},

		'a date range is expanded to cover the whole end day' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::list_donations( 20, 0, array( 'date_from' => '2026-08-01', 'date_to' => '2026-08-31' ) );

			assert_contains( "'2026-08-01 00:00:00'", $wpdb->reads[0], 'A range starts at the beginning of its first day.' );
			assert_contains( "'2026-08-31 23:59:59'", $wpdb->reads[0], 'A range that stopped at midnight would silently drop the last day.' );
		},

		'only known columns can be used for filter dropdowns' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			assert_same( array(), PSPGD_Repository::distinct_donation_values( 'email' ), 'An unlisted column must not reach the query.' );
			assert_same( 0, count( $wpdb->reads ), 'A rejected column must not hit the database at all.' );
		},

		'erasing a donor clears the identity and keeps the record' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::anonymize_donor( 12 );

			assert_same( '', $wpdb->updates[0]['data']['first_name'], 'The name is what identifies a person.' );
			assert_contains( '@erased.invalid', $wpdb->updates[0]['data']['email'], 'The replacement address must be unmailable and unmatchable.' );
			assert_same( 12, $wpdb->updates[0]['where']['id'], 'Only that donor is anonymised.' );
			assert_contains( '@erased.invalid', $wpdb->updates[1]['data']['email'], 'The address copied onto their subscriptions must go too.' );
		},
	)
);
