<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who is submitting the donation form, how often, and whether they are a
 * person: the visitor's address, the per-address throttle, and the optional
 * Cloudflare Turnstile check.
 */
final class PSPGD_Security {

	/** Where the visitor's address can be read from. */
	public const IP_SOURCES = array( 'remote_addr', 'cloudflare', 'x_real_ip', 'x_forwarded_for' );

	// Cloudflare's verification API, called server-side. See readme, External services.
	private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent

	// -----------------------------------------------------------------
	// The visitor's address
	// -----------------------------------------------------------------

	/** The configured source, defaulting to the direct connection. */
	public static function ip_source(): string {
		$source = sanitize_key( (string) get_option( 'pspgd_client_ip_source', 'remote_addr' ) );

		return in_array( $source, self::IP_SOURCES, true ) ? $source : 'remote_addr';
	}

	/**
	 * The visitor's address.
	 *
	 * REMOTE_ADDR is the only value a visitor cannot write, so it is the
	 * default. Behind a CDN or proxy it is the proxy's address, shared by
	 * every visitor -- so a site behind one says which header its proxy sets.
	 * A header that is missing or is not an address falls back to
	 * REMOTE_ADDR rather than to anything a visitor typed.
	 */
	public static function client_ip(): string {
		$remote = self::server_ip( 'REMOTE_ADDR' );

		switch ( self::ip_source() ) {
			case 'cloudflare':
				$address = self::server_ip( 'HTTP_CF_CONNECTING_IP' );
				break;
			case 'x_real_ip':
				$address = self::server_ip( 'HTTP_X_REAL_IP' );
				break;
			case 'x_forwarded_for':
				$address = self::nearest_forwarded_ip();
				break;
			default:
				$address = '';
		}

		$address = '' !== $address ? $address : $remote;

		/**
		 * Filters the visitor address the donation form throttles on.
		 *
		 * @param string $address The address chosen from the configured source.
		 * @param string $remote  REMOTE_ADDR as PHP received it.
		 */
		return (string) apply_filters( 'pspgd_client_ip', $address, $remote );
	}

	/**
	 * What each possible source holds on the current request, for the
	 * settings screen: an administrator can see which one carries their own
	 * address.
	 *
	 * @return array<string, string> Source key => address ('' when absent).
	 */
	public static function addresses_seen(): array {
		return array(
			'remote_addr'     => self::server_ip( 'REMOTE_ADDR' ),
			'cloudflare'      => self::server_ip( 'HTTP_CF_CONNECTING_IP' ),
			'x_real_ip'       => self::server_ip( 'HTTP_X_REAL_IP' ),
			'x_forwarded_for' => self::nearest_forwarded_ip(),
		);
	}

	/** One $_SERVER entry, if it is a valid address. */
	private static function server_ip( string $key ): string {
		$value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';

		return self::valid_ip( $value );
	}

	/**
	 * The last address in X-Forwarded-For: the one the nearest proxy saw.
	 * Anything before it was supplied by whoever connected to that proxy,
	 * and can be anything they liked.
	 */
	private static function nearest_forwarded_ip(): string {
		$header = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		$parts  = array_values( array_filter( array_map( 'trim', explode( ',', $header ) ) ) );

		return $parts ? self::valid_ip( (string) end( $parts ) ) : '';
	}

