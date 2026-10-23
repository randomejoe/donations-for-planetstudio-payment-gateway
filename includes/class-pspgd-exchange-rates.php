<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily exchange rates, fetched once and reused from the database.
 *
 * Payments never use these: a donation is always charged in the currency the
 * donor picked. They exist for one display problem -- a campaign can collect
 * in several currencies, and a single "raised so far" figure has to express
 * all of it in one unit.
 *
 * Nothing here is ever on the critical path of a payment. A missing rate
 * means a campaign shows its currencies side by side instead of one total,
 * which is the behaviour this plugin had before rates came back.
 *
 * Rates are fetched by a daily cron job and read from the options table on
 * every request. The provider is free and unauthenticated, so it is asked at
 * most once per currency per day.
 */
final class PSPGD_Exchange_Rates {
	private const CRON_HOOK = 'pspgd_refresh_exchange_rates';

	/**
	 * Every rate is stored against one pivot currency and any other pair is
	 * crossed through it. AMD because every bank PSPG supports settles it and
	 * the rate source publishes it; the choice is internal and has no effect
	 * on which currencies a site offers or sets its goals in.
	 */
	private const PIVOT = 'AMD';

	/** Refuse to reuse a cached rate older than this. */
	private const MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * How long to wait before trying the provider again after a failure,
	 * so an outage cannot turn into a fetch on every admin page load.
	 */
	private const RETRY_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * The furthest a fetched rate may move from the last known one, either
	 * way, before it is refused: 0.25 accepts anything from 1/1.25 to 1.25
	 * times the old rate. See rate_is_plausible().
	 */
	private const MAX_CHANGE = 0.25;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_rates' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		// A safety net for installs where WP-Cron never fires: top up from an
		// admin request, but only when there is nothing usable cached at all.
		add_action( 'admin_init', array( __CLASS__, 'maybe_refresh' ) );
	}

	// -----------------------------------------------------------------
	// Conversion
	// -----------------------------------------------------------------

	/**
	 * Convert an amount between two supported currencies.
	 *
	 * @return array{ok: bool, amount?: float, rate?: float, message?: string}
	 */
	public static function convert( float $amount, string $from, string $to ): array {
		$from = strtoupper( trim( $from ) );
		$to   = strtoupper( trim( $to ) );

		if ( $from === $to ) {
			return array( 'ok' => true, 'amount' => round( $amount, 2 ), 'rate' => 1.0 );
		}

		$rate = self::rate( $from, $to );
		if ( $rate <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'Unable to retrieve the current exchange rate. Please try again shortly.', 'donations-for-planetstudio-payment-gateway' ) );
		}

		return array( 'ok' => true, 'amount' => round( $amount * $rate, 2 ), 'rate' => $rate );
	}

	/**
	 * The rate that turns one unit of $from into $to.
	 *
	 * Only rates against the pivot are stored, so any other pair is crossed
	 * through it. Returns 0.0 when either leg is missing or too old --
	 * callers must treat that as "no total available", never as free money.
	 */
	public static function rate( string $from, string $to ): float {
		$from = strtoupper( trim( $from ) );
		$to   = strtoupper( trim( $to ) );

		if ( $from === $to ) {
			return 1.0;
		}

		$from_pivot = self::rate_to_pivot( $from );
		$to_pivot   = self::rate_to_pivot( $to );

		if ( $from_pivot <= 0 || $to_pivot <= 0 ) {
			return 0.0;
		}

		return $from_pivot / $to_pivot;
	}

	/**
	 * Express a set of per-currency totals as a single figure.
	 *
	 * All or nothing on purpose: if even one currency cannot be converted,
	 * the caller gets ok=false and shows the currencies separately rather
	 * than a total that quietly leaves donations out of it.
	 *
	 * @param array<string, array{total: float, count?: int}|float> $by_currency
	 *
	 * @return array{ok: bool, total: float, target: string, converted: bool, missing: string[]}
	 */
	public static function combine( array $by_currency, string $target ): array {
		$target = strtoupper( trim( $target ) );
		$result = array(
			'ok'        => true,
			'total'     => 0.0,
			'target'    => $target,
			'converted' => false,
			'missing'   => array(),
		);

		foreach ( $by_currency as $currency => $totals ) {
			$currency = strtoupper( (string) $currency );
			$amount   = is_array( $totals ) ? (float) ( $totals['total'] ?? 0 ) : (float) $totals;

			if ( '' === $currency ) {
				continue;
			}

			if ( $currency === $target ) {
				$result['total'] += $amount;
				continue;
			}

			$conversion = self::convert( $amount, $currency, $target );
			if ( empty( $conversion['ok'] ) ) {
				$result['ok']        = false;
				$result['missing'][] = $currency;
				continue;
			}

			$result['total']    += (float) $conversion['amount'];
			$result['converted'] = true;
		}

		if ( ! $result['ok'] ) {
			$result['total'] = 0.0;
		}

		return $result;
	}

	/**
	 * True when every supported currency can currently be converted -- and
	 * trivially true for a single-currency site, which never needs a rate.
	 */
	public static function rates_available(): bool {
		if ( ! self::needs_rates() ) {
			return true;
		}

		foreach ( self::supported_currencies() as $currency ) {
			if ( self::rate_to_pivot( $currency ) <= 0 ) {
				return false;
			}
		}

		return true;
	}

	/** Rates for display are read only from the database; this never calls the provider. */
	public static function display_rates(): array {
		$rates = array();
		foreach ( self::supported_currencies() as $currency ) {
			$rates[ $currency ] = self::PIVOT === $currency ? 1.0 : self::rate_to_pivot( $currency );
		}

		return $rates;
	}

	// -----------------------------------------------------------------
	// Fetching and scheduling
	// -----------------------------------------------------------------

	/** Fetch all supported rates once and persist them in the WordPress database. */
	public static function refresh_rates(): void {
		// A site offering one currency has nothing to combine, so it never
		// calls the rate source at all.
		if ( ! self::needs_rates() ) {
			return;
		}

		$failed = false;
		foreach ( self::supported_currencies() as $currency ) {
			if ( self::PIVOT === $currency ) {
				continue;
			}

			if ( ! self::fetch_and_store_rate( $currency ) ) {
				$failed = true;
			}
		}

		if ( $failed ) {
			// Hold off the lazy top-up below; the daily job will try again.
			set_transient( self::retry_lock_key(), 1, self::RETRY_INTERVAL );
		} else {
			delete_transient( self::retry_lock_key() );
		}
	}

	/**
	 * Fetch rates from an admin request when the cron job has never
	 * delivered any -- a fresh install, or a site with WP-Cron disabled and
	 * no system cron behind it. Deliberately not hooked to front-end
	 * requests: a visitor should never wait on an outbound HTTP call.
	 */
	public static function maybe_refresh(): void {
		if ( self::rates_available() || get_transient( self::retry_lock_key() ) ) {
			return;
		}

		self::refresh_rates();
	}

	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Start now rather than in 24 hours, so a fresh install has
			// rates on the next request instead of tomorrow.
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function schedule_daily_refresh(): void {
		self::ensure_schedule();
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	/** Whether this site has more than one currency to combine. */
	public static function needs_rates(): bool {
		return count( self::supported_currencies() ) > 1;
	}

	/**
	 * Every currency a figure may need converting from or to: the ones
	 * offered on the form, plus the goal currency.
	 *
	 * @return string[]
	 */
	private static function supported_currencies(): array {
		return array_values( array_unique( array_merge( PSPGD_PSPG::allowed_currencies(), array( PSPGD_Settings::goal_currency() ) ) ) );
	}

	private static function rate_to_pivot( string $currency ): float {
		$currency = strtoupper( trim( $currency ) );
		if ( self::PIVOT === $currency ) {
			return 1.0;
		}

		$stored = self::stored_rate( $currency );

		return $stored ? (float) $stored['rate'] : 0.0;
	}

	/**
	 * The cached rate for a currency, or null when it is absent, malformed
	 * or older than the plugin is willing to reuse.
	 *
	 * @return array{rate: float, fetched_at: int}|null
	 */
	private static function stored_rate( string $currency ): ?array {
		$stored = get_option( self::fallback_rate_key( $currency ), array() );
		if ( ! is_array( $stored ) || empty( $stored['rate'] ) || empty( $stored['fetched_at'] ) ) {
			return null;
		}

		$rate = (float) $stored['rate'];
		if ( $rate <= 0 ) {
			return null;
		}

		$max_age = (int) apply_filters( 'pspgd_exchange_rate_max_age', self::MAX_AGE );
		if ( $max_age > 0 && ( time() - (int) $stored['fetched_at'] ) > $max_age ) {
			return null;
		}

		return array( 'rate' => $rate, 'fetched_at' => (int) $stored['fetched_at'] );
	}

	/**
	 * Ask the public rate source for one currency's rate against the pivot.
	 *
	 * This is the plugin's only call to a third-party service. It sends no
	 * donor or site data -- just a request for a published JSON file -- and
	 * can be switched off with the `pspgd_fetch_exchange_rates` filter, or
	 * pointed elsewhere with `pspgd_exchange_rate_sources`.
	 */
	private static function fetch_and_store_rate( string $currency ): bool {
		if ( ! apply_filters( 'pspgd_fetch_exchange_rates', true ) ) {
			return false;
		}

		$currency = strtolower( $currency );
		$pivot    = strtolower( self::PIVOT );
		$path     = '/v1/currencies/' . $currency . '.min.json';
		$urls     = (array) apply_filters(
			'pspgd_exchange_rate_sources',
			array(
				// Data, not an asset: a server-side daily download of published rates. See readme, External services.
				'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest' . $path, // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
				'https://latest.currency-api.pages.dev' . $path,
			),
			strtoupper( $currency )
		);

		foreach ( $urls as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 8 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$rate = is_array( $data ) && isset( $data[ $currency ][ $pivot ] ) ? (float) $data[ $currency ][ $pivot ] : 0.0;
			if ( $rate > 0 && self::rate_is_plausible( $currency, $rate, (string) $url ) ) {
				update_option(
					self::fallback_rate_key( $currency ),
					array( 'rate' => $rate, 'fetched_at' => time() ),
					false
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a fetched rate is close enough to the last known one to be
	 * believed.
	 *
	 * Converted totals decide when a campaign closes at its goal, and closing
	 * cancels its recurring donors for good. One bad figure from a free feed
	 * -- a slipped decimal point, a broken mirror -- must not be able to do
	 * that, so a rate that moves further than MAX_CHANGE in one step is
	 * refused and logged, and the last known rate stays in use. A real move
	 * that large is accepted once the last known rate has aged out (MAX_AGE);
	 * until then campaigns go on using the old rate.
	 */
	private static function rate_is_plausible( string $currency, float $rate, string $source ): bool {
		$last = self::stored_rate( $currency );
		if ( ! $last ) {
			return true;
		}

		$max_change = (float) apply_filters( 'pspgd_exchange_rate_max_change', self::MAX_CHANGE, strtoupper( $currency ) );
		if ( $max_change <= 0 ) {
			return true;
		}

		$ratio = $rate / $last['rate'];
		if ( $ratio <= 1 + $max_change && $ratio >= 1 / ( 1 + $max_change ) ) {
			return true;
		}

		PSPGD_Repository::log_event(
			'exchange_rate',
			0,
			'exchange_rate_rejected',
			sprintf( 'A fetched %s rate moved too far from the last known one, so it was ignored and the last known rate kept.', strtoupper( $currency ) ),
			array(
				'currency'  => strtoupper( $currency ),
				'rate'      => $rate,
				'last_rate' => $last['rate'],
				'url'       => $source,
			)
		);

		return false;
	}

	private static function fallback_rate_key( string $currency ): string {
		return 'pspgd_exchange_rate_' . strtolower( $currency ) . '_' . strtolower( self::PIVOT ) . '_last_known';
	}

	private static function retry_lock_key(): string {
		return 'pspgd_exchange_rate_retry';
	}
}
