<?php
/**
 * The plugin's SQL, run against a real MySQL or MariaDB database.
 *
 * The focused tests in tests/ check queries as strings against a stand-in
 * $wpdb that never runs them. The statements that matter most are the ones
 * whose WHERE clause is the safety: a donation is confirmed once, a
 * subscription is claimed by one worker, a purge never deletes a paid
 * signup. This runs them on a WordPress site with the plugin active.
 *
 * Every test runs inside a transaction that is rolled back, starting from
 * empty plugin tables, so the site's own data is never changed. It refuses
 * to run anywhere but a local site with the plugin's test mode on.
 *
 * Run through WP-CLI, piping the file in (tests/ is not in the release zip
 * the test sites load). From the wp-test folder:
 *
 *     docker compose run --rm -T cli71 wp eval-file - < ../donations-for-planetstudio-payment-gateway/tests/integration/db-test.php
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'PSPGD_Repository' ) ) {
	fwrite( STDERR, "Run this with wp eval-file on a site where the plugin is active.\n" );
	exit( 1 );
}

$it_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $it_host, array( 'localhost', '127.0.0.1' ), true ) || '1' !== (string) get_option( 'pspgd_test_mode', '0' ) ) {
	fwrite( STDERR, "Refusing to run: this needs a local site (localhost) with the plugin's test mode on.\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------

/** @return string[] The plugin's tables, prefixed. */
function it_tables(): array {
	global $wpdb;

	return array(
		'donors'        => $wpdb->prefix . 'pspgd_donors',
		'donations'     => $wpdb->prefix . 'pspgd_donations',
		'subscriptions' => $wpdb->prefix . 'pspgd_subscriptions',
		'events'        => $wpdb->prefix . 'pspgd_events',
	);
}

/** @param mixed $expected @param mixed $actual */
function it_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

function it_true( bool $actual, string $message ): void {
	it_same( true, $actual, $message );
}

function it_false( bool $actual, string $message ): void {
	it_same( false, $actual, $message );
}

/**
 * Run each test in a transaction on empty plugin tables, then roll back.
 * A test fails on an exception, on any database error, and on any
 * _doing_it_wrong() -- which is how $wpdb->prepare() reports a placeholder
 * count that does not match its arguments.
 *
 * @param array<string, callable> $tests
 */
function it_run( array $tests ): void {
	global $wpdb;

	foreach ( it_tables() as $table ) {
		$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		if ( 'innodb' !== strtolower( $engine ) ) {
			fwrite( STDERR, "Refusing to run: {$table} is not InnoDB ({$engine}), so a rollback would not undo the tests.\n" );
			exit( 1 );
		}
	}

	add_action(
		'doing_it_wrong_run',
		static function ( $function_name, $message ): void {
			$GLOBALS['it_wrong'][] = $function_name . ': ' . wp_strip_all_tags( (string) $message );
		},
		10,
		2
	);
	// Count mail instead of sending it.
	add_filter(
		'pre_wp_mail',
		static function ( $short_circuit, $atts ) {
			$GLOBALS['it_mail'][] = $atts;
			return true;
		},
		10,
		2
	);

	$wpdb->show_errors( true );
	$wpdb->suppress_errors( false );

	$failures = 0;
	foreach ( $tests as $name => $test ) {
		$GLOBALS['it_wrong'] = array();
		$GLOBALS['it_mail']  = array();

		$wpdb->query( 'START TRANSACTION' );
		ob_start();
		$error = '';
		try {
			foreach ( it_tables() as $table ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
			}
			PSPGD_Stats::flush();
			$test();
		} catch ( Throwable $exception ) {
			$error = $exception->getMessage();
		}
		$output = (string) ob_get_clean();
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();

		if ( '' === $error && false !== stripos( $output, 'database error' ) ) {
			$error = 'Database error: ' . trim( wp_strip_all_tags( $output ) );
		}
		if ( '' === $error && $GLOBALS['it_wrong'] ) {
			$error = 'Called incorrectly: ' . implode( '; ', $GLOBALS['it_wrong'] );
		}

		if ( '' === $error ) {
			echo "PASS: {$name}\n";
		} else {
			$failures++;
			echo "FAIL: {$name}\n{$error}\n";
		}
	}

	exit( $failures > 0 ? 1 : 0 );
}

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

/** Local MySQL time this many seconds ago (negative for the future). */
function it_ago( int $seconds ): string {
	return PSPGD_Clock::local_mysql( PSPGD_Clock::now() - $seconds );
}

function it_donor( string $first_name = 'Ani', string $last_name = 'Test' ): int {
	return PSPGD_Repository::upsert_donor(
		array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => strtolower( preg_replace( '/[^a-z0-9]/i', '', $first_name . $last_name ) ) . '@example.invalid',
			'language'   => 'en',
		)
	);
}