	private static function valid_ip( string $value ): string {
		$value = trim( $value );

		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	// -----------------------------------------------------------------
	// The throttle
	// -----------------------------------------------------------------

	/**
	 * How many requests one address may make per window.
	 *
	 * 'attempt' counts donations that passed every check and are about to
	 * reach the bank; 'submit' is a looser ceiling on submissions of any
	 * kind, valid or not, so a flood of junk posts is cut off cheaply.
	 */
	public static function rate_limit( string $bucket = 'attempt' ): int {
		if ( 'submit' === $bucket ) {
			return max( 1, (int) apply_filters( 'pspgd_submit_limit', 30 ) );
		}

		return max( 1, (int) apply_filters( 'pspgd_rate_limit', 5 ) );
	}

	/** The throttle window, in seconds. */
	public static function rate_window(): int {
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'pspgd_rate_window', 10 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Count one donation attempt against an address and say whether it is
	 * still inside the limit.
	 *
	 * The window is fixed from the first attempt: the stored counter expires
	 * when the window does, so a refused donor can wait it out. (It used to
	 * be reset on every attempt, so someone who kept trying stayed locked out
	 * for good.) Only attempts that passed validation and are about to reach
	 * the bank are counted -- five corrected typos are not five donations.
	 */
	public static function within_rate_limit( string $address, string $bucket = 'attempt' ): bool {
		if ( '' === $address ) {
			return true;
		}

		$bucket = 'submit' === $bucket ? 'submit' : 'attempt';
		$limit  = self::rate_limit( $bucket );
		$key    = 'pspgd_rate_' . $bucket . '_' . md5( $address );
		$now    = PSPGD_Clock::now();
		$window = self::rate_window();
		$state  = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || $now - (int) $state['start'] >= $window ) {
			$state = array(
				'start' => $now,
				'count' => 0,
			);
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, max( 1, $window - ( $now - (int) $state['start'] ) ) );

		if ( $limit + 1 === $state['count'] ) {
			// Only the moment the limit is crossed is recorded. Logging every
			// refused request would turn a flood into a second flood, of
			// database writes.
			PSPGD_Repository::log_event(
				'security',
				0,
				'donation_rate_limited',
				'submit' === $bucket
					? sprintf( 'Donation form submissions from one address exceeded %1$d in %2$d minutes and are being refused until the window ends.', $limit, (int) ( $window / MINUTE_IN_SECONDS ) )
					: sprintf( 'Donation attempts from one address exceeded %1$d in %2$d minutes and are being refused until the window ends.', $limit, (int) ( $window / MINUTE_IN_SECONDS ) )
			);
		}

		return $state['count'] <= $limit;
	}

	// -----------------------------------------------------------------
	// Cloudflare Turnstile
	// -----------------------------------------------------------------

	/**
	 * The Turnstile keys saved in PlanetStudio Payment Gateway. The site
	 * enters them once, there; this plugin only decides whether its own form
	 * uses them.
	 *
	 * @return array{site_key: string, secret_key: string, theme: string}
	 */
	public static function turnstile_keys(): array {
		$keys = array(
			'site_key'   => '',
			'secret_key' => '',
			'theme'      => 'auto',
		);

		if ( ! class_exists( 'PSPG_Bot_Protection' ) || ! method_exists( 'PSPG_Bot_Protection', 'get_settings' ) ) {
			return $keys;
		}

		$settings = (array) PSPG_Bot_Protection::get_settings();
		$theme    = sanitize_key( (string) ( $settings['theme'] ?? 'auto' ) );

		return array(
			'site_key'   => trim( (string) ( $settings['site_key'] ?? '' ) ),
			'secret_key' => trim( (string) ( $settings['secret_key'] ?? '' ) ),
			'theme'      => in_array( $theme, array( 'light', 'dark', 'auto' ), true ) ? $theme : 'auto',
		);
	}

	/** Whether PSPG holds both Turnstile keys. */
	public static function turnstile_available(): bool {
		$keys = self::turnstile_keys();

		return '' !== $keys['site_key'] && '' !== $keys['secret_key'];
	}

	/** Whether the donation form asks for a Turnstile check. Off by default. */
	public static function turnstile_active(): bool {
		return '1' === (string) get_option( 'pspgd_turnstile', '0' ) && self::turnstile_available();
	}

	/**
	 * Ask Cloudflare whether the challenge this donor solved is genuine.
	 *
	 * Fails closed: an unanswered check is a refused donation, because a
	 * check that waves everything through when Cloudflare cannot be reached
	 * protects nothing. Unreachable is logged, so a server that cannot reach
	 * Cloudflare at all is found on the first test donation.
	 */
	public static function verify_turnstile( string $token, string $address ): bool {
		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post(
			self::TURNSTILE_VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => self::turnstile_keys()['secret_key'],
					'response' => $token,
					'remoteip' => $address,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			PSPGD_Repository::log_event( 'security', 0, 'turnstile_unreachable', 'Cloudflare Turnstile could not be reached to check a donation, so the donation was refused.' );
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && ! empty( $data['success'] );
	}
}
