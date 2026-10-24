<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This class is the only reader and writer of the plugin's own tables
// (donors, donations, subscriptions, events). There is no WordPress API for
// custom tables, so it queries them directly; every value goes through
// $wpdb->prepare(), and table names through the %i identifier placeholder.
// Nothing here is cached in the object cache on purpose: these are payment
// records read by the bank callback, the charge worker and the admin screens,
// and a stale read is worse than a repeated one.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Reads and writes the plugin's own tables.
 */
final class PSPGD_Repository {

	public static function upsert_donor( array $data ): int {
		global $wpdb;

		$email = sanitize_email( $data['email'] ?? '' );
		if ( '' === $email ) {
			return 0;
		}

		$table = $wpdb->prefix . 'pspgd_donors';
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s LIMIT 1', $table, $email ) );

		$payload = array(
			'first_name' => sanitize_text_field( $data['first_name'] ?? '' ),
			'last_name'  => sanitize_text_field( $data['last_name'] ?? '' ),
			'email'      => $email,
			// A returning donor's most recent choice wins: if they switch
			// language, that is the language to write to them in.
			'language'   => PSPGD_I18n::normalize_locale( (string) ( $data['language'] ?? '' ) ),
			'updated_at' => $now,
		);

		if ( $row ) {
			$wpdb->update( $table, $payload, array( 'id' => (int) $row->id ) );
			return (int) $row->id;
		}

		$payload['created_at'] = $now;
		$wpdb->insert( $table, $payload );

		return (int) $wpdb->insert_id;
	}

