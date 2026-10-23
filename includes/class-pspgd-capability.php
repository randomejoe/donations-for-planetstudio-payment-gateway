<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's one capability. Registered on every request, not only in
 * wp-admin: the donation form checks it too.
 */
final class PSPGD_Capability {

	/**
	 * Reading the donation log, exporting it, and seeing and cancelling
	 * recurring donations. Every administrator has it. A role editor can
	 * give it to staff who should see donations without being able to
	 * change the bank or the settings, which stay with manage_options.
	 */
	public const MANAGE_DONATIONS = 'pspgd_manage_donations';

	public static function register(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ) );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @return array<string, bool>
	 */
	public static function grant( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::MANAGE_DONATIONS ] = true;
		}

		return $allcaps;
	}
}
