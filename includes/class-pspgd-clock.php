<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place the plugin asks what time it is.
 *
 * Every comparison against "now" uses a real Unix timestamp, and every
 * datetime the plugin stores is the site's local wall-clock time.
 * WordPress's current_time( 'timestamp' ) is neither: it is a Unix
 * timestamp shifted by the site's UTC offset. Mixing it with real
 * timestamps -- DateTime::getTimestamp(), wp_date() -- moved every
 * deadline, retry date and reconciliation window by that offset, which is
 * four hours on an Armenian site: campaigns closed four hours before their
 * end date and the reconciliation job treated every payment and every
 * in-flight recurring charge as overdue the moment it started.
 */
final class PSPGD_Clock {

	/** @var int|null A fixed moment for tests. Never set in production. */
	private static $frozen = null;

	/** Now, as a real Unix timestamp. */
	public static function now(): int {
		return null === self::$frozen ? time() : self::$frozen;
	}

	/**
	 * A moment as the site's local 'Y-m-d H:i:s', the format of every
	 * datetime column this plugin writes.
	 */
	public static function local_mysql( ?int $timestamp = null ): string {
		return wp_date( 'Y-m-d H:i:s', null === $timestamp ? self::now() : $timestamp, wp_timezone() );
	}

	/**
	 * A stored site-local datetime (or date) as a real Unix timestamp.
	 *
	 * @return int|null Null when the value is empty or unreadable.
	 */
	public static function to_timestamp( string $local ): ?int {
		$local = trim( $local );
		if ( '' === $local ) {
			return null;
		}

		try {
			return ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $error ) {
			return null;
		}
	}

	/**
	 * Fix the clock at a moment, or release it with null. Only the test
	 * suite calls this.
	 */
	public static function freeze( ?int $timestamp ): void {
		self::$frozen = $timestamp;
	}
}
