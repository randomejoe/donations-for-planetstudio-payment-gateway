<?php
/**
 * What the two settings screens store, from what an administrator posts.
 *
 * PSPGD_Admin::form_settings_from_post() and email_settings_from_post()
 * clean every field into the option it is saved as; the save handlers only
 * check the nonce and capability, write the result and redirect. A value
 * that is not on the screen's list -- a currency, a frequency, a network --
 * must never reach an option the form or the emails read.
 *
 * Run from the plugin folder: php tests/settings-save-test.php
 */

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
require_once dirname( __DIR__ ) . '/includes/class-pspgd-security.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin.php';

function settings_reset(): void {
	test_reset_options();
	test_reset_filters();
}

/** @return array<string, mixed> */
function settings_form( array $post ): array {
	return PSPGD_Admin::form_settings_from_post( $post );
}

run_tests(
	array(
		'checkboxes are stored as 1 when ticked and 0 otherwise' => static function (): void {
			settings_reset();

			$options = settings_form( array( 'test_mode' => '1', 'turnstile' => '0', 'delete_data_on_uninstall' => 'on' ) );

			assert_same( array( '1', '0', '0', '1' ), array( $options['pspgd_test_mode'], $options['pspgd_turnstile'], $options['pspgd_recurring_enabled'], $options['pspgd_delete_data_on_uninstall'] ), 'Ticked, posted as 0, missing, and ticked.' );
		},

		'only known currencies and frequencies are stored' => static function (): void {
			settings_reset();

			$options = settings_form(
				array(
					'currencies' => array( 'usd', 'XYZ', 'amd', array( 'EUR' ) ),
					'intervals'  => array( 'weekly', 'yearly' ),
				)
			);

			assert_same( array( 'AMD', 'USD' ), $options['pspgd_currencies'], 'Unknown codes and stray lists are dropped.' );
			assert_same( array( 'weekly' ), $options['pspgd_intervals'], 'A frequency the plugin cannot charge is dropped.' );
		},

		'unticking every currency keeps the site default rather than none' => static function (): void {
			settings_reset();
			update_option( 'pspgd_currencies', array( 'USD', 'EUR' ) );
			update_option( 'pspgd_default_currency', 'EUR' );

			assert_same( array( 'EUR' ), settings_form( array() )['pspgd_currencies'], 'The form always offers a currency.' );
		},

		'spam protection and languages are reduced to what the plugin understands' => static function (): void {
			settings_reset();

			$options = settings_form( array( 'client_ip_source' => 'x_forwarded_for', 'languages' => 'hy_AM, en-US, hy, not a locale!' ) );
			assert_same( 'x_forwarded_for', $options['pspgd_client_ip_source'], 'A known address source is kept.' );
			assert_same( array( 'hy', 'en' ), $options['pspgd_languages'], 'Locales become language codes, once each.' );

			assert_same( 'remote_addr', settings_form( array( 'client_ip_source' => 'http_client_ip' ) )['pspgd_client_ip_source'], 'An unknown source falls back to the connection address.' );
		},

		'colours must be hex and the corner radius stays between 0 and 32' => static function (): void {
			settings_reset();

			$options = settings_form( array( 'colors' => array( 'primary' => '#ABC', 'accent' => 'red', 'text' => array( '#000' ) ), 'radius' => '99' ) );

			assert_same( array( 'primary' => '#abc' ), $options['pspgd_colors'], 'Only a hex colour is stored.' );
			assert_same( 32, $options['pspgd_radius'], 'A radius above 32 is capped.' );
			assert_same( 6, settings_form( array() )['pspgd_radius'], 'No radius posted means the default.' );
		},

		'suggested amounts are parsed per shown currency, and others are kept' => static function (): void {
			settings_reset();
			update_option( 'pspgd_amount_presets', array( 'EUR' => array( 10.0 ), 'USD' => array( 5.0 ) ) );

			$options = settings_form(
				array(
					'currencies' => array( 'AMD', 'USD' ),
					'presets'    => array(
						'AMD' => '1000, 5000, abc, -5, 2500, 1, 2, 3, 4, 5, 6',
						'USD' => '',
						'EUR' => '99',
					),
				)
			);

			assert_same(
				array(
					'EUR' => array( 10.0 ),
					'AMD' => array( 1000.0, 5000.0, 2500.0, 1.0, 2.0, 3.0, 4.0, 5.0 ),
				),
				$options['pspgd_amount_presets'],
				'Positive numbers only, at most eight; an emptied currency is cleared; a currency switched off keeps its amounts.'
			);
		},

		'minimum and maximum amounts are set or cleared per shown currency' => static function (): void {
			settings_reset();
			update_option( 'pspgd_min_amounts', array( 'AMD' => 100.0, 'EUR' => 1.0 ) );

			$options = settings_form(
				array(
					'currencies'  => array( 'AMD', 'USD' ),
					'min_amounts' => array( 'AMD' => '0', 'USD' => '2.5', 'EUR' => '50' ),
					'max_amounts' => array( 'AMD' => array( '9' ) ),
				)
			);

			assert_same( array( 'EUR' => 1.0, 'USD' => 2.5 ), $options['pspgd_min_amounts'], '0 clears a minimum; a hidden currency is left as it was.' );
			assert_same( array(), $options['pspgd_max_amounts'], 'A list is not an amount.' );
		},

		'form wording keeps only known labels, as plain text' => static function (): void {
			settings_reset();

			$options = settings_form( array( 'labels' => array( 'en' => array( 'amount' => '<b>Sum</b>', 'no_such_label' => 'x' ), 'zz' => array( 'amount' => 'y' ) ) ) );
			$labels  = $options['pspgd_form_labels'];

			assert_same( 'Sum', $labels['en']['amount'], 'Markup is stripped from labels.' );
			assert_false( isset( $labels['en']['no_such_label'] ), 'A label the form does not have is dropped.' );
			assert_false( isset( $labels['zz'] ), 'A language the site does not offer is dropped.' );
		},

		'event retention defaults to 180 days and 0 keeps everything' => static function (): void {
			settings_reset();

			assert_same( 180, settings_form( array() )['pspgd_event_retention_days'], 'The default.' );
			assert_same( 0, settings_form( array( 'event_retention_days' => '0' ) )['pspgd_event_retention_days'], 'Keep everything.' );
		},

		'a field posted as a list where text belongs is stored empty' => static function (): void {
			settings_reset();

			$options = settings_form( array( 'bank_id' => array( 'arca' ), 'font_family' => array( 'Arial' ) ) );

			assert_same( array( '', '' ), array( $options['pspgd_bank_id'], $options['pspgd_font_family'] ), 'Nothing is read out of a list.' );
		},

		'email bodies keep their allowed markup; subjects and headings are plain text' => static function (): void {
			settings_reset();

			$options   = PSPGD_Admin::email_settings_from_post(
				array(
					'templates' => array(
						'en' => array(
							'donation_receipt_body'    => 'Thanks. <a href="https://example.org">Read more</a><script>x</script>',
							'donation_receipt_subject' => '<b>Thank you</b>',
							'not_a_template'           => 'x',
						),
					),
				)
			);
			$templates = $options['pspgd_email_templates']['en'];

			assert_contains( '<a href="https://example.org">', $templates['donation_receipt_body'], 'A link stays in a body.' );
			assert_not_contains( '<script>', $templates['donation_receipt_body'], 'A script tag does not.' );
			assert_same( 'Thank you', $templates['donation_receipt_subject'], 'A subject is plain text.' );
			assert_false( isset( $templates['not_a_template'] ), 'An unknown template is dropped.' );
		},

		'social links are web addresses for known networks only' => static function (): void {
			settings_reset();

			$options = PSPGD_Admin::email_settings_from_post(
				array(
					'social' => array(
						'facebook'  => ' https://facebook.com/example ',
						'instagram' => 'javascript:alert(1)',
						'myspace'   => 'https://myspace.com/example',
						'x'         => array( 'https://x.com/example' ),
					),
				)
			);

			assert_same( array( 'facebook' => 'https://facebook.com/example' ), $options['pspgd_social_links'], 'Every email footer links these, so nothing else gets in.' );
		},

		'sender and staff addresses are cleaned' => static function (): void {
			settings_reset();

			$options = PSPGD_Admin::email_settings_from_post(
				array(
					'from_address'        => 'not an address',
					'reply_to'            => 'hello@example.org',
					'staff_notify_emails' => "a@example.org, nope\nb@example.org; a@example.org",
				)
			);

			assert_same( 'hello@example.org', $options['pspgd_email_reply_to'], 'A valid reply-to is kept.' );
			assert_same( 'a@example.org, b@example.org', $options['pspgd_staff_notify_emails'], 'Staff addresses: valid ones, once each.' );
			assert_true( ! is_email( $options['pspgd_email_from_address'] ), 'An invalid sender is not stored as one.' );
		},
	)
);
