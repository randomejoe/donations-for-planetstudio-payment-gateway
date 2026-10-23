<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every email the plugin sends to donors.
 *
 * An email is composed from three separate things: an admin-editable
 * template (subject, heading, body) resolved in the donor's own language, a
 * details table built from the donation record, and a fixed layout. Keeping
 * them apart is what lets an editor rewrite the wording without losing the
 * donation number, date and bank reference a donor needs in order to
 * recognise a charge on their statement.
 */
final class PSPGD_Emails {

	/** Sent once the bank has saved the donor's card and the schedule is running. */
	public static function notify_recurring_activated( object $subscription, string $next_payment_at ): void {
		$locale = self::subscription_locale( $subscription );
		$donor  = PSPGD_Repository::find_donor( (int) $subscription->donor_id );

		$sent = self::send(
			$subscription->email,
			'recurring_activation',
			$locale,
			self::subscription_vars( $subscription, $donor, $next_payment_at ),
			array(
				'amount'       => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'frequency'    => self::interval_label( (string) $subscription->interval_key, $locale ),
				'campaign'     => self::campaign_title( (int) $subscription->campaign_id ),
				'next_payment' => self::format_date( $next_payment_at ),
			),
			self::template_value( 'recurring_cancel_label', array(), $locale ),
			PSPGD_Subscriptions::cancel_url( $subscription )
		);

		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, $sent ? 'recurring_activation_email_sent' : 'recurring_activation_email_failed' );
	}

	/** Sent when the donor cancels from the link in their emails. */
	public static function notify_recurring_cancelled( object $subscription ): void {
		$locale = self::subscription_locale( $subscription );
		$donor  = PSPGD_Repository::find_donor( (int) $subscription->donor_id );
		$sent   = self::send(
			$subscription->email,
			'recurring_cancel',
			$locale,
			self::subscription_vars( $subscription, $donor, '' ),
			array(
				'amount'    => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'frequency' => self::interval_label( (string) $subscription->interval_key, $locale ),
			)
		);

		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, $sent ? 'recurring_cancellation_email_sent' : 'recurring_cancellation_email_failed' );
	}

	/**
	 * Sent each time a recurring charge fails but the subscription is still
	 * within its retry budget (see PSPGD_Recurring).
	 */
	public static function notify_recurring_payment_failed( object $subscription, int $failed_count, int $max_failures ): void {
		$remaining = max( 0, $max_failures - $failed_count );
		$locale    = self::subscription_locale( $subscription );
		$donor     = PSPGD_Repository::find_donor( (int) $subscription->donor_id );

		$vars              = self::subscription_vars( $subscription, $donor, (string) $subscription->next_payment_at );
		$vars['remaining'] = (string) $remaining;

		$sent = self::send(
			$subscription->email,
			'recurring_payment_failed',
			$locale,
			$vars,
			array(
				'amount'        => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'frequency'     => self::interval_label( (string) $subscription->interval_key, $locale ),
				'next_payment'  => self::format_date( (string) $subscription->next_payment_at ),
				'attempts_left' => (string) $remaining,
			),
			self::template_value( 'recurring_cancel_label', array(), $locale ),
			PSPGD_Subscriptions::cancel_url( $subscription )
		);

		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, $sent ? 'recurring_payment_failed_email_sent' : 'recurring_payment_failed_email_failed' );
	}

	/**
	 * Sent once a subscription is auto-cancelled after too many consecutive
	 * failed charges.
	 */
	public static function notify_recurring_cancelled_after_failures( object $subscription ): void {
		$locale = self::subscription_locale( $subscription );
		$donor  = PSPGD_Repository::find_donor( (int) $subscription->donor_id );

		$sent = self::send(
			$subscription->email,
			'recurring_cancelled_after_failures',
			$locale,
			self::subscription_vars( $subscription, $donor, '' ),
			array(
				'amount'    => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'frequency' => self::interval_label( (string) $subscription->interval_key, $locale ),
			)
		);

		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, $sent ? 'recurring_cancelled_after_failures_email_sent' : 'recurring_cancelled_after_failures_email_failed' );
	}

	/**
	 * Sent when a campaign closes and its recurring donations end with it.
	 *
	 * The donor pledged to a campaign, not to a standing order, so this is
	 * news they are owed rather than a silent stop: their card has been
	 * released at the bank and nothing further will be taken.
	 */
	public static function notify_recurring_cancelled_campaign_closed( object $subscription, string $reason ): void {
		$locale = self::subscription_locale( $subscription );
		$donor  = PSPGD_Repository::find_donor( (int) $subscription->donor_id );
		$vars   = self::subscription_vars( $subscription, $donor, '' );

		$vars['campaign_outcome'] = 'goal_reached' === $reason
			? PSPGD_I18n::t( 'campaign_outcome_goal', 'reached its goal', $locale )
			: PSPGD_I18n::t( 'campaign_outcome_ended', 'come to an end', $locale );

		$sent = self::send(
			$subscription->email,
			'recurring_cancelled_campaign_closed',
			$locale,
			$vars,
			array(
				'amount'    => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'frequency' => self::interval_label( (string) $subscription->interval_key, $locale ),
				'campaign'  => self::campaign_title( (int) $subscription->campaign_id ),
			)
		);

		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, $sent ? 'recurring_cancelled_campaign_closed_email_sent' : 'recurring_cancelled_campaign_closed_email_failed' );
	}

	/**
	 * The donation was paid, but the bank returned no reusable card binding,
	 * so no recurring schedule exists.
	 */
	public static function notify_recurring_setup_failed( object $subscription ): void {
		$locale = self::subscription_locale( $subscription );
		$donor  = PSPGD_Repository::find_donor( (int) $subscription->donor_id );

		$sent = self::send(
			$subscription->email,
			'recurring_setup_failed',
			$locale,
			self::subscription_vars( $subscription, $donor, '' ),
			array(
				'amount'    => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'frequency' => self::interval_label( (string) $subscription->interval_key, $locale ),
				'campaign'  => self::campaign_title( (int) $subscription->campaign_id ),
			)
		);

		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, $sent ? 'recurring_setup_email_sent' : 'recurring_setup_email_failed' );
	}

	public static function send_receipt( object $donation ): void {
		if ( PSPGD_Repository::event_exists( 'donation', (int) $donation->id, 'payment_receipt_sent' ) ) {
			return;
		}

		$donor = PSPGD_Repository::find_donor( (int) $donation->donor_id );
		if ( ! $donor || ! is_email( $donor->email ) ) {
			return;
		}

		$subscription = (int) $donation->subscription_id > 0 ? PSPGD_Repository::find_subscription( (int) $donation->subscription_id ) : null;
		$campaign     = (int) $donation->campaign_id > 0 ? get_post( (int) $donation->campaign_id ) : null;
		$locale       = self::donation_locale( $donation, $donor );

		$cta_label = '';
		$cta_url   = '';
		if ( self::is_live( $subscription ) ) {
			$cta_label = self::template_value( 'recurring_cancel_label', array(), $locale );
			$cta_url   = PSPGD_Subscriptions::cancel_url( $subscription );
		}

		// A scheduled charge has to read differently from a donation the donor
		// has just made: they are not at their keyboard, and what they most
		// need is to recognise which recurring donation this was.
		if ( 'recurring' === (string) $donation->type ) {
			$template = 'recurring_receipt';
		} elseif ( $campaign ) {
			$template = 'campaign_receipt';
		} else {
			$template = 'donation_receipt';
		}

		$details = array(
			'donation_id' => '#' . (int) $donation->id,
			'date'        => self::format_date( (string) $donation->created_at ),
			'amount'      => PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency ),
			'campaign'    => $campaign ? $campaign->post_title : '',
			'frequency'   => $subscription
				? self::interval_label( (string) $subscription->interval_key, $locale )
				: PSPGD_I18n::email_label( 'one_time', $locale ),
			'reference'   => (string) $donation->transaction_reference,
		);

		$next_payment = self::next_payment_date( $subscription );
		if ( '' !== $next_payment ) {
			$details['next_payment'] = $next_payment;
		}

		$sent = self::send(
			$donor->email,
			$template,
			$locale,
			self::donation_vars( $donation, $donor, $subscription, $campaign ),
			$details,
			$cta_label,
			$cta_url
		);

		PSPGD_Repository::log_event( 'donation', (int) $donation->id, $sent ? 'payment_receipt_sent' : 'payment_receipt_failed' );
	}

	/**
	 * Compose one email from a template group and send it.
	 *
	 * @param string                $template_key Template group, e.g. 'donation_receipt'.
	 * @param array<string, string> $vars         Placeholder values for the template.
	 * @param array<string, string> $details      Detail rows keyed by email label. Blank values are dropped.
	 */
	private static function send( string $email, string $template_key, string $locale, array $vars, array $details = array(), string $cta_label = '', string $cta_url = '' ): bool {
		$composed = self::compose( $template_key, $locale, $vars, $details, $cta_label, $cta_url );

		return self::send_email( $email, $composed['subject'], $composed['html'] );
	}

	/**
	 * @return array{subject: string, html: string}
	 */
	public static function compose( string $template_key, string $locale, array $vars, array $details = array(), string $cta_label = '', string $cta_url = '' ): array {
		$locale = PSPGD_I18n::normalize_locale( $locale );

		return array(
			'subject' => self::template_value( $template_key . '_subject', $vars, $locale ),
			'html'    => self::email_markup(
				array(
					'locale'    => $locale,
					'greeting'  => self::greeting( (string) ( $vars['first_name'] ?? '' ), $locale ),
					'heading'   => self::template_value( $template_key . '_heading', $vars, $locale ),
					'body'      => self::template_value( $template_key . '_body', $vars, $locale ),
					'details'   => $details,
					'cta_label' => $cta_label,
					'cta_url'   => $cta_url,
				)
			),
		);
	}

	private static function greeting( string $first_name, string $locale ): string {
		$first_name = trim( $first_name );
		if ( '' === $first_name ) {
			return '';
		}

		return sprintf( PSPGD_I18n::email_label( 'greeting', $locale ), $first_name );
	}

	/**
	 * Tags an editor may use in an email body. Deliberately small: enough for
	 * a paragraph break, emphasis and a link, and nothing that can break the
	 * layout around it.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_body_html(): array {
		return array(
			'a'      => array( 'href' => true, 'title' => true ),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * The logo shown at the top of every email, in both appearances.
	 *
	 * Two files rather than one: a client rendering the mail in dark mode is
	 * told to swap them, so the wordmark stays legible instead of turning into
	 * dark ink on a dark card.
	 *
	 * @return array{light: string, dark: string}
	 */
	public static function logo_urls(): array {
		$light = esc_url_raw( (string) get_option( 'pspgd_email_logo_light', '' ) );
		$dark  = esc_url_raw( (string) get_option( 'pspgd_email_logo_dark', '' ) );

		if ( '' === $light ) {
			$light = self::site_logo_url();
		}

		if ( '' === $dark ) {
			// Nothing better to offer, so the same mark serves both
			// appearances. It may sit dark-on-dark in some clients, which is
			// why the settings screen asks for a light version.
			$dark = $light;
		}

		return array( 'light' => $light, 'dark' => $dark );
	}

	/**
	 * The theme's own logo (Appearance > Customize > Site Identity), or
	 * nothing. An email with no logo shows the site name as its masthead
	 * instead.
	 */
	public static function site_logo_url(): string {
		$logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;
		if ( $logo_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = (string) wp_get_attachment_image_url( $logo_id, 'full' );
			if ( '' !== $url ) {
				return esc_url_raw( $url );
			}
		}

		return '';
	}

	/**
	 * Networks the footer can link to, in the order they are shown.
	 *
	 * @return array<string, string> Key => human label.
	 */
	public static function social_networks(): array {
		return array(
			'facebook'  => __( 'Facebook', 'donations-for-planetstudio-payment-gateway' ),
			'instagram' => __( 'Instagram', 'donations-for-planetstudio-payment-gateway' ),
			'linkedin'  => __( 'LinkedIn', 'donations-for-planetstudio-payment-gateway' ),
			'youtube'   => __( 'YouTube', 'donations-for-planetstudio-payment-gateway' ),
			'telegram'  => __( 'Telegram', 'donations-for-planetstudio-payment-gateway' ),
			'x'         => __( 'X', 'donations-for-planetstudio-payment-gateway' ),
			'tiktok'    => __( 'TikTok', 'donations-for-planetstudio-payment-gateway' ),
		);
	}

	/**
	 * The social links an editor has filled in, in display order.
	 *
	 * Nothing is hardcoded: a network with no URL behind it is simply not
	 * shown, so the footer is empty until someone fills the fields in.
	 *
	 * @return array<string, string> Label => URL.
	 */
	public static function social_links(): array {
		$stored = (array) get_option( 'pspgd_social_links', array() );
		$links  = array();

		foreach ( self::social_networks() as $key => $label ) {
			$url = esc_url_raw( trim( (string) ( $stored[ $key ] ?? '' ) ) );
			if ( '' !== $url ) {
				$links[ $label ] = $url;
			}
		}

		return $links;
	}

	private static function social_markup(): string {
		$links = self::social_links();
		if ( ! $links ) {
			return '';
		}

		$colors   = PSPGD_Settings::colors();
		$rendered = array();
		foreach ( $links as $label => $url ) {
			$rendered[] = '<a class="fd-link" href="' . esc_url( $url ) . '" style="color:' . esc_attr( $colors['primary'] ) . ';text-decoration:none;font-weight:700;">' . esc_html( $label ) . '</a>';
		}

		return '<p class="fd-footer" style="margin:0 0 12px;color:#6b7280;font-size:13px;line-height:1.8;">'
			. implode( '<span style="color:#d1d5db;"> &middot; </span>', $rendered )
			. '</p>';
	}

	/**
	 * @param array<string, mixed> $email
	 */
	private static function email_markup( array $email ): string {
		$locale    = (string) ( $email['locale'] ?? 'en' );
		$logos     = self::logo_urls();
		$colors    = PSPGD_Settings::colors();
		$font      = PSPGD_Settings::email_font();
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		// Two logos, one picture: a client that understands dark mode picks
		// the light-on-dark file, and every client that does not falls
		// straight through to the <img> and shows the normal one. With no
		// logo at all the site name stands in, so the email is never
		// headed by a broken image.
		if ( '' !== $logos['light'] ) {
			$logo = '<picture>'
				. '<source srcset="' . esc_url( $logos['dark'] ) . '" media="(prefers-color-scheme: dark)">'
				. '<img src="' . esc_url( $logos['light'] ) . '" alt="' . esc_attr( $site_name ) . '" width="180" style="display:block;max-width:180px;width:100%;height:auto;margin:0 auto 24px;border:0;">'
				. '</picture>';
		} else {
			$logo = '<p class="fd-strong" style="margin:0 0 24px;text-align:center;font-size:18px;font-weight:700;color:' . esc_attr( $colors['primary'] ) . ';">' . esc_html( $site_name ) . '</p>';
		}

		$greeting = '' !== (string) $email['greeting']
			? '<p class="fd-strong" style="margin:0 0 14px;color:' . esc_attr( $colors['text'] ) . ';font-size:16px;line-height:1.6;">' . esc_html( (string) $email['greeting'] ) . '</p>'
			: '';

		// The body is the one admin-editable field allowed to carry markup, so
		// a line break or a link survives instead of being escaped into text.
		$body = '<div class="fd-text" style="margin:0;color:#4b5563;font-size:16px;line-height:1.6;">' . wpautop( wp_kses( (string) $email['body'], self::allowed_body_html() ) ) . '</div>';

		$details = self::details_markup( (array) $email['details'], $locale );

		$cta = ! empty( $email['cta_label'] ) && ! empty( $email['cta_url'] )
			? '<p style="margin:28px 0 0;text-align:center;"><a href="' . esc_url( (string) $email['cta_url'] ) . '" style="display:inline-block;padding:12px 18px;background:' . esc_attr( $colors['primary'] ) . ';color:#ffffff;text-decoration:none;font-weight:700;border-radius:' . (int) PSPGD_Settings::radius() . 'px;">' . esc_html( (string) $email['cta_label'] ) . '</a></p>'
			: '';

		$footer = self::social_markup()
			. '<p class="fd-footer" style="margin:0 0 6px;color:#6b7280;">' . esc_html( sprintf( PSPGD_I18n::email_label( 'footer_thanks', $locale ), $site_name ) ) . '</p>'
			. '<p class="fd-footer" style="margin:0;color:#6b7280;">' . esc_html( PSPGD_I18n::email_label( 'footer_contact', $locale ) ) . '</p>';

		return '<!doctype html><html><head><meta charset="utf-8">'
			. '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
			. '<style>' . self::email_styles() . '</style>'
			. '</head>'
			. '<body class="fd-body" style="margin:0;padding:32px 16px;background:#f3f4f6;font-family:' . esc_attr( $font ) . ';color:' . esc_attr( $colors['text'] ) . ';">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
			. '<table role="presentation" class="fd-card" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-top:6px solid ' . esc_attr( $colors['accent'] ) . ';">'
			. '<tr><td style="padding:40px 34px;text-align:left;">'
			. $logo
			. '<h1 class="fd-strong" style="margin:0 0 18px;font-size:25px;line-height:1.25;color:' . esc_attr( $colors['text'] ) . ';text-align:center;">' . esc_html( (string) $email['heading'] ) . '</h1>'
			. $greeting
			. $body
			. $details
			. $cta
			. '</td></tr>'
			. '<tr><td class="fd-rule" style="padding:20px 34px 28px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.6;text-align:center;">' . $footer . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Dark-mode overrides.
	 *
	 * Honoured by Apple Mail, Outlook for Mac and Outlook.com; Gmail applies
	 * its own inversion instead and ignores this, which is why every colour
	 * above is also readable as Gmail leaves it. Nothing here changes the
	 * layout, so a client that drops the <style> block entirely still gets a
	 * correct email.
	 */
	private static function email_styles(): string {
		$accent = PSPGD_Settings::colors()['accent'];

		return ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
			. '@media (prefers-color-scheme: dark){'
			. '.fd-body{background:#111827 !important;}'
			. '.fd-card{background:#1f2937 !important;}'
			. '.fd-strong{color:#f9fafb !important;}'
			. '.fd-text,.fd-footer{color:#d1d5db !important;}'
			. '.fd-detail-label{color:#9ca3af !important;}'
			. '.fd-detail-value{color:#f9fafb !important;}'
			. '.fd-rule{border-color:#374151 !important;}'
			. '.fd-link{color:' . $accent . ' !important;}'
			. '}';
	}

	/**
	 * The table that makes a charge recognisable on a bank statement. Rows
	 * with nothing in them are dropped rather than rendered empty.
	 *
	 * @param array<string, string> $details
	 */
	private static function details_markup( array $details, string $locale ): string {
		$rows = '';

		foreach ( $details as $key => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$rows .= '<tr>'
				. '<td class="fd-detail-label" style="padding:8px 0;color:#6b7280;font-size:14px;vertical-align:top;width:45%;">' . esc_html( PSPGD_I18n::email_label( (string) $key, $locale ) ) . '</td>'
				. '<td class="fd-detail-value" style="padding:8px 0;color:' . esc_attr( PSPGD_Settings::colors()['text'] ) . ';font-size:14px;font-weight:700;vertical-align:top;text-align:right;">' . esc_html( $value ) . '</td>'
				. '</tr>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0 0;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;">' . $rows . '</table>';
	}

	// -----------------------------------------------------------------
	// Placeholder values
	// -----------------------------------------------------------------
	/**
	 * Placeholders available to every template. Documented for editors on the
	 * Email Templates screen.
	 *
	 * @return string[]
	 */
	public static function placeholders(): array {
		return array(
			'first_name',
			'last_name',
			'donation_id',
			'date',
			'amount',
			'amount_formatted',
			'currency',
			'campaign_title',
			'campaign_outcome',
			'interval',
			'next_payment_at',
			'transaction_reference',
			'remaining',
			'cancel_url',
			'site_name',
		);
	}

	/** @return array<string, string> */
	private static function base_vars( ?object $donor, string $locale ): array {
		return array(
			'first_name' => $donor ? (string) $donor->first_name : '',
			'last_name'  => $donor ? (string) $donor->last_name : '',
			'site_name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/** @return array<string, string> */
	private static function subscription_vars( object $subscription, ?object $donor, string $next_payment_at ): array {
		$locale = self::subscription_locale( $subscription );

		return array_merge(
			self::base_vars( $donor, $locale ),
			array(
				'donation_id'           => '',
				'date'                  => '',
				'amount'                => (string) $subscription->amount,
				'amount_formatted'      => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ),
				'currency'              => (string) $subscription->currency,
				'campaign_title'        => self::campaign_title( (int) $subscription->campaign_id ),
				'interval'              => self::interval_label( (string) $subscription->interval_key, $locale ),
				'next_payment_at'       => '' !== $next_payment_at ? self::format_date( $next_payment_at ) : '',
				'transaction_reference' => '',
				'remaining'             => '',
				'cancel_url'            => PSPGD_Subscriptions::cancel_url( $subscription ),
			)
		);
	}

	/** @return array<string, string> */
	private static function donation_vars( object $donation, ?object $donor, ?object $subscription, ?object $campaign ): array {
		$locale = self::donation_locale( $donation, $donor );

		return array_merge(
			self::base_vars( $donor, $locale ),
			array(
				'donation_id'           => (string) (int) $donation->id,
				'date'                  => self::format_date( (string) $donation->created_at ),
				'amount'                => (string) $donation->amount,
				'amount_formatted'      => PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency ),
				'currency'              => (string) $donation->currency,
				'campaign_title'        => $campaign ? (string) $campaign->post_title : '',
				'interval'              => $subscription
					? self::interval_label( (string) $subscription->interval_key, $locale )
					: PSPGD_I18n::email_label( 'one_time', $locale ),
				'next_payment_at'       => self::next_payment_date( $subscription ),
				'transaction_reference' => (string) $donation->transaction_reference,
				'remaining'             => '',
				'cancel_url'            => $subscription ? PSPGD_Subscriptions::cancel_url( $subscription ) : '',
			)
		);
	}

	private static function interval_label( string $interval_key, string $locale ): string {
		$key = in_array( $interval_key, array( 'weekly', 'monthly' ), true ) ? $interval_key : 'one_time';

		return PSPGD_I18n::email_label( $key, $locale );
	}

	private static function campaign_title( int $campaign_id ): string {
		if ( $campaign_id <= 0 ) {
			return '';
		}

		$campaign = get_post( $campaign_id );

		return $campaign ? (string) $campaign->post_title : '';
	}

	private static function format_date( string $mysql_date ): string {
		$mysql_date = trim( $mysql_date );
		if ( '' === $mysql_date ) {
			return '';
		}

		// The stored value is the site's local wall clock. strtotime() would
		// read it as UTC and wp_date() would then shift it again, dating a
		// late-evening donation with the next day.
		$formatted = mysql2date( (string) get_option( 'date_format', 'Y-m-d' ), $mysql_date );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Whether the donor still has a schedule they could cancel.
	 *
	 * 'processing' counts. It is the charge worker's claim on the row, taken
	 * before the payment and released after it -- not a state the donor did
	 * anything to reach. Reading it as "not active" is what dropped the
	 * cancel link out of every scheduled receipt, since those are composed
	 * while the claim is held.
	 */
	private static function is_live( ?object $subscription ): bool {
		return $subscription && in_array( (string) $subscription->status, array( 'active', 'processing' ), true );
	}

	/**
	 * The date of the donor's next charge, when there is one to promise.
	 *
	 * Blank for a schedule that has ended, and for one whose stored date is
	 * the charge being confirmed rather than a future one: a receipt composed
	 * before the schedule moved on (the reconciliation path picking up a
	 * charge whose own run never reported an outcome) would otherwise offer
	 * the payment just taken as one still to come.
	 */
	private static function next_payment_date( ?object $subscription ): string {
		if ( ! self::is_live( $subscription ) || ! self::is_future( (string) $subscription->next_payment_at ) ) {
			return '';
		}

		return self::format_date( (string) $subscription->next_payment_at );
	}

	/**
	 * Whether a stored site-local datetime is still ahead of the clock.
	 *
	 * Read in the site's timezone rather than through strtotime(), which
	 * would take a naive 'Y-m-d H:i:s' as UTC and put a date up to a day on
	 * the wrong side of now.
	 */
	private static function is_future( string $mysql_date ): bool {
		$moment = PSPGD_Clock::to_timestamp( $mysql_date );

		return null !== $moment && $moment > PSPGD_Clock::now();
	}

	// -----------------------------------------------------------------
	// Sending
	// -----------------------------------------------------------------
	/**
	 * Send one HTML email from the configured From and Reply-to addresses.
	 */
	public static function send_email( string $email, string $subject, string $message ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_name() . ' <' . self::from_address() . '>',
		);

		$reply_to = self::reply_to_address();
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return wp_mail( $email, $subject, $message, $headers );
	}

	public static function from_name(): string {
		$name = sanitize_text_field( (string) get_option( 'pspgd_email_from_name', '' ) );

		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function from_address(): string {
		$address = sanitize_email( (string) get_option( 'pspgd_email_from_address', '' ) );
		if ( '' !== $address ) {
			return $address;
		}

		// The same shape WordPress core uses for its own mail: an address at
		// the site's domain, which is what SPF and DMARC expect to see.
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', $host );

		return '' !== $host ? sanitize_email( 'wordpress@' . $host ) : sanitize_email( (string) get_option( 'admin_email', '' ) );
	}

	public static function reply_to_address(): string {
		return sanitize_email( (string) get_option( 'pspgd_email_reply_to', '' ) );
	}

	/**
	 * Render one email with sample data and send it, so an editor can see the
	 * result of their wording without waiting for a real donation.
	 */
	public static function send_preview( string $template_key, string $locale, string $email ): bool {
		$locale = PSPGD_I18n::normalize_locale( $locale );

		// A plausible donation in the site's own default currency: the second
		// suggested amount, which is the one the form pre-selects.
		$currency       = PSPGD_Settings::default_currency();
		$presets        = PSPGD_I18n::amount_presets();
		$sample_amount  = (float) ( $presets[ $currency ][1] ?? $presets[ $currency ][0] ?? 50 );

		$sample = array(
			'first_name'            => 'Alex',
			'last_name'             => 'Smith',
			'site_name'             => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'donation_id'           => '1042',
			'date'                  => self::format_date( (string) current_time( 'mysql' ) ),
			'amount'                => number_format( $sample_amount, 2, '.', '' ),
			'amount_formatted'      => PSPGD_I18n::format_amount( $sample_amount, $currency ),
			'currency'              => $currency,
			'campaign_title'        => __( 'Sample campaign', 'donations-for-planetstudio-payment-gateway' ),
			'campaign_outcome'      => PSPGD_I18n::t( 'campaign_outcome_goal', 'reached its goal', $locale ),
			'interval'              => PSPGD_I18n::email_label( 'monthly', $locale ),
			'next_payment_at'       => self::format_date( PSPGD_Clock::local_mysql( PSPGD_Clock::now() + ( 30 * DAY_IN_SECONDS ) ) ),
			'transaction_reference' => '900123456789',
			'remaining'             => '2',
			'cancel_url'            => home_url( '/' ),
		);

		$composed = self::compose(
			$template_key,
			$locale,
			$sample,
			array(
				'donation_id'  => '#1042',
				'date'         => $sample['date'],
				'amount'       => $sample['amount_formatted'],
				'campaign'     => $sample['campaign_title'],
				'frequency'    => $sample['interval'],
				'reference'    => $sample['transaction_reference'],
				'next_payment' => $sample['next_payment_at'],
			),
			self::template_value( 'recurring_cancel_label', array(), $locale ),
			home_url( '/' )
		);

		return self::send_email(
			$email,
			/* translators: %s: the email's own subject line. */
			sprintf( __( '[Test] %s', 'donations-for-planetstudio-payment-gateway' ), $composed['subject'] ),
			$composed['html']
		);
	}

	// -----------------------------------------------------------------
	// Templates
	// -----------------------------------------------------------------
	private static function subscription_locale( object $subscription ): string {
		return PSPGD_I18n::normalize_locale( (string) ( $subscription->language ?? '' ) );
	}

	private static function donation_locale( object $donation, ?object $donor = null ): string {
		$language = (string) ( $donation->language ?? '' );
		if ( '' === $language && $donor ) {
			$language = (string) ( $donor->language ?? '' );
		}

		return PSPGD_I18n::normalize_locale( $language );
	}

	/**
	 * Resolve an email template in a specific language.
	 *
	 * The locale is an argument, never inferred from the current request:
	 * most of these emails are sent from cron or from a bank callback, where
	 * there is no page and therefore no language to read. Inferring it there
	 * is what sent every follow-up email in English regardless of the donor.
	 */
	public static function template_value( string $key, array $vars = array(), ?string $locale = null ): string {
		$templates = (array) get_option( 'pspgd_email_templates', array() );
		$locale    = PSPGD_I18n::normalize_locale( (string) ( $locale ?? '' ) );

		$value = trim( (string) ( $templates[ $locale ][ $key ] ?? '' ) );
		if ( '' === $value ) {
			$value = self::default_template( $key, $locale );
		}

		$replace = array();
		foreach ( $vars as $var_key => $var_value ) {
			$replace[ '{' . $var_key . '}' ] = (string) $var_value;
		}

		return strtr( $value, $replace );
	}

	/**
	 * Built-in English wording for every template key. Other languages come
	 * from locale packs; see PSPGD_I18n::pack().
	 *
	 * @return array<string, string>
	 */
	public static function default_templates(): array {
		return array(
			'donation_receipt_subject'                    => 'Thank you for your donation',
			'donation_receipt_heading'                    => 'Thank you for your support',
			'donation_receipt_body'                       => 'We received your donation. The details are below.',
			'campaign_receipt_subject'                    => 'Thank you for supporting {campaign_title}',
			'campaign_receipt_heading'                    => 'You are supporting {campaign_title}',
			'campaign_receipt_body'                       => 'Your donation helps move {campaign_title} forward. Thank you for being part of this campaign.',
			'recurring_receipt_subject'                   => 'Your {interval} of {amount_formatted}',
			'recurring_receipt_heading'                   => 'Your recurring donation was received',
			'recurring_receipt_body'                      => 'This is the scheduled payment for your {interval}. Thank you for your continuous support.',
			'recurring_cancel_subject'                    => 'Recurring donation cancelled',
			'recurring_cancel_heading'                    => 'Recurring donation cancelled',
			'recurring_cancel_body'                       => 'Your recurring donation has been cancelled. No future payments will be made.',
			'recurring_cancel_label'                      => 'Cancel recurring donation',
			'recurring_activation_subject'                => 'Your recurring donation is active',
			'recurring_activation_heading'                => 'Thank you for your support',
			'recurring_activation_body'                   => 'Your {interval} of {amount_formatted} is set up. The first payment has been taken, and the next one is scheduled.',
			'recurring_setup_failed_subject'              => 'Your donation was received',
			'recurring_setup_failed_heading'              => 'Thank you for your support',
			'recurring_setup_failed_body'                 => 'Your donation was received, but we could not set up the recurring schedule. No future automatic payments will be made.',
			'recurring_payment_failed_subject'            => 'We could not process your recurring donation',
			'recurring_payment_failed_heading'            => 'Your recurring payment did not go through',
			'recurring_payment_failed_body'               => 'We tried to charge your {interval} of {amount_formatted}, but the payment did not go through. We will try again on the date below. {remaining} attempt(s) remain before this recurring donation is cancelled.',
			'recurring_cancelled_after_failures_subject'  => 'Your recurring donation has been cancelled',
			'recurring_cancelled_after_failures_heading'  => 'Your recurring donation has been cancelled',
			'recurring_cancelled_campaign_closed_subject' => 'Your recurring donation to {campaign_title} has ended',
			'recurring_cancelled_campaign_closed_heading' => 'Thank you -- this campaign is complete',
			'recurring_cancelled_campaign_closed_body'    => '{campaign_title} has {campaign_outcome}, so your {interval} of {amount_formatted} has ended with it, and nothing further will be charged. Thank you for seeing it through.',
			'recurring_cancelled_after_failures_body'     => 'We were unable to charge your {interval} of {amount_formatted} after several attempts, so it has been cancelled. No further charges will be made.',
		);
	}

	/** The built-in wording for one template key in one language. */
	public static function default_template( string $key, string $locale ): string {
		$pack    = PSPGD_I18n::pack( $locale )['templates'];
		$english = self::default_templates();

		return (string) ( $pack[ $key ] ?? $english[ $key ] ?? '' );
	}
}
