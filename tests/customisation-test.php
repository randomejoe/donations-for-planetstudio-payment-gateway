<?php
/**
 * Nothing about one organisation is built in: the bank, the currencies, the
 * languages, the colours and the logo all come from settings, and a fresh
 * install with no settings at all still produces a sensible, unbranded
 * form and email.
 *
 * Run from the plugin folder: php tests/customisation-test.php
 */

require_once __DIR__ . '/bootstrap.php';

function determine_locale(): string {
	return $GLOBALS['test_site_locale'] ?? 'en_US';
}
function get_locale(): string {
	return determine_locale();
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
	return '<p>' . trim( $text ) . '</p>';
}
/** @param array<string, mixed> $allowed */
function wp_kses( string $text, array $allowed ): string {
	return strip_tags( $text, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
}
/** @return mixed */
function get_theme_mod( string $name, $default = false ) {
	return 'custom_logo' === $name ? ( $GLOBALS['test_custom_logo'] ?? 0 ) : $default;
}
/** @return string|false */
function wp_get_attachment_image_url( int $id, string $size = 'thumbnail' ) {
	return $id > 0 ? 'https://example.org/wp-content/uploads/logo-' . $id . '.png' : false;
}

require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-cancel-links.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-capability.php';
require_once dirname( __DIR__ ) . '/includes/public/class-pspgd-form.php';

/** A fresh install: none of the settings the other tests start from. */
function customisation_fresh_install( array $options = array() ): void {
	test_reset_options(
		array_merge(
			array(
				'pspgd_currencies'    => array(),
				'pspgd_goal_currency' => '',
				'pspgd_languages'     => array(),
				'pspgd_bank_id'       => '',
			),
			$options
		)
	);
	test_reset_filters();
	PSPGD_I18n::reset_packs();
	$GLOBALS['test_site_locale'] = 'en_US';
	$GLOBALS['test_custom_logo'] = 0;
}

/** Every shipped file, as path => contents. Tests and tooling are not shipped. */
function customisation_shipped_files(): array {
	$root  = dirname( __DIR__ );
	$files = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		$relative = substr( (string) $file, strlen( $root ) + 1 );
		if ( preg_match( '#^(tests|tools|\.git)/#', $relative ) || ! preg_match( '/\.(php|js|css|txt|md|pot)$/', $relative ) ) {
			continue;
		}
		$files[ $relative ] = (string) file_get_contents( (string) $file );
	}

	return $files;
}

