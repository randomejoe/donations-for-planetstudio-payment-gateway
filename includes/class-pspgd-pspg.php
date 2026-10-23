<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that talks to PlanetStudio Payment Gateway: registering
 * payments, charging and releasing saved cards, and turning what the bank
 * reports back into a donation status.
 */
final class PSPGD_PSPG {

	/**
	 * Bank statuses that mean no money was taken. Every PSPG adapter reports
	 * one shared vocabulary (paid, failed, refunded, voided, authorized,
	 * registered, pending, processing, unknown); the spellings beyond it are
	 * accepted so an adapter that words a decline differently is not read as
	 * a payment.
	 */
	private const FAILED_STATUSES = array( 'failed', 'declined', 'voided', 'reversed', 'cancelled', 'canceled', 'rejected', 'expired' );

	/** The prefix every saved-card client id this plugin creates carries. */
	private const CLIENT_ID_PREFIX = 'pspgd-subscription-';

	public static function init(): void {
		add_action( 'pspg_payment_confirmed', array( __CLASS__, 'handle_confirmed_payment' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_browser_return' ), 5 );

		// Refunds and voids are made on PSPG's own Orders screen. Mirroring
		// them here keeps the totals, the campaign bar and the donation log
		// honest without a second refund screen.
		add_action( 'pspg_admin_orders_after_refund', array( __CLASS__, 'handle_admin_refund' ), 10, 4 );
		add_action( 'pspg_admin_orders_after_void', array( __CLASS__, 'handle_admin_void' ), 10, 4 );

		// Wallets (Idram, Telcell) return the donor through PSPG's own URL
		// and then to whatever these filters say -- the home page unless an
		// integration answers. Send our donors to the payment result screen.
		add_filter( 'pspg_idram_success_redirect_url', array( __CLASS__, 'wallet_success_url' ), 10, 3 );
		add_filter( 'pspg_telcell_success_redirect_url', array( __CLASS__, 'wallet_success_url' ), 10, 3 );
		add_filter( 'pspg_idram_fail_redirect_url', array( __CLASS__, 'wallet_fail_url' ), 10, 3 );
	}

	/**
	 * The PSPG functions, methods and classes this plugin calls that the
	 * active PSPG does not have, as short names for an admin notice.
	 *
	 * Every call site checks its piece and quietly falls back when it is
	 * missing -- no test-mode reading, no bank list, no card release, no
	 * bank-order check on a browser return -- so a PSPG update that renames
	 * one would otherwise go unnoticed. Empty when PSPG is not active at
	 * all; the setup notice covers that.
	 *
	 * @return string[]
	 */
	public static function missing_pspg_parts(): array {
		if ( ! function_exists( 'pspg_create_payment' ) ) {
			return array();
		}

		$missing = array();
		foreach ( array( 'pspg_core', 'pspg_get_bank', 'pspg_sync_status', 'pspg_charge_saved_card' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = $function . '()';
			}
		}

		$core = function_exists( 'pspg_core' ) ? pspg_core() : null;
		foreach ( array( 'config' => 'get', 'banks' => 'all', 'orders' => 'get' ) as $service => $method ) {
			$object = is_object( $core ) && method_exists( $core, $service ) ? $core->$service() : null;
			if ( ! is_object( $object ) || ! method_exists( $object, $method ) ) {
				$missing[] = 'pspg_core()->' . $service . '()->' . $method . '()';
			}
		}

		if ( ! interface_exists( 'PSPG_Bank_Interface' ) || ! method_exists( 'PSPG_Bank_Interface', 'unbind_saved_card' ) ) {
			$missing[] = 'PSPG_Bank_Interface::unbind_saved_card()';
		}
		if ( ! class_exists( 'PSPG_Bank_ArCa' ) ) {
			$missing[] = 'PSPG_Bank_ArCa';
		}
		if ( ! class_exists( 'PSPG_Bot_Protection' ) || ! method_exists( 'PSPG_Bot_Protection', 'get_settings' ) ) {
			$missing[] = 'PSPG_Bot_Protection::get_settings()';
		}

		return $missing;
	}

	/** Whether the gateway is active and a bank has been chosen for donations. */
	public static function is_ready(): bool {
		return function_exists( 'pspg_create_payment' ) && function_exists( 'pspg_get_bank' ) && '' !== self::bank_id();
	}

	/**
	 * The PSPG bank every donation, recurring charge and card release goes
	 * through, as chosen on the settings screen.
	 */
	public static function bank_id(): string {
		return PSPGD_Settings::bank_id();
	}

	/**
	 * Banks PSPG has registered, as id => title, for the settings screen.
	 *
	 * @return array<string, string>
	 */
	public static function bank_choices(): array {
		$choices = array();
		foreach ( self::get_available_banks() as $bank_id => $bank ) {
			$choices[ (string) $bank_id ] = is_object( $bank ) && method_exists( $bank, 'title' ) ? (string) $bank->title() : (string) $bank_id;
		}

		return $choices;
	}

	public static function is_test_mode(): bool {
		if ( self::donations_test_mode() ) {
			return true;
		}
		if ( ! function_exists( 'pspg_core' ) || ! pspg_core() || ! method_exists( pspg_core(), 'config' ) ) {
			return false;
		}

		$config = pspg_core()->config();
		$value  = $config && method_exists( $config, 'get' ) ? $config->get( 'pspg.' . self::bank_id() . '.test_mode', false ) : false;

		return is_bool( $value ) ? $value : in_array( (string) $value, array( '1', 'yes', 'true', 'on' ), true );
	}

	public static function donations_test_mode(): bool {
		return '1' === (string) get_option( 'pspgd_test_mode', '0' );
	}

	public static function get_available_banks(): array {
		if ( ! function_exists( 'pspg_core' ) || ! pspg_core() || ! method_exists( pspg_core(), 'banks' ) ) {
			return array();
		}

		$registry = pspg_core()->banks();
		if ( ! $registry || ! method_exists( $registry, 'all' ) ) {
			return array();
		}

		return $registry->all();
	}

	public static function bank_supports_currency( string $bank_id, string $currency ): bool {
		$bank = function_exists( 'pspg_get_bank' ) ? pspg_get_bank( $bank_id ) : null;
		if ( ! $bank ) {
			return false;
		}

		return in_array( strtoupper( $currency ), self::allowed_currencies(), true );
	}

	/**
	 * Whether a bank goes through PSPG's ArCa adapter: ArCa itself and the
	 * ArCa-network banks PSPG lists under it (ACBA, Ardshinbank, Evocabank
	 * and the rest). This is the only saved-card flow this plugin has run in
	 * production, and the only one whose order status report it can read
	 * amounts from.
	 */
	public static function is_arca_network( string $bank_id ): bool {
		if ( 1 === preg_match( '/^arca(_[a-z0-9]+)?$/', $bank_id ) ) {
			return true;
		}

		$bank = function_exists( 'pspg_get_bank' ) ? pspg_get_bank( $bank_id ) : null;

		return is_object( $bank ) && class_exists( 'PSPG_Bank_ArCa' ) && $bank instanceof PSPG_Bank_ArCa;
	}

	/**
	 * Whether recurring donations are offered through a bank.
	 *
	 * The bank has to support saved cards in PSPG, and -- in this version --
	 * be an ArCa-network bank. Ameriabank, Inecobank and AMIO also report
	 * saved-card support, but their binding flow has not been run against
	 * this plugin; a site testing one on staging can switch it on with the
	 * `pspgd_bank_supports_recurring` filter.
	 */
	public static function bank_supports_recurring( string $bank_id ): bool {
		$bank = function_exists( 'pspg_get_bank' ) ? pspg_get_bank( $bank_id ) : null;
		if ( ! $bank || ! method_exists( $bank, 'supports' ) ) {
			return false;
		}

		$supports = (array) $bank->supports();
		$capable  = ! empty( $supports['binding'] ) && ! empty( $supports['subs'] );

		/**
		 * Filters whether recurring donations are offered through a bank.
		 *
		 * @param bool   $supported Whether this plugin offers recurring donations through the bank.
		 * @param string $bank_id   PSPG bank id.
		 * @param bool   $capable   Whether the bank reports saved-card support in PSPG.
		 */
		return (bool) apply_filters( 'pspgd_bank_supports_recurring', $capable && self::is_arca_network( $bank_id ), $bank_id, $capable );
	}

	public static function allowed_currencies(): array {
		return PSPGD_Settings::currencies();
	}

	/**
	 * The client id a subscription's saved card is bound under at the bank.
	 *
	 * Stored on the subscription when it is created, because the bank checks
	 * every later charge and release against it: deriving it afresh from a
	 * format string means any change to that format -- or importing
	 * subscriptions from another system -- leaves every existing card bound
	 * under an id nothing asks for any more.
	 */
	public static function client_id_for( object $subscription ): string {
		$stored = trim( (string) ( $subscription->client_id ?? '' ) );

		return '' !== $stored ? $stored : self::CLIENT_ID_PREFIX . (int) $subscription->id;
	}

	public static function create_initial_payment( int $donation_id, array $data ): array {
		if ( self::donations_test_mode() ) {
			// The bank is simulated, not the donation: a test payment is
			// applied exactly as a real one would be, so test mode shows what
			// a live site does -- the receipt, the campaign closing at its
			// goal, the figures, the staff email and the hook.
			$donation = PSPGD_Repository::find_donation( $donation_id );
			if ( $donation ) {
				PSPGD_Payments::apply_report(
					$donation,
					array(
						'status'        => 'paid',
						'reference'     => 'pspgd-test-' . $donation_id,
						'binding_id'    => (int) $donation->subscription_id > 0 ? 'pspgd-test-binding-' . (int) $donation->subscription_id : '',
						'problem'       => '',
						'settled'       => null,
						'pspg_order_id' => 0,
						'bank_result'   => array( 'test_mode' => true ),
					),
					array( 'channel' => 'test_mode' )
				);
			}

			return array(
				'ok'           => true,
				'status'       => 'paid',
				'test_mode'    => true,
				'redirect_url' => self::return_url( $donation_id, array( 'pspgd_test_payment' => '1' ) ),
			);
		}

		if ( ! self::is_ready() ) {
			return array(
				'ok'      => false,
				'message' => '' === self::bank_id()
					? __( 'Online donations are not set up yet. Please try again later.', 'donations-for-planetstudio-payment-gateway' )
					: __( 'PlanetStudio Payment Gateway is not available.', 'donations-for-planetstudio-payment-gateway' ),
			);
		}

		$subscription_id = (int) ( $data['subscription_id'] ?? 0 );

		$args = array(
			'amount'      => $data['amount'],
			// ArCa selects stored credentials by alpha code, then converts its request to numeric ISO.
			'currency'    => strtoupper( (string) $data['currency'] ),
			'description' => sprintf( 'Donation #%d', $donation_id ),
			'source'      => 'pspgd_donations',
			'source_id'   => (string) $donation_id,
			'return_url'  => self::return_url( $donation_id ),
			'fail_url'    => self::return_url( $donation_id, array( 'pspgd_donation_failed' => '1' ) ),
		);

		if ( $subscription_id > 0 ) {
			// Only a recurring donation should ask ArCa to create a card
			// binding. ArCa creates one automatically after a successful
			// payment whenever register.do carries ANY clientId -- so a plain
			// one-time donation must never send this field, or the donor's
			// card gets tokenized/bound at the bank for a binding we'd never
			// use (a one-time donation has no subscription to attach it to).
			//
			// IMPORTANT: this clientId MUST be the same value used later for
			// every automatic charge and for the release. ArCa's
			// paymentOrderBinding.do validates the binding against the
			// clientId on the charging order, so it is stored on the
			// subscription now and read back from there.
			$client_id = self::CLIENT_ID_PREFIX . $subscription_id;
			PSPGD_Repository::set_subscription_client_id( $subscription_id, $client_id );
			$args['client_id'] = $client_id;

			// For display only (admin orders table) -- register.do for the
			// donor-facing first payment always uses main credentials.
			$args['is_binding'] = 1;
		}

		$result = pspg_create_payment( self::bank_id(), $args );

		if ( ! empty( $result['pspg_order_id'] ) ) {
			PSPGD_Repository::attach_pspg_order( $donation_id, (int) $result['pspg_order_id'], self::reported_bank_order_id( $result ) );
		}

		PSPGD_Repository::log_event( 'donation', $donation_id, 'payment_created', '', $result );

		self::flag_redirect_url_anomaly_if_any( $donation_id, $result );

		return $result;
	}

	/**
	 * Flag (but never block) a hosted-payment redirect_url whose merchant
	 * path segment looks unexpected -- e.g. carries a suffix like
	 * "_binding" or "_test" after the numeric merchant id. This usually
	 * means that exact sub-merchant path isn't provisioned/activated on
	 * the bank's side, so the donor's browser will hit a 404 on the bank's
	 * hosted page even though the payment was registered.
	 */
	private static function flag_redirect_url_anomaly_if_any( int $donation_id, array $result ): void {
		$redirect_url = '';
		foreach ( array( 'redirect_url', 'payment_url', 'url', 'form_url' ) as $key ) {
			if ( ! empty( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				$redirect_url = (string) $result[ $key ];
				break;
			}
		}

		if ( '' === $redirect_url ) {
			return;
		}

		$path = (string) wp_parse_url( $redirect_url, PHP_URL_PATH );
		if ( ! preg_match( '#/merchants/([^/]+)/#', $path, $matches ) ) {
			return;
		}

		$merchant_segment = (string) $matches[1];
		if ( ctype_digit( $merchant_segment ) ) {
			return;
		}

		PSPGD_Repository::log_event(
			'donation',
			$donation_id,
			'payment_redirect_url_anomaly',
			sprintf(
				'The payment page URL returned by the bank has an unusual merchant path ("%s") that may not be activated yet, which can cause a 404 for the donor even though the payment was registered. Verify with the bank that this exact merchant path is enabled.',
				$merchant_segment
			),
			array( 'redirect_url' => $redirect_url )
		);
	}

	public static function charge_subscription( int $donation_id, object $subscription ): array {
		if ( ! function_exists( 'pspg_charge_saved_card' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Recurring payments are not available.', 'donations-for-planetstudio-payment-gateway' ),
			);
		}

		$result = pspg_charge_saved_card(
			(string) ( $subscription->bank_id ?? '' ) !== '' ? (string) $subscription->bank_id : self::bank_id(),
			array(
				'amount'      => $subscription->amount,
				'currency'    => $subscription->currency,
				'binding_id'  => $subscription->binding_id,
				'client_id'   => self::client_id_for( $subscription ),
				'description' => sprintf( 'Recurring donation #%d', $donation_id ),
				'source'      => 'pspgd_donations',
				'source_id'   => (string) $donation_id,
				'return_url'  => home_url( '/' ),
				'fail_url'    => home_url( '/' ),
			)
		);

		if ( ! empty( $result['pspg_order_id'] ) ) {
			PSPGD_Repository::attach_pspg_order( $donation_id, (int) $result['pspg_order_id'], self::reported_bank_order_id( $result ) );
		}

		return $result;
	}

	/**
	 * What a saved-card charge came to, for the recurring schedule.
	 *
	 * 'paid' when the bank says so. 'failed' for an explicit decline, for an
	 * order the bank registered but never took money for (a binding charge
	 * has nobody at a keyboard to finish it), and for a request that never
	 * reached the point of charging. 'unknown' when the bank may or may not
	 * have charged -- the request timed out, or the status is one this
	 * plugin cannot read as either -- because treating that as a failure
	 * would schedule a retry against a card that may already have paid.
	 *
	 * @return string 'paid', 'failed' or 'unknown'.
	 */
	public static function charge_outcome( array $result ): string {
		if ( empty( $result['ok'] ) ) {
			return 'http_error' === (string) ( $result['error'] ?? '' ) ? 'unknown' : 'failed';
		}

		$status = self::normalize_status( $result );
		if ( 'paid' === $status || 'failed' === $status ) {
			return $status;
		}

		return 'registered' === strtolower( (string) ( $result['status'] ?? '' ) ) ? 'failed' : 'unknown';
	}

	public static function unbind_subscription( object $subscription ): array {
		$bank_id = (string) ( $subscription->bank_id ?? '' ) !== '' ? (string) $subscription->bank_id : self::bank_id();
		$bank    = function_exists( 'pspg_get_bank' ) ? pspg_get_bank( $bank_id ) : null;
		if ( ! $bank || ! method_exists( $bank, 'unbind_saved_card' ) || empty( $subscription->binding_id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The saved card could not be revoked at the gateway.', 'donations-for-planetstudio-payment-gateway' ),
			);
		}

		return $bank->unbind_saved_card(
			array(
				'currency'   => $subscription->currency,
				'binding_id' => $subscription->binding_id,
				// MUST match the clientId the binding was created and charged
				// under. ArCa validates unBindCard.do against it and rejects a
				// mismatch as belonging to "another merchant".
				'client_id'  => self::client_id_for( $subscription ),
			)
		);
	}

	/**
	 * Confirm the payment when the bank returns the donor's browser to the
	 * site. PSPG callbacks remain the primary notification path, but this
	 * also covers banks that only redirect after a successful payment.
	 *
	 * Reads only query arguments the bank or this plugin put on the return
	 * URL; the HMAC return token, not a nonce, is what proves the request
	 * belongs to this donation.
	 */
	public static function handle_browser_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Bank redirect; authenticated by the HMAC return token checked below.
		$donation_id   = empty( $_GET['pspgd_donation'] ) ? 0 : absint( $_GET['pspgd_donation'] );
		$token         = empty( $_GET['pspgd_return_token'] ) ? '' : sanitize_text_field( wp_unslash( $_GET['pspgd_return_token'] ) );
		$test_return   = ! empty( $_GET['pspgd_test_payment'] );
		$failed_return = ! empty( $_GET['pspgd_donation_failed'] );
		$order_id      = empty( $_GET['orderId'] ) ? '' : sanitize_text_field( wp_unslash( $_GET['orderId'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $donation_id <= 0 || ! self::verify_return_token( $donation_id, $token ) ) {
			return;
		}

		if ( $test_return ) {
			$donation = PSPGD_Repository::find_donation( $donation_id );
			if ( $donation && 'paid' === $donation->status ) {
				self::render_return_status( 'paid' );
			}
		}

		if ( '' === $order_id ) {
			// ArCa sends the donor to fail_url on a decline. Whether or not
			// it appends an order reference, the redirect itself is a
			// statement that the payment did not go through.
			if ( $failed_return ) {
				PSPGD_Payments::mark_failed( $donation_id, 'browser_return_failed','The donor returned through the gateway fail URL without an order reference.' );
				self::render_return_status( 'failed' );
			}

			// Banks that return without an order reference (or a donor
			// reloading the result page): ask the bank about the order this
			// donation registered, exactly as reconciliation would, rather
			// than dropping the donor on the home page with no word on
			// their payment.
			$donation = PSPGD_Repository::find_donation( $donation_id );
			if ( ! $donation ) {
				return;
			}

			if ( 'pending' === (string) $donation->status && ! empty( $donation->pspg_order_id ) ) {
				self::render_return_status( self::normalize_status( self::reconcile_donation( $donation ) ) );
			}

			self::render_return_status( self::donor_facing_status( (string) $donation->status ) );
		}

		$donation = PSPGD_Repository::find_donation( $donation_id );

		if ( ! $donation || empty( $donation->pspg_order_id ) ) {
			return;
		}

		if ( ! function_exists( 'pspg_sync_status' ) ) {
			self::render_return_status( 'pending' );
			return;
		}

		$stored_order_id = self::get_stored_bank_order_id( (int) $donation->pspg_order_id );
		if ( '' === $stored_order_id || ! hash_equals( $stored_order_id, $order_id ) ) {
			self::render_return_status( 'pending' );
			return;
		}

		// The order registered by create_initial_payment() uses MAIN
		// credentials, so the status check does too (the default when no
		// binding flags are passed). Binding credentials here return "Order
		// not found" (errorCode 6) for an order made under the main merchant.
		$result = pspg_sync_status(
			self::bank_id(),
			array(
				'pspg_order_id' => (int) $donation->pspg_order_id,
				'orderId'       => $order_id,
				'currency'      => $donation->currency,
			)
		);

		$result = is_array( $result ) ? $result : array();

		// normalize_status() reports anything it does not recognise as still
		// pending. On a fail_url return that "pending" is really a decline,
		// and leaving it pending would have the reconciliation job re-check a
		// dead order for the next 24 hours.
		if ( $failed_return && 'paid' !== self::normalize_status( $result ) ) {
			$result['status'] = 'failed';
		}

		PSPGD_Repository::log_event( 'donation', (int) $donation->id, 'browser_return_sync', 'Payment status checked after gateway return.', $result );
		self::handle_confirmed_payment(
			(int) $donation->pspg_order_id,
			$result,
			array(
				'orderId' => $order_id,
				'channel' => 'browser_return',
			)
		);

		$current = PSPGD_Repository::find_donation( (int) $donation->id );
		self::render_return_status( $current ? self::donor_facing_status( (string) $current->status ) : self::normalize_status( $result ) );
	}

	/** A stored donation status as one of the three result screens. */
	private static function donor_facing_status( string $status ): string {
		if ( 'paid' === $status ) {
			return 'paid';
		}

		return in_array( $status, array( 'failed', 'abandoned', 'refunded' ), true ) ? 'failed' : 'pending';
	}

	/**
	 * Re-check a donation that was registered with the bank but never
	 * confirmed -- the donor closed the tab, or the callback never arrived --
	 * and apply whatever the bank now reports through exactly the same path a
	 * callback would take, so receipts and subscription activation stay on
	 * one code path.
	 *
	 * @return array The bank result, or a failure array when it could not be asked.
	 */
	public static function reconcile_donation( object $donation ): array {
		if ( ! function_exists( 'pspg_sync_status' ) || empty( $donation->pspg_order_id ) ) {
			return array(
				'ok'      => false,
				'status'  => 'pending',
				'message' => __( 'PSPG status sync is not available.', 'donations-for-planetstudio-payment-gateway' ),
			);
		}

		$order_id = trim( (string) ( $donation->bank_order_id ?? '' ) );
		if ( '' === $order_id ) {
			$order_id = self::get_stored_bank_order_id( (int) $donation->pspg_order_id );
		}
		if ( '' === $order_id ) {
			return array(
				'ok'      => false,
				'status'  => 'pending',
				'message' => __( 'No bank order reference is stored for this donation.', 'donations-for-planetstudio-payment-gateway' ),
			);
		}

		$args = array(
			'pspg_order_id' => (int) $donation->pspg_order_id,
			'orderId'       => $order_id,
			'currency'      => $donation->currency,
		);

		// A scheduled charge was made under the bank's saved-card (binding)
		// merchant, so its status is asked under the same one. Everything a
		// donor paid at the bank's page was made under the main merchant,
		// and asking with binding credentials there returns "Order not found".
		if ( 'recurring' === (string) $donation->type ) {
			$args['is_binding'] = 1;
		}

		$bank_id = '' !== (string) ( $donation->bank_id ?? '' ) ? (string) $donation->bank_id : self::bank_id();
		$result  = pspg_sync_status( $bank_id, $args );
		$result  = is_array( $result ) ? $result : array();

		PSPGD_Repository::log_event( 'donation', (int) $donation->id, 'reconcile_sync', 'Pending payment re-checked with the bank.', $result );
		self::handle_confirmed_payment(
			(int) $donation->pspg_order_id,
			$result,
			array(
				'orderId' => $order_id,
				'channel' => 'reconcile',
			)
		);

		return $result;
	}

	/**
	 * The address the bank sends a donor back to.
	 *
	 * @param array<string, string> $extra_args
	 */
	public static function return_url( int $donation_id, array $extra_args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'pspgd_donation'     => $donation_id,
					'pspgd_return_token' => self::return_token( $donation_id ),
				),
				$extra_args
			),
			home_url( '/' )
		);
	}

	/**
	 * Where a wallet payment (Idram, Telcell) sends the donor after paying.
	 *
	 * @param mixed      $url          The URL another integration chose, if any.
	 * @param int        $order_number PSPG order number.
	 * @param array|null $row          The PSPG order row.
	 * @return mixed
	 */
	public static function wallet_success_url( $url, $order_number = 0, $row = null ) {
		$donation_id = self::donation_id_from_order_row( $url, $row );

		return $donation_id > 0 ? self::return_url( $donation_id ) : $url;
	}

	/**
	 * @param mixed      $url
	 * @param int        $order_number
	 * @param array|null $row
	 * @return mixed
	 */
	public static function wallet_fail_url( $url, $order_number = 0, $row = null ) {
		$donation_id = self::donation_id_from_order_row( $url, $row );

		return $donation_id > 0 ? self::return_url( $donation_id, array( 'pspgd_donation_failed' => '1' ) ) : $url;
	}

	/**
	 * The donation behind a PSPG order row, when the row is one of ours and
	 * no other integration has already answered.
	 *
	 * @param mixed $url
	 * @param mixed $row
	 */
	private static function donation_id_from_order_row( $url, $row ): int {
		if ( '' !== (string) $url || ! is_array( $row ) || 'pspgd_donations' !== (string) ( $row['source'] ?? '' ) ) {
			return 0;
		}

		return absint( $row['source_id'] ?? 0 );
	}

	private static function verify_return_token( int $donation_id, string $token ): bool {
		return '' !== $token && hash_equals( self::return_token( $donation_id ), $token );
	}

	private static function return_token( int $donation_id ): string {
		return hash_hmac( 'sha256', 'pspgd_donation_return|' . $donation_id, wp_salt( 'auth' ) );
	}

	private static function get_stored_bank_order_id( int $pspg_order_id ): string {
		if ( $pspg_order_id <= 0 || ! function_exists( 'pspg_core' ) || ! pspg_core() || ! method_exists( pspg_core(), 'orders' ) ) {
			return '';
		}

		$orders = pspg_core()->orders();
		if ( ! $orders || ! method_exists( $orders, 'get' ) ) {
			return '';
		}

		$row = $orders->get( $pspg_order_id );
		if ( ! is_array( $row ) || empty( $row['orderId'] ) ) {
			return '';
		}

		return (string) $row['orderId'];
	}

	/**
	 * Apply what the bank reported about a payment to its donation.
	 *
	 * Runs for PSPG callbacks, browser returns, reconciliation and scheduled
	 * charges. The report is read here and applied by PSPGD_Payments, which
	 * checks it against the donation before anything is marked paid.
	 *
	 * @param mixed $order_number PSPG order number.
	 * @param mixed $bank_result  Normalised bank result.
	 * @param mixed $request      The request that carried it, or this plugin's own context.
	 */
	public static function handle_confirmed_payment( $order_number, $bank_result, $request ): void {
		$donation = PSPGD_Repository::find_donation_by_pspg_order( (int) $order_number );
		if ( ! $donation ) {
			return;
		}

		PSPGD_Payments::apply_report(
			$donation,
			self::read_report( $donation, is_array( $bank_result ) ? $bank_result : array(), (int) $order_number ),
			is_array( $request ) ? $request : array()
		);
	}

	/**
	 * A PSPG result read into the report PSPGD_Payments applies: the
	 * donation status it means, the reference a donor can find on their
	 * statement, the card binding, and whether it describes this donation's
	 * payment at all.
	 *
	 * @param array<string, mixed> $bank_result
	 * @return array<string, mixed>
	 */
	public static function read_report( object $donation, array $bank_result, int $pspg_order_id ): array {
		return array(
			'status'        => self::normalize_status( $bank_result ),
			'reference'     => self::extract_reference( $bank_result ),
			'binding_id'    => self::extract_binding_id( $bank_result ),
			'problem'       => self::verification_problem( $donation, $bank_result ),
			'settled'       => self::settled_amount( (string) ( $donation->bank_id ?? '' ), $bank_result ),
			'pspg_order_id' => $pspg_order_id,
			'bank_result'   => $bank_result,
		);
	}

	/**
	 * Whether a bank report describes this donation's payment.
	 *
	 * @param array<string, mixed> $result
	 * @return string '' when it does (or the bank gives nothing to compare),
	 *                'order_mismatch' when it names another bank order,
	 *                'amount_mismatch' when it names another amount or currency.
	 */
	public static function verification_problem( object $donation, array $result ): string {
		$expected_order = trim( (string) ( $donation->bank_order_id ?? '' ) );
		$reported_order = self::reported_bank_order_id( $result );

		if ( '' !== $expected_order && '' !== $reported_order && ! hash_equals( $expected_order, $reported_order ) ) {
			return 'order_mismatch';
		}

		$settled = self::settled_amount( (string) ( $donation->bank_id ?? '' ), $result );
		if ( null === $settled ) {
			return '';
		}

		if ( abs( $settled['amount'] - (float) $donation->amount ) >= 0.005 ) {
			return 'amount_mismatch';
		}

		if ( '' !== $settled['currency'] && strtoupper( $settled['currency'] ) !== strtoupper( (string) $donation->currency ) ) {
			return 'amount_mismatch';
		}

		return '';
	}

	/**
	 * The bank's own order id in a PSPG result, or ''.
	 *
	 * @param array<string, mixed> $result
	 */
	public static function reported_bank_order_id( array $result ): string {
		$ids        = isset( $result['bank_ids'] ) && is_array( $result['bank_ids'] ) ? $result['bank_ids'] : array();
		$raw        = isset( $result['raw'] ) && is_array( $result['raw'] ) ? $result['raw'] : array();
		$candidates = array( $ids['orderId'] ?? null, $result['orderId'] ?? null, $raw['orderId'] ?? null );

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				return trim( (string) $candidate );
			}
		}

