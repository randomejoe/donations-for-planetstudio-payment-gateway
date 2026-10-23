<?php
/**
 * When a campaign stops taking money, and what happens to the donors who
 * had set up a recurring gift to it.
 *
 * Two things must never happen: a donor charged for a campaign that is over,
 * and a campaign retired on a total that has not counted every currency.
 *
 * Run from the plugin folder: php tests/campaign-closing-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-exchange-rates.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-i18n.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-campaigns.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-recurring.php';

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return determine_locale();
}

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

const CLOSED_REASON = '_pspgd_campaign_closed_reason';
const SETTLED       = '_pspgd_campaign_closed_settled';
const CLOSE_AT_GOAL = '_pspgd_campaign_close_at_goal';

/** A campaign whose editor ticked "Stop taking donations when the goal is reached". */
function closing_add_goal_campaign( int $campaign_id, float $goal ): void {
	test_add_campaign( $campaign_id, array( '_pspgd_campaign_goal' => $goal, CLOSE_AT_GOAL => '1' ) );
}

function closing_reset(): void {
	global $wpdb;
	test_reset_options();
	test_reset_transients();
	test_reset_posts();
	test_reset_mail();
	$wpdb->reset_log();
	$wpdb->result_sets = array();
	$wpdb->rows        = array();
	test_set_now( '2026-08-23 12:00:00' );
}

function closing_seed_rates(): void {
	foreach ( array( 'usd' => 400.0, 'eur' => 440.0 ) as $currency => $rate ) {
		update_option( 'pspgd_exchange_rate_' . $currency . '_amd_last_known', array( 'rate' => $rate, 'fetched_at' => time() ), false );
	}
}

/** Queue one campaign_progress() result set. */
function closing_queue_totals( array $totals ): void {
	global $wpdb;
	$rows = array();
	foreach ( $totals as $currency => $total ) {
		$rows[] = test_row( array( 'currency' => $currency, 'donation_count' => '1', 'total' => (string) $total ) );
	}
	$wpdb->result_sets[] = $rows;
}

