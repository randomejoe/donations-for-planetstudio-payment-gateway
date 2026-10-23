<?php
/**
 * Donor language: normalisation, and the email templates resolving in a
 * language that was chosen when the donor filled the form rather than
 * whatever the sending request happens to look like.
 *
 * Run from the plugin folder: php tests/donor-language-test.php
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * The plugin's own textdomain helper. Deliberately NOT defined here as a
 * Polylang stub: these tests run with no Polylang and no page, exactly like
 * a cron run or a bank callback, which is the context the language bug
 * lived in.
 */
function apply_filters( string $hook, $value ) {
	return $value;
}

function determine_locale(): string {
	return $GLOBALS['test_site_locale'] ?? 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

$GLOBALS['test_site_locale'] = 'en_US';

require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-cancel-links.php';

run_tests(
	array(
		'locale strings are reduced to a supported language' => static function (): void {
			assert_same( 'hy', PSPGD_I18n::normalize_locale( 'hy' ), 'A bare language code is already usable.' );
			assert_same( 'hy', PSPGD_I18n::normalize_locale( 'hy_AM' ), 'WordPress locales carry a region.' );
			assert_same( 'hy', PSPGD_I18n::normalize_locale( 'hy-AM' ), 'Browser and Polylang tags use a hyphen.' );
			assert_same( 'en', PSPGD_I18n::normalize_locale( 'en_GB' ), 'A supported language with an unfamiliar region still resolves.' );
		},

		'an unknown or empty language falls back rather than reaching a template lookup' => static function (): void {
			assert_same( 'en', PSPGD_I18n::normalize_locale( '' ), 'A donor recorded before languages were stored still gets an email.' );
			assert_same( 'en', PSPGD_I18n::normalize_locale( 'ru_RU' ), 'An unsupported language must not produce an empty template.' );
			assert_same( 'en', PSPGD_I18n::normalize_locale( 'nonsense' ), 'Posted values are validated, not trusted.' );
		},

		'form labels can be resolved in a language other than the current one' => static function (): void {
			test_reset_options();

			assert_same( 'Donate', PSPGD_I18n::t( 'donate', 'Donate', 'en' ), 'English is the baseline.' );
			assert_same( 'Նվիրաբերել', PSPGD_I18n::t( 'donate', 'Donate', 'hy' ), 'Armenian labels must be reachable by request.' );
		},

		'every message the form shows a donor is editable and translated' => static function (): void {
			$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/public/class-pspgd-form.php' );
			preg_match_all( "/translate\\( '([a-z_]+)', '((?:[^'\\\\]|\\\\.)*)'/", $source, $calls, PREG_SET_ORDER );
			$english  = PSPGD_I18n::english_labels();
			$armenian = PSPGD_I18n::pack( 'hy' )['labels'];

			assert_true( count( $calls ) > 10, 'The form\'s wording is found.' );
			foreach ( $calls as $call ) {
				list( , $key, $fallback ) = $call;
				assert_true( isset( $english[ $key ] ), $key . ' is not in PSPGD_I18n::english_labels(), so it cannot be edited on the settings screen.' );
				assert_same( stripslashes( $fallback ), $english[ $key ], $key . ' says one thing in the form and another on the settings screen.' );
				assert_true( isset( $armenian[ $key ] ), $key . ' has no Armenian wording, so an Armenian donor reads it in English.' );
			}
		},

		'an Armenian donor gets Armenian email with no request context at all' => static function (): void {
			test_reset_options();

			// No Polylang, no page: this is a cron run.
			assert_same(
				'Ձեր կրկնվող նվիրատվությունը ակտիվ է',
				PSPGD_Emails::template_value( 'recurring_activation_subject', array(), 'hy' ),
				'This is the bug: follow-up emails used to fall back to English here.'
			);
		},

		'the same template still resolves in English for an English donor' => static function (): void {
			test_reset_options();

			assert_same(
				'Your recurring donation is active',
				PSPGD_Emails::template_value( 'recurring_activation_subject', array(), 'en' ),
				'Passing a locale must not change what an English donor receives.'
			);
		},

		'placeholders are filled in whichever language is chosen' => static function (): void {
			test_reset_options();

			assert_contains(
				'5,000 AMD',
				PSPGD_Emails::template_value(
					'recurring_activation_body',
					array( 'amount_formatted' => '5,000 AMD' ),
					'hy'
				),
				'A translated body must still carry the donation figures.'
			);
		},

		'templates saved with the older placeholders keep working' => static function (): void {
			test_reset_options(
				array(
					'pspgd_email_templates' => array(
						'hy' => array( 'recurring_activation_body' => 'Ձեր {amount} {currency} նվիրատվությունը ակտիվ է։' ),
					),
				)
			);

			assert_contains(
				'5000.00 AMD',
				PSPGD_Emails::template_value(
					'recurring_activation_body',
					array( 'amount' => '5000.00', 'currency' => 'AMD' ),
					'hy'
				),
				'Wording an editor saved before the placeholder set grew must not start rendering literal braces.'
			);
		},

		'an admin override wins over the built-in wording, per language' => static function (): void {
			test_reset_options(
				array(
					'pspgd_email_templates' => array(
						'hy' => array( 'recurring_cancel_subject' => 'Չեղարկված' ),
					),
				)
			);

			assert_same( 'Չեղարկված', PSPGD_Emails::template_value( 'recurring_cancel_subject', array(), 'hy' ), 'Saved Armenian wording must be used.' );
			assert_same( 'Recurring donation cancelled', PSPGD_Emails::template_value( 'recurring_cancel_subject', array(), 'en' ), 'An Armenian override must not leak into English mail.' );
		},

		'a blank override falls through to the built-in wording' => static function (): void {
			test_reset_options(
				array(
					'pspgd_email_templates' => array(
						'hy' => array( 'recurring_cancel_subject' => '   ' ),
					),
				)
			);

			assert_same(
				'Կրկնվող նվիրատվությունը չեղարկված է',
				PSPGD_Emails::template_value( 'recurring_cancel_subject', array(), 'hy' ),
				'Clearing a field in the admin means "use the default", not "send an empty subject".'
			);
		},

		'an unsupported stored language still produces a usable email' => static function (): void {
			test_reset_options();

			assert_same(
				'Your recurring donation is active',
				PSPGD_Emails::template_value( 'recurring_activation_subject', array(), 'ru' ),
				'A language nobody translated must degrade to the default, not to nothing.'
			);
		},

		'with no stored language the site locale decides' => static function (): void {
			$GLOBALS['test_site_locale'] = 'hy_AM';
			assert_same( 'hy', PSPGD_I18n::current_locale(), 'An Armenian site should not be writing to donors in English.' );

			$GLOBALS['test_site_locale'] = 'en_US';
			assert_same( 'en', PSPGD_I18n::current_locale(), 'And back again.' );
		},
	)
);
