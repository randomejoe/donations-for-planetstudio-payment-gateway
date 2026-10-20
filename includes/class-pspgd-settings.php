<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-level choices: which bank takes the payments, which currencies are
 * offered, how the form and the emails look, and which optional parts of
 * the form are shown.
 *
 * Everything here has a neutral default so a fresh install works (in test
 * mode) before anyone opens the settings screen.
 */
final class PSPGD_Settings {

	/**
	 * Currencies the plugin knows how to present. PlanetStudio Payment
	 * Gateway settles AMD, USD, EUR and RUB, depending on the bank; whether a
	 * given bank accepts one is still up to that bank's configuration.
	 *
	 * @return string[]
	 */
	public static function known_currencies(): array {
		$currencies = apply_filters( 'pspgd_known_currencies', array( 'AMD', 'USD', 'EUR', 'RUB' ) );

		return array_values( array_unique( array_filter( array_map( 'strtoupper', array_map( 'strval', (array) $currencies ) ) ) ) );
	}

	// -----------------------------------------------------------------
	// Payment
	// -----------------------------------------------------------------

	/** The PSPG bank id every donation is registered with, e.g. "arca_acba" or "ameria". */
	public static function bank_id(): string {
		return sanitize_key( (string) get_option( 'pspgd_bank_id', '' ) );
	}

	/**
	 * Currencies offered to donors, in the order an administrator ticked
	 * them. Never empty: a site that has not chosen yet is offered AMD, the
	 * one currency every bank PSPG supports can settle.
	 *
	 * @return string[]
	 */
	public static function currencies(): array {
		$stored = get_option( 'pspgd_currencies', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$known  = self::known_currencies();

		$currencies = array();
		foreach ( $stored as $currency ) {
			$currency = strtoupper( (string) $currency );
			if ( in_array( $currency, $known, true ) && ! in_array( $currency, $currencies, true ) ) {
				$currencies[] = $currency;
			}
		}

		return $currencies ? $currencies : array( 'AMD' );
	}

	/** The currency selected when the form first appears. */
	public static function default_currency(): string {
		$currencies = self::currencies();
		$stored     = strtoupper( (string) get_option( 'pspgd_default_currency', '' ) );

		return in_array( $stored, $currencies, true ) ? $stored : $currencies[0];
	}

	/**
	 * The currency campaign goals are entered in, and the unit donations in
	 * every other currency are converted into for the progress bar.
	 */
	public static function goal_currency(): string {
		$currencies = self::currencies();
		$stored     = strtoupper( (string) get_option( 'pspgd_goal_currency', '' ) );

		return in_array( $stored, $currencies, true ) ? $stored : self::default_currency();
	}

	/**
	 * Recurring frequencies offered on the form.
	 *
	 * @return string[] Subset of "monthly", "weekly".
	 */
	public static function intervals(): array {
		$stored = get_option( 'pspgd_intervals', array( 'monthly', 'weekly' ) );
		$stored = is_array( $stored ) ? $stored : array();

		return array_values( array_intersect( array( 'monthly', 'weekly' ), array_map( 'strval', $stored ) ) );
	}

	// -----------------------------------------------------------------
	// Form options
	// -----------------------------------------------------------------

	/** Show a newsletter opt-in read by Mailchimp for WordPress (field `mc4wp-subscribe`). */
	public static function newsletter_optin(): bool {
		return '1' === (string) get_option( 'pspgd_newsletter_optin', '0' );
	}

	/** Show the list of supporters (first name and initial) on campaign pages. */
	public static function show_supporters(): bool {
		return '0' !== (string) get_option( 'pspgd_show_supporters', '1' );
	}

	/** Image shown on the review step, typically the accepted card logos. Empty = none. */
	public static function payment_image_url(): string {
		return esc_url_raw( (string) get_option( 'pspgd_payment_image_url', '' ) );
	}

	// -----------------------------------------------------------------
	// Appearance
	// -----------------------------------------------------------------

	/**
	 * Brand colours used by the form, the campaign page, the payment return
	 * screen and every email.
	 *
	 * @return array{primary: string, accent: string, text: string}
	 */
	public static function colors(): array {
		$defaults = array(
			'primary' => '#1d4ed8',
			'accent'  => '#93c5fd',
			'text'    => '#1f2937',
		);
		$stored   = get_option( 'pspgd_colors', array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$colors   = array();

		foreach ( $defaults as $key => $default ) {
			$value          = self::sanitize_hex( (string) ( $stored[ $key ] ?? '' ) );
			$colors[ $key ] = '' !== $value ? $value : $default;
		}

		return $colors;
	}

	/** CSS font-family for the form. "inherit" follows the theme. */
	public static function font_family(): string {
		$font = trim( (string) get_option( 'pspgd_font_family', '' ) );
		$font = preg_replace( '/[^a-zA-Z0-9 ,"\'\-]/', '', $font );

		return '' !== $font ? $font : 'inherit';
	}

	/** Corner radius, in pixels, for buttons, inputs and panels. */
	public static function radius(): int {
		$radius = get_option( 'pspgd_radius', 6 );

		return max( 0, min( 32, (int) $radius ) );
	}

	/** Font stack used in emails, where the theme's font is not available. */
	public static function email_font(): string {
		$font = self::font_family();

		return 'inherit' === $font
			? '-apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'
			: $font . ', Helvetica, Arial, sans-serif';
	}

	public static function sanitize_hex( string $color ): string {
		$color = trim( $color );

		return preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color ) ? strtolower( $color ) : '';
	}
}
