<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happens to a donation as its payment moves on: confirmed, held for
 * review, failed or refunded, and everything that follows each of those.
 *
 * Every status change after a donation is created comes through here, each
 * one guarded so two paths racing for the same row cannot both win. The
 * bank is never asked from here: PSPGD_PSPG reads what PSPG reports and
 * hands it over as a report (see PSPGD_PSPG::read_report()), and test mode
 * hands over a simulated one, so a test payment takes the same path as a
 * real one.
 */
final class PSPGD_Payments {

	/**
	 * Apply a bank report to its donation.
	 *
	 * Runs for PSPG callbacks, browser returns, reconciliation, scheduled
	 * charges and test payments.
	 *
	 * @param array<string, mixed> $report  status, reference, binding_id,
	 *                                      problem, settled, pspg_order_id
	 *                                      and bank_result; see
	 *                                      PSPGD_PSPG::read_report().
	 * @param array<string, mixed> $context Where the report came from:
	 *                                      channel, and defer_receipt for a
	 *                                      scheduled charge.
	 */
	public static function apply_report( object $donation, array $report, array $context = array() ): void {
		$status        = (string) ( $report['status'] ?? 'pending' );
		$reference     = (string) ( $report['reference'] ?? '' );
		$problem       = (string) ( $report['problem'] ?? '' );
		$pspg_order_id = (int) ( $report['pspg_order_id'] ?? 0 );
		$bank_result   = isset( $report['bank_result'] ) && is_array( $report['bank_result'] ) ? $report['bank_result'] : array();

		if ( 'order_mismatch' === $problem ) {
			// A report about some other bank order, delivered against this
			// donation's PSPG order number. Acting on it would confirm or
			// fail the wrong donation, so it is recorded and nothing else.
			PSPGD_Repository::log_event(
				'security',
				(int) $donation->id,
				'payment_order_mismatch',
				'A payment report named a different bank order than the one this donation registered, so it was ignored.',
				array(
					'pspg_order_id' => $pspg_order_id,
					'order_id'      => (string) ( $donation->bank_order_id ?? '' ),
					'bank_ids'      => isset( $bank_result['bank_ids'] ) && is_array( $bank_result['bank_ids'] ) ? $bank_result['bank_ids'] : array(),
				)
			);
			return;
		}

		if ( ! PSPGD_Repository::mark_donation_processing( (int) $donation->id ) ) {
			return;
		}

		if ( 'paid' === $status && 'amount_mismatch' === $problem ) {
			$settled = isset( $report['settled'] ) && is_array( $report['settled'] ) ? $report['settled'] : null;
			self::hold_for_review( $donation, $bank_result, $reference, $pspg_order_id, $settled );
			return;
		}

		PSPGD_Repository::update_donation_status( (int) $donation->id, $status, $reference );

		if ( 'paid' === $status && 'recurring_initial' === $donation->type && (int) $donation->subscription_id > 0 ) {
			$binding_id   = (string) ( $report['binding_id'] ?? '' );
			$subscription = PSPGD_Repository::find_subscription( (int) $donation->subscription_id );
			if ( '' !== $binding_id && $subscription ) {
				PSPGD_Subscriptions::activate( $subscription, $binding_id );
			} elseif ( $subscription ) {
				if ( ! PSPGD_Repository::event_exists( 'subscription', (int) $subscription->id, 'recurring_binding_missing' ) ) {
					// The donor was charged, so this is not an abandoned signup
					// and must never be swept up by the abandonment purge -- it
					// needs a person to follow up with them instead.
					PSPGD_Repository::mark_subscription_setup_failed( (int) $subscription->id );
					PSPGD_Repository::log_event(
						'subscription',
						(int) $subscription->id,
						'recurring_binding_missing',
						'Payment was confirmed, but the bank did not return a reusable card binding.',
						$bank_result
					);
					PSPGD_Emails::notify_recurring_setup_failed( $subscription );
				}
			}
		}

		// A scheduled recurring charge sends its own receipt once it has
		// released the subscription and moved it on to its next date; see
		// PSPGD_Recurring::charge_once(). Sending from here would compose the
		// email mid-charge, with the row still claimed and still pointed at
		// the payment being confirmed.
		$defer_receipt = ! empty( $context['defer_receipt'] );

		if ( 'paid' === $status && 'recurring_initial' !== $donation->type && ! $defer_receipt ) {
			PSPGD_Emails::send_receipt( $donation );
		}
		// The donation just changed state, so the figures above the donation
		// log are stale; drop them rather than showing a confirmed payment
		// as missing for the rest of the cache window.
		PSPGD_Stats::flush();

		if ( 'paid' === $status && (int) $donation->campaign_id > 0 ) {
			// This payment may be the one that finishes the campaign, and the
			// next visitor should not be offered a form for a funded goal.
			// Cancelling its recurring donors is left to the hourly sweep:
			// unbinding cards and sending email does not belong in the
			// request the bank is waiting on.
			PSPGD_Campaigns::close_if_finished( (int) $donation->campaign_id, false );
		}

		PSPGD_Repository::log_event(
			'donation',
			(int) $donation->id,
			'payment_' . $status,
			'test_mode' === ( $context['channel'] ?? '' )
				? 'Test payment completed without contacting the bank.'
				: 'PSPG payment callback processed.',
			array(
				'pspg_order_id' => $pspg_order_id,
				'bank_result'   => $bank_result,
			)
		);

		if ( 'paid' === $status ) {
			self::after_paid( $donation, $reference );
		}
	}

