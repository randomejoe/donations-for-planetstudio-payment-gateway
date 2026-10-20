<?php
/**
 * Campaign totals: what the database may add up, and what the page shows.
 *
 * The query layer still refuses to add currencies together -- SQL has no
 * rates -- and the display layer combines them only when it has a rate for
 * every currency in the campaign.
 *
 * Run from the plugin folder: php tests/campaign-progress-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';

/** Labels resolve through the site locale; these tests read the English ones. */
function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

/** Seed the rate cache the way a successful daily refresh would have left it. */
function campaign_seed_rates( array $rates ): void {
	foreach ( $rates as $currency => $rate ) {
		update_option(
			'pspgd_exchange_rate_' . strtolower( (string) $currency ) . '_amd_last_known',
			array( 'rate' => $rate, 'fetched_at' => time() ),
			false
		);
	}
}

/** A campaign_progress() result, without going through the database. */
function campaign_progress_of( array $totals ): array {
	$progress = array( 'by_currency' => array(), 'donation_count' => 0 );
	foreach ( $totals as $currency => $total ) {
		$progress['by_currency'][ $currency ] = array( 'total' => (float) $total, 'count' => 1 );
		$progress['donation_count']++;
	}
	return $progress;
}

run_tests(
	array(
		// -------------------------------------------------------------
		// The query layer
		// -------------------------------------------------------------

		'paid totals are reported per currency, never combined in SQL' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();
			$wpdb->result_sets = array(
				array(
					test_row( array( 'currency' => 'AMD', 'donation_count' => '4', 'total' => '1240000.00' ) ),
					test_row( array( 'currency' => 'USD', 'donation_count' => '2', 'total' => '310.00' ) ),
					test_row( array( 'currency' => 'EUR', 'donation_count' => '1', 'total' => '95.00' ) ),
				),
			);

			$progress = PSPGD_Repository::campaign_progress( array( 12 ) );

			assert_same( 1240000.0, $progress['by_currency']['AMD']['total'], 'AMD must be reported on its own.' );
			assert_same( 310.0, $progress['by_currency']['USD']['total'], 'USD must be reported on its own.' );
			assert_same( 95.0, $progress['by_currency']['EUR']['total'], 'EUR must be reported on its own.' );
			assert_same( 7, $progress['donation_count'], 'The donation count spans every currency.' );
			assert_same( 1240000.0, PSPGD_Campaigns::base_subtotal( $progress, 'AMD' ), 'The goal-currency subtotal is what a campaign falls back to without rates.' );
			assert_same( 310.0, PSPGD_Campaigns::base_subtotal( $progress, 'USD' ), 'Any currency can be the goal currency.' );
		},

		'the query groups by currency and only counts paid donations' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();
			$wpdb->result_sets = array( array() );

			PSPGD_Repository::campaign_progress( array( 12, 34 ) );

			assert_contains( 'GROUP BY currency', $wpdb->reads[0], 'Totals must be grouped, not summed across currencies.' );
			assert_contains( "status = 'paid'", $wpdb->reads[0], 'Pending and failed donations must not appear in a total.' );
		},

		'no campaigns means no query and an empty result' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			$progress = PSPGD_Repository::campaign_progress( array() );

			assert_same( 0, count( $wpdb->reads ), 'An empty campaign list must not hit the database.' );
			assert_same( array(), $progress['by_currency'], 'An empty campaign list has no totals.' );
			assert_same( 0.0, PSPGD_Campaigns::base_subtotal( $progress, 'AMD' ), 'An empty campaign list has no goal progress.' );
		},

		// -------------------------------------------------------------
		// The display layer
		// -------------------------------------------------------------

		'every currency counts toward the goal once rates are available' => static function (): void {
			test_reset_options();
			campaign_seed_rates( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			$view = PSPGD_Campaigns::progress_view(
				campaign_progress_of( array( 'AMD' => 50000.0, 'USD' => 100.0, 'EUR' => 100.0 ) ),
				200000.0
			);

			assert_true( $view['combined'], 'Three rates, three currencies: one figure.' );
			assert_same( 134000.0, $view['total'], '50 000 AMD + 100 USD + 100 EUR at the cached rates.' );
			assert_same( 67, $view['percent'], 'A donation in EUR must move the bar like any other.' );
		},

		'the same money is restated in each currency without moving the bar' => static function (): void {
			test_reset_options();
			campaign_seed_rates( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			$view = PSPGD_Campaigns::progress_view(
				campaign_progress_of( array( 'AMD' => 50000.0, 'USD' => 100.0, 'EUR' => 100.0 ) ),
				200000.0
			);

			assert_same( array( 'AMD', 'USD', 'EUR' ), array_keys( $view['currencies'] ), 'A visitor can switch between every currency the plugin accepts.' );
			assert_same( '134,000 AMD', $view['currencies']['AMD']['raised_label'], 'AMD is shown without a minor unit.' );
			assert_same( '335.00 USD', $view['currencies']['USD']['raised_label'], '134 000 AMD is 335 USD at 400.' );
			assert_same( 'of 500.00 USD goal', $view['currencies']['USD']['goal_label'], 'The goal converts with the total, so the percentage never changes.' );
			assert_same( 'of 200,000 AMD goal', $view['currencies']['AMD']['goal_label'], 'The goal is still set and stored in AMD.' );
		},

		'a campaign with no goal shows what it has raised instead of a percentage' => static function (): void {
			test_reset_options();
			campaign_seed_rates( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			$view = PSPGD_Campaigns::progress_view( campaign_progress_of( array( 'USD' => 100.0 ) ), 0.0 );

			assert_same( 0, $view['percent'], 'No goal means no bar to fill.' );
			assert_same( 'raised so far', $view['currencies']['AMD']['goal_label'], 'An open-ended campaign still reports its total.' );
			assert_same( '40,000 AMD', $view['currencies']['AMD']['raised_label'], 'A USD-only campaign still has an AMD figure.' );
		},

		'without a rate the campaign falls back to listing currencies' => static function (): void {
			test_reset_options();
			campaign_seed_rates( array( 'usd' => 400.0 ) );

			$view = PSPGD_Campaigns::progress_view(
				campaign_progress_of( array( 'AMD' => 50000.0, 'USD' => 100.0, 'EUR' => 100.0 ) ),
				200000.0
			);

			assert_false( $view['combined'], 'A missing EUR rate must not silently drop the EUR donations.' );
			assert_same( 50000.0, $view['total'], 'The bar falls back to the AMD subtotal, which is the old behaviour.' );
			assert_same( array( 'AMD' ), array_keys( $view['currencies'] ), 'Nothing to switch to when nothing can be converted.' );
			assert_same( array( '100.00 USD', '100.00 EUR' ), $view['other'], 'The other currencies are listed beside the bar instead.' );
		},

		'an AMD-only campaign works on a site that has never fetched a rate' => static function (): void {
			test_reset_options();

			$view = PSPGD_Campaigns::progress_view( campaign_progress_of( array( 'AMD' => 50000.0 ) ), 100000.0 );

			assert_true( $view['combined'], 'There is nothing to convert, so nothing can be missing.' );
			assert_same( 50000.0, $view['total'], 'The AMD subtotal is the total.' );
			assert_same( 50, $view['percent'], 'Half way there.' );
			assert_false( $view['converted'], 'No conversion happened, so the admin screen must not claim one did.' );
		},

		'the bar never reports more than a full goal' => static function (): void {
			test_reset_options();
			campaign_seed_rates( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			$view = PSPGD_Campaigns::progress_view( campaign_progress_of( array( 'USD' => 1000.0 ) ), 100000.0 );

			assert_same( 400000.0, $view['total'], 'An overfunded campaign still reports everything it raised.' );
			assert_same( 100, $view['percent'], 'A progress bar cannot be more than full.' );
		},

		'a total built from more than one currency is marked as converted' => static function (): void {
			test_reset_options();
			campaign_seed_rates( array( 'usd' => 400.0, 'eur' => 440.0 ) );

			$view = PSPGD_Campaigns::progress_view( campaign_progress_of( array( 'AMD' => 1000.0, 'USD' => 10.0 ) ), 0.0 );

			assert_true( $view['converted'], 'A USD donation inside an AMD figure is a conversion, which the admin screen says out loud.' );
		},
	)
);