	public static function create_donation( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert(
			$wpdb->prefix . 'pspgd_donations',
			array(
				'donor_id'        => (int) ( $data['donor_id'] ?? 0 ),
				'subscription_id' => (int) ( $data['subscription_id'] ?? 0 ),
				'campaign_id'     => (int) ( $data['campaign_id'] ?? 0 ),
				'pspg_order_id'   => null,
				'bank_order_id'   => '',
				'bank_id'         => sanitize_key( $data['bank_id'] ?? '' ),
				'amount'          => (string) ( $data['amount'] ?? '0.00' ),
				'currency'        => sanitize_text_field( $data['currency'] ?? '' ),
				'type'            => sanitize_key( $data['type'] ?? 'one_time' ),
				'status'          => sanitize_key( $data['status'] ?? 'pending' ),
				'source_id'       => sanitize_text_field( $data['source_id'] ?? '' ),
				'language'        => PSPGD_I18n::normalize_locale( (string) ( $data['language'] ?? '' ) ),
				'is_anonymous'    => empty( $data['is_anonymous'] ) ? 0 : 1,
				// Every donation row is created through here, so the site's own
				// test mode is the fallback: a caller that forgets to say
				// records a simulated payment as real money, and it is the
				// figures above the donation log that carry the mistake.
				'is_test'         => self::donation_is_test( $data ) ? 1 : 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		if ( false === $wpdb->insert_id || (int) $wpdb->insert_id <= 0 ) {
			self::log_event( 'donation', 0, 'donation_insert_failed', 'Donation record could not be created.', array( 'db_error' => $wpdb->last_error, 'table' => $wpdb->prefix . 'pspgd_donations' ) );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Link a donation to the PSPG order that carries it, and to the bank's
	 * own id for that order.
	 *
	 * The bank's id is kept here as well as in PSPG's orders table because a
	 * payment report is checked against it before the donation is touched;
	 * see PSPGD_PSPG::verification_problem(). PSPG's own copy is rewritten
	 * by whatever report arrives, so it cannot be the thing a report is
	 * checked against.
	 */
	public static function attach_pspg_order( int $donation_id, int $pspg_order_id, string $bank_order_id = '' ): void {
		global $wpdb;

		$data = array(
			'pspg_order_id' => $pspg_order_id,
			'updated_at'    => current_time( 'mysql' ),
		);

		$bank_order_id = sanitize_text_field( $bank_order_id );
		if ( '' !== $bank_order_id ) {
			$data['bank_order_id'] = $bank_order_id;
		}

		$wpdb->update( $wpdb->prefix . 'pspgd_donations', $data, array( 'id' => $donation_id ) );
	}

	public static function find_donation_by_pspg_order( int $pspg_order_id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pspg_order_id = %d LIMIT 1', $table, $pspg_order_id ) );

		return $row ?: null;
	}

	/**
	 * Whether a donation being created is a simulated one.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function donation_is_test( array $data ): bool {
		if ( array_key_exists( 'is_test', $data ) ) {
			return ! empty( $data['is_test'] );
		}

		return class_exists( 'PSPGD_PSPG' ) && PSPGD_PSPG::donations_test_mode();
	}

	public static function find_donation( int $donation_id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $donation_id ) );

		return $row ?: null;
	}

	public static function update_donation_status( int $donation_id, string $status, string $reference = '' ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pspgd_donations',
			array(
				'status'                => sanitize_key( $status ),
				'transaction_reference' => sanitize_text_field( $reference ),
				'updated_at'            => current_time( 'mysql' ),
			),
			array( 'id' => $donation_id )
		);
	}

	public static function mark_donation_processing( int $donation_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'pspgd_donations',
			array(
				'status'     => 'processing',
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'     => $donation_id,
				'status' => 'pending',
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Move a donation to a new status only from one of the given statuses,
	 * in one statement, so two paths racing for the same row cannot both
	 * win.
	 *
	 * @param string[] $from
	 */
	public static function transition_donation_status( int $donation_id, array $from, string $to ): bool {
		global $wpdb;

		$from = array_values( array_filter( array_map( 'sanitize_key', $from ) ) );
		if ( ! $from ) {
			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')',
				array_merge( array( $wpdb->prefix . 'pspgd_donations', sanitize_key( $to ), current_time( 'mysql' ), $donation_id ), $from )
			)
		);

		return 1 === (int) $updated;
	}

	public static function create_subscription( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'donor_id'          => (int) $data['donor_id'],
				'campaign_id'       => (int) ( $data['campaign_id'] ?? 0 ),
				'email'             => sanitize_email( $data['email'] ),
				'amount'            => (string) $data['amount'],
				'currency'          => sanitize_text_field( $data['currency'] ),
				'bank_id'           => sanitize_key( $data['bank_id'] ),
				'client_id'         => '',
				'interval_key'      => sanitize_key( $data['interval_key'] ),
				// Snapshotted here rather than read from the donor record at
				// send time, so a later change never retranslates the emails of
				// a subscription the donor set up in another language.
				'language'          => PSPGD_I18n::normalize_locale( (string) ( $data['language'] ?? '' ) ),
				'is_anonymous'      => empty( $data['is_anonymous'] ) ? 0 : 1,
				'is_test'           => empty( $data['is_test'] ) ? 0 : 1,
				'status'            => 'pending',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		if ( false === $wpdb->insert_id || (int) $wpdb->insert_id <= 0 ) {
			self::log_event( 'subscription', 0, 'subscription_insert_failed', 'Subscription record could not be created.', array( 'db_error' => $wpdb->last_error, 'table' => $wpdb->prefix . 'pspgd_subscriptions' ) );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public static function activate_subscription( int $subscription_id, string $binding_id, string $next_payment_at ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array( 'binding_id' => sanitize_text_field( $binding_id ), 'status' => 'active', 'next_payment_at' => $next_payment_at, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $subscription_id )
		);
	}

	/**
	 * Record the client id the bank will bind this subscription's card
	 * under. See PSPGD_PSPG::client_id_for().
	 */
	public static function set_subscription_client_id( int $subscription_id, string $client_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'client_id'  => sanitize_text_field( $client_id ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $subscription_id )
		);
	}

	public static function find_subscription( int $subscription_id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'pspgd_subscriptions';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $subscription_id ) );
		return $row ?: null;
	}

	public static function claim_due_subscription( object $subscription ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'status'     => 'processing',
				// Recorded so a claim abandoned by a fatal or a timeout can be
				// spotted and released later; see release_stuck_subscription().
				'claimed_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'              => (int) $subscription->id,
				'status'          => 'active',
				'next_payment_at' => $subscription->next_payment_at,
			)
		);

