<?php
/**
 * What the donation form accepts, and what it refuses before anything is
 * written or the bank is contacted.
 *
 * PSPGD_Form::validate_submission() holds every check on the posted fields;
 * handle_submit() runs it after the nonce, the throttle and the bot checks,
 * and sends a refusal back to the form. Each refusal here is one a donor
 * can meet, in the order the form checks them.
 *
 * Run from the plugin folder: php tests/form-validation-test.php
 */

/** @return object|null A campaign post from $GLOBALS['form_posts'], or null. */
function get_post( int $id ) {
	return $GLOBALS['form_posts'][ $id ] ?? null;
}

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';
require_once dirname( __DIR__ ) . '/includes/public/class-pspgd-form.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

/** PSPG's ArCa adapter, which is what makes a bank able to take recurring donations here. */
final class PSPG_Bank_ArCa {
	public function supports(): array {
		return array( 'binding' => true, 'subs' => true );
	}
}

/** A wallet: takes one-time payments, saves no cards. */
final class Form_Test_Wallet {
	public function supports(): array {
		return array();
	}
}

/** @return object|null */
function pspg_get_bank( string $bank_id ) {
	if ( 'arca' === $bank_id ) {
		return new PSPG_Bank_ArCa();
	}

	return 'idram' === $bank_id ? new Form_Test_Wallet() : null;
}

/** A live site taking AMD and USD through ArCa, with no campaigns. */
function form_reset(): void {
	test_reset_options();
	test_reset_filters();
	test_reset_posts();
	$GLOBALS['form_posts'] = array();

	update_option( 'pspgd_test_mode', '0' );
	update_option( 'pspgd_bank_id', 'arca' );
	update_option( 'pspgd_currencies', array( 'AMD', 'USD' ) );
	update_option( 'pspgd_min_amounts', array( 'AMD' => 500 ) );
	update_option( 'pspgd_max_amounts', array( 'AMD' => 1000000 ) );
}

/** A complete, valid one-time donation, with any field replaced. */
function form_post( array $changes = array() ): array {
	return array_merge(
		array(
			'amount'        => '5000',
			'currency'      => 'AMD',
			'donation_type' => 'one_time',
			'first_name'    => 'Ani',
			'last_name'     => 'Test',
			'email'         => 'ani@example.com',
			'consent'       => '1',
		),
		$changes
	);
}

function form_campaign( int $id, string $status = 'publish', array $meta = array() ): void {
	$GLOBALS['form_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_type'   => 'pspgd_campaign',
		'post_status' => $status,
	);
	test_add_campaign( $id, $meta );
}

/** The error key a post is refused with, or 'ok'. */
function form_outcome( array $post, string $language = 'en' ): string {
	$check = PSPGD_Form::validate_submission( $post, $language );

	return empty( $check['ok'] ) ? (string) $check['error'] : 'ok';
}