/** Overwrite columns directly, for states the plugin reaches over time. */
function it_set( string $table, int $id, array $fields ): void {
	global $wpdb;

	$wpdb->update( it_tables()[ $table ], $fields, array( 'id' => $id ) );
}

function it_row( string $table, int $id ): ?object {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', it_tables()[ $table ], $id ) );
}

/** A real (not test-mode) donation, with any column overwritten afterwards. */
function it_donation( array $fields = array() ): int {
	$id = PSPGD_Repository::create_donation(
		array(
			'donor_id' => $fields['donor_id'] ?? it_donor(),
			'amount'   => '5000.00',
			'currency' => 'AMD',
			'bank_id'  => 'arca',
			'is_test'  => 0,
		)
	);
	it_true( $id > 0, 'The fixture donation must be created.' );

	unset( $fields['donor_id'] );
	if ( $fields ) {
		it_set( 'donations', $id, $fields );
	}

	return $id;
}

function it_subscription( array $fields = array() ): int {
	$id = PSPGD_Repository::create_subscription(
		array(
			'donor_id'     => $fields['donor_id'] ?? it_donor(),
			'email'        => 'anitest@example.invalid',
			'amount'       => '3000.00',
			'currency'     => 'AMD',
			'bank_id'      => 'arca',
			'interval_key' => 'monthly',
		)
	);
	it_true( $id > 0, 'The fixture subscription must be created.' );

	unset( $fields['donor_id'] );
	if ( $fields ) {
		it_set( 'subscriptions', $id, $fields );
	}

	return $id;
}

/** @param object[] $rows @return int[] */
function it_ids( array $rows, string $column = 'id' ): array {
	return array_map( static fn( $row ): int => (int) $row->$column, $rows );
}

/** @return string[] */
function it_event_types( string $object_type, int $object_id ): array {
	global $wpdb;

	return array_map(
		'strval',
		$wpdb->get_col( $wpdb->prepare( 'SELECT event_type FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id', it_tables()['events'], $object_type, $object_id ) )
	);
}

/**
 * The fields a browser would post from the first form on a page that saves
 * plugin settings: ticked boxes, chosen options, every other named field.
 *
 * @return array<string, mixed>
 */
function it_posted_fields( string $html ): array {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();

	$pairs = array();
	$xpath = new DOMXPath( $doc );
	foreach ( $xpath->query( '//form[.//input[@name="action" and starts-with(@value, "pspgd_save_")]]//*[@name]' ) as $field ) {
		$name = rawurlencode( $field->getAttribute( 'name' ) );
		$tag  = strtolower( $field->nodeName );
		$type = strtolower( $field->getAttribute( 'type' ) );

		if ( 'select' === $tag ) {
			$chosen = null;
			foreach ( $field->getElementsByTagName( 'option' ) as $option ) {
				if ( null === $chosen || $option->hasAttribute( 'selected' ) ) {
					$chosen = $option;
				}
			}
			$pairs[] = $name . '=' . rawurlencode( $chosen ? $chosen->getAttribute( 'value' ) : '' );
		} elseif ( 'textarea' === $tag ) {
			$pairs[] = $name . '=' . rawurlencode( $field->textContent );
		} elseif ( in_array( $type, array( 'checkbox', 'radio' ), true ) ) {
			if ( $field->hasAttribute( 'checked' ) ) {
				$pairs[] = $name . '=' . rawurlencode( $field->hasAttribute( 'value' ) ? $field->getAttribute( 'value' ) : 'on' );
			}
		} elseif ( 'input' === $tag && ! in_array( $type, array( 'submit', 'button' ), true ) ) {
			$pairs[] = $name . '=' . rawurlencode( $field->getAttribute( 'value' ) );
		}
	}

	parse_str( implode( '&', $pairs ), $post );

	return $post;
}