run_tests(
	array(
		// -------------------------------------------------------------
		// No trace of the organisation this was first built for
		// -------------------------------------------------------------

		'no shipped file carries the original organisation or its design' => static function (): void {
			$forbidden = array(
				'/frontline/i'            => 'the original plugin name',
				'/\bfyn\b/i'              => 'the organisation initials',
				'/youth/i'                => 'the organisation name',
				'/manrope/i'              => 'the original brand font',
				'/#3e0080|#b281e2|#f3ebfb|#28114a/i' => 'the original brand colours',
				'/payment2\.png/'         => 'a hardcoded media library upload',
				'/wp-content\/uploads/'   => 'a hardcoded media library path',
				'/Hakobyan/'              => 'the original sample donor',
			);

			foreach ( customisation_shipped_files() as $path => $contents ) {
				foreach ( $forbidden as $pattern => $what ) {
					assert_false( (bool) preg_match( $pattern, $contents ), sprintf( '%s still contains %s.', $path, $what ) );
				}
			}
		},

		// -------------------------------------------------------------
		// Payments
		// -------------------------------------------------------------

		'a fresh install offers AMD only and has no bank until one is chosen' => static function (): void {
			customisation_fresh_install();

			assert_same( array( 'AMD' ), PSPGD_PSPG::allowed_currencies(), 'With nothing chosen, the one currency every PSPG bank settles.' );
			assert_same( 'AMD', PSPGD_Settings::default_currency(), 'The default currency comes from what is offered.' );
			assert_same( 'AMD', PSPGD_Settings::goal_currency(), 'So does the goal currency.' );
			assert_same( '', PSPGD_PSPG::bank_id(), 'No bank is assumed.' );
			assert_false( PSPGD_PSPG::is_ready(), 'Without a bank the form must not try to take money.' );
		},

		'currencies are whatever the site ticked, in its order, and nothing it did not' => static function (): void {
			customisation_fresh_install(
				array(
					'pspgd_currencies'       => array( 'eur', 'USD', 'XYZ', 'EUR' ),
					'pspgd_default_currency' => 'USD',
					'pspgd_goal_currency'    => 'GBP',
					'pspgd_bank_id'          => 'ameria',
				)
			);

			assert_same( array( 'EUR', 'USD' ), PSPGD_Settings::currencies(), 'Unknown codes and duplicates are dropped; case is normalised.' );
			assert_same( 'USD', PSPGD_Settings::default_currency(), 'The chosen default is honoured when it is offered.' );
			assert_same( 'USD', PSPGD_Settings::goal_currency(), 'A goal currency that is not offered falls back to the default.' );
			assert_same( 'ameria', PSPGD_PSPG::bank_id(), 'The bank is the one chosen.' );
		},

		'amounts have sensible defaults for every offered currency' => static function (): void {
			customisation_fresh_install( array( 'pspgd_currencies' => array( 'USD', 'RUB', 'AMD' ) ) );

			$presets = PSPGD_I18n::amount_presets();
			$minimum = PSPGD_I18n::min_amounts();
			$maximum = PSPGD_I18n::max_amounts();

			assert_same( array( 'USD', 'RUB', 'AMD' ), array_keys( $presets ), 'One row of presets per offered currency, and only those.' );
			assert_true( count( $presets['RUB'] ) >= 2, 'A currency without saved presets still gets buttons.' );
			assert_true( $minimum['USD'] > 0 && $minimum['USD'] < $maximum['USD'], 'Every currency gets a usable range.' );
			assert_same( '1,000 AMD', PSPGD_I18n::format_amount( 1000.0, 'AMD' ), 'AMD is shown without a minor unit.' );
			assert_same( '1,000.00 RUB', PSPGD_I18n::format_amount( 1000.0, 'RUB' ), 'Other currencies keep two decimals.' );
		},

		// -------------------------------------------------------------
		// Languages
		// -------------------------------------------------------------

		'a single-language site is not shown columns for languages it does not use' => static function (): void {
			customisation_fresh_install();
			$GLOBALS['test_site_locale'] = 'de_DE';

			assert_same( array( 'de' ), PSPGD_I18n::supported_locales(), 'With no setting and no Polylang, the site language is the only one.' );
			assert_same( 'de', PSPGD_I18n::default_locale(), 'And it is the default.' );
			assert_same( 'Donate', PSPGD_I18n::t( 'donate', 'Donate', 'de' ), 'A language with no pack falls back to English, never to nothing.' );
		},

		'the Armenian pack is used when Armenian is one of the site languages' => static function (): void {
			customisation_fresh_install( array( 'pspgd_languages' => array( 'en', 'hy' ) ) );

			assert_same( 'Նվիրաբերել', PSPGD_I18n::t( 'donate', 'Donate', 'hy' ), 'The bundled pack supplies the label.' );
			assert_same( 'Ամսաթիվ', PSPGD_I18n::email_label( 'date', 'hy' ), 'And the email structure.' );
			assert_true( '' !== PSPGD_Emails::default_template( 'donation_receipt_subject', 'hy' ), 'And the email wording.' );
		},

		'another plugin can add a language pack' => static function (): void {
			customisation_fresh_install( array( 'pspgd_languages' => array( 'en', 'de' ) ) );
			test_set_filter(
				'pspgd_locale_pack',
				static function ( array $pack, string $code ): array {
					return 'de' === $code ? array( 'labels' => array( 'donate' => 'Spenden' ), 'templates' => array( 'donation_receipt_subject' => 'Danke für Ihre Spende' ) ) : $pack;
				}
			);

			assert_same( 'Spenden', PSPGD_I18n::t( 'donate', 'Donate', 'de' ), 'A filtered pack supplies labels.' );
			assert_same( 'Danke für Ihre Spende', PSPGD_Emails::template_value( 'donation_receipt_subject', array(), 'de' ), 'And templates.' );
			assert_same( 'Continue', PSPGD_I18n::t( 'continue', 'Continue', 'de' ), 'Keys the pack leaves out fall back to English.' );
		},

		// -------------------------------------------------------------
		// Appearance
		// -------------------------------------------------------------

		'the form reads its colours, font and radius from settings' => static function (): void {
			customisation_fresh_install(
				array(
					'pspgd_colors'      => array( 'primary' => '#0A7D4F', 'accent' => 'not-a-colour' ),
					'pspgd_font_family' => 'Inter, sans-serif; } body { display:none',
					'pspgd_radius'      => 99,
				)
			);

			$css = PSPGD_Form::brand_css();

			assert_contains( '--pspgd-primary:#0a7d4f', $css, 'A valid colour is used.' );
			assert_contains( '--pspgd-accent:#93c5fd', $css, 'An invalid colour falls back to the neutral default.' );
			assert_not_contains( '{ display', $css, 'The font setting cannot break out of the declaration.' );
			assert_contains( '--pspgd-radius:32px', $css, 'The radius is clamped.' );
		},

		'an email with no logo set uses the theme logo, and with none shows the site name' => static function (): void {
			customisation_fresh_install( array( 'pspgd_colors' => array( 'primary' => '#0a7d4f' ) ) );

			$email = PSPGD_Emails::compose( 'donation_receipt', 'en', array( 'first_name' => 'Alex' ), array(), 'Cancel', 'https://example.org/cancel' );
			assert_not_contains( '<img', $email['html'], 'No logo configured and none in the theme: no image at all.' );
			assert_contains( 'Example Charity', $email['html'], 'The site name stands in for the logo.' );
			assert_contains( 'background:#0a7d4f', $email['html'], 'The button uses the brand colour.' );

			$GLOBALS['test_custom_logo'] = 42;
			$email = PSPGD_Emails::compose( 'donation_receipt', 'en', array(), array() );
			assert_contains( 'logo-42.png', $email['html'], 'The theme logo is used when the plugin has none of its own.' );
		},

		'mail is sent from an address at the site\'s own domain' => static function (): void {
			customisation_fresh_install();

			assert_same( 'wordpress@example.test', PSPGD_Emails::from_address(), 'The fallback sender is at the site domain, like WordPress core.' );
		},

		'a single-currency site never calls the exchange-rate service' => static function (): void {
			customisation_fresh_install( array( 'pspgd_currencies' => array( 'USD' ) ) );
			test_reset_http();

			PSPGD_Exchange_Rates::refresh_rates();
			PSPGD_Exchange_Rates::maybe_refresh();

			assert_same( 0, count( test_http_requests() ), 'Nothing to combine means no outbound request.' );
			assert_true( PSPGD_Exchange_Rates::rates_available(), 'And no "rates missing" state either.' );
		},

		// -------------------------------------------------------------
		// Campaigns
		// -------------------------------------------------------------

		'a campaign keeps the goal currency it was created with' => static function (): void {
			customisation_fresh_install( array( 'pspgd_currencies' => array( 'USD', 'EUR' ), 'pspgd_goal_currency' => 'USD' ) );
			test_reset_posts();
			test_add_campaign( 5, array( '_pspgd_campaign_goal_currency' => 'EUR' ) );
			test_add_campaign( 6 );

			assert_same( 'EUR', PSPGD_Campaigns::goal_currency( 5 ), 'A stamped campaign keeps its own currency.' );
			assert_same( 'USD', PSPGD_Campaigns::goal_currency( 6 ), 'An unstamped one uses the site setting.' );

			$view = PSPGD_Campaigns::progress_view( array( 'by_currency' => array( 'USD' => array( 'total' => 250.0, 'count' => 3 ) ) ), 1000.0, 'USD' );
			assert_same( 'USD', $view['base'], 'Progress is measured in the goal currency.' );
			assert_same( 25, $view['percent'], 'A USD campaign needs no exchange rate at all.' );
		},

		'supporters are named by first name and initial, in any script' => static function (): void {
			customisation_fresh_install();

			assert_same( 'Anna H.', PSPGD_Campaigns::public_name( 'Anna', 'Harris' ), 'First name and last initial.' );
			assert_same( 'Աննա Հ.', PSPGD_Campaigns::public_name( 'Աննա', 'Հակոբյան' ), 'A multibyte initial is a whole character, not half of one.' );
			assert_same( 'Anna', PSPGD_Campaigns::public_name( 'Anna', '' ), 'No dangling full stop without a last name.' );
		},
	)
);
