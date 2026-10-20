<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settles donations the bank never told us about.
 *
 * A donation is normally confirmed by the PSPG callback, or by the donor's
 * browser returning with an order reference. Both can be missed: the donor
 * pays and closes the tab before the redirect, the callback is lost, or the
 * site is briefly unreachable. Without this job those donations sit at
 * `pending` forever -- money actually taken by the bank that never appears in
 * a total and never produces a receipt.
 *
 * This runs every 15 minutes and does three things:
 *
 *  1. re-asks the bank about payments that have been pending for a while;
 *  2. marks payments abandoned once a day has passed with no confirmation;
 *  3. releases subscriptions whose charge attempt died mid-flight.
 */
final class PSPGD_Reconciliation {

	public const HOOK     = 'pspgd_reconcile';
	public const SCHEDULE = 'pspgd_fifteen_minutes';

	/** How long a payment may stay pending before the bank is re-asked. */
	private const RECHECK_AFTER = 20 * MINUTE_IN_SECONDS;

	/** How long a payment may stay unconfirmed before it is abandoned. */
	private const ABANDON_AFTER = DAY_IN_SECONDS;

	/** How long a claimed subscription may stay silent before it is released. */
	private const STUCK_CLAIM_AFTER = HOUR_IN_SECONDS;

	/** Donations re-checked per run, so one run cannot stall on a slow bank. */
	private const BATCH = 20;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Donations for PlanetStudio Payment Gateway)', 'donations-for-planetstudio-payment-gateway' ),
		);

		return $schedules;
	}

	public static function ensure_schedule(): void {
		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && self::SCHEDULE !== $event->schedule ) {
			wp_unschedule_event( $event->timestamp, self::HOOK );
			$event = false;
		}

		if ( ! $event ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::SCHEDULE, self::HOOK );
		}
	}

	public static function run(): void {
		self::recheck_pending_payments();
		self::abandon_unconfirmed_payments();
		self::purge_abandoned_subscriptions();
		self::release_stuck_subscriptions();
		self::prune_events();
	}

	/**
	 * Drop event-log rows past their retention age.
	 *
	 * The log is written on every payment, email and cron decision, so
	 * left alone it grows without bound. Setting the retention to 0 keeps
	 * everything, for anyone who would rather manage it themselves.
	 */
	private static function prune_events(): void {
		$days = (int) get_option( 'pspgd_event_retention_days', 180 );
		if ( $days <= 0 ) {
			return;
		}

		PSPGD_Repository::prune_events( self::ago( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Ask the bank again about payments that have been pending long enough
	 * that neither the callback nor the browser return is still coming.
	 */
	private static function recheck_pending_payments(): void {
		if ( ! PSPGD_PSPG::is_ready() ) {
			return;
		}

		foreach ( PSPGD_Repository::stale_pending_donations( self::ago( self::RECHECK_AFTER ), self::BATCH ) as $donation ) {
			$result = PSPGD_PSPG::reconcile_donation( $donation );
			$status = PSPGD_PSPG::normalize_status( is_array( $result ) ? $result : array() );

			if ( 'paid' === $status ) {
				PSPGD_Repository::log_event(
					'donation',
					(int) $donation->id,
					'reconcile_recovered',
					'A payment the bank never reported back was found to be paid and has been confirmed.'
				);
			}
		}
	}

	/**
	 * Stop chasing payments that were never completed.
	 *
	 * `abandoned` is deliberately distinct from `failed`: the donor walked
	 * away, the bank never declined anything. Keeping them apart is what lets
	 * the reporting screens show a meaningful completion rate.
	 */
	private static function abandon_unconfirmed_payments(): void {
		foreach ( PSPGD_Repository::unconfirmed_donations( self::ago( self::ABANDON_AFTER ), 50 ) as $donation ) {
			if ( ! PSPGD_Repository::abandon_donation( (int) $donation->id ) ) {
				continue;
			}

			PSPGD_Stats::flush();

			PSPGD_Repository::log_event(
				'donation',
				(int) $donation->id,
				'payment_abandoned',
				'No confirmation arrived within 24 hours, so the payment was never completed.',
				array(
					'amount'          => $donation->amount,
					'currency'        => $donation->currency,
					'type'            => $donation->type,
					'subscription_id' => (int) $donation->subscription_id,
				)
			);
		}
	}

	/**
	 * Discard recurring donations that were set up but never paid for.
	 *
	 * The subscription row is written before the donor is sent to the bank,
	 * so every abandoned payment page leaves one behind. A day is long enough
	 * for a late callback to still activate it; after that the attempt is
	 * over, and keeping the row would mean the Recurring Donations panel
	 * slowly filling with donations that never happened.
	 *
	 * One identity-free event is kept per discarded attempt, so the funnel
	 * stays reportable without retaining the details of someone who did not
	 * complete a donation.
	 */
	private static function purge_abandoned_subscriptions(): void {
		foreach ( PSPGD_Repository::abandoned_subscriptions( self::ago( self::ABANDON_AFTER ), 50 ) as $subscription ) {
			$subscription_id = (int) $subscription->id;

			// Deleting first is also how the row is claimed: a confirmation
			// arriving in the same moment moves it out of pending, and the
			// delete then matches nothing and is skipped.
			if ( ! PSPGD_Repository::delete_abandoned_subscription( $subscription_id ) ) {
				continue;
			}

			PSPGD_Repository::delete_unstarted_donations( $subscription_id );
			PSPGD_Repository::detach_donations_from_subscription( $subscription_id );

			PSPGD_Repository::log_event(
				'subscription',
				$subscription_id,
				'subscription_abandoned',
				'A recurring donation was started but never paid for, so the incomplete record was discarded.',
				array(
					'amount'       => $subscription->amount,
					'currency'     => $subscription->currency,
					'interval_key' => $subscription->interval_key,
					'campaign_id'  => (int) $subscription->campaign_id,
					'is_test'      => ! empty( $subscription->is_test ),
					'created_at'   => $subscription->created_at,
				)
			);
		}
	}

	/**
	 * Put back subscriptions whose charge attempt never reported an outcome.
	 *
	 * The claim in claim_due_subscription() is what stops two workers charging
	 * the same card twice, but a fatal or a timeout leaves the row claimed and
	 * therefore invisible to due_subscriptions() forever. next_payment_at is
	 * left untouched, so a released subscription is simply due again.
	 */
	private static function release_stuck_subscriptions(): void {
		foreach ( PSPGD_Repository::stuck_subscriptions( self::ago( self::STUCK_CLAIM_AFTER ), self::BATCH ) as $subscription ) {
			if ( ! PSPGD_Repository::release_stuck_subscription( (int) $subscription->id ) ) {
				continue;
			}

			PSPGD_Repository::log_event(
				'subscription',
				(int) $subscription->id,
				'recurring_claim_recovered',
				sprintf( 'A charge attempt claimed at %s never reported an outcome, so the subscription was made active again.', (string) $subscription->claimed_at )
			);
		}
	}

	private static function ago( int $seconds ): string {
		return PSPGD_Clock::local_mysql( PSPGD_Clock::now() - $seconds );
	}
}