		return '';
	}

	/**
	 * The amount and currency the bank says it took, where it says so in a
	 * form this plugin can read: the ArCa network's order status report,
	 * which gives the amount in minor units. Other banks report amounts in
	 * shapes that have not been verified here, so they are not compared.
	 *
	 * @param array<string, mixed> $result
	 * @return array{amount: float, currency: string}|null
	 */
	public static function settled_amount( string $bank_id, array $result ): ?array {
		if ( '' === $bank_id || ! self::is_arca_network( $bank_id ) ) {
			return null;
		}

		$raw = isset( $result['raw'] ) && is_array( $result['raw'] ) ? $result['raw'] : array();
		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			return null;
		}

		return array(
			'amount'   => round( (float) $raw['amount'] / 100, 2 ),
			'currency' => isset( $raw['currency'] ) ? self::currency_alpha( (string) $raw['currency'] ) : '',
		);
	}

	/** ISO 4217 numeric or alpha code => alpha code, for the currencies PSPG settles. */
	private static function currency_alpha( string $code ): string {
		$code    = strtoupper( trim( $code ) );
		$numeric = array(
			'051' => 'AMD',
			'51'  => 'AMD',
			'840' => 'USD',
			'978' => 'EUR',
			'643' => 'RUB',
		);

		if ( isset( $numeric[ $code ] ) ) {
			return $numeric[ $code ];
		}

		return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
	}

	/**
	 * A refund made on PSPG's Orders screen.
	 *
	 * @param mixed $row          PSPG order row.
	 * @param mixed $order_number PSPG order number.
	 * @param mixed $result       Bank refund result.
	 * @param mixed $sync_result  Bank status after the refund.
	 */
	public static function handle_admin_refund( $row, $order_number, $result = array(), $sync_result = array() ): void {
		self::apply_admin_reversal( (int) $order_number, $result, $sync_result, 'refund' );
	}

	/**
	 * A void (cancelled authorisation) made on PSPG's Orders screen.
	 *
	 * @param mixed $row
	 * @param mixed $order_number
	 * @param mixed $result
	 * @param mixed $sync_result
	 */
	public static function handle_admin_void( $row, $order_number, $result = array(), $sync_result = array() ): void {
		self::apply_admin_reversal( (int) $order_number, $result, $sync_result, 'void' );
	}

	/**
	 * Mark a donation refunded once the bank confirms the whole payment was
	 * returned. A partial refund leaves the donation paid -- the plugin has
	 * no partial amounts -- and is recorded in the event log.
	 *
	 * @param mixed $result
	 * @param mixed $sync_result
	 */
	private static function apply_admin_reversal( int $order_number, $result, $sync_result, string $kind ): void {
		$donation = PSPGD_Repository::find_donation_by_pspg_order( $order_number );
		if ( ! $donation || ! in_array( (string) $donation->status, array( 'paid', 'review' ), true ) ) {
			return;
		}

		$bank_status = is_array( $sync_result ) ? strtolower( (string) ( $sync_result['status'] ?? '' ) ) : '';

		if ( in_array( $bank_status, array( 'refunded', 'voided' ), true ) ) {
			PSPGD_Payments::mark_refunded( $donation, $kind, $order_number );
			return;
		}

		if ( is_array( $result ) && ! empty( $result['ok'] ) ) {
			PSPGD_Repository::log_event(
				'donation',
				(int) $donation->id,
				'payment_partially_refunded',
				'Part of this payment was refunded in PlanetStudio Payment Gateway. The donation stays paid at its original amount.',
				array( 'pspg_order_id' => $order_number )
			);
		}
	}

	/**
	 * What a bank result means for the donation.
	 *
	 * Only an explicit "paid" is money received. An `ok` flag on its own
	 * only says the request to the bank succeeded -- a decline is reported
	 * with ok=true too -- so it no longer counts as payment. Statuses that
	 * are neither paid nor a clear failure (registered, authorised,
	 * processing, unknown) stay pending: the reconciliation job asks again,
	 * and a payment that never completes is marked abandoned after a day.
	 *
	 * @param array<string, mixed> $result
	 * @return string 'paid', 'failed', 'refunded' or 'pending'.
	 */
	public static function normalize_status( array $result ): string {
		$status = strtolower( trim( (string) ( $result['status'] ?? '' ) ) );

		if ( 'paid' === $status || 'refunded' === $status ) {
			return $status;
		}

		return in_array( $status, self::FAILED_STATUSES, true ) ? 'failed' : 'pending';
	}

	private static function render_return_status( string $status ): void {
		$messages = array(
			'paid'    => array(
				__( 'Thank you for your donation.', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Your payment has been confirmed. A receipt has been sent to your email address.', 'donations-for-planetstudio-payment-gateway' ),
			),
			'failed'  => array(
				__( 'Your payment was not completed.', 'donations-for-planetstudio-payment-gateway' ),
				__( 'No donation has been confirmed. Please try again or contact us if you need help.', 'donations-for-planetstudio-payment-gateway' ),
			),
			'default' => array(
				__( 'Your payment is being confirmed.', 'donations-for-planetstudio-payment-gateway' ),
				__( 'We will email your receipt once the bank confirms the donation.', 'donations-for-planetstudio-payment-gateway' ),
			),
		);
		$message = $messages[ $status ] ?? $messages['default'];
		$colors  = PSPGD_Settings::colors();

		/**
		 * Filters where the "Return to site" button on the payment result
		 * screen goes.
		 *
		 * @param string $url    Defaults to the home page.
		 * @param string $status 'paid', 'failed' or 'pending'.
		 */
		$return = (string) apply_filters( 'pspgd_return_url', home_url( '/' ), $status );

		$html = sprintf(
			'<main style="max-width:640px;margin:80px auto;padding:40px;background:#fff;border-top:5px solid %5$s;font-family:%7$s;color:%6$s;text-align:center"><h1 style="margin:0 0 16px;font-size:30px">%1$s</h1><p style="margin:0 0 28px;font-size:17px;line-height:1.6">%2$s</p><a href="%3$s" style="display:inline-block;padding:12px 22px;background:%5$s;color:#fff;text-decoration:none;font-weight:700;border-radius:%8$dpx">%4$s</a></main>',
			esc_html( $message[0] ),
			esc_html( $message[1] ),
			esc_url( $return ),
			esc_html(
				sprintf(
					/* translators: %s: the site name. */
					__( 'Return to %s', 'donations-for-planetstudio-payment-gateway' ),
					wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
				)
			),
			esc_attr( $colors['primary'] ),
			esc_attr( $colors['text'] ),
			esc_attr( PSPGD_Settings::email_font() ),
			(int) PSPGD_Settings::radius()
		);

		nocache_headers();
		wp_die( wp_kses( $html, self::result_screen_html() ), esc_html( $message[0] ), array( 'response' => 200 ) );
	}

	/**
	 * Markup the payment result screen is built from. Every value in it is
	 * escaped as it is assembled; this is the last word on what can appear.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function result_screen_html(): array {
		return array(
			'main' => array( 'style' => true ),
			'h1'   => array( 'style' => true ),
			'p'    => array( 'style' => true ),
			'a'    => array(
				'href'  => true,
				'style' => true,
			),
		);
	}

	/**
	 * The reference a donor can find on their card statement.
	 *
	 * The retrieval reference number (RRN) is what banks print; the ArCa
	 * network reports it as authRefNum. The bank's order ids are the
	 * fallback, so a reference is never empty when the bank sent one.
	 */
	private static function extract_reference( array $result ): string {
		foreach ( array( 'transaction_id', 'payment_id', 'rrn', 'approval_code' ) as $key ) {
			if ( ! empty( $result[ $key ] ) && is_scalar( $result[ $key ] ) ) {
				return (string) $result[ $key ];
			}
		}

		$raw = isset( $result['raw'] ) && is_array( $result['raw'] ) ? $result['raw'] : array();
		if ( ! empty( $raw['authRefNum'] ) && is_scalar( $raw['authRefNum'] ) ) {
			return (string) $raw['authRefNum'];
		}

		$order_id = self::reported_bank_order_id( $result );
		if ( '' !== $order_id ) {
			return $order_id;
		}

		if ( ! empty( $result['bank_ids'] ) && is_array( $result['bank_ids'] ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $result['bank_ids'], 'is_scalar' ) ) );
		}

		return '';
	}

	private static function extract_binding_id( array $result ): string {
		foreach ( array( 'binding_id', 'bindingId' ) as $key ) {
			if ( ! empty( $result[ $key ] ) ) {
				return (string) $result[ $key ];
			}
		}
		foreach ( $result as $value ) {
			if ( is_array( $value ) ) {
				$binding_id = self::extract_binding_id( $value );
				if ( '' !== $binding_id ) {
					return $binding_id;
				}
			}
		}
		return '';
	}
}