	/**
	 * Mark a donation failed, but only while it is still pending, so a
	 * confirmation that arrives at the same moment always wins.
	 *
	 * @return bool Whether this call failed it.
	 */
	public static function mark_failed( int $donation_id, string $event_type = '', string $message = '' ): bool {
		if ( ! PSPGD_Repository::mark_donation_processing( $donation_id ) ) {
			return false;
		}

		PSPGD_Repository::update_donation_status( $donation_id, 'failed' );
		PSPGD_Stats::flush();

		if ( '' !== $event_type ) {
			PSPGD_Repository::log_event( 'donation', $donation_id, $event_type, $message );
		}

		return true;
	}

	/**
	 * Mark a paid (or held) donation refunded once the whole payment has
	 * gone back to the donor.
	 *
	 * @param string $kind 'refund' or 'void'.
	 * @return bool Whether this call refunded it.
	 */
	public static function mark_refunded( object $donation, string $kind, int $pspg_order_id ): bool {
		if ( ! PSPGD_Repository::transition_donation_status( (int) $donation->id, array( 'paid', 'review' ), 'refunded' ) ) {
			return false;
		}

		PSPGD_Stats::flush();
		PSPGD_Repository::log_event(
			'donation',
			(int) $donation->id,
			'payment_refunded',
			'void' === $kind
				? 'The payment was voided in PlanetStudio Payment Gateway, so the donation is marked refunded and left out of every total.'
				: 'The payment was refunded in PlanetStudio Payment Gateway, so the donation is marked refunded and left out of every total.',
			array( 'pspg_order_id' => $pspg_order_id )
		);

		return true;
	}

	/**
	 * Park a payment the bank reported as paid, but for a different amount
	 * or currency than the donation asked for.
	 *
	 * It is not "paid": every figure, receipt and campaign total would quote
	 * a sum the bank did not take. It is not "failed" either -- money moved.
	 * 'review' keeps it out of every total until a person has compared the
	 * PSPG order with the bank.
	 *
	 * @param array<string, mixed>                       $bank_result
	 * @param array{amount: float, currency: string}|null $settled
	 */
	private static function hold_for_review( object $donation, array $bank_result, string $reference, int $pspg_order_id, ?array $settled ): void {
		PSPGD_Repository::update_donation_status( (int) $donation->id, 'review', $reference );
		PSPGD_Stats::flush();

		PSPGD_Repository::log_event(
			'security',
			(int) $donation->id,
			'payment_amount_mismatch',
			sprintf(
				'The bank reported this payment as paid for %1$s, but the donation asked for %2$s. It is held for review and left out of every total.',
				$settled ? PSPGD_I18n::format_amount( $settled['amount'], '' !== $settled['currency'] ? $settled['currency'] : (string) $donation->currency ) : '?',
				PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency )
			),
			array(
				'pspg_order_id' => $pspg_order_id,
				'amount'        => (string) $donation->amount,
				'currency'      => (string) $donation->currency,
				'bank_result'   => $bank_result,
			)
		);

		$held                        = clone $donation;
		$held->status                = 'review';
		$held->transaction_reference = '' !== $reference ? $reference : (string) $donation->transaction_reference;

		PSPGD_Notifications::donation_held_for_review( $held, $settled );
	}

	/**
	 * Everything that follows a donation becoming paid, wherever that
	 * happened: the staff notification and the public hook.
	 */
	private static function after_paid( object $donation, string $reference ): void {
		$paid         = clone $donation;
		$paid->status = 'paid';
		if ( '' !== $reference ) {
			$paid->transaction_reference = $reference;
		}

		PSPGD_Notifications::donation_received( $paid );

		/**
		 * Fires once when a donation becomes paid: one-time donations, the
		 * first payment of a recurring donation, and every scheduled charge.
		 * Test-mode donations fire it too; check `$donation->is_test`.
		 *
		 * @param object $donation The donation row, with its new status.
		 */
		do_action( 'pspgd_donation_paid', $paid );
	}
}
