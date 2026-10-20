<?php
/**
 * The daily rate cache behind combined campaign totals.
 *
 * Two things matter here. The provider is free and unauthenticated, so it
 * must be asked once a day and never once a page view. And a rate that is
 * missing, stale or unparseable must produce "no total", never a wrong one.
 *
 * Run from the plugin folder: php tests/exchange-rates-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';

const RATES_USD_BODY = '{"date":"2026-09-12","usd":{"amd":400,"eur":0.9}}';
const RATES_EUR_BODY = '{"date":"2026-09-12","eur":{"amd":440,"usd":1.1}}';

/** Seed the cache the way a successful refresh would have left it. */
function rates_seed( array $rates, int $age = 0 ): void {
	foreach ( $rates as $currency => $rate ) {
		update_option(
			'pspgd_exchange_rate_' . strtolower( (string) $currency ) . '_amd_last_known',
			array( 'rate' => $rate, 'fetched_at' => time() - $age ),
			false
		);
	}
}

function rates_reset(): void {
	test_reset_options();
	test_reset_transients();
	test_reset_http();
	test_reset_filters();
	test_reset_cron();
}

run_tests(
	array(
		'a refresh asks each provider once and stores what it gets' => static function (): void {
			rates_reset();
			test_queue_http( '/usd.min.json', 200, RATES_USD_BODY );
			test_queue_http( '/eur.min.json', 200, RATES_EUR_BODY );

			PSPGD_Exchange_Rates::refresh_rates();

			assert_same( 2, count( test_http_requests() ), 'One request per non-AMD currency, and no more.' );
			assert_same( array( 'AMD' => 1.0, 'USD' => 400.0, 'EUR' => 440.0 ), PSPGD_Exchange_Rates::display_rates(), 'Both rates must be readable from the database afterwards.' );
		},

		'converting reads the cache and never calls the provider' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			for ( $i = 0; $i < 5; $i++ ) {
				PSPGD_Exchange_Rates::convert( 100.0, 'USD', 'AMD' );
				PSPGD_Exchange_Rates::combine( array( 'USD' => array( 'total' => 100.0 ) ), 'AMD' );
			}

			assert_same( 0, count( test_http_requests() ), 'A page view must never wait on the rate provider.' );
		},

		'the backup provider covers the first one being down' => static function (): void {
			rates_reset();
			test_queue_http( 'cdn.jsdelivr.net', 'error' );
			test_queue_http( 'currency-api.pages.dev', 200, RATES_USD_BODY );
			test_queue_http( '/eur.min.json', 200, RATES_EUR_BODY );

			PSPGD_Exchange_Rates::refresh_rates();

			assert_same( 400.0, PSPGD_Exchange_Rates::rate( 'USD', 'AMD' ), 'A failed first provider must fall through to the second.' );
		},

		'a non-200 response and unparseable JSON are both treated as no rate' => static function (): void {
			rates_reset();
			test_queue_http( '/usd.min.json', 429, '' );
			test_queue_http( '/usd.min.json', 200, 'not json at all' );
			test_queue_http( '/eur.min.json', 200, '{"eur":{"usd":1.1}}' );

			PSPGD_Exchange_Rates::refresh_rates();

			assert_same( 0.0, PSPGD_Exchange_Rates::rate( 'USD', 'AMD' ), 'A rate-limited response must not be stored as a rate.' );
			assert_same( 0.0, PSPGD_Exchange_Rates::rate( 'EUR', 'AMD' ), 'A payload without an AMD figure is not a rate.' );
		},

		'rates that are not against AMD cross through it' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			$converted = PSPGD_Exchange_Rates::convert( 100.0, 'EUR', 'USD' );

			assert_true( (bool) $converted['ok'], 'EUR to USD is two stored rates away, not zero.' );
			assert_same( 110.0, (float) $converted['amount'], '100 EUR at 440 AMD is 44 000 AMD, which is 110 USD at 400.' );
		},

		'a currency converted to itself needs no rate at all' => static function (): void {
			rates_reset();

			$converted = PSPGD_Exchange_Rates::convert( 5000.0, 'AMD', 'AMD' );

			assert_true( (bool) $converted['ok'], 'An AMD-only campaign must work on a site that has never fetched a rate.' );
			assert_same( 5000.0, (float) $converted['amount'], 'Converting to the same currency must change nothing.' );
		},

		'a rate too old to trust is not reused' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0, 'eur' => 440.0 ), 31 * DAY_IN_SECONDS );

			assert_same( 0.0, PSPGD_Exchange_Rates::rate( 'USD', 'AMD' ), 'A month-old rate must not be presented as the current one.' );
			assert_false( PSPGD_Exchange_Rates::rates_available(), 'Stale rates mean no combined total.' );
		},

		'how old is too old can be shortened by a filter' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0, 'eur' => 440.0 ), 2 * DAY_IN_SECONDS );
			test_set_filter(
				'pspgd_exchange_rate_max_age',
				static function () {
					return DAY_IN_SECONDS;
				}
			);

			assert_same( 0.0, PSPGD_Exchange_Rates::rate( 'USD', 'AMD' ), 'A site that wants fresher rates must be able to say so.' );
		},

		'a total is combined only when every currency can be converted' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0 ) );
			$totals = array(
				'AMD' => array( 'total' => 50000.0 ),
				'USD' => array( 'total' => 100.0 ),
				'EUR' => array( 'total' => 100.0 ),
			);

			$combined = PSPGD_Exchange_Rates::combine( $totals, 'AMD' );

			assert_false( (bool) $combined['ok'], 'A missing EUR rate must not produce a total that silently omits EUR.' );
			assert_same( array( 'EUR' ), $combined['missing'], 'The caller is told which currency it could not express.' );
			assert_same( 0.0, (float) $combined['total'], 'A partial total must not be handed back as if it were whole.' );
		},

		'every currency present means one figure' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0, 'eur' => 440.0 ) );
			$totals = array(
				'AMD' => array( 'total' => 50000.0 ),
				'USD' => array( 'total' => 100.0 ),
				'EUR' => array( 'total' => 100.0 ),
			);

			$combined = PSPGD_Exchange_Rates::combine( $totals, 'AMD' );

			assert_true( (bool) $combined['ok'], 'All three rates are available, so all three can be added up.' );
			assert_same( 134000.0, (float) $combined['total'], '50 000 + 40 000 + 44 000 AMD.' );
			assert_true( (bool) $combined['converted'], 'Something other than AMD went into that figure.' );
		},

		'an AMD-only campaign is combined without touching a rate' => static function (): void {
			rates_reset();

			$combined = PSPGD_Exchange_Rates::combine( array( 'AMD' => array( 'total' => 50000.0 ) ), 'AMD' );

			assert_true( (bool) $combined['ok'], 'No foreign currency means nothing to convert.' );
			assert_same( 50000.0, (float) $combined['total'], 'The AMD subtotal is already the total.' );
			assert_false( (bool) $combined['converted'], 'Nothing was converted, so no rate note belongs on the page.' );
		},

		'the daily job is scheduled once and starts immediately' => static function (): void {
			rates_reset();

			PSPGD_Exchange_Rates::ensure_schedule();
			$first = wp_next_scheduled( 'pspgd_refresh_exchange_rates' );
			PSPGD_Exchange_Rates::ensure_schedule();

			assert_same( $first, wp_next_scheduled( 'pspgd_refresh_exchange_rates' ), 'An already scheduled job must not be rescheduled on every request.' );
			assert_same( 'daily', $GLOBALS['test_cron_recurrence']['pspgd_refresh_exchange_rates'], 'Once a day, as the provider expects.' );
			assert_true( $first <= time(), 'A fresh install needs rates now, not in 24 hours.' );

			PSPGD_Exchange_Rates::clear_schedule();
			assert_false( (bool) wp_next_scheduled( 'pspgd_refresh_exchange_rates' ), 'Deactivating the plugin must leave no orphaned cron event.' );
		},

		'a provider outage is not retried on every admin page load' => static function (): void {
			rates_reset();

			PSPGD_Exchange_Rates::maybe_refresh();
			$after_first = count( test_http_requests() );
			PSPGD_Exchange_Rates::maybe_refresh();

			assert_same( 4, $after_first, 'The first attempt tries both providers for both currencies.' );
			assert_same( $after_first, count( test_http_requests() ), 'A failure must hold off the next attempt rather than hammer the provider.' );
		},

		'the top-up does nothing once rates are cached' => static function (): void {
			rates_reset();
			rates_seed( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			PSPGD_Exchange_Rates::maybe_refresh();

			assert_same( 0, count( test_http_requests() ), 'Cached rates are reused for the day; the cron job refreshes them.' );
		},
	)
);
