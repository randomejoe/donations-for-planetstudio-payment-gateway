<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes donation records answerable to WordPress's privacy tools.
 *
 * Donor names and email addresses live in this plugin's own tables, so a
 * subject-access or erasure request handled through Tools > Export/Erase
 * Personal Data used to come back with nothing from the donation system at
 * all -- which reads as "we hold no data about you" and is wrong.
 */
final class PSPGD_Privacy {

	private const GROUP = 'pspgd';

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/** @param array<string, array<string, mixed>> $exporters */
	public static function register_exporter( $exporters ): array {
		$exporters                = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Donations for PlanetStudio Payment Gateway', 'donations-for-planetstudio-payment-gateway' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/** @param array<string, array<string, mixed>> $erasers */
	public static function register_eraser( $erasers ): array {
		$erasers                = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Donations for PlanetStudio Payment Gateway', 'donations-for-planetstudio-payment-gateway' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$donor = PSPGD_Repository::find_donor_by_email( $email );
		if ( ! $donor ) {
			return array( 'data' => array(), 'done' => true );
		}

		$export = array(
			array(
				'group_id'    => self::GROUP . '-donor',
				'group_label' => __( 'Donor record', 'donations-for-planetstudio-payment-gateway' ),
				'item_id'     => 'pspgd-donor-' . (int) $donor->id,
				'data'        => array(
					array( 'name' => __( 'First name', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donor->first_name ),
					array( 'name' => __( 'Last name', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donor->last_name ),
					array( 'name' => __( 'Email', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donor->email ),
					array( 'name' => __( 'Language', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donor->language ),
					array( 'name' => __( 'First seen', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donor->created_at ),
				),
			),
		);

		foreach ( PSPGD_Repository::donations_for_donor( (int) $donor->id ) as $donation ) {
			$export[] = array(
				'group_id'    => self::GROUP . '-donations',
				'group_label' => __( 'Donations', 'donations-for-planetstudio-payment-gateway' ),
				'item_id'     => 'pspgd-donation-' . (int) $donation->id,
				'data'        => array(
					array( 'name' => __( 'Date', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donation->created_at ),
					array( 'name' => __( 'Amount', 'donations-for-planetstudio-payment-gateway' ), 'value' => PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency ) ),
					array( 'name' => __( 'Type', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donation->type ),
					array( 'name' => __( 'Status', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donation->status ),
					array( 'name' => __( 'Bank reference', 'donations-for-planetstudio-payment-gateway' ), 'value' => $donation->transaction_reference ),
				),
			);
		}

		foreach ( PSPGD_Repository::subscriptions_for_donor( (int) $donor->id ) as $subscription ) {
			$export[] = array(
				'group_id'    => self::GROUP . '-subscriptions',
				'group_label' => __( 'Recurring donations', 'donations-for-planetstudio-payment-gateway' ),
				'item_id'     => 'pspgd-subscription-' . (int) $subscription->id,
				'data'        => array(
					array( 'name' => __( 'Started', 'donations-for-planetstudio-payment-gateway' ), 'value' => $subscription->created_at ),
					array( 'name' => __( 'Amount', 'donations-for-planetstudio-payment-gateway' ), 'value' => PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ) ),
					array( 'name' => __( 'Frequency', 'donations-for-planetstudio-payment-gateway' ), 'value' => $subscription->interval_key ),
					array( 'name' => __( 'Status', 'donations-for-planetstudio-payment-gateway' ), 'value' => $subscription->status ),
					array( 'name' => __( 'Next payment', 'donations-for-planetstudio-payment-gateway' ), 'value' => $subscription->next_payment_at ),
				),
			);
		}

		return array( 'data' => $export, 'done' => true );
	}

	/**
	 * Anonymise the donor rather than deleting their donations.
	 *
	 * The donations themselves are financial records the organisation has to
	 * keep; what identifies a person is the donor row, so that is what is
	 * cleared. An active recurring donation is retained and reported as such:
	 * erasing the address it is charged against would leave a live card
	 * binding with no way to tell the donor anything about it, which serves
	 * nobody -- it needs cancelling first.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$donor = PSPGD_Repository::find_donor_by_email( $email );
		if ( ! $donor ) {
			return $response;
		}

		foreach ( PSPGD_Repository::subscriptions_for_donor( (int) $donor->id ) as $subscription ) {
			if ( 'active' === $subscription->status ) {
				$response['items_retained'] = true;
				$response['messages'][]     = __( 'This donor has a recurring donation that is still active. Cancel it first -- erasing the record would leave a saved card being charged with no way to contact them about it.', 'donations-for-planetstudio-payment-gateway' );

				return $response;
			}
		}

		PSPGD_Repository::anonymize_donor( (int) $donor->id );

		$response['items_removed'] = true;
		$response['messages'][]    = __( 'The donor record was anonymised. Donation amounts and dates are kept as financial records, with nothing identifying the donor.', 'donations-for-planetstudio-payment-gateway' );

		return $response;
	}
}
