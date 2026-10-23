<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_Recurring {
	public const HOOK = 'pspgd_process_recurring';

	private const LOCK_KEY = 'pspgd_recurring_lock';
	private const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Consecutive failed charges allowed before a subscription is
	 * automatically cancelled and the donor is notified.
	 */
	private const MAX_CONSECUTIVE_FAILURES = 3;

	/**
	 * Days to wait before each retry after a failed charge, indexed by the
	 * number of failures already recorded.
	 *
	 * A failure used to be rescheduled a whole interval later, so a monthly
	 * donor whose card was briefly declined waited a month for the first
	 * retry and three months to reach cancellation -- by which point a card
	 * that had simply been reissued was long past recovering.
	 */
	private const RETRY_DAYS = array( 3, 7, 14 );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'process_due_subscriptions' ) );
	}

	public static function ensure_schedule(): void {
		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && 'hourly' !== $event->schedule ) {
			wp_unschedule_event( $event->timestamp, self::HOOK );
			$event = false;
		}
		if ( ! $event ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * One interval from now. Used when there is no signup date to anchor to.
	 */
	public static function next_payment_at( string $interval_key ): string {
		$now = ( new DateTimeImmutable( '@' . PSPGD_Clock::now() ) )->setTimezone( wp_timezone() );

		return $now->modify( 'weekly' === $interval_key ? '+1 week' : '+1 month' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The next charge date for a subscription, anchored to the date the donor
	 * signed up rather than to "now".
	 *
	 * Anchoring matters after a retry: a monthly donor who signed up on the
	 * 4th, failed on the 4th and was charged successfully on the 7th belongs
	 * back on the 4th, not three days later for the rest of the subscription.
	 * Month lengths are respected too, so a signup on the 31st lands on the
	 * 30th, 29th or 28th in shorter months rather than spilling into the next
	 * one, which is what strtotime( '+1 month' ) does.
	 */
	public static function next_anniversary( object $subscription ): string {
		$interval = (string) $subscription->interval_key;
		$now      = PSPGD_Clock::now();

		try {
			$anchor = new DateTimeImmutable( (string) $subscription->created_at, wp_timezone() );
		} catch ( Exception $error ) {
			return self::next_payment_at( $interval );
		}

		// A subscription reaching 600 cycles is a decade of weekly giving; the
		// cap exists only so a nonsense anchor cannot spin here forever.
		for ( $cycle = 1; $cycle <= 600; $cycle++ ) {
			$candidate = self::add_cycles( $anchor, $interval, $cycle );
			if ( $candidate->getTimestamp() > $now ) {
				return $candidate->format( 'Y-m-d H:i:s' );
			}
		}

		return self::next_payment_at( $interval );
	}

	private static function add_cycles( DateTimeImmutable $anchor, string $interval_key, int $cycles ): DateTimeImmutable {
		if ( 'weekly' === $interval_key ) {
			return $anchor->modify( '+' . ( 7 * $cycles ) . ' days' );
		}

		$day   = (int) $anchor->format( 'j' );
		$month = $anchor->modify( 'first day of this month' )->modify( '+' . $cycles . ' months' );

		return $month->setDate(
			(int) $month->format( 'Y' ),
			(int) $month->format( 'n' ),
			min( $day, (int) $month->format( 't' ) )
		);
	}

	/**
	 * When to retry after a failed charge.
	 *
	 * @param int $failures_already_recorded The count before this failure is added.
	 */
	public static function retry_at( int $failures_already_recorded ): string {
		$ladder = array_values( array_filter( array_map( 'absint', (array) apply_filters( 'pspgd_retry_days', self::RETRY_DAYS ) ) ) );
		$ladder = $ladder ? $ladder : self::RETRY_DAYS;
		$index  = max( 0, min( $failures_already_recorded, count( $ladder ) - 1 ) );

		return PSPGD_Clock::local_mysql( PSPGD_Clock::now() + ( $ladder[ $index ] * DAY_IN_SECONDS ) );
	}

	public static function max_consecutive_failures(): int {
		return max( 1, (int) apply_filters( 'pspgd_max_consecutive_failures', self::MAX_CONSECUTIVE_FAILURES ) );
	}

	public static function process_due_subscriptions(): void {
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			foreach ( PSPGD_Repository::due_subscriptions() as $subscription ) {
				if ( ! PSPGD_Repository::claim_due_subscription( $subscription ) ) {
					continue;
				}

				self::charge_once( $subscription, 'recurring_cron' );
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Charge one subscription's saved card and deal with the outcome:
	 * record the donation, reschedule, notify the donor, and cancel once the
	 * retry budget is spent.
	 *
	 * The caller must have claimed the subscription first. There is
	 * deliberately no way to trigger this from the admin panel: a charge only
	 * ever happens because a donor's schedule came due.
	 */
	public static function charge_once( object $subscription, string $channel ): bool {
		// Last line of defence. A closed campaign's schedules are cancelled
		// when it closes, so reaching this means one was missed -- a campaign
		// closed by hand, a sweep that has not run, a row restored from a
		// backup. Whatever the cause, the donor must not be charged for a
		// campaign that is over.
		if ( ! self::campaign_accepts_charges( $subscription ) ) {
			return false;
		}

		$failures = (int) $subscription->failed_payment_count;

		$donation_id = PSPGD_Repository::create_donation(
			array(
				'donor_id'        => $subscription->donor_id,
				'subscription_id' => $subscription->id,
				'campaign_id'     => $subscription->campaign_id,
				'bank_id'         => $subscription->bank_id,
				'amount'          => $subscription->amount,
				'currency'        => $subscription->currency,
				'type'            => 'recurring',
				'status'          => 'pending',
				'source_id'       => wp_generate_uuid4(),
				'language'        => (string) ( $subscription->language ?? '' ),
				'is_anonymous'    => ! empty( $subscription->is_anonymous ),
				'is_test'         => ! empty( $subscription->is_test ),
			)
		);

		if ( $donation_id <= 0 ) {
			// No donation row means nothing was charged, so this is not a
			// bank failure and must not count against the donor's card.
			PSPGD_Repository::reschedule_subscription( (int) $subscription->id, self::retry_at( $failures ), false );
			return false;
		}

		$result = PSPGD_PSPG::charge_subscription( $donation_id, $subscription );
		$result = is_array( $result ) ? $result : array();

		// What the bank says happened, not whether the request to it
		// succeeded: a declined card comes back with ok=true and a failed
		// status, and used to be scheduled for next month as though it had
		// paid -- no retry, no email, the failure count reset to zero.
		$outcome = PSPGD_PSPG::charge_outcome( $result );

		if ( 'paid' === $outcome && ! empty( $result['pspg_order_id'] ) ) {
			PSPGD_PSPG::handle_confirmed_payment(
				(int) $result['pspg_order_id'],
				$result,
				array(
					'channel'       => $channel,
					'defer_receipt' => true,
				)
			);
		} elseif ( 'paid' === $outcome ) {
			// Paid, but with no PSPG order to confirm it against.
			$outcome = 'unknown';
		} elseif ( 'failed' === $outcome ) {
			PSPGD_Payments::mark_failed( $donation_id );
		}

		if ( 'failed' === $outcome ) {
			PSPGD_Repository::reschedule_subscription( (int) $subscription->id, self::retry_at( $failures ), false );
		} elseif ( 'paid' === $outcome ) {
			PSPGD_Repository::reschedule_subscription( (int) $subscription->id, self::next_anniversary( $subscription ), true );
		} else {
			// The bank may have charged the card. Retrying would risk
			// charging it twice, so the schedule moves on uncounted and the
			// donation stays pending for the reconciliation job to settle.
			PSPGD_Repository::move_subscription_on( (int) $subscription->id, self::next_anniversary( $subscription ) );
		}

		$events = array(
			'paid'    => 'recurring_charge_created',
			'failed'  => 'recurring_charge_failed',
			'unknown' => 'recurring_charge_unconfirmed',
		);
		PSPGD_Repository::log_event(
			'subscription',
			(int) $subscription->id,
			$events[ $outcome ],
			'unknown' === $outcome
				? sprintf( 'Charge attempted through %s, but the bank did not report an outcome. The donation stays pending until reconciliation hears from the bank.', $channel )
				: sprintf( 'Charge attempted through %s.', $channel ),
			$result
		);

		// The receipt is composed here rather than in the payment callback:
		// by this point the claim has been released, next_payment_at is the
		// date of the next charge and the donation row carries the bank's
		// reference, so the donor gets a cancel link, a date they can rely on
		// and a reference that matches their statement. The
		// payment_receipt_sent event keeps it to one email if the callback
		// path has already been there. A payment held for review (the bank
		// settled a different amount) is not receipted.
		$paid = false;
		if ( 'paid' === $outcome ) {
			$charged = PSPGD_Repository::find_donation( $donation_id );
			if ( $charged && 'paid' === (string) $charged->status ) {
				PSPGD_Emails::send_receipt( $charged );
				$paid = true;
			}
		}

		if ( 'failed' === $outcome ) {
			self::handle_failed_charge( (int) $subscription->id );
		}

		return $paid;
	}

	/**
	 * Whether this subscription's campaign is still running -- and if it is
	 * not, end the subscription here rather than simply skipping it.
	 *
	 * Skipping would leave the row active and permanently overdue, so every
	 * hourly run would pick it up again and the donor's card would stay bound
	 * at the bank indefinitely.
	 */
	private static function campaign_accepts_charges( object $subscription ): bool {
		$campaign_id = (int) ( $subscription->campaign_id ?? 0 );
		if ( $campaign_id <= 0 ) {
			return true;
		}

		$status = PSPGD_Campaigns::status( $campaign_id );
		if ( ! empty( $status['open'] ) ) {
			return true;
		}

		PSPGD_Repository::cancel_subscription( (int) $subscription->id );
		$result = PSPGD_PSPG::unbind_subscription( $subscription );
		PSPGD_Repository::log_event(
			'subscription',
			(int) $subscription->id,
			'recurring_cancelled_campaign_closed',
			sprintf( 'Charge skipped and schedule ended: campaign %d is closed (%s).', $campaign_id, (string) $status['reason'] ),
			is_array( $result ) ? $result : array()
		);
		PSPGD_Emails::notify_recurring_cancelled_campaign_closed( $subscription, (string) $status['reason'] );

		return false;
	}

	/**
	 * Look at the fresh failed-payment count after a reschedule and either
	 * notify the donor that a charge failed, or cancel the subscription and
	 * notify them once the consecutive-failure threshold is reached.
	 */
	private static function handle_failed_charge( int $subscription_id ): void {
		$subscription = PSPGD_Repository::find_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		$failed_count = (int) $subscription->failed_payment_count;

		if ( $failed_count >= self::max_consecutive_failures() ) {
			PSPGD_Repository::cancel_subscription( $subscription_id );
			$result = PSPGD_PSPG::unbind_subscription( $subscription );
			PSPGD_Repository::log_event(
				'subscription',
				$subscription_id,
				'recurring_cancelled_after_failures',
				sprintf( 'Cancelled after %d consecutive failed charges.', $failed_count ),
				$result
			);
			PSPGD_Emails::notify_recurring_cancelled_after_failures( $subscription );
			return;
		}

		PSPGD_Emails::notify_recurring_payment_failed( $subscription, $failed_count, self::max_consecutive_failures() );
	}

	private static function acquire_lock(): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( self::LOCK_KEY, (string) time(), 'pspgd_donations', self::LOCK_TTL );
		}

		$expires_at = time() + self::LOCK_TTL;
		if ( add_option( self::LOCK_KEY, (string) $expires_at, '', false ) ) {
			return true;
		}

		$current = (int) get_option( self::LOCK_KEY, 0 );
		if ( $current > time() ) {
			return false;
		}

		delete_option( self::LOCK_KEY );
		return (bool) add_option( self::LOCK_KEY, (string) $expires_at, '', false );
	}

	private static function release_lock(): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( self::LOCK_KEY, 'pspgd_donations' );
			return;
		}

		delete_option( self::LOCK_KEY );
	}
}