/** The reopen check runs when an editor saves the campaign. */
function closing_reopen( int $campaign_id ): void {
	$method = new ReflectionMethod( 'PSPGD_Campaigns', 'reopen_if_settings_allow' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	$method->invoke( null, $campaign_id );
}

function closing_subscription( array $overrides = array() ): object {
	return (object) array_merge(
		array(
			'id'                   => 7,
			'donor_id'             => 3,
			'campaign_id'          => 12,
			'bank_id'              => 'acba',
			'amount'               => 10000.0,
			'currency'             => 'AMD',
			'interval_key'         => 'monthly',
			'status'               => 'active',
			'binding_id'           => 'binding-1',
			'email'                => 'donor@example.com',
			'language'             => 'en',
			'failed_payment_count' => 0,
			'is_anonymous'         => 0,
			'created_at'           => '2026-01-04 09:00:00',
		),
		$overrides
	);
}

run_tests(
	array(
		// -------------------------------------------------------------
		// The end date
		// -------------------------------------------------------------

		'a campaign runs to the end of its final day' => static function (): void {
			closing_reset();

			assert_false( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'An editor picking the 23rd means donations are taken on the 23rd.' );
			assert_false( PSPGD_Campaigns::deadline_passed( '2026-08-24' ), 'Tomorrow is not the past.' );
			assert_true( PSPGD_Campaigns::deadline_passed( '2026-08-22' ), 'Yesterday is.' );

			test_set_now( '2026-08-23 23:59:58' );
			assert_false( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'Two seconds before midnight is still the final day.' );
			test_set_now( '2026-08-24 00:00:01' );
			assert_true( PSPGD_Campaigns::deadline_passed( '2026-08-23' ), 'A second after midnight it is over.' );
		},

		'no end date and a malformed one both mean ongoing' => static function (): void {
			closing_reset();

			assert_false( PSPGD_Campaigns::deadline_passed( '' ), 'A blank end date is an ongoing campaign, not an expired one.' );
			assert_false( PSPGD_Campaigns::deadline_passed( 'soon' ), 'Unparseable meta must never close a campaign by accident.' );
		},

		'a passed end date closes the campaign before the sweep has run' => static function (): void {
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-08-01' ) );

			$status = PSPGD_Campaigns::status( 12 );

			assert_false( $status['open'], 'The date alone is enough to stop taking money.' );
			assert_same( 'ended', $status['reason'], 'The donor is told the campaign ended, not that it was funded.' );
		},

		'an open campaign needs no query to answer' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-12-31', '_pspgd_campaign_goal' => 100000.0 ) );

			assert_true( PSPGD_Campaigns::is_open( 12 ), 'A campaign with time left and an unmet goal is open.' );
			assert_same( 0, count( $wpdb->reads ), 'This runs on every form render; it must not total the donations.' );
		},

		// -------------------------------------------------------------
		// The goal
		// -------------------------------------------------------------

		'a campaign keeps taking donations past its goal unless told to stop' => static function (): void {
			global $wpdb;
			closing_reset();
			closing_seed_rates();
			test_add_campaign( 12, array( '_pspgd_campaign_goal' => 100000.0 ) );
			closing_queue_totals( array( 'AMD' => 120000.0 ) );

			assert_same( '', PSPGD_Campaigns::close_if_finished( 12 ), 'By default only the end date closes a campaign.' );
			assert_true( PSPGD_Campaigns::is_open( 12 ), 'An overfunded campaign is still open.' );
			assert_same( 0, count( $wpdb->reads ), 'Without the box ticked there is nothing to total.' );
		},

		'a goal reached across three currencies closes the campaign' => static function (): void {
			closing_reset();
			closing_seed_rates();
			closing_add_goal_campaign( 12, 130000.0 );
			closing_queue_totals( array( 'AMD' => 50000.0, 'USD' => 100.0, 'EUR' => 100.0 ) );

			$reason = PSPGD_Campaigns::close_if_finished( 12 );

			assert_same( 'goal_reached', $reason, '134 000 AMD of a 130 000 goal is funded, whatever currencies it arrived in.' );
			assert_false( PSPGD_Campaigns::is_open( 12 ), 'A funded campaign stops taking donations.' );
		},

		'a campaign short of its goal stays open' => static function (): void {
			closing_reset();
			closing_seed_rates();
			closing_add_goal_campaign( 12, 200000.0 );
			closing_queue_totals( array( 'AMD' => 50000.0, 'USD' => 100.0 ) );

			assert_same( '', PSPGD_Campaigns::close_if_finished( 12 ), '90 000 of 200 000 is not funded.' );
			assert_true( PSPGD_Campaigns::is_open( 12 ), 'Still asking for donations.' );
		},

		'a missing rate never retires a campaign on a partial total' => static function (): void {
			closing_reset();
			// USD only: the EUR donations below cannot be counted.
			update_option( 'pspgd_exchange_rate_usd_amd_last_known', array( 'rate' => 400.0, 'fetched_at' => time() ), false );
			closing_add_goal_campaign( 12, 100000.0 );
			closing_queue_totals( array( 'AMD' => 90000.0, 'USD' => 100.0, 'EUR' => 500.0 ) );

			assert_same( '', PSPGD_Campaigns::close_if_finished( 12 ), 'Without every currency the figure is not one to retire a campaign on.' );
		},

		'an AMD subtotal already past the goal closes it without any rate' => static function (): void {
			closing_reset();
			closing_add_goal_campaign( 12, 100000.0 );
			closing_queue_totals( array( 'AMD' => 120000.0, 'EUR' => 500.0 ) );

			assert_same( 'goal_reached', PSPGD_Campaigns::close_if_finished( 12 ), 'AMD alone is over the line, so the uncounted EUR cannot change the answer.' );
		},

		'a campaign with no goal is never closed by its total' => static function (): void {
			closing_reset();
			closing_seed_rates();
			test_add_campaign( 12, array( CLOSE_AT_GOAL => '1' ) );

			assert_same( '', PSPGD_Campaigns::close_if_finished( 12 ), 'An open-ended campaign has no line to cross.' );
		},

		'a closed campaign stays closed when the rate moves against it' => static function (): void {
			closing_reset();
			closing_seed_rates();
			closing_add_goal_campaign( 12, 130000.0 );
			closing_queue_totals( array( 'AMD' => 50000.0, 'USD' => 100.0, 'EUR' => 100.0 ) );
			PSPGD_Campaigns::close_if_finished( 12 );

			// The dram strengthens; the same donations are now worth less.
			update_option( 'pspgd_exchange_rate_usd_amd_last_known', array( 'rate' => 200.0, 'fetched_at' => time() ), false );
			update_option( 'pspgd_exchange_rate_eur_amd_last_known', array( 'rate' => 220.0, 'fetched_at' => time() ), false );

			assert_false( PSPGD_Campaigns::is_open( 12 ), 'Donors were told it was funded; it must not reopen and re-solicit them.' );
			assert_same( 'goal_reached', PSPGD_Campaigns::status( 12 )['reason'], 'The recorded reason does not get recalculated either.' );
		},

		// -------------------------------------------------------------
		// The donors
		// -------------------------------------------------------------

		'closing a campaign cancels its recurring donations' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-08-01' ) );
			$wpdb->result_sets[] = array( closing_subscription(), closing_subscription( array( 'id' => 8, 'donor_id' => 4 ) ) );
			$wpdb->rows          = array( null, null );

			PSPGD_Campaigns::close_if_finished( 12 );

			$cancelled = array_values(
				array_filter(
					$wpdb->updates,
					static function ( array $update ): bool {
						return 'cancelled' === ( $update['data']['status'] ?? '' );
					}
				)
			);
			assert_same( 2, count( $cancelled ), 'Every active schedule pointed at the campaign ends with it.' );
			assert_same( 2, count( test_sent_mail() ), 'A donor whose monthly gift stops is told, not left to notice.' );
		},

		'settling a campaign twice does not cancel its donors twice' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-08-01' ) );
			$wpdb->result_sets[] = array( closing_subscription() );
			$wpdb->rows          = array( null );

			PSPGD_Campaigns::close_if_finished( 12 );
			$after_first = count( test_sent_mail() );
			PSPGD_Campaigns::close_if_finished( 12 );

			assert_same( 1, $after_first, 'One donor, one email.' );
			assert_same( $after_first, count( test_sent_mail() ), 'An hourly sweep must not email the same donor every hour.' );
			assert_true( '' !== (string) get_post_meta( 12, SETTLED, true ), 'The campaign is marked settled so the sweep leaves it alone.' );
		},

		'closing from a payment callback defers the cancellations' => static function (): void {
			global $wpdb;
			closing_reset();
			closing_seed_rates();
			closing_add_goal_campaign( 12, 100000.0 );
			closing_queue_totals( array( 'AMD' => 120000.0 ) );

			PSPGD_Campaigns::close_if_finished( 12, false );

			assert_same( 'goal_reached', (string) get_post_meta( 12, CLOSED_REASON, true ), 'The form closes immediately.' );
			assert_same( 0, count( test_sent_mail() ), 'Unbinding cards and sending email does not belong in the request the bank is waiting on.' );
			assert_same( '', (string) get_post_meta( 12, SETTLED, true ), 'It is left for the sweep to finish.' );
		},

		'the sweep finishes a campaign that was closed elsewhere' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_closed_reason' => 'goal_reached', '_pspgd_campaign_closed_at' => '2026-08-23 11:00:00' ) );
			$wpdb->result_sets[] = array( closing_subscription() );
			$wpdb->rows          = array( null );

			PSPGD_Campaigns::close_finished_campaigns();

			assert_same( 1, count( test_sent_mail() ), 'The donor is cancelled on the next sweep instead of never.' );
			assert_true( '' !== (string) get_post_meta( 12, SETTLED, true ), 'And the campaign is not swept again.' );
		},

		// -------------------------------------------------------------
		// The charge itself
		// -------------------------------------------------------------

		'a due charge for a closed campaign is refused, not merely skipped' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-08-01' ) );
			$wpdb->rows = array( null );

			$charged = PSPGD_Recurring::charge_once( closing_subscription(), 'recurring_cron' );

			assert_false( $charged, 'A campaign that is over must never take another payment from a donor.' );
			$donations = array_values(
				array_filter(
					$wpdb->inserts,
					static function ( array $insert ): bool {
						return false !== strpos( (string) $insert['table'], 'pspgd_donations' );
					}
				)
			);
			assert_same( 0, count( $donations ), 'No donation row means nothing was ever sent to the bank.' );
			assert_same( 'cancelled', (string) ( $wpdb->updates[0]['data']['status'] ?? '' ), 'The schedule is ended here rather than left due forever.' );
		},

		'a due charge for a campaign still running is allowed through' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-12-31' ) );

			assert_true( PSPGD_Campaigns::is_open( 12 ), 'The guard keys off this, and it must not close a live campaign.' );
		},

		// -------------------------------------------------------------
		// Reopening
		// -------------------------------------------------------------

		'pushing the end date out puts a closed campaign back on sale' => static function (): void {
			global $wpdb;
			closing_reset();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-08-01' ) );
			$wpdb->result_sets[] = array();
			PSPGD_Campaigns::close_if_finished( 12 );
			assert_false( PSPGD_Campaigns::is_open( 12 ), 'Closed to begin with.' );

			update_post_meta( 12, '_pspgd_campaign_deadline', '2026-12-31' );
			closing_reopen( 12 );

			assert_true( PSPGD_Campaigns::is_open( 12 ), 'An editor extending the campaign means to keep fundraising.' );
			assert_same( '', (string) get_post_meta( 12, SETTLED, true ), 'If it closes again, its donors are cancelled again.' );
		},

		'an editor cannot reopen a campaign that is still funded' => static function (): void {
			global $wpdb;
			closing_reset();
			closing_seed_rates();
			closing_add_goal_campaign( 12, 100000.0 );
			closing_queue_totals( array( 'AMD' => 120000.0 ) );
			PSPGD_Campaigns::close_if_finished( 12, false );

			closing_queue_totals( array( 'AMD' => 120000.0 ) );
			closing_reopen( 12 );

			assert_false( PSPGD_Campaigns::is_open( 12 ), 'Saving the post without raising the goal changes nothing.' );

			update_post_meta( 12, '_pspgd_campaign_goal', 500000.0 );
			closing_queue_totals( array( 'AMD' => 120000.0 ) );
			closing_reopen( 12 );

			assert_true( PSPGD_Campaigns::is_open( 12 ), 'Raising the goal above what was raised reopens it.' );
		},

		'unticking "stop at the goal" reopens a funded campaign' => static function (): void {
			closing_reset();
			closing_seed_rates();
			closing_add_goal_campaign( 12, 100000.0 );
			closing_queue_totals( array( 'AMD' => 120000.0 ) );
			PSPGD_Campaigns::close_if_finished( 12, false );
			assert_false( PSPGD_Campaigns::is_open( 12 ), 'Closed on its goal to begin with.' );

			delete_post_meta( 12, CLOSE_AT_GOAL );
			closing_reopen( 12 );

			assert_true( PSPGD_Campaigns::is_open( 12 ), 'An editor who no longer wants the goal to close it can keep fundraising.' );
		},

		// -------------------------------------------------------------
		// Telling other plugins
		// -------------------------------------------------------------

		'the closing action fires once, with the donors it cancelled' => static function (): void {
			global $wpdb;
			closing_reset();
			test_reset_filters();
			test_add_campaign( 12, array( '_pspgd_campaign_deadline' => '2026-08-01' ) );
			$wpdb->result_sets[] = array( closing_subscription(), closing_subscription( array( 'id' => 8, 'donor_id' => 4 ) ) );
			$wpdb->rows          = array( null, null );

			PSPGD_Campaigns::close_if_finished( 12 );
			PSPGD_Campaigns::close_finished_campaigns();

			$fired = test_fired_actions( 'pspgd_campaign_closed' );
			assert_same( 1, count( $fired ), 'A site following up with donors hears about each campaign once.' );
			assert_same( 12, $fired[0]['args'][0], 'It is told which campaign.' );
			assert_same( 'ended', $fired[0]['args'][1], 'And why it closed.' );
			assert_same( array( 7, 8 ), array_map( 'intval', array_column( $fired[0]['args'][2], 'id' ) ), 'And which recurring donations ended with it.' );
		},

		'a subscription with no campaign is never blocked' => static function (): void {
			closing_reset();

			assert_true( PSPGD_Campaigns::is_open( 0 ), 'General giving has no campaign to end.' );
		},
	)
);
