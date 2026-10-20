<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The handful of numbers worth seeing at a glance above the donation log.
 *
 * Four grouped queries, cached for five minutes. Money is always reported
 * per currency and never added across currencies -- a single combined
 * figure would treat different currencies as the same unit.
 *
 * Simulated donations are left out of every figure here, including the
 * completion rate: a test payment is not money raised, and a run of them
 * while test mode is on would otherwise read as a very good month.
 */
final class PSPGD_Stats {

	private const CACHE_KEY = 'pspgd_stats';
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** A donor counts as active if they have given in this many days. */
	private const ACTIVE_DONOR_DAYS = 365;

	/** Weeks per month, for expressing a weekly pledge as a monthly figure. */
	private const WEEKS_PER_MONTH = 4.33;

	/**
	 * @return array{
	 *     money: array<string, array{month: float, total: float}>,
	 *     paid_month: int,
	 *     paid_total: int,
	 *     supporters: int,
	 *     recurring: int,
	 *     committed: array<string, float>,
	 *     completion: ?float,
	 *     outcomes: array<string, int>
	 * }
	 */
	public static function snapshot(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$snapshot = array(
			'money'      => self::money(),
			'supporters' => self::active_supporters(),
			'outcomes'   => self::outcomes(),
		);

		$snapshot['paid_month'] = 0;
		$snapshot['paid_total'] = 0;
		foreach ( $snapshot['money'] as $totals ) {
			$snapshot['paid_month'] += (int) $totals['month_count'];
			$snapshot['paid_total'] += (int) $totals['count'];
		}

		$recurring               = self::recurring();
		$snapshot['recurring']   = $recurring['count'];
		$snapshot['committed']   = $recurring['committed'];
		$snapshot['completion']  = self::completion_rate( $snapshot['outcomes'] );

		set_transient( self::CACHE_KEY, $snapshot, self::CACHE_TTL );

		return $snapshot;
	}

	/** Called whenever a donation changes state, so the band is never stale. */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Paid money and donation counts, this month and all time, per currency.
	 *
	 * @return array<string, array{month: float, total: float, month_count: int, count: int}>
	 */
	private static function money(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency,
					COALESCE( SUM( CASE WHEN created_at >= %s THEN amount ELSE 0 END ), 0 ) AS month_total,
					COALESCE( SUM( amount ), 0 ) AS total,
					SUM( CASE WHEN created_at >= %s THEN 1 ELSE 0 END ) AS month_count,
					COUNT(*) AS total_count
				FROM %i
				WHERE status = %s AND is_test = 0
				GROUP BY currency',
				self::month_start(),
				self::month_start(),
				$wpdb->prefix . 'pspgd_donations',
				'paid'
			)
		);

		$money = array();
		foreach ( (array) $rows as $row ) {
			$currency = strtoupper( (string) $row->currency );
			if ( '' === $currency ) {
				continue;
			}

			$money[ $currency ] = array(
				'month'       => (float) $row->month_total,
				'total'       => (float) $row->total,
				'month_count' => (int) $row->month_count,
				'count'       => (int) $row->total_count,
			);
		}

		return $money;
	}

	/** Distinct donors with a paid donation in the last year. */
	private static function active_supporters(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT( DISTINCT donor_id ) FROM %i
				WHERE status = %s AND is_test = 0 AND donor_id > 0 AND created_at >= %s',
				$wpdb->prefix . 'pspgd_donations',
				'paid',
				self::days_ago( self::ACTIVE_DONOR_DAYS )
			)
		);
	}

	/**
	 * How every donation attempt ever made ended up.
	 *
	 * @return array<string, int>
	 */
	private static function outcomes(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE is_test = 0 GROUP BY status', $wpdb->prefix . 'pspgd_donations' ) );

		$outcomes = array();
		foreach ( (array) $rows as $row ) {
			$outcomes[ (string) $row->status ] = (int) $row->total;
		}

		return $outcomes;
	}

	/**
	 * Live recurring donations and what they are worth per month.
	 *
	 * @return array{count: int, committed: array<string, float>}
	 */
	private static function recurring(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, interval_key, COUNT(*) AS total, COALESCE( SUM( amount ), 0 ) AS amount
				FROM %i
				WHERE status = %s AND is_test = 0
				GROUP BY currency, interval_key',
				$wpdb->prefix . 'pspgd_subscriptions',
				'active'
			)
		);

		return array(
			'count'     => array_sum( array_map( static fn( $row ) => (int) $row->total, (array) $rows ) ),
			'committed' => self::monthly_committed( (array) $rows ),
		);
	}

	/**
	 * Express active pledges as a monthly figure per currency, so weekly and
	 * monthly donors can be compared.
	 *
	 * @param object[] $rows
	 * @return array<string, float>
	 */
	public static function monthly_committed( array $rows ): array {
		$committed = array();

		foreach ( $rows as $row ) {
			$currency = strtoupper( (string) ( $row->currency ?? '' ) );
			if ( '' === $currency ) {
				continue;
			}

			$amount = (float) ( $row->amount ?? 0 );
			if ( 'weekly' === (string) ( $row->interval_key ?? '' ) ) {
				$amount *= self::WEEKS_PER_MONTH;
			}

			$committed[ $currency ] = ( $committed[ $currency ] ?? 0.0 ) + $amount;
		}

		return $committed;
	}

	/**
	 * Share of donation attempts that were actually paid.
	 *
	 * Only counts attempts that reached an outcome: a donation still pending
	 * has not failed, it just has not finished yet. Returns null when there
	 * is nothing to divide by, which the band shows as a dash rather than as
	 * a misleading 0%.
	 *
	 * @param array<string, int> $outcomes
	 */
	public static function completion_rate( array $outcomes ): ?float {
		$paid     = (int) ( $outcomes['paid'] ?? 0 );
		$finished = $paid
			+ (int) ( $outcomes['failed'] ?? 0 )
			+ (int) ( $outcomes['abandoned'] ?? 0 )
			+ (int) ( $outcomes['refunded'] ?? 0 );

		if ( $finished <= 0 ) {
			return null;
		}

		return round( $paid / $finished * 100, 1 );
	}

	private static function month_start(): string {
		return wp_date( 'Y-m-01 00:00:00', PSPGD_Clock::now(), wp_timezone() );
	}

	private static function days_ago( int $days ): string {
		return PSPGD_Clock::local_mysql( PSPGD_Clock::now() - ( $days * DAY_IN_SECONDS ) );
	}
}
