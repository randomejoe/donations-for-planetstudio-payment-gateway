<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails to the organisation's own staff: a short note for each new
 * donation, and an alert when a payment is held for review.
 *
 * Off until someone enters an address on the Emails screen. Written in the
 * site's admin language with the plugin's own strings, not the donor-facing
 * templates: these are working notes for staff, not something to brand or
 * reword.
 */
final class PSPGD_Notifications {

	/**
	 * Addresses from the "Notify staff" setting.
	 *
	 * @return string[]
	 */
	public static function staff_recipients(): array {
		$raw        = (string) get_option( 'pspgd_staff_notify_emails', '' );
		$recipients = array();

		foreach ( preg_split( '/[\s,;]+/', $raw ) as $candidate ) {
			$email = sanitize_email( (string) $candidate );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $recipients, true ) ) {
				$recipients[] = $email;
			}
		}

		return $recipients;
	}

	/** A donation has been paid. Sent once per donation. */
	public static function donation_received( object $donation ): void {
		$recipients = self::staff_recipients();
		if ( ! $recipients || PSPGD_Repository::event_exists( 'donation', (int) $donation->id, 'staff_notified' ) ) {
			return;
		}

		$donor  = PSPGD_Repository::find_donor( (int) $donation->donor_id );
		$amount = PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency );
		$name   = $donor ? trim( (string) $donor->first_name . ' ' . (string) $donor->last_name ) : '';

		$subject = sprintf(
			/* translators: 1: amount with currency, 2: donor name. */
			__( 'New donation: %1$s from %2$s', 'donations-for-planetstudio-payment-gateway' ),
			$amount,
			'' !== $name ? $name : __( 'a donor', 'donations-for-planetstudio-payment-gateway' )
		);

		$sent = self::send(
			$recipients,
			self::subject_prefix( $donation ) . $subject,
			__( 'A donation has been received.', 'donations-for-planetstudio-payment-gateway' ),
			self::details( $donation, $donor )
		);

		PSPGD_Repository::log_event( 'donation', (int) $donation->id, $sent ? 'staff_notified' : 'staff_notification_failed' );
	}

	/**
	 * The bank reported a payment as paid for a different amount or
	 * currency than the donation asked for. Staff need to look at it: it is
	 * in no total and the donor has not been sent a receipt.
	 *
	 * @param array{amount: float, currency: string}|null $settled
	 */
	public static function donation_held_for_review( object $donation, ?array $settled ): void {
		$recipients = self::staff_recipients();
		if ( ! $recipients ) {
			return;
		}

		$donor   = PSPGD_Repository::find_donor( (int) $donation->donor_id );
		$details = self::details( $donation, $donor );

		if ( $settled ) {
			$details[ __( 'Amount the bank reported', 'donations-for-planetstudio-payment-gateway' ) ] = PSPGD_I18n::format_amount(
				(float) $settled['amount'],
				'' !== $settled['currency'] ? $settled['currency'] : (string) $donation->currency
			);
		}

		$sent = self::send(
			$recipients,
			self::subject_prefix( $donation ) . sprintf(
				/* translators: %d: donation number. */
				__( 'Donation #%d needs review: the bank reported a different amount', 'donations-for-planetstudio-payment-gateway' ),
				(int) $donation->id
			),
			__( 'The bank reported this payment as paid, but for a different amount or currency than the donor chose. It has been left out of every total and no receipt was sent. Compare the order in PlanetStudio Payment Gateway with your bank before doing anything else.', 'donations-for-planetstudio-payment-gateway' ),
			$details
		);

		PSPGD_Repository::log_event( 'donation', (int) $donation->id, $sent ? 'staff_review_notified' : 'staff_notification_failed' );
	}

	private static function subject_prefix( object $donation ): string {
		return empty( $donation->is_test ) ? '' : __( '[Test] ', 'donations-for-planetstudio-payment-gateway' );
	}

	/**
	 * @return array<string, string> Label => value. Empty values are dropped when rendered.
	 */
	private static function details( object $donation, ?object $donor ): array {
		$types = array(
			'one_time'          => __( 'One-time', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_initial' => __( 'First payment of a recurring donation', 'donations-for-planetstudio-payment-gateway' ),
			'recurring'         => __( 'Scheduled recurring payment', 'donations-for-planetstudio-payment-gateway' ),
		);

		$campaign = (int) $donation->campaign_id > 0 ? get_post( (int) $donation->campaign_id ) : null;

		return array(
			__( 'Donation', 'donations-for-planetstudio-payment-gateway' )       => '#' . (int) $donation->id,
			__( 'Amount', 'donations-for-planetstudio-payment-gateway' )         => PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency ),
			__( 'Donor', 'donations-for-planetstudio-payment-gateway' )          => $donor ? trim( (string) $donor->first_name . ' ' . (string) $donor->last_name ) : '',
			__( 'Email', 'donations-for-planetstudio-payment-gateway' )          => $donor ? (string) $donor->email : '',
			__( 'Type', 'donations-for-planetstudio-payment-gateway' )           => $types[ (string) $donation->type ] ?? (string) $donation->type,
			__( 'Campaign', 'donations-for-planetstudio-payment-gateway' )       => $campaign ? (string) $campaign->post_title : '',
			__( 'Anonymous', 'donations-for-planetstudio-payment-gateway' )      => empty( $donation->is_anonymous ) ? '' : __( 'Yes, do not name them publicly', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Bank reference', 'donations-for-planetstudio-payment-gateway' ) => (string) $donation->transaction_reference,
		);
	}

	/**
	 * @param string[]              $recipients
	 * @param array<string, string> $details
	 */
	private static function send( array $recipients, string $subject, string $intro, array $details ): bool {
		$rows = '';
		foreach ( $details as $label => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			$rows .= '<tr><td style="padding:6px 16px 6px 0;color:#6b7280;vertical-align:top;">' . esc_html( (string) $label ) . '</td><td style="padding:6px 0;color:#111827;font-weight:600;">' . esc_html( $value ) . '</td></tr>';
		}

		$log_url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=pspgd' ) : '';
		$html    = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:24px;font-family:-apple-system,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#111827;background:#ffffff;">'
			. '<p style="margin:0 0 16px;">' . esc_html( $intro ) . '</p>'
			. '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">' . $rows . '</table>'
			. ( '' !== $log_url ? '<p style="margin:0;"><a href="' . esc_url( $log_url ) . '">' . esc_html__( 'Open the donation log', 'donations-for-planetstudio-payment-gateway' ) . '</a></p>' : '' )
			. '</body></html>';

		$sent = false;
		foreach ( $recipients as $recipient ) {
			$sent = PSPGD_Subscriptions::send_email( $recipient, $subject, $html ) || $sent;
		}

		return $sent;
	}
}
