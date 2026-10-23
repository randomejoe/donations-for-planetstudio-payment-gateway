<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The donor's side of a recurring donation: switching it on once the bank
 * has saved their card, and the cancel link every email carries. The emails
 * themselves are PSPGD_Emails.
 */
final class PSPGD_Subscriptions {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'render_cancel_confirmation' ) );
		add_action( 'admin_post_nopriv_pspgd_donation_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_pspgd_donation_cancel', array( __CLASS__, 'handle_cancel' ) );
	}

	public static function activate( object $subscription, string $binding_id ): void {
		if ( 'active' === $subscription->status ) {
			return;
		}

		$next_payment_at = PSPGD_Recurring::next_anniversary( $subscription );
		PSPGD_Repository::activate_subscription( (int) $subscription->id, $binding_id, $next_payment_at );

		PSPGD_Emails::notify_recurring_activated( $subscription, $next_payment_at );
	}

	public static function render_cancel_confirmation(): void {
		if ( ! isset( $_GET['pspgd_cancel'], $_GET['token'] ) ) {
			return;
		}
		$subscription = PSPGD_Repository::find_subscription( absint( $_GET['pspgd_cancel'] ) );
		$token        = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		if ( ! self::valid_token( $subscription, $token ) || 'active' !== $subscription->status ) {
			wp_die( esc_html__( 'This cancellation link is invalid or has already been used.', 'donations-for-planetstudio-payment-gateway' ), 404 );
		}
		wp_die(
			'<h1>' . esc_html__( 'Cancel recurring donation?', 'donations-for-planetstudio-payment-gateway' ) . '</h1><p>' . esc_html__( 'No future recurring payments will be made.', 'donations-for-planetstudio-payment-gateway' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pspgd_donation_cancel"><input type="hidden" name="subscription_id" value="' . esc_attr( (string) $subscription->id ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '"><input type="hidden" name="pspgd_cancel_nonce" value="' . esc_attr( wp_create_nonce( 'pspgd_donation_cancel_' . $subscription->id ) ) . '"><button type="submit">' . esc_html__( 'Cancel recurring donation', 'donations-for-planetstudio-payment-gateway' ) . '</button></form>',
			esc_html__( 'Cancel recurring donation', 'donations-for-planetstudio-payment-gateway' ),
			array( 'response' => 200 )
		);
	}

	public static function handle_cancel(): void {
		$subscription = PSPGD_Repository::find_subscription( absint( $_POST['subscription_id'] ?? 0 ) );
		$token        = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! $subscription || ! isset( $_POST['pspgd_cancel_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pspgd_cancel_nonce'] ) ), 'pspgd_donation_cancel_' . $subscription->id ) || ! self::valid_token( $subscription, $token ) ) {
			wp_die( esc_html__( 'This cancellation request is invalid.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		// The cancel link is now stable for the life of the subscription, so
		// the status is what makes cancelling single-use: a second submission
		// must not unbind the card again or send a second confirmation.
		if ( 'active' !== $subscription->status ) {
			wp_die( esc_html__( 'This recurring donation has already been cancelled.', 'donations-for-planetstudio-payment-gateway' ), 200 );
		}

		PSPGD_Repository::cancel_subscription( (int) $subscription->id );
		$result = PSPGD_PSPG::unbind_subscription( $subscription );
		PSPGD_Repository::log_event( 'subscription', (int) $subscription->id, 'subscription_cancelled', '', $result );

		PSPGD_Emails::notify_recurring_cancelled( $subscription );
		wp_die( '<h1>' . esc_html__( 'Recurring donation cancelled', 'donations-for-planetstudio-payment-gateway' ) . '</h1><p>' . esc_html__( 'No future payments will be made.', 'donations-for-planetstudio-payment-gateway' ) . '</p>', esc_html__( 'Recurring donation cancelled', 'donations-for-planetstudio-payment-gateway' ), array( 'response' => 200 ) );
	}

	// -----------------------------------------------------------------
	// Cancel links
	// -----------------------------------------------------------------
	public static function cancel_url( object $subscription ): string {
		return add_query_arg(
			array(
				'pspgd_cancel' => (int) $subscription->id,
				'token'            => rawurlencode( self::cancel_token( $subscription ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The donor's cancel token.
	 *
	 * Derived from the subscription rather than generated and stored, so that
	 * every email carries the same working link. Regenerating it on each send
	 * would silently invalidate the link in every email the donor had already
	 * received -- including the activation email, the one most likely to
	 * still be in their inbox months later. A donor who cannot self-cancel
	 * becomes a support ticket, or a chargeback.
	 *
	 * Same construction as the payment return token in PSPGD_PSPG:
	 * unguessable without the site's auth salt, and nothing secret is stored.
	 */
	private static function cancel_token( object $subscription ): string {
		return hash_hmac(
			'sha256',
			'pspgd_cancel|' . (int) $subscription->id . '|' . (string) $subscription->created_at,
			wp_salt( 'auth' )
		);
	}

	private static function valid_token( ?object $subscription, string $token ): bool {
		if ( ! $subscription || '' === $token ) {
			return false;
		}

		return hash_equals( self::cancel_token( $subscription ), $token );
	}

	// -----------------------------------------------------------------
	// Composition
	// -----------------------------------------------------------------
}
