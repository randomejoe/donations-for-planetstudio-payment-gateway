<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The link that lets a donor cancel their recurring donation, which every
 * recurring email carries. PSPGD_Emails puts it in; PSPGD_Subscriptions
 * checks it when the donor follows it.
 */
final class PSPGD_Cancel_Links {

	public static function url( object $subscription ): string {
		return add_query_arg(
			array(
				'pspgd_cancel' => (int) $subscription->id,
				'token'        => rawurlencode( self::token( $subscription ) ),
			),
			home_url( '/' )
		);
	}

	public static function is_valid( ?object $subscription, string $token ): bool {
		if ( ! $subscription || '' === $token ) {
			return false;
		}

		return hash_equals( self::token( $subscription ), $token );
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
	private static function token( object $subscription ): string {
		return hash_hmac(
			'sha256',
			'pspgd_cancel|' . (int) $subscription->id . '|' . (string) $subscription->created_at,
			wp_salt( 'auth' )
		);
	}
}