		return 1 === (int) $updated;
	}

	public static function cancel_subscription( int $subscription_id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array( 'status' => 'cancelled', 'next_payment_at' => null, 'cancelled_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $subscription_id )
		);
	}

	public static function delete_pending_subscription( int $subscription_id ): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'id'     => $subscription_id,
				'status' => 'pending',
			)
		);
	}

	public static function find_donor( int $donor_id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'pspgd_donors';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $donor_id ) );
		return $row ?: null;
	}

	public static function find_donor_by_email( string $email ): ?object {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return null;
		}

		$table = $wpdb->prefix . 'pspgd_donors';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s LIMIT 1', $table, $email ) );

		return $row ?: null;
	}

	/** @return object[] */
	public static function donations_for_donor( int $donor_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE donor_id = %d ORDER BY created_at DESC', $table, $donor_id )
		);
	}

	/**
	 * Live schedules pointed at any of a campaign's translations.
	 *
	 * Test subscriptions are included deliberately: they are excluded from
	 * charging, but a closed campaign should still leave none of them sitting
	 * active in the admin list.
	 *
	 * @param int[] $campaign_ids
	 * @return object[]
	 */
	public static function active_subscriptions_for_campaigns( array $campaign_ids, int $limit = 500 ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $campaign_ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE campaign_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND status = %s ORDER BY id ASC LIMIT %d',
				array_merge( array( $wpdb->prefix . 'pspgd_subscriptions' ), $ids, array( 'active', $limit ) )
			)
		);
	}

	public static function subscriptions_for_donor( int $donor_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_subscriptions';

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE donor_id = %d ORDER BY created_at DESC', $table, $donor_id )
		);
	}

	/**
	 * Strip everything identifying from a donor record, keeping the row so
	 * that the donations pointing at it still add up.
	 *
	 * The email is replaced with a value at the reserved .invalid domain, so
	 * it can never collide with a real donor or be mailed by accident, and
	 * the row can never be matched back to a person.
	 */
	public static function anonymize_donor( int $donor_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pspgd_donors',
			array(
				'first_name' => '',
				'last_name'  => '',
				'email'      => 'erased-' . $donor_id . '@erased.invalid',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $donor_id )
		);

		$wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'email'      => 'erased-' . $donor_id . '@erased.invalid',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'donor_id' => $donor_id )
		);
	}

	public static function event_exists( string $object_type, int $object_id, string $event_type ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pspgd_events';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE object_type = %s AND object_id = %d AND event_type = %s LIMIT 1', $table, $object_type, $object_id, $event_type ) );
	}

	/**
	 * Subscriptions whose next charge is due.
	 *
	 * Test-mode subscriptions are excluded: they hold a simulated binding
	 * that the bank has never seen, so charging one only produces a real
	 * decline and a real payment-failure email to the donor.
	 *
	 * @return object[]
	 */
	public static function due_subscriptions( int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pspgd_subscriptions';
		$now   = current_time( 'mysql' );
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND is_test = 0 AND next_payment_at <= %s ORDER BY next_payment_at ASC LIMIT %d', $table, 'active', $now, $limit ) );
	}

	public static function reschedule_subscription( int $subscription_id, string $next_payment_at, bool $success ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'pspgd_subscriptions';
		$payload = array( 'status' => 'active', 'next_payment_at' => $next_payment_at, 'claimed_at' => null, 'updated_at' => current_time( 'mysql' ) );
		if ( $success ) {
			$payload['last_payment_at']      = current_time( 'mysql' );
			$payload['failed_payment_count'] = 0;
			$wpdb->update( $table, $payload, array( 'id' => $subscription_id ) );
			return;
		}

		// Use one SQL statement so consecutive failures are not reset to one and
		// concurrent workers cannot lose an increment.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET status = %s,
					next_payment_at = %s,
					updated_at = %s,
					claimed_at = NULL,
					failed_payment_count = failed_payment_count + 1
				WHERE id = %d',
				$table,
				$payload['status'],
				$payload['next_payment_at'],
				$payload['updated_at'],
				$subscription_id
			)
		);
	}

	/**
	 * Release a charge whose outcome is not known yet and move the schedule
	 * to its next date, without counting it either way.
	 *
	 * Counting it as a failure would schedule a retry against a card that
	 * may already have paid; the donation it left behind stays pending, and
	 * the reconciliation job settles it with the bank.
	 */
	public static function move_subscription_on( int $subscription_id, string $next_payment_at ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'status'          => 'active',
				'next_payment_at' => $next_payment_at,
				'claimed_at'      => null,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $subscription_id )
		);
	}

	/**
	 * Donations that were registered with the bank but never confirmed, for
	 * the reconciliation job to re-check with pspg_sync_status().
	 *
	 * @return object[]
	 */
	public static function stale_pending_donations( string $not_updated_since, int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE status = %s AND pspg_order_id IS NOT NULL AND updated_at <= %s
				ORDER BY updated_at ASC
				LIMIT %d',
				$table,
				'pending',
				$not_updated_since,
				$limit
			)
		);
	}

	/**
	 * Donations still pending long after the donor left, whatever the reason
	 * -- including ones that never reached the bank at all, which is why
	 * this does not require a pspg_order_id.
	 *
	 * @return object[]
	 */
	public static function unconfirmed_donations( string $created_before, int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE status = %s AND created_at <= %s
				ORDER BY created_at ASC
				LIMIT %d',
				$table,
				'pending',
				$created_before,
				$limit
			)
		);
	}

	/**
	 * Mark a donation abandoned, but only while it is still pending, so a
	 * bank callback that lands in the same moment always wins.
	 */
	public static function abandon_donation( int $donation_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'pspgd_donations',
			array(
				'status'     => 'abandoned',
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'     => $donation_id,
				'status' => 'pending',
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Donations claimed to apply a bank report that never got their outcome
	 * written -- a fatal or a lost database write between
	 * mark_donation_processing() and the status that follows it, which is
	 * one statement later. Left alone they are never re-checked, never
	 * abandoned and never counted, because every sweep looks at pending rows.
	 *
	 * @return object[]
	 */
	public static function stuck_processing_donations( string $not_updated_since, int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE status = %s AND updated_at <= %s
				ORDER BY updated_at ASC
				LIMIT %d',
				$table,
				'processing',
				$not_updated_since,
				$limit
			)
		);
	}

	/**
	 * Return a stuck donation to pending, so it is re-checked and settled
	 * like any other. Conditional on the row still being in processing and
	 * not touched since the cut-off, so a path that claimed it a moment ago
	 * always wins.
	 */
	public static function release_stuck_donation( int $donation_id, string $not_updated_since ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s AND updated_at <= %s',
				$wpdb->prefix . 'pspgd_donations',
				'pending',
				current_time( 'mysql' ),
				$donation_id,
				'processing',
				$not_updated_since
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Subscriptions claimed for charging that never reported back -- a fatal,
	 * a timeout or a gateway hang between claim_due_subscription() and
	 * reschedule_subscription(). Left alone they are never charged again,
	 * because due_subscriptions() only looks at active rows.
	 *
	 * @return object[]
	 */
	public static function stuck_subscriptions( string $claimed_before, int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_subscriptions';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE status = %s AND claimed_at IS NOT NULL AND claimed_at <= %s
				ORDER BY claimed_at ASC
				LIMIT %d',
				$table,
				'processing',
				$claimed_before,
				$limit
			)
		);
	}

	/**
	 * Return a stuck subscription to active without touching next_payment_at,
	 * so it is picked up again on the next run. Conditional on the row still
	 * being in processing, so a worker that recovers on its own always wins.
	 */
	public static function release_stuck_subscription( int $subscription_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'status'     => 'active',
				'claimed_at' => null,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'     => $subscription_id,
				'status' => 'processing',
			)
		);

		return 1 === (int) $updated;
	}

	public static function log_event( string $object_type, int $object_id, string $event_type, string $message = '', array $context = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'pspgd_events',
			array(
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
				'event_type'  => sanitize_key( $event_type ),
				'message'     => sanitize_textarea_field( $message ),
				'context'     => wp_json_encode( self::redact_context( $context ) ),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Fields worth keeping out of a gateway response.
	 *
	 * Whole bank payloads used to be stored verbatim and forever, which over
	 * a few years makes this the largest table in the database and an
	 * uncontrolled store of payment metadata nobody decided to keep. An
	 * allowlist means a new field the bank starts returning is dropped by
	 * default rather than retained by default.
	 *
	 * @return string[]
	 */
	public static function context_allowlist(): array {
		return array(
			'ok', 'status', 'bank_status', 'bank', 'bank_id', 'channel',
			'error', 'errorCode', 'errorMessage', 'message',
			'orderId', 'orderStatus', 'pspg_order_id', 'order_id',
			'amount', 'currency', 'source', 'source_id',
			'transaction_id', 'payment_id', 'rrn', 'approval_code', 'bank_ids',
			'binding_id', 'bindingId', 'client_id', 'is_binding',
			'redirect_url', 'payment_url', 'url', 'form_url',
			'interval_key', 'campaign_id', 'is_test', 'created_at', 'db_error', 'table',
			'rate', 'last_rate',
		);
	}

	/**
	 * Keep only allowlisted keys, at a bounded depth and length.
	 *
	 * @param mixed $context
	 * @return mixed
	 */
	public static function redact_context( $context, int $depth = 0 ) {
		if ( is_scalar( $context ) || null === $context ) {
			return is_string( $context ) && strlen( $context ) > 500
				? substr( $context, 0, 500 ) . '...'
				: $context;
		}

		if ( ! is_array( $context ) || $depth > 3 ) {
			return null;
		}

		$allowed = self::context_allowlist();
		$clean   = array();

		foreach ( $context as $key => $value ) {
			// A list of values (bank_ids, for example) keeps its shape; a map
			// is filtered by key.
			if ( is_int( $key ) ) {
				$clean[ $key ] = self::redact_context( $value, $depth + 1 );
				continue;
			}

			if ( ! in_array( (string) $key, $allowed, true ) ) {
				continue;
			}

			$clean[ $key ] = self::redact_context( $value, $depth + 1 );
		}

		return $clean;
	}

	/**
	 * Delete events past their retention age, in one bounded statement so a
	 * backlog is worked through across runs rather than in one long lock.
	 */
	public static function prune_events( string $older_than, int $limit = 500 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_events';

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s ORDER BY created_at ASC LIMIT %d',
				$table,
				$older_than,
				$limit
			)
		);
	}

	public static function list_recent_events( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_events';

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, object_type, object_id, event_type, message, context, created_at FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				$limit,
				$offset
			)
		);
	}

	public static function count_events(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_events';

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Build the WHERE clause shared by the donation list, its count and the
	 * CSV export, so an export always contains exactly the rows on screen.
	 *
	 * @param array<string, string> $filters
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	private static function donation_filter_sql( array $filters ): array {
		global $wpdb;

		$clauses = array();
		$args    = array();

		foreach ( array( 'status', 'type', 'currency', 'language' ) as $field ) {
			$value = trim( (string) ( $filters[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$clauses[] = "d.{$field} = %s";
				$args[]    = 'currency' === $field ? strtoupper( $value ) : $value;
			}
		}

		$campaign_id = absint( $filters['campaign_id'] ?? 0 );
		if ( $campaign_id > 0 ) {
			$clauses[] = 'd.campaign_id = %d';
			$args[]    = $campaign_id;
		}

		$from = trim( (string) ( $filters['date_from'] ?? '' ) );
		if ( '' !== $from ) {
			$clauses[] = 'd.created_at >= %s';
			$args[]    = $from . ' 00:00:00';
		}

		$to = trim( (string) ( $filters['date_to'] ?? '' ) );
		if ( '' !== $to ) {
			$clauses[] = 'd.created_at <= %s';
			$args[]    = $to . ' 23:59:59';
		}

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '( donor.email LIKE %s OR donor.first_name LIKE %s OR donor.last_name LIKE %s OR d.transaction_reference LIKE %s )';
			array_push( $args, $like, $like, $like, $like );
		}

		return array(
			'sql'  => $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			'args' => $args,
		);
	}

	/**
	 * @param array<string, string> $filters
	 * @return object[]
	 */
	public static function list_donations( int $limit = 50, int $offset = 0, array $filters = array() ): array {
		global $wpdb;

		$where = self::donation_filter_sql( $filters );
		$args  = array_merge(
			array( $wpdb->prefix . 'pspgd_donations', $wpdb->prefix . 'pspgd_donors', $wpdb->posts ),
			$where['args'],
			array( $limit, $offset )
		);

		// The WHERE clause is assembled by donation_filter_sql() from fixed column
		// names and placeholders only; its values are in $args, which the sniffs
		// cannot count.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, donor.email, donor.first_name, donor.last_name, campaign.post_title AS campaign_title
				FROM %i d
				LEFT JOIN %i donor ON donor.id = d.donor_id
				LEFT JOIN %i campaign ON campaign.ID = d.campaign_id
				{$where['sql']}
				ORDER BY d.created_at DESC
				LIMIT %d OFFSET %d",
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/** @param array<string, string> $filters */
	public static function count_donations( array $filters = array() ): int {
		global $wpdb;

		$where = self::donation_filter_sql( $filters );

		// Same WHERE clause as list_donations(): fixed columns and placeholders only.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i d LEFT JOIN %i donor ON donor.id = d.donor_id {$where['sql']}",
				array_merge( array( $wpdb->prefix . 'pspgd_donations', $wpdb->prefix . 'pspgd_donors' ), $where['args'] )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Every donation matching the filters, read in batches so an export of a
	 * large table cannot exhaust memory.
	 *
	 * @param array<string, string> $filters
	 * @return Generator<object>
	 */
	public static function stream_donations( array $filters = array(), int $batch = 500 ): Generator {
		$offset = 0;

		do {
			$rows = self::list_donations( $batch, $offset, $filters );
			foreach ( $rows as $row ) {
				yield $row;
			}
			$offset += $batch;
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Distinct values actually present in the donation table, for building
	 * filter dropdowns that only offer what exists.
	 *
	 * @return string[]
	 */
	public static function distinct_donation_values( string $column ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'status', 'type', 'currency', 'language' ), true ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'pspgd_donations';
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT %i FROM %i WHERE %i <> '' ORDER BY %i ASC", $column, $table, $column, $column ) );

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * List recurring-donation subscriptions for the admin "Recurring Donations" panel.
	 *
	 * A subscription row is created before the donor is sent to the bank, so
	 * a `pending` row is not a recurring donation -- it is someone who opened
	 * the payment page and may never come back. Those are hidden by default
	 * so the panel shows only donations that actually exist. They are removed
	 * entirely a day later; see PSPGD_Reconciliation.
	 *
	 * @return object[]
	 */
	public static function list_subscriptions( int $limit = 50, int $offset = 0, bool $include_incomplete = false ): array {
		global $wpdb;

		// 'pending' is excluded by comparing against a value no real status
		// has, so the query keeps one shape either way.
		$excluded = $include_incomplete ? '' : 'pending';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, donor.email AS donor_email, donor.first_name, donor.last_name, campaign.post_title AS campaign_title
				FROM %i s
				LEFT JOIN %i donor ON donor.id = s.donor_id
				LEFT JOIN %i campaign ON campaign.ID = s.campaign_id
				WHERE s.status <> %s
				ORDER BY s.created_at DESC
				LIMIT %d OFFSET %d',
				$wpdb->prefix . 'pspgd_subscriptions',
				$wpdb->prefix . 'pspgd_donors',
				$wpdb->posts,
				$excluded,
				$limit,
				$offset
			)
		);
	}

	/** Must match list_subscriptions(), or paging drifts out of step with the rows. */
	public static function count_subscriptions( bool $include_incomplete = false ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status <> %s',
				$wpdb->prefix . 'pspgd_subscriptions',
				$include_incomplete ? '' : 'pending'
			)
		);
	}

	/**
	 * Subscriptions the donor started but never completed: still pending long
	 * after signup, with nothing settled or in flight against them.
	 *
	 * The NOT EXISTS clause is what keeps this safe. A donor whose payment
	 * went through but whose card binding never came back also leaves a row
	 * that is not active -- that one is marked setup_failed and must be kept,
	 * because they were charged. A donation still `pending` means the donor
	 * came back recently and is mid-flow, so that row is left alone too.
	 *
	 * @return object[]
	 */
	public static function abandoned_subscriptions( string $created_before, int $limit = 50 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.* FROM %i s
				WHERE s.status = %s
					AND s.created_at <= %s
					AND NOT EXISTS (
						SELECT 1 FROM %i d
						WHERE d.subscription_id = s.id
							AND d.status IN ('paid', 'refunded', 'pending', 'review')
					)
				ORDER BY s.created_at ASC
				LIMIT %d",
				$wpdb->prefix . 'pspgd_subscriptions',
				'pending',
				$created_before,
				$wpdb->prefix . 'pspgd_donations',
				$limit
			)
		);
	}

	/**
	 * Remove an abandoned subscription, conditional on it still being pending
	 * so a confirmation landing at the same moment always wins. Returns false
	 * when it did not delete, which is also how the caller claims the row.
	 */
	public static function delete_abandoned_subscription( int $subscription_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'id'     => $subscription_id,
				'status' => 'pending',
			)
		);

		return 1 === (int) $deleted;
	}

	/**
	 * Delete the donation rows of an abandoned subscription that never reached
	 * the bank at all. A row that did reach it -- declined, or abandoned at
	 * the payment page -- is a real attempt and stays in the donation log,
	 * where it feeds the completion rate alongside one-time donations.
	 */
	public static function delete_unstarted_donations( int $subscription_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'pspgd_donations';

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE subscription_id = %d AND status = %s AND pspg_order_id IS NULL',
				$table,
				$subscription_id,
				'pending'
			)
		);
	}

	/**
	 * Point the surviving donation rows away from a subscription that no
	 * longer exists. 0 is the column's own "no subscription" default, so the
	 * donation keeps its own amount, currency and status without carrying a
	 * reference to a deleted row.
	 */
	public static function detach_donations_from_subscription( int $subscription_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pspgd_donations',
			array(
				'subscription_id' => 0,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'subscription_id' => $subscription_id )
		);
	}

	/**
	 * The donor was charged, but the bank returned no reusable card binding,
	 * so there is no recurring donation to run -- and there is also nothing
	 * incomplete to clean up. Kept visible in the admin panel and never
	 * purged, because someone paid.
	 */
	public static function mark_subscription_setup_failed( int $subscription_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'pspgd_subscriptions',
			array(
				'status'     => 'setup_failed',
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'     => $subscription_id,
				'status' => 'pending',
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Given a list of subscription ids, return the subset that have at
	 * least one donation whose payment_redirect_url_anomaly event was
	 * logged (see PSPGD_PSPG::flag_redirect_url_anomaly_if_any()).
	 * Used by the Recurring Donations admin panel to flag rows where the
	 * bank returned a hosted-payment URL that may 404 for the donor.
	 *
	 * @param int[] $subscription_ids
	 * @return int[] Subscription ids that have a flagged event.
	 */
	public static function subscriptions_with_flagged_redirect_url( array $subscription_ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $subscription_ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT d.subscription_id
				FROM %i e
				INNER JOIN %i d ON d.id = e.object_id
				WHERE e.object_type = 'donation'
					AND e.event_type = 'payment_redirect_url_anomaly'
					AND d.subscription_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( $wpdb->prefix . 'pspgd_events', $wpdb->prefix . 'pspgd_donations' ), $ids )
			)
		);

		return array_map( 'absint', $rows );
	}

	/**
	 * Paid totals for a campaign, grouped by the currency the donor gave in.
	 *
	 * This layer never adds currencies together: SQL has no idea what a
	 * rate is, and summing the amount column across currencies would treat
	 * different currencies as the same unit. Combining them into one figure
	 * is the job of PSPGD_Exchange_Rates::combine(), which can say when it
	 * has no rate to do it with.
	 *
	 * @param int[] $campaign_ids
	 * @return array{by_currency: array<string, array{total: float, count: int}>, donation_count: int}
	 */
	public static function campaign_progress( array $campaign_ids ): array {
		global $wpdb;

		$progress = array(
			'by_currency'    => array(),
			'donation_count' => 0,
		);

		$table = $wpdb->prefix . 'pspgd_donations';
		$ids   = array_values( array_filter( array_map( 'absint', $campaign_ids ) ) );
		if ( ! $ids ) {
			return $progress;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, COUNT(*) AS donation_count, COALESCE(SUM(amount), 0) AS total
				FROM %i
				WHERE campaign_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND status = %s AND is_test = 0
				GROUP BY currency',
				array_merge( array( $table ), $ids, array( 'paid' ) )
			)
		);

		foreach ( (array) $rows as $row ) {
			$currency = strtoupper( (string) $row->currency );
			if ( '' === $currency ) {
				continue;
			}

			$progress['by_currency'][ $currency ] = array(
				'total' => (float) $row->total,
				'count' => (int) $row->donation_count,
			);
			$progress['donation_count'] += (int) $row->donation_count;
		}

		return $progress;
	}

	public static function campaign_donors( array $campaign_ids, int $limit = 20 ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $campaign_ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.amount, d.currency, d.is_anonymous, d.created_at, donor.first_name, donor.last_name
				FROM %i d
				LEFT JOIN %i donor ON donor.id = d.donor_id
				WHERE d.campaign_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND d.status = %s AND d.is_test = 0
				ORDER BY d.created_at DESC
				LIMIT %d',
				array_merge( array( $wpdb->prefix . 'pspgd_donations', $wpdb->prefix . 'pspgd_donors' ), $ids, array( 'paid', $limit ) )
			)
		);
	}
}