/** Numbers compared by value, whether the database handed them back as strings or not. @param mixed $value @return mixed */
function it_normalise( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'it_normalise', $value );
	}

	return is_numeric( $value ) ? (float) $value : $value;
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it_run(
	array(
		// --- One winner per row ------------------------------------------

		'a pending donation is claimed for processing exactly once' => static function (): void {
			$id = it_donation();

			it_true( PSPGD_Repository::mark_donation_processing( $id ), 'The first path to reach a pending donation claims it.' );
			it_false( PSPGD_Repository::mark_donation_processing( $id ), 'A second callback for the same payment must find it already claimed.' );
			it_same( 'processing', it_row( 'donations', $id )->status, 'The claim is in the row.' );
		},

		'a status moves only from the statuses it is allowed to leave' => static function (): void {
			$id = it_donation( array( 'status' => 'paid' ) );

			it_true( PSPGD_Repository::transition_donation_status( $id, array( 'paid', 'review' ), 'refunded' ), 'A paid donation can be refunded.' );
			it_false( PSPGD_Repository::transition_donation_status( $id, array( 'paid', 'review' ), 'refunded' ), 'Refunding it twice changes nothing the second time.' );
			it_false( PSPGD_Repository::transition_donation_status( $id, array(), 'paid' ), 'No starting status means no move.' );

			$failed = it_donation( array( 'status' => 'failed' ) );
			it_false( PSPGD_Repository::transition_donation_status( $failed, array( 'paid', 'review' ), 'refunded' ), 'A failed payment was never money, so it cannot be refunded.' );
			it_same( 'failed', it_row( 'donations', $failed )->status, 'And it stays failed.' );
		},

		'a due subscription is claimed by one worker only' => static function (): void {
			$id       = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( HOUR_IN_SECONDS ) ) );
			$snapshot = it_row( 'subscriptions', $id );

			it_true( PSPGD_Repository::claim_due_subscription( $snapshot ), 'The first worker claims it.' );
			it_false( PSPGD_Repository::claim_due_subscription( $snapshot ), 'A second worker holding the same row must not charge the card again.' );

			$claimed = it_row( 'subscriptions', $id );
			it_same( 'processing', $claimed->status, 'The row is marked as being charged.' );
			it_true( null !== $claimed->claimed_at, 'The claim time is recorded so a stuck claim can be released.' );
		},

		'a claim on a row whose date has moved on fails' => static function (): void {
			$id    = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( HOUR_IN_SECONDS ) ) );
			$stale = it_row( 'subscriptions', $id );
			it_set( 'subscriptions', $id, array( 'next_payment_at' => it_ago( -30 * DAY_IN_SECONDS ) ) );

			it_false( PSPGD_Repository::claim_due_subscription( $stale ), 'A worker that read the row before it was rescheduled must not charge it.' );
		},

		'only a pending donation can be abandoned' => static function (): void {
			$pending = it_donation();
			$paid    = it_donation( array( 'status' => 'paid' ) );

			it_true( PSPGD_Repository::abandon_donation( $pending ), 'A pending donation is abandoned.' );
			it_false( PSPGD_Repository::abandon_donation( $paid ), 'A callback that landed first wins.' );
			it_same( 'paid', it_row( 'donations', $paid )->status, 'The paid donation stays paid.' );
		},

		'a stuck claim is released once and keeps its date' => static function (): void {
			$due = it_ago( 2 * HOUR_IN_SECONDS );
			$id  = it_subscription( array( 'status' => 'processing', 'next_payment_at' => $due, 'claimed_at' => it_ago( HOUR_IN_SECONDS ) ) );

			it_true( PSPGD_Repository::release_stuck_subscription( $id ), 'A row still processing is released.' );
			it_false( PSPGD_Repository::release_stuck_subscription( $id ), 'Releasing it again does nothing.' );

			$row = it_row( 'subscriptions', $id );
			it_same( array( 'active', null, $due ), array( $row->status, $row->claimed_at, $row->next_payment_at ), 'Active again, unclaimed, and due on the same date.' );
		},

		'only a pending subscription is purged or marked as a failed setup' => static function (): void {
			$active = it_subscription( array( 'status' => 'active' ) );
			it_false( PSPGD_Repository::delete_abandoned_subscription( $active ), 'A live subscription is never purged.' );
			it_false( PSPGD_Repository::mark_subscription_setup_failed( $active ), 'Nor marked as a failed setup.' );
			it_true( null !== it_row( 'subscriptions', $active ), 'It is still there.' );

			$pending = it_subscription();
			it_true( PSPGD_Repository::mark_subscription_setup_failed( $pending ), 'A pending one can be marked as a failed setup.' );
			it_false( PSPGD_Repository::delete_abandoned_subscription( $pending ), 'After which the purge leaves it alone: the donor was charged.' );
		},

		'failures are counted in the database, and a success resets the count' => static function (): void {
			$id   = it_subscription( array( 'status' => 'processing', 'claimed_at' => it_ago( 60 ) ) );
			$next = it_ago( -3 * DAY_IN_SECONDS );

			PSPGD_Repository::reschedule_subscription( $id, $next, false );
			PSPGD_Repository::reschedule_subscription( $id, $next, false );
			$row = it_row( 'subscriptions', $id );
			it_same( array( '2', 'active', null, $next ), array( (string) $row->failed_payment_count, $row->status, $row->claimed_at, $row->next_payment_at ), 'Two failures are two, and the row is released for the retry.' );

			PSPGD_Repository::reschedule_subscription( $id, $next, true );
			$row = it_row( 'subscriptions', $id );
			it_same( '0', (string) $row->failed_payment_count, 'A successful charge clears the failures.' );
			it_true( null !== $row->last_payment_at, 'And records when it was paid.' );
		},

		'a cancelled subscription has no next payment date' => static function (): void {
			$id = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( -DAY_IN_SECONDS ) ) );

			PSPGD_Repository::cancel_subscription( $id );

			$row = it_row( 'subscriptions', $id );
			it_same( array( 'cancelled', null ), array( $row->status, $row->next_payment_at ), 'NULL, not a zero date that would sort as due.' );
			it_true( null !== $row->cancelled_at, 'The cancellation time is kept.' );
		},

		'a bank order number belongs to one donation' => static function (): void {
			$first  = it_donation();
			$second = it_donation();
			it_same( array( null, null ), array( it_row( 'donations', $first )->pspg_order_id, it_row( 'donations', $second )->pspg_order_id ), 'Any number of donations can be waiting for an order.' );

			PSPGD_Repository::attach_pspg_order( $first, 900001, 'bank-order-1' );
			it_same( $first, (int) PSPGD_Repository::find_donation_by_pspg_order( 900001 )->id, 'The order leads back to its donation.' );
			it_same( 'bank-order-1', it_row( 'donations', $first )->bank_order_id, 'The bank order id is kept for checking reports against.' );

			global $wpdb;
			$wpdb->suppress_errors( true );
			PSPGD_Repository::attach_pspg_order( $second, 900001 );
			$wpdb->suppress_errors( false );
			it_same( null, it_row( 'donations', $second )->pspg_order_id, 'The database refuses to point a second donation at the same order.' );
		},

		// --- What the scheduled jobs pick up -----------------------------

		'due subscriptions are live, real and past their date, oldest first' => static function (): void {
			$older   = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( 2 * DAY_IN_SECONDS ) ) );
			$newer   = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( HOUR_IN_SECONDS ) ) );
			$future  = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( -HOUR_IN_SECONDS ) ) );
			$test    = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( HOUR_IN_SECONDS ), 'is_test' => 1 ) );
			$claimed = it_subscription( array( 'status' => 'processing', 'next_payment_at' => it_ago( HOUR_IN_SECONDS ) ) );

			it_same( array( $older, $newer ), it_ids( PSPGD_Repository::due_subscriptions() ), 'Not the future one, not a test one, not one already being charged.' );
			unset( $future, $test, $claimed );
		},

		'reconciliation re-checks only registered payments left alone long enough' => static function (): void {
			$stale   = it_donation( array( 'pspg_order_id' => 900002, 'updated_at' => it_ago( HOUR_IN_SECONDS ) ) );
			$recent  = it_donation( array( 'pspg_order_id' => 900003, 'updated_at' => it_ago( 60 ) ) );
			$no_bank = it_donation( array( 'updated_at' => it_ago( HOUR_IN_SECONDS ) ) );

			it_same( array( $stale ), it_ids( PSPGD_Repository::stale_pending_donations( it_ago( 20 * MINUTE_IN_SECONDS ) ) ), 'Only an order the bank knows about, untouched for 20 minutes.' );
			unset( $recent, $no_bank );
		},

		'unconfirmed donations include ones that never reached the bank' => static function (): void {
			$registered = it_donation( array( 'pspg_order_id' => 900004, 'created_at' => it_ago( 2 * DAY_IN_SECONDS ) ) );
			$unstarted  = it_donation( array( 'created_at' => it_ago( 2 * DAY_IN_SECONDS ) ) );
			$recent     = it_donation();
			$paid       = it_donation( array( 'status' => 'paid', 'created_at' => it_ago( 2 * DAY_IN_SECONDS ) ) );

			$found = it_ids( PSPGD_Repository::unconfirmed_donations( it_ago( DAY_IN_SECONDS ) ) );
			sort( $found );
			it_same( array( $registered, $unstarted ), $found, 'Old and still pending, with or without a bank order.' );
			unset( $recent, $paid );
		},

		'stuck subscriptions are ones claimed long ago and still processing' => static function (): void {
			$stuck   = it_subscription( array( 'status' => 'processing', 'claimed_at' => it_ago( 2 * HOUR_IN_SECONDS ) ) );
			$working = it_subscription( array( 'status' => 'processing', 'claimed_at' => it_ago( 60 ) ) );

			it_same( array( $stuck ), it_ids( PSPGD_Repository::stuck_subscriptions( it_ago( HOUR_IN_SECONDS ) ) ), 'A worker that is still charging is left alone.' );
			unset( $working );
		},

		'the abandonment purge never touches a signup that took money or is mid-payment' => static function (): void {
			$old = it_ago( 2 * DAY_IN_SECONDS );

			$abandoned = it_subscription( array( 'created_at' => $old ) );
			it_donation( array( 'subscription_id' => $abandoned, 'status' => 'abandoned' ) );
			$declined = it_subscription( array( 'created_at' => $old ) );
			it_donation( array( 'subscription_id' => $declined, 'status' => 'failed' ) );

			$kept = array();
			foreach ( array( 'paid', 'refunded', 'pending', 'review' ) as $status ) {
				$kept[ $status ] = it_subscription( array( 'created_at' => $old ) );
				it_donation( array( 'subscription_id' => $kept[ $status ], 'status' => $status ) );
			}
			$recent = it_subscription();

			$found = it_ids( PSPGD_Repository::abandoned_subscriptions( it_ago( DAY_IN_SECONDS ) ) );
			sort( $found );
			it_same( array( $abandoned, $declined ), $found, 'A paid, refunded, pending or held donation keeps the subscription; so does being recent.' );
			unset( $recent );
		},

		'purging deletes only donations that never reached the bank, and detaches the rest' => static function (): void {
			$subscription = it_subscription();
			$unstarted    = it_donation( array( 'subscription_id' => $subscription ) );
			$declined     = it_donation( array( 'subscription_id' => $subscription, 'status' => 'failed', 'pspg_order_id' => 900005 ) );
			$walked_away  = it_donation( array( 'subscription_id' => $subscription, 'pspg_order_id' => 900006 ) );

			it_same( 1, PSPGD_Repository::delete_unstarted_donations( $subscription ), 'One donation never reached the bank.' );
			PSPGD_Repository::detach_donations_from_subscription( $subscription );

			it_same( null, it_row( 'donations', $unstarted ), 'It is gone.' );
			it_same( array( '0', '0' ), array( (string) it_row( 'donations', $declined )->subscription_id, (string) it_row( 'donations', $walked_away )->subscription_id ), 'Real attempts stay in the log, pointing at no subscription.' );
		},

		'the reconciliation job settles old rows and leaves live ones alone' => static function (): void {
			$old          = it_ago( 2 * DAY_IN_SECONDS );
			$unconfirmed  = it_donation( array( 'created_at' => $old ) );
			$paid         = it_donation( array( 'status' => 'paid', 'created_at' => $old ) );
			$abandoned    = it_subscription( array( 'created_at' => $old ) );
			$signup       = it_donation( array( 'subscription_id' => $abandoned, 'created_at' => $old ) );
			$stuck        = it_subscription( array( 'status' => 'processing', 'claimed_at' => $old, 'next_payment_at' => $old ) );
			$live         = it_subscription( array( 'status' => 'active', 'next_payment_at' => it_ago( -DAY_IN_SECONDS ) ) );
			PSPGD_Repository::log_event( 'donation', $paid, 'ancient_event', 'Older than any retention.' );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE event_type = %s', it_tables()['events'], it_ago( 400 * DAY_IN_SECONDS ), 'ancient_event' ) );

			PSPGD_Reconciliation::run();

			it_same( 'abandoned', it_row( 'donations', $unconfirmed )->status, 'A day-old pending donation is abandoned.' );
			it_same( 'paid', it_row( 'donations', $paid )->status, 'A paid one is not.' );
			it_same( null, it_row( 'subscriptions', $abandoned ), 'An abandoned signup is purged...' );
			it_same( array( 'abandoned', '0' ), array( it_row( 'donations', $signup )->status, (string) it_row( 'donations', $signup )->subscription_id ), '...its donation, which was abandoned first in the same run, is kept and detached...' );
			it_true( in_array( 'subscription_abandoned', it_event_types( 'subscription', $abandoned ), true ), '...and one event says so.' );
			it_same( 'active', it_row( 'subscriptions', $stuck )->status, 'A stuck claim is released.' );
			it_same( 'active', it_row( 'subscriptions', $live )->status, 'A live subscription is untouched.' );
			it_same( array(), it_event_types( 'donation', $paid ), 'Events past their retention are pruned.' );
		},

		'events are pruned oldest first, in bounded batches' => static function (): void {
			global $wpdb;
			foreach ( array( 300, 200, 1 ) as $days ) {
				PSPGD_Repository::log_event( 'test', $days, 'aged', '' );
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE object_id = %d', it_tables()['events'], it_ago( $days * DAY_IN_SECONDS ), $days ) );
			}

			it_same( 1, PSPGD_Repository::prune_events( it_ago( 180 * DAY_IN_SECONDS ), 1 ), 'The limit holds.' );
			it_same( array(), it_event_types( 'test', 300 ), 'The oldest went first.' );
			it_same( 1, PSPGD_Repository::prune_events( it_ago( 180 * DAY_IN_SECONDS ) ), 'The next batch takes the rest that are too old.' );
			it_same( array( 'aged' ), it_event_types( 'test', 1 ), 'A recent event stays.' );
		},

		// --- Reading back -------------------------------------------------

		'an event is stored with only allowlisted context and found again' => static function (): void {
			PSPGD_Repository::log_event( 'donation', 42, 'payment_paid', 'Paid.', array( 'status' => 'paid', 'pan' => '4111111111111111' ) );

			it_true( PSPGD_Repository::event_exists( 'donation', 42, 'payment_paid' ), 'The event can be found by object and type.' );
			it_false( PSPGD_Repository::event_exists( 'donation', 43, 'payment_paid' ), 'Not for another object.' );

			$row = PSPGD_Repository::list_recent_events( 1 )[0];
			it_same( array( 'status' => 'paid' ), json_decode( (string) $row->context, true ), 'A field off the allowlist never reaches the table.' );
			it_same( 1, PSPGD_Repository::count_events(), 'One event.' );
		},

		'the donation list, its count and the export agree under every filter' => static function (): void {
			$ani  = it_donor( 'Ani', 'Test' );
			$odd  = it_donor( 'Odd_50%', 'Name' );
			$bob  = it_donor( 'Ani150', 'Bob' );
			it_donation( array( 'donor_id' => $ani, 'status' => 'paid', 'campaign_id' => 7, 'created_at' => '2026-03-15 10:00:00' ) );
			$usd = it_donation( array( 'donor_id' => $ani, 'status' => 'paid', 'currency' => 'USD', 'created_at' => '2026-04-01 00:00:00' ) );
			it_donation( array( 'donor_id' => $odd, 'status' => 'failed', 'created_at' => '2026-04-30 23:59:59' ) );
			it_donation( array( 'donor_id' => $bob, 'type' => 'recurring_initial', 'language' => 'hy', 'created_at' => '2026-05-01 00:00:00' ) );

			$cases = array(
				'no filter'     => array( array(), 4 ),
				'status'        => array( array( 'status' => 'paid' ), 2 ),
				'type'          => array( array( 'type' => 'recurring_initial' ), 1 ),
				'currency'      => array( array( 'currency' => 'usd' ), 1 ),
				'language'      => array( array( 'language' => 'hy' ), 1 ),
				'campaign'      => array( array( 'campaign_id' => '7' ), 1 ),
				'whole days'    => array( array( 'date_from' => '2026-04-01', 'date_to' => '2026-04-30' ), 2 ),
				'name search'   => array( array( 'search' => 'ani' ), 3 ),
				'literal % _'   => array( array( 'search' => '_50%' ), 1 ),
				'email search'  => array( array( 'search' => 'odd50name@' ), 1 ),
				'combined'      => array( array( 'status' => 'paid', 'search' => 'Ani', 'date_from' => '2026-04-01' ), 1 ),
			);

			foreach ( $cases as $label => $case ) {
				list( $filters, $expected ) = $case;
				$listed   = PSPGD_Repository::list_donations( 50, 0, $filters );
				$streamed = iterator_to_array( PSPGD_Repository::stream_donations( $filters, 1 ), false );
				it_same( $expected, count( $listed ), "List, {$label}." );
				it_same( $expected, PSPGD_Repository::count_donations( $filters ), "Count, {$label}." );
				it_same( it_ids( $listed ), it_ids( $streamed ), "Export, {$label}, read one row at a time." );
			}

			$first = PSPGD_Repository::list_donations( 1, 0, array( 'currency' => 'USD' ) )[0];
			it_same( array( $usd, 'anitest@example.invalid' ), array( (int) $first->id, (string) $first->email ), 'Rows carry the donor details.' );
			it_same( array( 'AMD', 'USD' ), PSPGD_Repository::distinct_donation_values( 'currency' ), 'Filter dropdowns offer the currencies present.' );
			it_same( array(), PSPGD_Repository::distinct_donation_values( 'amount' ), 'Only the known columns can be listed.' );
		},

		'the recurring list and its count hide unfinished signups the same way' => static function (): void {
			it_subscription();
			it_subscription( array( 'status' => 'active' ) );
			it_subscription( array( 'status' => 'setup_failed' ) );

			it_same( 2, count( PSPGD_Repository::list_subscriptions() ), 'A pending signup is not a recurring donation.' );
			it_same( 2, PSPGD_Repository::count_subscriptions(), 'The count agrees, or paging drifts.' );
			it_same( 3, count( PSPGD_Repository::list_subscriptions( 50, 0, true ) ), 'Shown on request.' );
			it_same( 3, PSPGD_Repository::count_subscriptions( true ), 'Counted on request.' );
		},

		'campaign totals count only real paid money, per currency' => static function (): void {
			$ani = it_donor( 'Ani', 'Test' );
			it_donation( array( 'donor_id' => $ani, 'campaign_id' => 7, 'status' => 'paid', 'amount' => '1000.50' ) );
			it_donation( array( 'campaign_id' => 8, 'status' => 'paid', 'amount' => '2000.00', 'is_anonymous' => 1 ) );
			it_donation( array( 'campaign_id' => 7, 'status' => 'paid', 'amount' => '10.00', 'currency' => 'USD' ) );
			it_donation( array( 'campaign_id' => 7, 'status' => 'paid', 'amount' => '99999.00', 'is_test' => 1 ) );
			it_donation( array( 'campaign_id' => 7, 'status' => 'review', 'amount' => '99999.00' ) );
			it_donation( array( 'campaign_id' => 9, 'status' => 'paid', 'amount' => '99999.00' ) );

			$progress = PSPGD_Repository::campaign_progress( array( 7, 8 ) );
			it_same(
				array(
					'by_currency'    => array(
						'AMD' => array( 'total' => 3000.5, 'count' => 2 ),
						'USD' => array( 'total' => 10.0, 'count' => 1 ),
					),
					'donation_count' => 3,
				),
				$progress,
				'A campaign and its translation add up; test, held and other campaigns stay out.'
			);
			it_same( 3, count( PSPGD_Repository::campaign_donors( array( 7, 8 ) ) ), 'The supporters list shows the same three.' );
		},

		'a campaign status box lists its donations and its own events' => static function (): void {
			it_donation( array( 'campaign_id' => 7, 'status' => 'paid', 'created_at' => it_ago( 60 ) ) );
			it_donation( array( 'campaign_id' => 8, 'created_at' => it_ago( 120 ) ) );
			PSPGD_Repository::log_event( 'campaign', 7, 'campaign_closed', 'Goal reached.' );
			PSPGD_Repository::log_event( 'campaign', 9, 'campaign_closed', 'Another campaign.' );

			$method = new ReflectionMethod( 'PSPGD_Campaigns', 'campaign_events' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			$rows = $method->invoke( null, array( 7, 8 ) );

			it_same( 3, count( $rows ), 'Two donations and one campaign event.' );
			it_same( 'campaign_closed', $rows[0]->event_type, 'Newest first.' );
		},

		'the figures above the donation log come from real, paid rows' => static function (): void {
			$ani = it_donor( 'Ani', 'Test' );
			$bob = it_donor( 'Bob', 'Test' );
			it_donation( array( 'donor_id' => $ani, 'status' => 'paid', 'amount' => '1000.00' ) );
			it_donation( array( 'donor_id' => $ani, 'status' => 'paid', 'amount' => '500.00', 'created_at' => it_ago( 400 * DAY_IN_SECONDS ) ) );
			it_donation( array( 'donor_id' => $bob, 'status' => 'failed' ) );
			it_donation( array( 'status' => 'paid', 'amount' => '99999.00', 'is_test' => 1 ) );
			it_subscription( array( 'status' => 'active', 'amount' => '1000.00' ) );
			it_subscription( array( 'status' => 'active', 'amount' => '1000.00', 'interval_key' => 'weekly' ) );
			it_subscription( array( 'status' => 'active', 'amount' => '99999.00', 'is_test' => 1 ) );

			$stats = PSPGD_Stats::snapshot();

			it_same( 1500.0, $stats['money']['AMD']['total'], 'All-time paid money, without the test donation.' );
			it_same( 1000.0, $stats['money']['AMD']['month'], 'This month only.' );
			it_same( 1, $stats['supporters'], 'One donor paid in the last year.' );
			$outcomes = $stats['outcomes'];
			ksort( $outcomes );
			it_same( array( 'failed' => 1, 'paid' => 2 ), $outcomes, 'Outcomes leave test donations out.' );
			it_same( 2, $stats['recurring'], 'Two real recurring donations.' );
			it_same( 1000.0 + 1000.0 * 4.33, (float) $stats['committed']['AMD'], 'A weekly pledge counts 4.33 times a month.' );
		},

		'a returning donor is updated in place, and erasure blanks every copy of the email' => static function (): void {
			$first  = it_donor( 'Ani', 'Test' );
			$second = PSPGD_Repository::upsert_donor( array( 'first_name' => 'Anahit', 'last_name' => 'Test', 'email' => 'anitest@example.invalid', 'language' => 'hy' ) );
			it_same( $first, $second, 'The same email is the same donor.' );
			it_same( 'Anahit', PSPGD_Repository::find_donor_by_email( 'anitest@example.invalid' )->first_name, 'With the newest name.' );

			$subscription = it_subscription( array( 'donor_id' => $first ) );
			PSPGD_Repository::anonymize_donor( $first );

			it_same( null, PSPGD_Repository::find_donor_by_email( 'anitest@example.invalid' ), 'The donor can no longer be found by email.' );
			it_same( 'erased-' . $first . '@erased.invalid', it_row( 'subscriptions', $subscription )->email, 'Nor through their recurring donation.' );
		},

		'subscriptions with a flagged payment page are found through their donations' => static function (): void {
			$flagged  = it_subscription();
			$clean    = it_subscription();
			$donation = it_donation( array( 'subscription_id' => $flagged ) );
			it_donation( array( 'subscription_id' => $clean ) );
			PSPGD_Repository::log_event( 'donation', $donation, 'payment_redirect_url_anomaly', 'Odd path.' );

			it_same( array( $flagged ), PSPGD_Repository::subscriptions_with_flagged_redirect_url( array( $flagged, $clean ) ), 'Only the flagged one.' );
		},

		// --- The settings screens ------------------------------------------

		'saving a settings screen without changing anything changes no setting' => static function (): void {
			// Every field set away from its default, so a field the screen
			// shows but its save handler drops -- or the other way round --
			// shows up as a change.
			$banks = array_keys( PSPGD_PSPG::bank_choices() );
			$seed  = array(
				'pspgd_test_mode'                => '1',
				'pspgd_recurring_enabled'        => '0',
				'pspgd_bank_id'                  => (string) end( $banks ),
				'pspgd_currencies'               => array( 'AMD', 'USD', 'EUR' ),
				'pspgd_default_currency'         => 'USD',
				'pspgd_goal_currency'            => 'EUR',
				'pspgd_intervals'                => array( 'monthly' ),
				'pspgd_newsletter_optin'         => '1',
				'pspgd_show_supporters'          => '0',
				'pspgd_payment_image_url'        => 'https://example.org/cards.png',
				'pspgd_client_ip_source'         => 'cloudflare',
				'pspgd_turnstile'                => '1',
				'pspgd_languages'                => array( 'hy', 'en' ),
				'pspgd_colors'                   => array( 'primary' => '#112233', 'accent' => '#abc', 'text' => '#000000' ),
				'pspgd_font_family'              => 'Georgia, serif',
				'pspgd_radius'                   => 12,
				'pspgd_amount_presets'           => array( 'AMD' => array( 1000.0, 2500.5 ), 'USD' => array( 10.0, 25.0 ), 'EUR' => array( 20.0 ) ),
				'pspgd_min_amounts'              => array( 'AMD' => 700.0, 'USD' => 5.0, 'EUR' => 3.0 ),
				'pspgd_max_amounts'              => array( 'AMD' => 900000.0, 'USD' => 2000.0, 'EUR' => 900.0 ),
				'pspgd_event_retention_days'     => 30,
				'pspgd_delete_data_on_uninstall' => '1',
				'pspgd_email_from_name'          => 'Test Org',
				'pspgd_email_from_address'       => 'donations@example.org',
				'pspgd_email_reply_to'           => 'reply@example.org',
				'pspgd_staff_notify_emails'      => 'a@example.org, b@example.org',
				'pspgd_email_logo_light'         => 'https://example.org/light.png',
				'pspgd_email_logo_dark'          => 'https://example.org/dark.png',
				'pspgd_social_links'             => array( 'facebook' => 'https://facebook.com/example', 'telegram' => 'https://t.me/example' ),
			);
			foreach ( $seed as $option => $value ) {
				update_option( $option, $value, false );
			}
			update_option( 'pspgd_form_labels', PSPGD_Admin::form_settings_from_post( array( 'labels' => array( 'en' => array( 'amount' => 'Sum' ), 'hy' => array( 'amount' => 'Գումար' ) ) ) )['pspgd_form_labels'], false );
			update_option( 'pspgd_email_templates', PSPGD_Admin::email_settings_from_post( array( 'templates' => array( 'en' => array( 'donation_receipt_subject' => 'Thanks & more', 'donation_receipt_body' => 'Hello <a href="https://example.org">there</a><br>Line two' ) ) ) )['pspgd_email_templates'], false );
			wp_cache_flush();

			require_once ABSPATH . 'wp-admin/includes/admin.php';
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			wp_set_current_user( (int) $admins[0] );

			$screens = array(
				'pspgd-form-settings'  => array( 'render_form_settings_page', 'form_settings_from_post' ),
				'pspgd-email-settings' => array( 'render_email_settings_page', 'email_settings_from_post' ),
			);
			foreach ( $screens as $page => $calls ) {
				$_GET['page'] = $page;
				ob_start();
				call_user_func( array( 'PSPGD_Admin', $calls[0] ) );
				$posted = it_posted_fields( (string) ob_get_clean() );
				it_true( count( $posted ) > 5, "The {$page} form must be found on the page before it can be checked." );

				foreach ( call_user_func( array( 'PSPGD_Admin', $calls[1] ), $posted ) as $option => $value ) {
					it_same( it_normalise( get_option( $option ) ), it_normalise( $value ), "Saving {$page} unchanged must leave {$option} as it was." );
				}
			}

			wp_set_current_user( 0 );
			unset( $_GET['page'] );
		},

		// --- A whole payment path -----------------------------------------

		'a payment reported twice is confirmed and receipted once' => static function (): void {
			$id = it_donation();
			PSPGD_Repository::attach_pspg_order( $id, 900007 );
			$report = array( 'ok' => true, 'status' => 'paid' );

			PSPGD_PSPG::handle_confirmed_payment( 900007, $report, array( 'channel' => 'callback' ) );
			PSPGD_PSPG::handle_confirmed_payment( 900007, $report, array( 'channel' => 'browser_return' ) );

			it_same( 'paid', it_row( 'donations', $id )->status, 'The donation is paid.' );
			$receipts = array_filter( $GLOBALS['it_mail'], static fn( $mail ): bool => in_array( 'anitest@example.invalid', (array) $mail['to'], true ) );
			it_same( 1, count( $receipts ), 'The donor gets one receipt, not one per report.' );
			it_same( 1, count( array_keys( it_event_types( 'donation', $id ), 'payment_paid', true ) ), 'The payment is logged once.' );
		},
	)
);
