<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_I18n {

	/** @var array<string, array<string, array<string, string>>> Loaded locale packs, by language code. */
	private static $packs = array();

	/**
	 * Languages the donation form and its emails are written in, as
	 * two-letter codes.
	 *
	 * Everything downstream -- labels, email templates, the stored donor
	 * language -- is normalised to one of these. Taken, in order, from the
	 * "Donor languages" setting, the languages configured in Polylang, or
	 * the site language, so a single-language site never sees columns for
	 * languages it does not use.
	 *
	 * @return string[]
	 */
	public static function supported_locales(): array {
		$configured = get_option( 'pspgd_languages', array() );
		$locales    = is_array( $configured ) ? $configured : array();

		if ( ! $locales && function_exists( 'pll_languages_list' ) ) {
			$locales = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		}

		if ( ! $locales ) {
			$locales = array( self::site_language() );
		}

		$locales = apply_filters( 'pspgd_supported_locales', $locales );
		$locales = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'language_code' ), (array) $locales ) ) ) );

		return $locales ? $locales : array( 'en' );
	}

	/**
	 * The language used when a donor's own is unknown: Polylang's default
	 * language, else the site language, else the first supported one.
	 */
	public static function default_locale(): string {
		$locales    = self::supported_locales();
		$candidates = array();

		if ( function_exists( 'pll_default_language' ) ) {
			$candidates[] = self::language_code( (string) pll_default_language( 'slug' ) );
		}
		$candidates[] = self::site_language();

		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $locales, true ) ) {
				return $candidate;
			}
		}

		return $locales[0];
	}

	/** The two-letter code of the site's own WordPress locale. */
	public static function site_language(): string {
		$code = self::language_code( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );

		return '' !== $code ? $code : 'en';
	}

	/** "hy_AM", "hy-AM", "HY" => "hy". Anything not language-shaped => ''. */
	public static function language_code( $locale ): string {
		$locale   = strtolower( str_replace( '-', '_', trim( (string) $locale ) ) );
		$language = (string) strtok( $locale, '_' );

		return preg_match( '/^[a-z]{2,3}$/', $language ) ? $language : '';
	}

	/**
	 * Reduce anything locale-shaped -- "hy", "hy_AM", "hy-AM" -- to a
	 * supported locale, falling back to the default rather than passing an
	 * unknown value on to a template lookup.
	 */
	public static function normalize_locale( string $locale ): string {
		$language = self::language_code( $locale );

		return '' !== $language && in_array( $language, self::supported_locales(), true ) ? $language : self::default_locale();
	}

	/**
	 * The locale of the request being served.
	 *
	 * Only meaningful while a visitor is on the site. Anything sent later --
	 * from cron, from a bank callback -- must use the locale stored on the
	 * donor's record instead, which is why every email path takes an explicit
	 * locale rather than calling this.
	 */
	public static function current_locale(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$polylang = (string) pll_current_language( 'slug' );
			if ( '' !== $polylang ) {
				return self::normalize_locale( $polylang );
			}
		}

		return self::normalize_locale( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() );
	}

	/**
	 * Built-in wording for one language, from `includes/locales/{code}.php`
	 * or the `pspgd_locale_pack` filter. English needs no pack.
	 *
	 * @return array{labels: array<string, string>, email_labels: array<string, string>, templates: array<string, string>}
	 */
	public static function pack( string $locale ): array {
		$code = self::language_code( $locale );

		if ( ! isset( self::$packs[ $code ] ) ) {
			$pack = array();
			$file = dirname( __FILE__ ) . '/locales/' . $code . '.php';
			if ( '' !== $code && 'en' !== $code && is_readable( $file ) ) {
				$loaded = include $file;
				$pack   = is_array( $loaded ) ? $loaded : array();
			}

			$pack = (array) apply_filters( 'pspgd_locale_pack', $pack, $code );

			self::$packs[ $code ] = array(
				'labels'       => isset( $pack['labels'] ) ? (array) $pack['labels'] : array(),
				'email_labels' => isset( $pack['email_labels'] ) ? (array) $pack['email_labels'] : array(),
				'templates'    => isset( $pack['templates'] ) ? (array) $pack['templates'] : array(),
			);
		}

		return self::$packs[ $code ];
	}

	/** Forget loaded packs. Only tests need this. */
	public static function reset_packs(): void {
		self::$packs = array();
	}

	public static function t( string $key, string $fallback, ?string $locale = null ): string {
		$locale    = null === $locale ? self::current_locale() : self::normalize_locale( $locale );
		$overrides = (array) get_option( 'pspgd_form_labels', array() );
		$override  = isset( $overrides[ $locale ][ $key ] ) ? trim( (string) $overrides[ $locale ][ $key ] ) : '';

		if ( $override !== '' ) {
			return $override;
		}

		$defaults = self::labels( $locale );

		return $defaults[ $key ] ?? $fallback;
	}

	/** @return array<string, string> English wording for every form and campaign label. */
	public static function english_labels(): array {
		return array(
			'amount' => 'Amount', 'details' => 'Your details', 'review' => 'Review', 'support' => 'Support us',
			'choose' => 'Choose your donation', 'donation_type' => 'Donation frequency', 'one_time' => 'One-time',
			'weekly' => 'Weekly', 'monthly' => 'Monthly', 'currency' => 'Currency', 'custom_amount' => 'Or enter a custom amount',
			'continue' => 'Continue', 'details_title' => 'A few details', 'first_name' => 'First name', 'last_name' => 'Last name',
			'email' => 'Email', 'newsletter_subscribe' => 'Subscribe to our newsletter.', 'anonymous' => 'Keep this donation anonymous', 'consent' => 'I agree to payment processing for this donation.',
			'privacy' => 'Privacy policy', 'your_donation' => 'Your donation', 'back' => 'Back', 'donate' => 'Donate',
			'review_donation' => 'Review donation',
			'campaign_end' => 'Campaign ends %s',
			'campaign_donations' => '%s donations',
			'campaign_goal' => 'of %s goal',
			'campaign_raised' => 'raised so far',
			'campaign_supporters' => 'Supporters',
			'anonymous_donor' => 'Anonymous donor',
			'campaign_support' => 'Support %s',
			'campaign_also_raised' => 'Also raised: %s',
			'campaign_currency' => 'Display currency',
			'campaign_closed_ended' => 'This campaign has ended. Thank you to everyone who gave.',
			'campaign_closed_goal' => 'This campaign has reached its goal. Thank you to everyone who gave.',
			'campaign_outcome_ended' => 'come to an end',
			'campaign_outcome_goal' => 'reached its goal',
			'campaign_eyebrow_ended' => 'Campaign closed',
			'campaign_eyebrow_goal' => 'Goal reached',
			'error_amount' => 'Choose or enter a donation amount.',
			'error_min' => 'The smallest donation we can accept is %s.',
			'error_max' => 'The largest donation we can accept online is %s.',
			'error_generic' => 'Your donation could not be started. Please check the form and try again.',
			'error_token' => 'We could not prepare the donation form. Please reload the page and try again.',
			'error_expired' => 'The donation form expired while it was open. Please check your details and try again.',
			'error_rate_limit' => 'Too many donation attempts from this connection. Please wait a few minutes and try again.',
			'error_blocked' => 'Your donation could not be submitted. Please try again.',
			'error_timing' => 'This form has been open too long, or was submitted too quickly. Please try again.',
			'error_campaign_closed' => 'This campaign is no longer accepting donations.',
			'error_email' => 'Please enter a valid email address so we can send your receipt.',
			'error_consent' => 'Please agree to payment processing before continuing.',
			'error_not_ready' => 'Online donations are not set up yet. Please try again later.',
			'error_currency' => 'Card payment is not available for the selected currency right now.',
			'error_frequency' => 'Please choose a donation frequency.',
			'error_recurring' => 'Recurring donations are not available right now. Please choose a one-time donation.',
			'error_turnstile' => 'Please complete the security check before donating.',
			'error_start' => 'We could not start your donation. Please try again in a moment.',
		);
	}

	/** @return array<string, string> */
	public static function labels( string $locale = 'en' ): array {
		return array_merge( self::english_labels(), self::pack( $locale )['labels'] );
	}

	/**
	 * Wording used to build an email around an admin-editable template: the
	 * greeting, the labels down the details table, and the footer.
	 *
	 * Kept out of the Email Templates screen on purpose. These are structural
	 * labels rather than messages, and an editor changing "Amount" to
	 * something else only makes the receipt harder to read. Translate them
	 * with a locale pack.
	 *
	 * @return array<string, string>
	 */
	public static function email_labels( string $locale = 'en' ): array {
		$english = array(
			'greeting'       => 'Hi %s,',
			'donation_id'    => 'Donation',
			'date'           => 'Date',
			'amount'         => 'Amount',
			'frequency'      => 'Frequency',
			'campaign'       => 'Campaign',
			'reference'      => 'Bank reference',
			'next_payment'   => 'Next payment',
			'attempts_left'  => 'Attempts remaining',
			'one_time'       => 'One-time donation',
			'monthly'        => 'Monthly donation',
			'weekly'         => 'Weekly donation',
			'footer_thanks'  => 'Thank you for supporting %s.',
			'footer_contact' => 'Questions about this donation? Reply to this email and we will get back to you.',
		);

		return array_merge( $english, self::pack( self::normalize_locale( $locale ) )['email_labels'] );
	}

	/** One email label, resolved in a given language. */
	public static function email_label( string $key, string $locale = 'en' ): string {
		$labels = self::email_labels( $locale );

		return $labels[ $key ] ?? $key;
	}

	public static function currency_symbol( string $currency ): string {
		$symbols = (array) apply_filters(
			'pspgd_currency_symbols',
			array( 'AMD' => '֏', 'USD' => '$', 'EUR' => '€', 'RUB' => '₽', 'GBP' => '£' )
		);

		return (string) ( $symbols[ strtoupper( $currency ) ] ?? strtoupper( $currency ) );
	}

	/**
	 * Decimal places shown for a currency. AMD has no minor unit in
	 * everyday use, so it is shown without decimals; everything else keeps
	 * two unless filtered.
	 */
	public static function display_decimals( string $currency ): int {
		$zero = (array) apply_filters( 'pspgd_zero_decimal_currencies', array( 'AMD', 'JPY', 'KRW' ) );

		return in_array( strtoupper( $currency ), array_map( 'strtoupper', $zero ), true ) ? 0 : 2;
	}

	/**
	 * Format an amount for display in its own currency.
	 *
	 * The currency code is always present because the plugin converts
	 * nothing at payment time -- a bare number would be ambiguous wherever
	 * several currencies appear together.
	 */
	public static function format_amount( float $amount, string $currency ): string {
		$currency = strtoupper( $currency );

		return number_format_i18n( $amount, self::display_decimals( $currency ) ) . ' ' . $currency;
	}

	/**
	 * Built-in per-currency defaults, used until an administrator saves their
	 * own. A currency not listed gets the generic row.
	 *
	 * @return array{presets: float[], min: float, max: float}
	 */
	private static function currency_defaults( string $currency ): array {
		$defaults = array(
			'AMD' => array( 'presets' => array( 5000, 10000, 20000, 50000 ), 'min' => 500.0, 'max' => 5000000.0 ),
			'RUB' => array( 'presets' => array( 500, 1000, 2500, 5000 ), 'min' => 100.0, 'max' => 1000000.0 ),
		);
		$generic  = array( 'presets' => array( 25, 50, 100, 250 ), 'min' => 2.0, 'max' => 15000.0 );

		$row = $defaults[ strtoupper( $currency ) ] ?? $generic;

		return (array) apply_filters( 'pspgd_currency_defaults', $row, strtoupper( $currency ) );
	}

	/** @return array<string, float[]> Suggested amounts, per offered currency. */
	public static function amount_presets(): array {
		$stored  = (array) get_option( 'pspgd_amount_presets', array() );
		$presets = array();

		foreach ( PSPGD_Settings::currencies() as $currency ) {
			$presets[ $currency ] = ! empty( $stored[ $currency ] ) && is_array( $stored[ $currency ] )
				? array_values( $stored[ $currency ] )
				: self::currency_defaults( $currency )['presets'];
		}

		return $presets;
	}

	/**
	 * Smallest donation accepted, per currency.
	 *
	 * A donation costs the same in card fees whatever its size, so a
	 * one-unit donation costs the organisation money to accept. It is also
	 * the shape a card tester uses to check whether a stolen number works.
	 *
	 * @return array<string, float>
	 */
	public static function min_amounts(): array {
		$stored   = (array) get_option( 'pspgd_min_amounts', array() );
		$maximums = self::max_amounts();
		$amounts  = array();

		foreach ( PSPGD_Settings::currencies() as $currency ) {
			$amount = isset( $stored[ $currency ] ) ? (float) $stored[ $currency ] : 0.0;
			$limit  = (float) ( $maximums[ $currency ] ?? 0 );

			// A minimum at or above the maximum would reject every donation,
			// so fall back rather than close the form.
			if ( $amount <= 0 || ( $limit > 0 && $amount >= $limit ) ) {
				$amount = (float) self::currency_defaults( $currency )['min'];
			}

			$amounts[ $currency ] = $amount;
		}

		return $amounts;
	}

	/** @return array<string, float> Largest donation accepted online, per currency. */
	public static function max_amounts(): array {
		$stored  = (array) get_option( 'pspgd_max_amounts', array() );
		$amounts = array();

		foreach ( PSPGD_Settings::currencies() as $currency ) {
			$amount               = isset( $stored[ $currency ] ) ? (float) $stored[ $currency ] : 0.0;
			$amounts[ $currency ] = $amount > 0 ? $amount : (float) self::currency_defaults( $currency )['max'];
		}

		return $amounts;
	}
}
