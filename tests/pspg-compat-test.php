<?php
/**
 * A PSPG that lacks a part this plugin calls is reported, not ignored.
 *
 * Each call into PSPG checks its own piece and falls back quietly, which
 * keeps a donor's request from fataling but would also hide a PSPG update
 * that renamed something. PSPGD_PSPG::missing_pspg_parts() lists what is
 * missing and the admin screens say so. The tests run in order: no PSPG,
 * then a PSPG with only its payment function, then a complete one.
 *
 * Run from the plugin folder: php tests/pspg-compat-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-pspg.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-pspgd-admin.php';

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/** @param mixed $value @return mixed */
	function wp_unslash( $value ) {
		return $value;
	}
}

/** Declared on call, so the earlier tests see no PSPG at all. */
function compat_load_payment_function_only(): void {
	function pspg_create_payment( string $bank_id, array $args ): array {
		return array();
	}
}

/** The rest of PSPG 1.3.5's surface, as far as this plugin uses it. */
function compat_load_complete_pspg(): void {
	interface PSPG_Bank_Interface {
		public function unbind_saved_card( array $args ): array;
	}

	final class PSPG_Bank_ArCa implements PSPG_Bank_Interface {
		public function unbind_saved_card( array $args ): array {
			return array();
		}
	}

	final class PSPG_Bot_Protection {
		public static function get_settings(): array {
			return array();
		}
	}

	final class Compat_Test_Service {
		/** @return mixed */
		public function get() {
			return null;
		}

		public function all(): array {
			return array();
		}
	}

	final class Compat_Test_Core {
		public function config(): Compat_Test_Service {
			return new Compat_Test_Service();
		}

		public function banks(): Compat_Test_Service {
			return new Compat_Test_Service();
		}

		public function orders(): Compat_Test_Service {
			return new Compat_Test_Service();
		}
	}

	function pspg_core(): Compat_Test_Core {
		return new Compat_Test_Core();
	}

	function pspg_get_bank( string $bank_id ) {
		return new PSPG_Bank_ArCa();
	}

	function pspg_sync_status( string $bank_id, array $args ): array {
		return array();
	}

	function pspg_charge_saved_card( string $bank_id, array $args ): array {
		return array();
	}
}

function compat_notice(): string {
	$_GET['page'] = 'pspgd-donations';
	ob_start();
	PSPGD_Admin::setup_notice();

	return (string) ob_get_clean();
}

run_tests(
	array(
		'no PSPG at all is left to the setup notice' => static function (): void {
			assert_same( array(), PSPGD_PSPG::missing_pspg_parts(), 'An inactive gateway is not an incompatible one.' );
		},

		'every part PSPG lacks is named' => static function (): void {
			compat_load_payment_function_only();

			assert_same(
				array(
					'pspg_core()',
					'pspg_get_bank()',
					'pspg_sync_status()',
					'pspg_charge_saved_card()',
					'pspg_core()->config()->get()',
					'pspg_core()->banks()->all()',
					'pspg_core()->orders()->get()',
					'PSPG_Bank_Interface::unbind_saved_card()',
					'PSPG_Bank_ArCa',
					'PSPG_Bot_Protection::get_settings()',
				),
				PSPGD_PSPG::missing_pspg_parts(),
				'Each fallback that would kick in silently is listed.'
			);
		},

		'the admin screens say so, in test mode too' => static function (): void {
			update_option( 'pspgd_test_mode', '1' );

			$notice = compat_notice();

			assert_contains( 'notice-error', $notice, 'A PSPG that cannot confirm payments is an error, not a hint.' );
			assert_contains( 'pspg_sync_status()', $notice, 'The notice names what is missing.' );
		},

		'a complete PSPG has nothing missing and no notice' => static function (): void {
			compat_load_complete_pspg();
			update_option( 'pspgd_test_mode', '1' );

			assert_same( array(), PSPGD_PSPG::missing_pspg_parts(), 'Everything this plugin calls is present.' );
			assert_same( '', compat_notice(), 'Nothing to report in test mode.' );
		},
	)
);