run_tests(
	array(
		'a complete donation comes back cleaned' => static function (): void {
			form_reset();

			$check = PSPGD_Form::validate_submission(
				form_post(
					array(
						'amount'       => '1500,50',
						'currency'     => 'usd',
						'first_name'   => ' Ani <b>',
						'is_anonymous' => '1',
					)
				),
				'en'
			);

			assert_true( (bool) $check['ok'], 'Nothing here should be refused.' );
			assert_same( 1500.5, $check['amount'], 'A decimal comma is read as a decimal point.' );
			assert_same( 'USD', $check['currency'], 'The currency code is upper-cased.' );
			assert_same( 'Ani', $check['first_name'], 'Markup and stray spaces are stripped from names.' );
			assert_same( array( 'arca', 'one_time', 0, true, false ), array( $check['bank_id'], $check['donation_type'], $check['campaign_id'], $check['is_anonymous'], $check['test_mode'] ), 'Bank, frequency, campaign and flags.' );
		},

		'an amount must be positive and in an offered currency' => static function (): void {
			form_reset();

			assert_same( 'error_amount', form_outcome( form_post( array( 'amount' => '0' ) ) ), 'Nothing to charge.' );
			assert_same( 'error_amount', form_outcome( form_post( array( 'amount' => '-50' ) ) ), 'A negative amount.' );
			assert_same( 'error_amount', form_outcome( form_post( array( 'currency' => 'EUR' ) ) ), 'A currency the site does not offer.' );
			assert_same( 'error_amount', form_outcome( form_post( array( 'amount' => array( '5000' ) ) ) ), 'A field posted as a list is not an amount.' );
		},

		'the minimum and maximum for the currency are enforced' => static function (): void {
			form_reset();

			assert_same( 'error_min', form_outcome( form_post( array( 'amount' => '499' ) ) ), 'Below the minimum.' );
			assert_same( 'ok', form_outcome( form_post( array( 'amount' => '500' ) ) ), 'The minimum itself is allowed.' );
			assert_same( 'ok', form_outcome( form_post( array( 'amount' => '1000000' ) ) ), 'So is the maximum.' );
			assert_same( 'error_max', form_outcome( form_post( array( 'amount' => '1000000.01' ) ) ), 'Above it is not.' );

			$check = PSPGD_Form::validate_submission( form_post( array( 'amount' => '100' ) ), 'en' );
			assert_contains( '500', (string) $check['message'], 'The donor is told what the minimum is.' );
		},

		'a receipt address and consent are required' => static function (): void {
			form_reset();

			assert_same( 'error_email', form_outcome( form_post( array( 'email' => '' ) ) ), 'No email.' );
			assert_same( 'error_email', form_outcome( form_post( array( 'email' => 'not-an-address' ) ) ), 'Not an email.' );
			assert_same( 'error_consent', form_outcome( form_post( array( 'consent' => '' ) ) ), 'Box not ticked.' );
			assert_same( 'error_consent', form_outcome( form_post( array( 'consent' => '0' ) ) ), 'A posted 0 is not consent.' );
		},

		'a live site needs a bank that takes the currency' => static function (): void {
			form_reset();
			update_option( 'pspgd_bank_id', '' );
			assert_same( 'error_not_ready', form_outcome( form_post() ), 'No bank chosen yet.' );

			update_option( 'pspgd_bank_id', 'nobank' );
			assert_same( 'error_currency', form_outcome( form_post() ), 'A bank PSPG does not know cannot take the payment.' );

			update_option( 'pspgd_test_mode', '1' );
			assert_same( 'ok', form_outcome( form_post() ), 'Test mode contacts no bank, so it needs none.' );
		},

		'the frequency must be one the site offers' => static function (): void {
			form_reset();

			assert_same( 'ok', form_outcome( form_post( array( 'donation_type' => 'monthly' ) ) ), 'Monthly through ArCa.' );
			assert_same( 'error_frequency', form_outcome( form_post( array( 'donation_type' => 'yearly' ) ) ), 'Not a frequency this plugin has.' );
			assert_same( 'error_frequency', form_outcome( form_post( array( 'donation_type' => '' ) ) ), 'An empty frequency is not one-time.' );
			assert_same( 'ok', form_outcome( array_diff_key( form_post(), array( 'donation_type' => 1 ) ) ), 'A form without the field is one-time.' );

			update_option( 'pspgd_intervals', array( 'monthly' ) );
			assert_same( 'error_frequency', form_outcome( form_post( array( 'donation_type' => 'weekly' ) ) ), 'Weekly, once the site has switched it off.' );
		},

		'recurring donations need them switched on and an ArCa-network bank' => static function (): void {
			form_reset();
			$monthly = form_post( array( 'donation_type' => 'monthly' ) );

			update_option( 'pspgd_bank_id', 'idram' );
			assert_same( 'error_recurring', form_outcome( $monthly ), 'A wallet saves no card to charge next month.' );
			assert_same( 'ok', form_outcome( form_post() ), 'It still takes one-time donations.' );

			update_option( 'pspgd_test_mode', '1' );
			assert_same( 'ok', form_outcome( $monthly ), 'Test mode simulates the saved card.' );

			update_option( 'pspgd_recurring_enabled', '0' );
			assert_same( 'error_recurring', form_outcome( $monthly ), 'Switched off is off, test mode or not.' );
		},

		'a campaign must exist and still be open' => static function (): void {
			form_reset();
			form_campaign( 7 );
			form_campaign( 8, 'trash' );
			form_campaign( 9, 'publish', array( '_pspgd_campaign_closed_reason' => 'goal_reached' ) );
			form_campaign( 10, 'publish', array( '_pspgd_campaign_deadline' => '2026-08-01' ) );
			$GLOBALS['form_posts'][11] = (object) array( 'ID' => 11, 'post_type' => 'page', 'post_status' => 'publish' );

			assert_same( 'ok', form_outcome( form_post( array( 'campaign_id' => '7' ) ) ), 'An open campaign.' );
			assert_same( 7, PSPGD_Form::validate_submission( form_post( array( 'campaign_id' => '7' ) ), 'en' )['campaign_id'], 'Its id is passed on.' );
			assert_same( 'error_campaign_closed', form_outcome( form_post( array( 'campaign_id' => '8' ) ) ), 'A trashed campaign.' );
			assert_same( 'error_campaign_closed', form_outcome( form_post( array( 'campaign_id' => '11' ) ) ), 'A page is not a campaign.' );
			assert_same( 'error_campaign_closed', form_outcome( form_post( array( 'campaign_id' => '12' ) ) ), 'A campaign that does not exist.' );
			assert_same( 'error_campaign_closed', form_outcome( form_post( array( 'campaign_id' => '10' ) ) ), 'A campaign past its end date, before the hourly job has closed it.' );

			$check = PSPGD_Form::validate_submission( form_post( array( 'campaign_id' => '9' ) ), 'en' );
			assert_contains( 'reached its goal', (string) $check['message'], 'A funded campaign says so.' );
		},

		'a closed campaign is reported before anything wrong with the details' => static function (): void {
			form_reset();
			form_campaign( 9, 'publish', array( '_pspgd_campaign_closed_reason' => 'ended' ) );

			assert_same( 'error_campaign_closed', form_outcome( form_post( array( 'campaign_id' => '9', 'email' => '' ) ) ), 'Fixing the email would not help, so the donor hears about the campaign first.' );
		},

		'the refusal is in the language the donor used' => static function (): void {
			form_reset();

			$english  = PSPGD_Form::validate_submission( form_post( array( 'email' => '' ) ), 'en' );
			$armenian = PSPGD_Form::validate_submission( form_post( array( 'email' => '' ) ), 'hy' );

			assert_contains( 'valid email address', (string) $english['message'], 'English.' );
			assert_contains( 'էլ. փոստի', (string) $armenian['message'], 'Armenian.' );
		},
	)
);
