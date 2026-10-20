<?php
/**
 * Discarding recurring donations that were never completed, without
 * discarding one that was actually paid for.
 *
 * Run from the plugin folder: php tests/subscription-purge-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-repository.php';

global $wpdb;
$wpdb = new PSPGD_Test_WPDB();

run_tests(
	array(
		'the admin list hides unfinished payment attempts by default' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::list_subscriptions( 20, 0 );

			assert_contains( "s.status <> 'pending'", $wpdb->reads[0], 'A donor who never finished paying is not a recurring donation.' );
		},

		'the admin list can be asked for them explicitly' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::list_subscriptions( 20, 0, true );

			assert_not_contains( "s.status <> 'pending'", $wpdb->reads[0], 'The toggle must actually widen the query.' );
		},

		'the count follows the same filter as the list' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::count_subscriptions();
			PSPGD_Repository::count_subscriptions( true );

			assert_contains( "status <> 'pending'", $wpdb->reads[0], 'A count that disagrees with the list breaks paging.' );
			assert_not_contains( "status <> 'pending'", $wpdb->reads[1], 'Asking for everything must count everything.' );
		},

		'a subscription with a settled payment is never a purge candidate' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::abandoned_subscriptions( '2026-08-22 12:00:00', 50 );

			assert_contains( 'NOT EXISTS', $wpdb->reads[0], 'A paid signup must be excluded by the query itself, not by later code.' );
			assert_contains( "d.status IN ('paid', 'refunded', 'pending', 'review')", $wpdb->reads[0], 'Paid, refunded, held-for-review and still-in-flight donations all protect a subscription.' );
			assert_contains( "s.status = 'pending'", $wpdb->reads[0], 'Only an unfinished signup can be discarded.' );
		},

		'purging is conditional on the row still being unfinished' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::delete_abandoned_subscription( 7 );

			assert_same( 'pending', $wpdb->deletes[0]['where']['status'], 'A subscription activated in the meantime must survive.' );
			assert_same( 7, $wpdb->deletes[0]['where']['id'], 'Only the identified row may be deleted.' );
		},

		'a purge that deleted nothing reports failure so the caller stops' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();
			$wpdb->delete_result = 0;

			assert_false(
				PSPGD_Repository::delete_abandoned_subscription( 7 ),
				'Losing the race must stop the rest of the purge from running against a live subscription.'
			);

			$wpdb->delete_result = 1;
		},

		'only donations that never reached the bank are deleted with it' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::delete_unstarted_donations( 7 );

			assert_contains( 'pspg_order_id IS NULL', $wpdb->queries[0], 'A donation the bank saw is a real attempt and must be kept.' );
			assert_contains( "status = 'pending'", $wpdb->queries[0], 'A declined or abandoned donation must survive the purge.' );
		},

		'surviving donations stop pointing at the deleted subscription' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::detach_donations_from_subscription( 7 );

			assert_same( 0, $wpdb->updates[0]['data']['subscription_id'], 'A donation must not reference a row that no longer exists.' );
			assert_same( 7, $wpdb->updates[0]['where']['subscription_id'], 'Only the donations of that subscription are detached.' );
		},

		'a paid signup with no card binding is kept, not discarded' => static function (): void {
			global $wpdb;
			$wpdb->reset_log();

			PSPGD_Repository::mark_subscription_setup_failed( 7 );

			assert_same( 'setup_failed', $wpdb->updates[0]['data']['status'], 'Someone paid, so this needs following up rather than deleting.' );
			assert_same( 'pending', $wpdb->updates[0]['where']['status'], 'An activated subscription must not be relabelled.' );
			assert_not_contains( "'pending'", $wpdb->updates[0]['data']['status'], 'setup_failed must leave the pending state, or the purge will still find it.' );
		},
	)
);
