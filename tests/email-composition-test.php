<?php
/**
 * Email composition: a stable cancel link, the right template for the kind
 * of donation, and a body an editor can actually format.
 *
 * Run from the plugin folder: php tests/email-composition-test.php
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
function get_bloginfo( string $show = '' ): string {
	return 'Example Charity';
}
function wp_specialchars_decode( string $text, $quote_style = null ): string {
	return html_entity_decode( $text, ENT_QUOTES );
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-value';
}
function wpautop( string $text, bool $br = true ): string {
	return '<p>' . str_replace( "\n\n", '</p><p>', trim( $text ) ) . '</p>';
}
/** @param array<string, mixed> $allowed */
function wp_kses( string $text, array $allowed ): string {
	return strip_tags( $text, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
}
function get_post( int $id ) {
	return null;
}

require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';

/** @param array<string, mixed> $fields */
function sub( array $fields = array() ): object {
	return test_row(
		array_merge(
			array(
				'id'                => 7,
				'created_at'        => '2026-01-04 09:00:00',
				'amount'            => '10000.00',
				'currency'          => 'AMD',
				'interval_key'      => 'monthly',
				'campaign_id'       => 0,
				'language'          => 'en',
				'status'            => 'active',
				'next_payment_at'   => '2026-09-04 09:00:00',
			),
			$fields
		)
	);
}

run_tests(
	array(
		'the cancel link is the same in every email' => static function (): void {
			$first  = PSPGD_Subscriptions::cancel_url( sub() );
			$second = PSPGD_Subscriptions::cancel_url( sub() );

			assert_same( $first, $second, 'Re-issuing the token used to invalidate the link in every email already sent.' );
			assert_contains( 'pspgd_cancel=7', $first, 'The link must identify the subscription.' );
			assert_contains( 'token=', $first, 'The link must carry a token.' );
		},

		'two subscriptions never share a cancel token' => static function (): void {
			$one = PSPGD_Subscriptions::cancel_url( sub( array( 'id' => 7 ) ) );
			$two = PSPGD_Subscriptions::cancel_url( sub( array( 'id' => 8 ) ) );

			assert_true( $one !== $two, 'A token that did not depend on the subscription would cancel the wrong donation.' );
		},

		'the token depends on the signup time as well as the id' => static function (): void {
			$one = PSPGD_Subscriptions::cancel_url( sub( array( 'created_at' => '2026-01-04 09:00:00' ) ) );
			$two = PSPGD_Subscriptions::cancel_url( sub( array( 'created_at' => '2026-06-04 09:00:00' ) ) );

			assert_true( $one !== $two, 'Reusing a database id must not resurrect an old cancel link.' );
		},

		'a scheduled charge gets its own wording, not the first-donation receipt' => static function (): void {
			test_reset_options();

			$recurring = PSPGD_Subscriptions::template_value( 'recurring_receipt_heading', array(), 'en' );
			$one_time  = PSPGD_Subscriptions::template_value( 'donation_receipt_heading', array(), 'en' );

			assert_true( $recurring !== $one_time, 'A donor not at their keyboard needs to be told which recurring donation this was.' );
			assert_same( 'Your recurring donation was received', $recurring, 'The recurring receipt must exist.' );
		},

		'a composed email carries the donation details, not just the wording' => static function (): void {
			test_reset_options();

			$composed = PSPGD_Subscriptions::compose(
				'donation_receipt',
				'en',
				array( 'first_name' => 'Anna', 'amount_formatted' => '10,000 AMD' ),
				array(
					'donation_id' => '#1042',
					'date'        => '4 January 2026',
					'amount'      => '10,000 AMD',
					'reference'   => '900123456789',
				)
			);

			assert_contains( 'Hi Anna,', $composed['html'], 'The donor is greeted by name.' );
			assert_contains( '#1042', $composed['html'], 'The donation number is what a donor quotes when they ask about a charge.' );
			assert_contains( '900123456789', $composed['html'], 'The bank reference is what matches this to a statement.' );
			assert_contains( 'Bank reference', $composed['html'], 'Every detail row is labelled.' );
		},

		'details with nothing in them are left out rather than rendered blank' => static function (): void {
			test_reset_options();

			$composed = PSPGD_Subscriptions::compose(
				'donation_receipt',
				'en',
				array( 'first_name' => 'Anna' ),
				array( 'donation_id' => '#1042', 'campaign' => '', 'reference' => '   ' )
			);

			assert_not_contains( 'Campaign', $composed['html'], 'A donation with no campaign must not show an empty campaign row.' );
			assert_not_contains( 'Bank reference', $composed['html'], 'A donation with no reference yet must not show an empty row.' );
		},

		'an email with no donor name skips the greeting instead of greeting nobody' => static function (): void {
			test_reset_options();

			$composed = PSPGD_Subscriptions::compose( 'donation_receipt', 'en', array( 'first_name' => '' ) );

			assert_not_contains( 'Hello', $composed['html'], 'A missing first name must not produce "Hello ,".' );
		},

		'the details table is labelled in the donor language' => static function (): void {
			test_reset_options();

			$composed = PSPGD_Subscriptions::compose(
				'donation_receipt',
				'hy',
				array( 'first_name' => 'Անna' ),
				array( 'amount' => '10,000 AMD' )
			);

			assert_contains( 'Գումար', $composed['html'], 'An Armenian donor should not get English row labels.' );
		},

		'an editor can put a link and a line break in a body' => static function (): void {
			test_reset_options(
				array(
					'pspgd_email_templates' => array(
						'en' => array( 'donation_receipt_body' => 'Thanks.<br><a href="https://example.test/receipt">Your receipt</a>' ),
					),
				)
			);

			$composed = PSPGD_Subscriptions::compose( 'donation_receipt', 'en', array() );

			assert_contains( '<a href="https://example.test/receipt">', $composed['html'], 'A link in the body used to be escaped into visible markup.' );
			assert_contains( '<br>', $composed['html'], 'A line break in the body must survive.' );
		},

		'markup that could break the layout is stripped from a body' => static function (): void {
			test_reset_options(
				array(
					'pspgd_email_templates' => array(
						'en' => array( 'donation_receipt_body' => 'Hello<script>alert(1)</script><table><tr><td>x</td></tr></table>' ),
					),
				)
			);

			$composed = PSPGD_Subscriptions::compose( 'donation_receipt', 'en', array() );

			assert_not_contains( '<script', $composed['html'], 'The body allowlist must not admit scripts.' );
			assert_not_contains( '<td>x</td>', $composed['html'], 'A table in the body would break the surrounding layout.' );
		},

		'the subject is filled in from the same placeholders as the body' => static function (): void {
			test_reset_options();

			$composed = PSPGD_Subscriptions::compose(
				'recurring_receipt',
				'en',
				array( 'interval' => 'Monthly donation', 'amount_formatted' => '10,000 AMD' )
			);

			assert_same( 'Your Monthly donation of 10,000 AMD', $composed['subject'], 'Placeholders must resolve in subjects too.' );
		},
	)
);
