<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_Admin {

	private const PER_PAGE = 20;

	/**
	 * Reading the donation log, exporting it, and seeing and cancelling
	 * recurring donations. Every administrator has it. A role editor can
	 * give it to staff who should see donations without being able to
	 * change the bank or the settings, which stay with manage_options.
	 */
	public const CAPABILITY = 'pspgd_manage_donations';

	/** Registered on every request, not only in wp-admin: the form checks it too. */
	public static function register_capability(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_capability' ) );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @return array<string, bool>
	 */
	public static function grant_capability( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAPABILITY ] = true;
		}

		return $allcaps;
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_pspgd_save_form_settings', array( __CLASS__, 'save_form_settings' ) );
		add_action( 'admin_post_pspgd_save_email_settings', array( __CLASS__, 'save_email_settings' ) );
		add_action( 'admin_post_pspgd_cancel_subscription', array( __CLASS__, 'handle_cancel_subscription' ) );
		add_action( 'admin_post_pspgd_send_test_email', array( __CLASS__, 'handle_send_test_email' ) );
		add_action( 'admin_post_pspgd_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/**
	 * Say plainly when the form cannot take money yet, on this plugin's own
	 * screens only: no bank chosen, or the gateway plugin missing. A PSPG
	 * that lacks parts this plugin calls is reported in test mode too, so it
	 * is seen before the switch to real payments.
	 */
	public static function setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'pspgd' ) ) {
			return;
		}

		$missing = PSPGD_PSPG::missing_pspg_parts();
		if ( $missing ) {
			$message = sprintf(
				/* translators: 1: PlanetStudio Payment Gateway version, 2: comma-separated list of PHP function and class names. */
				__( 'This version of PlanetStudio Payment Gateway (%1$s) is missing parts this plugin relies on: %2$s. Payments may not be confirmed, checked or cancelled correctly. Update both plugins, and check the donation log before relying on it.', 'donations-for-planetstudio-payment-gateway' ),
				defined( 'PSPG_VERSION' ) ? (string) PSPG_VERSION : '?',
				implode( ', ', $missing )
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( PSPGD_PSPG::donations_test_mode() ) {
			return;
		}

		if ( ! function_exists( 'pspg_create_payment' ) ) {
			$message = __( 'PlanetStudio Payment Gateway is not active, so donations cannot be taken. Install and activate it, or switch on test mode to try the form.', 'donations-for-planetstudio-payment-gateway' );
		} elseif ( '' === PSPGD_PSPG::bank_id() ) {
			$message = __( 'Choose the bank that takes donations under Donations > Form Settings. Until then the form refuses donations.', 'donations-for-planetstudio-payment-gateway' );
		} else {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Donations for PlanetStudio Payment Gateway', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Donations', 'donations-for-planetstudio-payment-gateway' ),
			self::CAPABILITY,
			'pspgd',
			array( __CLASS__, 'render_donations_page' ),
			'dashicons-heart',
			56
		);
		add_submenu_page(
			'pspgd',
			__( 'Donation Log', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Donation Log', 'donations-for-planetstudio-payment-gateway' ),
			self::CAPABILITY,
			'pspgd',
			array( __CLASS__, 'render_donations_page' )
		);
		add_submenu_page(
			'pspgd',
			__( 'Recurring Donations', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Recurring Donations', 'donations-for-planetstudio-payment-gateway' ),
			self::CAPABILITY,
			'pspgd-recurring',
			array( __CLASS__, 'render_recurring_page' )
		);
		add_submenu_page(
			'pspgd',
			__( 'Donation Form Settings', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Form Settings', 'donations-for-planetstudio-payment-gateway' ),
			'manage_options',
			'pspgd-form-settings',
			array( __CLASS__, 'render_form_settings_page' )
		);
		add_submenu_page(
			'pspgd',
			__( 'Email Templates', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Emails', 'donations-for-planetstudio-payment-gateway' ),
			'manage_options',
			'pspgd-email-settings',
			array( __CLASS__, 'render_email_settings_page' )
		);
	}

	public static function render_donations_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		$filters        = self::donation_filters();
		$donation_total = PSPGD_Repository::count_donations( $filters );
		$donation_pages = max( 1, (int) ceil( $donation_total / self::PER_PAGE ) );
		$donation_page  = min( $donation_pages, max( 1, absint( $_GET['d_page'] ?? 1 ) ) );
		$donations      = PSPGD_Repository::list_donations( self::PER_PAGE, ( $donation_page - 1 ) * self::PER_PAGE, $filters );

		$event_total = PSPGD_Repository::count_events();
		$event_pages = max( 1, (int) ceil( $event_total / self::PER_PAGE ) );
		$event_page  = min( $event_pages, max( 1, absint( $_GET['e_page'] ?? 1 ) ) );
		$events      = PSPGD_Repository::list_recent_events( self::PER_PAGE, ( $event_page - 1 ) * self::PER_PAGE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Donations for PlanetStudio Payment Gateway', 'donations-for-planetstudio-payment-gateway' ); ?></h1>

			<?php self::render_stat_band(); ?>

			<p><?php esc_html_e( 'Recent donation payments created through PSPG.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

			<?php self::render_donation_filters( $filters, $donation_total ); ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Donor', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Language', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Anonymous', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Type', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Status', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Bank', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Bank reference', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'PSPG Order', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Created', 'donations-for-planetstudio-payment-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $donations ) ) : ?>
						<tr><td colspan="12"><?php echo esc_html( self::has_donation_filters( $filters ) ? __( 'No donations match these filters.', 'donations-for-planetstudio-payment-gateway' ) : __( 'No donations yet.', 'donations-for-planetstudio-payment-gateway' ) ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $donations as $donation ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $donation->id ); ?></td>
							<td>
								<?php echo esc_html( trim( $donation->first_name . ' ' . $donation->last_name ) ); ?><br>
								<a href="mailto:<?php echo esc_attr( $donation->email ); ?>"><?php echo esc_html( $donation->email ); ?></a>
							</td>
							<td><?php echo esc_html( strtoupper( PSPGD_I18n::normalize_locale( (string) ( $donation->language ?? '' ) ) ) ); ?></td>
							<td><?php echo ! empty( $donation->is_anonymous ) ? esc_html__( 'Yes', 'donations-for-planetstudio-payment-gateway' ) : esc_html__( 'No', 'donations-for-planetstudio-payment-gateway' ); ?></td>
							<td><?php echo esc_html( $donation->amount . ' ' . $donation->currency ); ?></td>
							<td><?php echo esc_html( $donation->type ); ?></td>
							<td><?php echo esc_html( $donation->campaign_title ?: '-' ); ?></td>
							<td>
								<?php echo esc_html( $donation->status ); ?>
								<?php if ( 'review' === $donation->status ) : ?>
									<?php // Held by PSPGD_PSPG::hold_for_review(). A refund in PSPG marks it refunded; see handle_admin_refund(). ?>
									<br><span class="description"><?php esc_html_e( 'Held: the bank reported a different amount. Left out of every total. Compare the order with your bank, and refund it in PSPG if it should not stand.', 'donations-for-planetstudio-payment-gateway' ); ?></span>
									<?php if ( (int) $donation->pspg_order_id > 0 ) : ?>
										<br><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pspg', 'orderNumber' => (int) $donation->pspg_order_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open the PSPG order', 'donations-for-planetstudio-payment-gateway' ); ?></a>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( ! empty( $donation->is_test ) ) : ?>
									<br><span class="pspgd-test-chip" style="display:inline-block;margin-top:3px;padding:1px 6px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:2px;font-size:11px;" title="<?php esc_attr_e( 'Taken while test mode was on. No money moved, so it is left out of the figures above and of campaign totals.', 'donations-for-planetstudio-payment-gateway' ); ?>"><?php esc_html_e( 'Test', 'donations-for-planetstudio-payment-gateway' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $donation->bank_id ); ?></td>
							<td><?php echo esc_html( $donation->transaction_reference ?: '-' ); ?></td>
							<td><?php echo esc_html( (string) $donation->pspg_order_id ); ?></td>
							<td><?php echo esc_html( $donation->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::render_pager( 'd_page', $donation_page, $donation_pages, $donation_total ); ?>

			<h2><?php esc_html_e( 'Event log', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
			<p><?php esc_html_e( 'Recent payment, recurring setup, and email events.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Event', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Record', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Message', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Details', 'donations-for-planetstudio-payment-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No events yet.', 'donations-for-planetstudio-payment-gateway' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td><?php echo esc_html( $event->created_at ); ?></td>
							<td><code><?php echo esc_html( $event->event_type ); ?></code></td>
							<td><?php echo esc_html( $event->object_type . ' #' . $event->object_id ); ?></td>
							<td><?php echo esc_html( $event->message ); ?></td>
							<td>
								<?php if ( ! empty( $event->context ) ) : ?>
									<details><summary><?php esc_html_e( 'View', 'donations-for-planetstudio-payment-gateway' ); ?></summary><pre style="white-space:pre-wrap;max-width:560px"><?php echo esc_html( $event->context ); ?></pre></details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::render_pager( 'e_page', $event_page, $event_pages, $event_total ); ?>
		</div>
		<?php
	}

	/**
	 * Filters read from the query string. Kept in one place so the table, its
	 * count and the CSV export cannot disagree about what is being shown.
	 *
	 * @return array<string, string>
	 */
	private static function donation_filters(): array {
		return array(
			'status'      => isset( $_GET['f_status'] ) ? sanitize_key( wp_unslash( $_GET['f_status'] ) ) : '',
			'type'        => isset( $_GET['f_type'] ) ? sanitize_key( wp_unslash( $_GET['f_type'] ) ) : '',
			'currency'    => isset( $_GET['f_currency'] ) ? sanitize_text_field( wp_unslash( $_GET['f_currency'] ) ) : '',
			'language'    => isset( $_GET['f_language'] ) ? sanitize_text_field( wp_unslash( $_GET['f_language'] ) ) : '',
			'campaign_id' => isset( $_GET['f_campaign'] ) ? (string) absint( $_GET['f_campaign'] ) : '',
			'date_from'   => self::sanitize_date( sanitize_text_field( wp_unslash( $_GET['f_from'] ?? '' ) ) ),
			'date_to'     => self::sanitize_date( sanitize_text_field( wp_unslash( $_GET['f_to'] ?? '' ) ) ),
			'search'      => isset( $_GET['f_search'] ) ? sanitize_text_field( wp_unslash( $_GET['f_search'] ) ) : '',
		);
	}

	/** @param mixed $value */
	/** A Y-m-d date from an already-sanitized string, or ''. */
	private static function sanitize_date( $value ): string {
		$value = trim( (string) $value );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/** @param array<string, string> $filters */
	private static function has_donation_filters( array $filters ): bool {
		foreach ( $filters as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter bar above the donation log, plus the export button. The export
	 * carries the same filters, so what downloads is what is on screen.
	 *
	 * @param array<string, string> $filters
	 */
	private static function render_donation_filters( array $filters, int $total ): void {
		$campaigns = get_posts(
			array(
				'post_type'      => 'pspgd_campaign',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => array( 'publish', 'draft', 'private' ),
			)
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
			<input type="hidden" name="page" value="pspgd">

			<label><span class="screen-reader-text"><?php esc_html_e( 'Status', 'donations-for-planetstudio-payment-gateway' ); ?></span>
				<select name="f_status">
					<option value=""><?php esc_html_e( 'Any status', 'donations-for-planetstudio-payment-gateway' ); ?></option>
					<?php foreach ( PSPGD_Repository::distinct_donation_values( 'status' ) as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $value ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label><span class="screen-reader-text"><?php esc_html_e( 'Type', 'donations-for-planetstudio-payment-gateway' ); ?></span>
				<select name="f_type">
					<option value=""><?php esc_html_e( 'Any type', 'donations-for-planetstudio-payment-gateway' ); ?></option>
					<?php foreach ( PSPGD_Repository::distinct_donation_values( 'type' ) as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['type'], $value ); ?>><?php echo esc_html( $value ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label><span class="screen-reader-text"><?php esc_html_e( 'Currency', 'donations-for-planetstudio-payment-gateway' ); ?></span>
				<select name="f_currency">
					<option value=""><?php esc_html_e( 'Any currency', 'donations-for-planetstudio-payment-gateway' ); ?></option>
					<?php foreach ( PSPGD_PSPG::allowed_currencies() as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['currency'], $value ); ?>><?php echo esc_html( $value ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label><span class="screen-reader-text"><?php esc_html_e( 'Language', 'donations-for-planetstudio-payment-gateway' ); ?></span>
				<select name="f_language">
					<option value=""><?php esc_html_e( 'Any language', 'donations-for-planetstudio-payment-gateway' ); ?></option>
					<?php foreach ( PSPGD_I18n::supported_locales() as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['language'], $value ); ?>><?php echo esc_html( strtoupper( $value ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<?php if ( $campaigns ) : ?>
				<label><span class="screen-reader-text"><?php esc_html_e( 'Campaign', 'donations-for-planetstudio-payment-gateway' ); ?></span>
					<select name="f_campaign">
						<option value=""><?php esc_html_e( 'Any campaign', 'donations-for-planetstudio-payment-gateway' ); ?></option>
						<?php foreach ( $campaigns as $campaign ) : ?>
							<option value="<?php echo esc_attr( (string) $campaign->ID ); ?>" <?php selected( $filters['campaign_id'], (string) $campaign->ID ); ?>><?php echo esc_html( $campaign->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<label><?php esc_html_e( 'From', 'donations-for-planetstudio-payment-gateway' ); ?>
				<input type="date" name="f_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
			</label>
			<label><?php esc_html_e( 'To', 'donations-for-planetstudio-payment-gateway' ); ?>
				<input type="date" name="f_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
			</label>

			<label><span class="screen-reader-text"><?php esc_html_e( 'Search donors', 'donations-for-planetstudio-payment-gateway' ); ?></span>
				<input type="search" name="f_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Name, email or bank reference', 'donations-for-planetstudio-payment-gateway' ); ?>">
			</label>

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'donations-for-planetstudio-payment-gateway' ); ?></button>
			<?php if ( self::has_donation_filters( $filters ) ) : ?>
				<a class="button-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pspgd' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'donations-for-planetstudio-payment-gateway' ); ?></a>
			<?php endif; ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 16px;">
			<input type="hidden" name="action" value="pspgd_export_csv">
			<?php wp_nonce_field( 'pspgd_export_csv' ); ?>
			<?php foreach ( $filters as $key => $value ) : ?>
				<?php if ( '' !== trim( (string) $value ) ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			<button type="submit" class="button">
				<?php
				printf(
					/* translators: %s: number of donations. */
					esc_html( _n( 'Export %s donation to CSV', 'Export %s donations to CSV', $total, 'donations-for-planetstudio-payment-gateway' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</button>
		</form>
		<?php
	}

	/**
	 * Stream the filtered donations as CSV.
	 *
	 * Written straight to the output buffer rather than assembled in memory,
	 * so exporting a year of donations does not depend on how much memory
	 * PHP happens to have.
	 */
	public static function handle_export_csv(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export donations.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}
		check_admin_referer( 'pspgd_export_csv' );

		$filters = array(
			'status'      => sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ),
			'type'        => sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ),
			'currency'    => sanitize_text_field( wp_unslash( $_POST['currency'] ?? '' ) ),
			'language'    => sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ),
			'campaign_id' => (string) absint( $_POST['campaign_id'] ?? 0 ),
			'date_from'   => self::sanitize_date( sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ) ),
			'date_to'     => self::sanitize_date( sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ) ),
			'search'      => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pspgd-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel assumes the system encoding without this, which turns every
		// name written in a non-Latin script into rubbish.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output, the download itself, not a file.

		fputcsv(
			$out,
			array(
				__( 'Donation ID', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Created', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Status', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Type', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Amount', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Currency', 'donations-for-planetstudio-payment-gateway' ),
				__( 'First name', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Last name', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Email', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Language', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Anonymous', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Campaign', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Bank reference', 'donations-for-planetstudio-payment-gateway' ),
				__( 'PSPG order', 'donations-for-planetstudio-payment-gateway' ),
				__( 'Subscription ID', 'donations-for-planetstudio-payment-gateway' ),
				// Last, so a sheet built against an earlier export keeps its
				// column positions.
				__( 'Test', 'donations-for-planetstudio-payment-gateway' ),
			)
		);

		foreach ( PSPGD_Repository::stream_donations( $filters ) as $donation ) {
			fputcsv(
				$out,
				array(
					(int) $donation->id,
					(string) $donation->created_at,
					(string) $donation->status,
					(string) $donation->type,
					(string) $donation->amount,
					(string) $donation->currency,
					self::csv_text( (string) $donation->first_name ),
					self::csv_text( (string) $donation->last_name ),
					self::csv_text( (string) $donation->email ),
					strtoupper( PSPGD_I18n::normalize_locale( (string) ( $donation->language ?? '' ) ) ),
					empty( $donation->is_anonymous ) ? 'no' : 'yes',
					self::csv_text( (string) ( $donation->campaign_title ?? '' ) ),
					self::csv_text( (string) $donation->transaction_reference ),
					(string) $donation->pspg_order_id,
					(int) $donation->subscription_id,
					empty( $donation->is_test ) ? 'no' : 'yes',
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output, as above.
		exit;
	}

	/** Addresses typed into one field, kept only if valid, de-duplicated, comma-separated. */
	public static function sanitize_email_list( string $raw ): string {
		$emails = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $candidate ) {
			$email = sanitize_email( (string) $candidate );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $emails, true ) ) {
				$emails[] = $email;
			}
		}

		return implode( ', ', $emails );
	}

	/**
	 * A value a donor typed, made safe to open in a spreadsheet.
	 *
	 * Excel, LibreOffice and Google Sheets run a cell that starts with one of
	 * these characters as a formula, so a "name" like =HYPERLINK(...) would
	 * act on whoever opens the export. A leading apostrophe makes it plain
	 * text and is not shown.
	 */
	public static function csv_text( string $value ): string {
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * A short row of figures above the donation log.
	 *
	 * Money is shown per currency on purpose: one combined total would add
	 * different currencies together as though they were the same unit.
	 */
	private static function render_stat_band(): void {
		$stats = PSPGD_Stats::snapshot();

		$tiles = array(
			array(
				'label' => __( 'Raised this month', 'donations-for-planetstudio-payment-gateway' ),
				'lines' => self::money_lines( $stats['money'], 'month' ),
				'note'  => sprintf(
					/* translators: %s: number of donations. */
					_n( '%s donation', '%s donations', $stats['paid_month'], 'donations-for-planetstudio-payment-gateway' ),
					number_format_i18n( $stats['paid_month'] )
				),
			),
			array(
				'label' => __( 'Raised all time', 'donations-for-planetstudio-payment-gateway' ),
				'lines' => self::money_lines( $stats['money'], 'total' ),
				'note'  => sprintf(
					/* translators: %s: number of donations. */
					_n( '%s donation', '%s donations', $stats['paid_total'], 'donations-for-planetstudio-payment-gateway' ),
					number_format_i18n( $stats['paid_total'] )
				),
			),
			array(
				'label' => __( 'Active supporters', 'donations-for-planetstudio-payment-gateway' ),
				'lines' => array( number_format_i18n( $stats['supporters'] ) ),
				'note'  => __( 'gave in the last 12 months', 'donations-for-planetstudio-payment-gateway' ),
			),
			array(
				'label' => __( 'Recurring donations', 'donations-for-planetstudio-payment-gateway' ),
				'lines' => array( number_format_i18n( $stats['recurring'] ) ),
				'note'  => $stats['committed']
					? sprintf(
						/* translators: %s: committed amounts per currency. */
						__( '%s per month', 'donations-for-planetstudio-payment-gateway' ),
						implode( ' · ', self::committed_lines( $stats['committed'] ) )
					)
					: __( 'none active yet', 'donations-for-planetstudio-payment-gateway' ),
			),
			array(
				'label' => __( 'Completed payments', 'donations-for-planetstudio-payment-gateway' ),
				'lines' => array( null === $stats['completion'] ? '—' : number_format_i18n( $stats['completion'], 1 ) . '%' ),
				'note'  => sprintf(
					/* translators: 1: failed count, 2: abandoned count. */
					__( '%1$s failed · %2$s abandoned', 'donations-for-planetstudio-payment-gateway' ),
					number_format_i18n( (int) ( $stats['outcomes']['failed'] ?? 0 ) ),
					number_format_i18n( (int) ( $stats['outcomes']['abandoned'] ?? 0 ) )
				),
			),
		);
		?>
		<div class="pspgd-stat-band" style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 24px;">
			<?php foreach ( $tiles as $tile ) : ?>
				<div style="flex:1 1 190px;min-width:190px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 14px;">
					<div style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#646970;"><?php echo esc_html( $tile['label'] ); ?></div>
					<?php foreach ( $tile['lines'] as $line ) : ?>
						<div style="font-size:19px;font-weight:600;color:#1d2327;margin-top:4px;"><?php echo esc_html( $line ); ?></div>
					<?php endforeach; ?>
					<div style="font-size:12px;color:#646970;margin-top:4px;"><?php echo esc_html( $tile['note'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		if ( PSPGD_PSPG::donations_test_mode() ) {
			// Said out loud while test mode is on, so a test donation that
			// does not show up here reads as intended rather than as a bug.
			echo '<p style="margin:-16px 0 24px;color:#646970;font-size:12px;">' . esc_html__( 'Test mode is on. Donations taken while it is on are marked Test and left out of these figures and of campaign totals.', 'donations-for-planetstudio-payment-gateway' ) . '</p>';
		}
	}

	/**
	 * One formatted line per currency, or a single zero line when nothing has
	 * been raised yet.
	 *
	 * @param array<string, array<string, float|int>> $money
	 * @return string[]
	 */
	private static function money_lines( array $money, string $key ): array {
		$lines = array();

		foreach ( $money as $currency => $totals ) {
			$amount = (float) ( $totals[ $key ] ?? 0 );
			if ( $amount <= 0 ) {
				continue;
			}

			$lines[] = PSPGD_I18n::format_amount( $amount, (string) $currency );
		}

		return $lines ? $lines : array( PSPGD_I18n::format_amount( 0.0, PSPGD_Settings::default_currency() ) );
	}

	/**
	 * @param array<string, float> $committed
	 * @return string[]
	 */
	private static function committed_lines( array $committed ): array {
		$lines = array();

		foreach ( $committed as $currency => $amount ) {
			$lines[] = PSPGD_I18n::format_amount( (float) $amount, (string) $currency );
		}

		return $lines;
	}

	/**
	 * Minimal previous/next + page-count pager. Preserves the other
	 * table's page query arg so paging one table doesn't reset the other.
	 */
	private static function render_pager( string $query_arg, int $current_page, int $total_pages, int $total_items ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = remove_query_arg( array( 'updated' ) );
		?>
		<p class="pspgd-pager" style="margin:12px 0;">
			<?php if ( $current_page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $query_arg, $current_page - 1, $base_url ) ); ?>"><?php esc_html_e( 'Previous', 'donations-for-planetstudio-payment-gateway' ); ?></a>
			<?php endif; ?>
			<span style="margin:0 8px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: current page, 2: total pages, 3: total item count. */
						__( 'Page %1$d of %2$d (%3$s total)', 'donations-for-planetstudio-payment-gateway' ),
						$current_page,
						$total_pages,
						number_format_i18n( $total_items )
					)
				);
				?>
			</span>
			<?php if ( $current_page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $query_arg, $current_page + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'donations-for-planetstudio-payment-gateway' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * "Recurring Donations" panel: lists subscriptions with their current
	 * binding status and lets an admin cancel a subscription or trigger an
	 * immediate charge (useful to verify a saved card / binding actually
	 * works right after setup, without waiting for the next scheduled run).
	 */
	public static function render_recurring_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		// A subscription row exists before the donor reaches the bank, so a
		// pending one is an unfinished payment attempt rather than a
		// recurring donation. Hidden unless explicitly asked for.
		$show_incomplete = ! empty( $_GET['show_incomplete'] );

		$sub_total = PSPGD_Repository::count_subscriptions( $show_incomplete );
		$sub_pages = max( 1, (int) ceil( $sub_total / self::PER_PAGE ) );
		$sub_page  = min( $sub_pages, max( 1, absint( $_GET['s_page'] ?? 1 ) ) );
		$subscriptions   = PSPGD_Repository::list_subscriptions( self::PER_PAGE, ( $sub_page - 1 ) * self::PER_PAGE, $show_incomplete );

		$incomplete_count = $show_incomplete ? 0 : max( 0, PSPGD_Repository::count_subscriptions( true ) - $sub_total );
		$panel_url        = add_query_arg( array( 'page' => 'pspgd-recurring' ), admin_url( 'admin.php' ) );
		$flagged_sub_ids = PSPGD_Repository::subscriptions_with_flagged_redirect_url( wp_list_pluck( $subscriptions, 'id' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Recurring Donations', 'donations-for-planetstudio-payment-gateway' ); ?></h1>
			<p><?php esc_html_e( 'All recurring donation subscriptions and their saved-card (binding) status.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

			<?php if ( $show_incomplete ) : ?>
				<p>
					<?php esc_html_e( 'Including unfinished payment attempts: a donor opened the payment page and has not completed it. These are discarded automatically 24 hours after they are started.', 'donations-for-planetstudio-payment-gateway' ); ?>
					<a href="<?php echo esc_url( $panel_url ); ?>"><?php esc_html_e( 'Hide them', 'donations-for-planetstudio-payment-gateway' ); ?></a>
				</p>
			<?php elseif ( $incomplete_count > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: number of unfinished payment attempts. */
						esc_html( _n( '%s unfinished payment attempt is hidden.', '%s unfinished payment attempts are hidden.', $incomplete_count, 'donations-for-planetstudio-payment-gateway' ) ),
						esc_html( number_format_i18n( $incomplete_count ) )
					);
					?>
					<a href="<?php echo esc_url( add_query_arg( 'show_incomplete', '1', $panel_url ) ); ?>"><?php esc_html_e( 'Show them', 'donations-for-planetstudio-payment-gateway' ); ?></a>
				</p>
			<?php endif; ?>

			<?php if ( isset( $_GET['pspgd_notice'] ) ) : ?>
				<?php
				$notice_map = array(
					'cancelled'      => array( 'success', __( 'Subscription cancelled.', 'donations-for-planetstudio-payment-gateway' ) ),
					'cancel_failed'  => array( 'error', __( 'Could not cancel the subscription at the gateway. See the event log for details.', 'donations-for-planetstudio-payment-gateway' ) ),
					'not_found'      => array( 'error', __( 'Subscription not found.', 'donations-for-planetstudio-payment-gateway' ) ),
				);
				$notice_key = sanitize_key( (string) $_GET['pspgd_notice'] );
				if ( isset( $notice_map[ $notice_key ] ) ) :
					list( $notice_type, $notice_text ) = $notice_map[ $notice_key ];
					?>
					<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice_text ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Donor', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Language', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Interval', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Status', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Binding', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Next Payment', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Last Payment', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Failures', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Alerts', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'donations-for-planetstudio-payment-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $subscriptions ) ) : ?>
						<tr><td colspan="13"><?php esc_html_e( 'No recurring donations yet.', 'donations-for-planetstudio-payment-gateway' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $subscriptions as $subscription ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $subscription->id ); ?></td>
							<td>
								<?php echo esc_html( trim( $subscription->first_name . ' ' . $subscription->last_name ) ); ?><br>
								<a href="mailto:<?php echo esc_attr( $subscription->donor_email ); ?>"><?php echo esc_html( $subscription->donor_email ); ?></a>
							</td>
							<td><?php echo esc_html( strtoupper( PSPGD_I18n::normalize_locale( (string) ( $subscription->language ?? '' ) ) ) ); ?></td>
							<td><?php echo esc_html( PSPGD_I18n::format_amount( (float) $subscription->amount, (string) $subscription->currency ) ); ?></td>
							<td><?php echo esc_html( $subscription->interval_key ); ?></td>
							<td><?php echo esc_html( $subscription->campaign_title ?: '-' ); ?></td>
							<td>
								<?php echo esc_html( $subscription->status ); ?>
								<?php if ( ! empty( $subscription->is_test ) ) : ?>
									<br><span class="pspgd-test-chip" style="display:inline-block;margin-top:3px;padding:1px 6px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:2px;font-size:11px;" title="<?php esc_attr_e( 'Created while test mode was on. Its saved card is simulated, so it is never charged automatically.', 'donations-for-planetstudio-payment-gateway' ); ?>"><?php esc_html_e( 'Test', 'donations-for-planetstudio-payment-gateway' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $subscription->binding_id ) ) : ?>
									<span class="dashicons dashicons-yes" style="color:#46b450;" title="<?php esc_attr_e( 'Saved card active', 'donations-for-planetstudio-payment-gateway' ); ?>"></span>
								<?php else : ?>
									<span class="dashicons dashicons-warning" style="color:#dba617;" title="<?php esc_attr_e( 'No saved card yet', 'donations-for-planetstudio-payment-gateway' ); ?>"></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $subscription->next_payment_at ?: '-' ); ?></td>
							<td><?php echo esc_html( $subscription->last_payment_at ?: '-' ); ?></td>
							<td><?php echo esc_html( (string) $subscription->failed_payment_count ); ?></td>
							<td>
								<?php if ( in_array( (int) $subscription->id, $flagged_sub_ids, true ) ) : ?>
									<span class="dashicons dashicons-warning" style="color:#d63638;" title="<?php esc_attr_e( 'The bank returned a payment page URL with an unusual merchant path on a recent charge for this subscription. It may 404 for the donor. See the event log below for details.', 'donations-for-planetstudio-payment-gateway' ); ?>"></span>
									<?php esc_html_e( 'Check payment URL', 'donations-for-planetstudio-payment-gateway' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'cancelled' !== $subscription->status ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this recurring donation?', 'donations-for-planetstudio-payment-gateway' ) ); ?>');">
										<input type="hidden" name="action" value="pspgd_cancel_subscription">
										<input type="hidden" name="subscription_id" value="<?php echo esc_attr( (string) $subscription->id ); ?>">
										<?php wp_nonce_field( 'pspgd_cancel_subscription_' . $subscription->id ); ?>
										<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'donations-for-planetstudio-payment-gateway' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::render_pager( 's_page', $sub_page, $sub_pages, $sub_total ); ?>
		</div>
		<?php
	}


	public static function render_form_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		$labels            = (array) get_option( 'pspgd_form_labels', array() );
		$presets           = PSPGD_I18n::amount_presets();
		$min_amounts       = PSPGD_I18n::min_amounts();
		$max_amounts       = PSPGD_I18n::max_amounts();
		$fields            = PSPGD_I18n::english_labels();
		$locales           = PSPGD_I18n::supported_locales();
		$test_mode         = PSPGD_PSPG::donations_test_mode();
		$recurring_enabled = '0' !== (string) get_option( 'pspgd_recurring_enabled', '1' );
		$banks             = PSPGD_PSPG::bank_choices();
		$bank_id           = PSPGD_Settings::bank_id();
		$currencies        = PSPGD_Settings::currencies();
		$intervals         = PSPGD_Settings::intervals();
		$colors            = PSPGD_Settings::colors();
		$languages         = (array) get_option( 'pspgd_languages', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Donation Form Settings', 'donations-for-planetstudio-payment-gateway' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form settings saved.', 'donations-for-planetstudio-payment-gateway' ); ?></p></div><?php endif; ?>
			<p><?php printf( /* translators: %s: the shortcode. */ esc_html__( 'Add the form to any page with the %s shortcode. Campaign pages show it automatically.', 'donations-for-planetstudio-payment-gateway' ), '<code>[pspgd_donation_form]</code>' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pspgd_save_form_settings">
				<?php wp_nonce_field( 'pspgd_save_form_settings' ); ?>

				<h2><?php esc_html_e( 'Payments', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pspgd-bank"><?php esc_html_e( 'Bank', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<?php if ( $banks ) : ?>
								<select id="pspgd-bank" name="bank_id">
									<option value=""><?php esc_html_e( '-- Choose a bank --', 'donations-for-planetstudio-payment-gateway' ); ?></option>
									<?php foreach ( $banks as $id => $title ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $bank_id, $id ); ?>><?php echo esc_html( $title . ' (' . $id . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input class="regular-text code" id="pspgd-bank" name="bank_id" value="<?php echo esc_attr( $bank_id ); ?>" placeholder="arca_acba">
								<p class="description"><?php esc_html_e( 'PlanetStudio Payment Gateway is not active, so its banks cannot be listed. Enter the bank id by hand, or activate the gateway.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Every donation, recurring charge and card release goes through this bank. Its credentials are set in PlanetStudio Payment Gateway. Recurring donations need a bank that supports saved cards.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Currencies offered', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<td>
							<?php foreach ( PSPGD_Settings::known_currencies() as $currency ) : ?>
								<label style="margin-right:14px;"><input type="checkbox" name="currencies[]" value="<?php echo esc_attr( $currency ); ?>" <?php checked( in_array( $currency, $currencies, true ) ); ?>> <?php echo esc_html( $currency ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Each donation is charged in the currency the donor picks, so the bank must be set up to accept every currency ticked here. With only one ticked, the currency selector is hidden.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-default-currency"><?php esc_html_e( 'Selected by default', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<select id="pspgd-default-currency" name="default_currency">
								<?php foreach ( PSPGD_Settings::known_currencies() as $currency ) : ?>
									<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( PSPGD_Settings::default_currency(), $currency ); ?>><?php echo esc_html( $currency ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-goal-currency"><?php esc_html_e( 'Campaign goal currency', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<select id="pspgd-goal-currency" name="goal_currency">
								<?php foreach ( PSPGD_Settings::known_currencies() as $currency ) : ?>
									<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( PSPGD_Settings::goal_currency(), $currency ); ?>><?php echo esc_html( $currency ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'New campaign goals are entered in this currency, and donations in other currencies are converted into it for the progress bar. A campaign keeps the currency it was created with if this is changed later.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Payment testing', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<label><input type="checkbox" name="test_mode" value="1" <?php checked( $test_mode ); ?>> <strong><?php esc_html_e( 'Enable test mode', 'donations-for-planetstudio-payment-gateway' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Test mode does not contact the bank. Donations are immediately recorded as paid, marked Test, and left out of every total; recurring schedules receive a simulated saved card that is never charged.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

				<h2><?php esc_html_e( 'Donation frequency', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<label><input type="checkbox" name="recurring_enabled" value="1" <?php checked( $recurring_enabled ); ?>> <strong><?php esc_html_e( 'Offer recurring donations', 'donations-for-planetstudio-payment-gateway' ); ?></strong></label>
				<p>
					<label style="margin-right:14px;"><input type="checkbox" name="intervals[]" value="monthly" <?php checked( in_array( 'monthly', $intervals, true ) ); ?>> <?php esc_html_e( 'Monthly', 'donations-for-planetstudio-payment-gateway' ); ?></label>
					<label><input type="checkbox" name="intervals[]" value="weekly" <?php checked( in_array( 'weekly', $intervals, true ) ); ?>> <?php esc_html_e( 'Weekly', 'donations-for-planetstudio-payment-gateway' ); ?></label>
				</p>
				<p class="description"><?php esc_html_e( 'Turn recurring off while saved-card payments are unavailable at the bank. Donors can still give once.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

				<h2><?php esc_html_e( 'Form options', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Newsletter opt-in', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<td>
							<label><input type="checkbox" name="newsletter_optin" value="1" <?php checked( PSPGD_Settings::newsletter_optin() ); ?>> <?php esc_html_e( 'Show a "subscribe to our newsletter" checkbox', 'donations-for-planetstudio-payment-gateway' ); ?></label>
							<p class="description"><?php esc_html_e( 'The checkbox is named mc4wp-subscribe, which Mailchimp for WordPress picks up through its "other forms" integration. This plugin does not store the choice itself.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Supporters list', 'donations-for-planetstudio-payment-gateway' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_supporters" value="1" <?php checked( PSPGD_Settings::show_supporters() ); ?>> <?php esc_html_e( 'List supporters on campaign pages (first name and last initial, or "Anonymous donor")', 'donations-for-planetstudio-payment-gateway' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-payment-image"><?php esc_html_e( 'Payment methods image', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="large-text code" type="url" id="pspgd-payment-image" name="payment_image_url" value="<?php echo esc_attr( PSPGD_Settings::payment_image_url() ); ?>" placeholder="https://">
							<p class="description"><?php esc_html_e( 'Optional. Shown above the Donate button, typically the logos of the cards you accept. Upload it to the media library and paste its URL.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-languages"><?php esc_html_e( 'Donor languages', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="regular-text code" id="pspgd-languages" name="languages" value="<?php echo esc_attr( implode( ', ', $languages ) ); ?>" placeholder="<?php echo esc_attr( implode( ', ', $locales ) ); ?>">
							<p class="description"><?php esc_html_e( 'Two-letter codes, comma-separated, e.g. "en, hy". Leave blank to follow Polylang, or the site language when Polylang is not installed. Each donor is written to in the language they gave in.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<?php self::render_protection_settings(); ?>

				<h2><?php esc_html_e( 'Appearance', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Used by the donation form, campaign pages, the payment result screen and every email.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pspgd-color-primary"><?php esc_html_e( 'Main colour', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td><input type="color" id="pspgd-color-primary" name="colors[primary]" value="<?php echo esc_attr( $colors['primary'] ); ?>"> <span class="description"><?php esc_html_e( 'Buttons, selected options, links and the progress bar. White text sits on it, so pick something dark enough.', 'donations-for-planetstudio-payment-gateway' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-color-accent"><?php esc_html_e( 'Accent colour', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td><input type="color" id="pspgd-color-accent" name="colors[accent]" value="<?php echo esc_attr( $colors['accent'] ); ?>"> <span class="description"><?php esc_html_e( 'Top borders, focus rings and soft backgrounds.', 'donations-for-planetstudio-payment-gateway' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-color-text"><?php esc_html_e( 'Text colour', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td><input type="color" id="pspgd-color-text" name="colors[text]" value="<?php echo esc_attr( $colors['text'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-font"><?php esc_html_e( 'Font', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="regular-text" id="pspgd-font" name="font_family" value="<?php echo esc_attr( 'inherit' === PSPGD_Settings::font_family() ? '' : PSPGD_Settings::font_family() ); ?>" placeholder="inherit">
							<p class="description"><?php esc_html_e( 'A CSS font-family, e.g. "Inter, sans-serif". Leave blank to use the theme\'s font. The font itself must already be loaded by the theme.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-radius"><?php esc_html_e( 'Corner radius', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td><input type="number" class="small-text" id="pspgd-radius" name="radius" min="0" max="32" step="1" value="<?php echo esc_attr( (string) PSPGD_Settings::radius() ); ?>"> px</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Suggested amounts', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Comma-separated amounts shown as buttons for each currency. The second one is pre-selected.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $currencies as $currency ) : ?>
						<tr><th scope="row"><label for="pspgd-preset-<?php echo esc_attr( strtolower( $currency ) ); ?>"><?php echo esc_html( $currency ); ?></label></th><td><input class="regular-text" id="pspgd-preset-<?php echo esc_attr( strtolower( $currency ) ); ?>" name="presets[<?php echo esc_attr( $currency ); ?>]" value="<?php echo esc_attr( implode( ', ', (array) $presets[ $currency ] ) ); ?>"></td></tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Minimum donation amounts', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Donations below these amounts are rejected before payment is sent to the gateway. A donation costs the same in card fees whatever its size, so a very small one costs money to accept -- and a very small one is also what somebody testing a stolen card number sends.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $currencies as $currency ) : ?>
						<tr><th scope="row"><label for="pspgd-min-<?php echo esc_attr( strtolower( $currency ) ); ?>"><?php echo esc_html( $currency ); ?></label></th><td><input type="number" class="regular-text" id="pspgd-min-<?php echo esc_attr( strtolower( $currency ) ); ?>" name="min_amounts[<?php echo esc_attr( $currency ); ?>]" min="0.01" step="0.01" value="<?php echo esc_attr( (string) (float) $min_amounts[ $currency ] ); ?>"></td></tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Maximum donation amounts', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Donations above these amounts are rejected before payment is sent to the gateway.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $currencies as $currency ) : ?>
						<tr><th scope="row"><label for="pspgd-max-<?php echo esc_attr( strtolower( $currency ) ); ?>"><?php echo esc_html( $currency ); ?></label></th><td><input type="number" class="regular-text" id="pspgd-max-<?php echo esc_attr( strtolower( $currency ) ); ?>" name="max_amounts[<?php echo esc_attr( $currency ); ?>]" min="1" step="0.01" value="<?php echo esc_attr( (string) (float) $max_amounts[ $currency ] ); ?>"></td></tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Event log retention', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'The event log records every payment, email and scheduled decision. Older entries are deleted automatically. Set this to 0 to keep everything.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pspgd-event-retention"><?php esc_html_e( 'Keep events for', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="pspgd-event-retention" name="event_retention_days" min="0" step="1" value="<?php echo esc_attr( (string) (int) get_option( 'pspgd_event_retention_days', 180 ) ); ?>">
							<?php esc_html_e( 'days', 'donations-for-planetstudio-payment-gateway' ); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Removing the plugin', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( '1' === (string) get_option( 'pspgd_delete_data_on_uninstall', '0' ) ); ?>> <?php esc_html_e( 'Delete all donation data when the plugin is deleted', 'donations-for-planetstudio-payment-gateway' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off by default. When ticked, deleting the plugin from the Plugins screen permanently removes every donor, donation, recurring donation, log entry and setting. Campaign pages are kept either way.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

				<h2><?php esc_html_e( 'Form wording', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Leave a field empty to use the built-in wording, shown greyed out. There is one column per donor language.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th><?php esc_html_e( 'Label', 'donations-for-planetstudio-payment-gateway' ); ?></th><?php foreach ( $locales as $locale ) : ?><th><?php echo esc_html( strtoupper( $locale ) ); ?></th><?php endforeach; ?></tr></thead>
					<tbody>
						<?php foreach ( $fields as $key => $default ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $default ); ?></strong></td>
								<?php foreach ( $locales as $locale ) : ?>
									<td><input class="regular-text" name="labels[<?php echo esc_attr( $locale ); ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $labels[ $locale ][ $key ] ?? '' ); ?>" placeholder="<?php echo esc_attr( PSPGD_I18n::labels( $locale )[ $key ] ?? '' ); ?>"></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save form settings', 'donations-for-planetstudio-payment-gateway' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Where the visitor's address comes from, with what each source holds on
	 * this very request, and whether the form asks for a Turnstile check.
	 */
	private static function render_protection_settings(): void {
		$sources = array(
			'remote_addr'     => __( 'Direct connection (REMOTE_ADDR)', 'donations-for-planetstudio-payment-gateway' ),
			'cloudflare'      => __( 'Cloudflare (CF-Connecting-IP header)', 'donations-for-planetstudio-payment-gateway' ),
			'x_real_ip'       => __( 'Reverse proxy (X-Real-IP header)', 'donations-for-planetstudio-payment-gateway' ),
			'x_forwarded_for' => __( 'Reverse proxy (last address in X-Forwarded-For)', 'donations-for-planetstudio-payment-gateway' ),
		);
		$current   = PSPGD_Security::ip_source();
		$seen      = PSPGD_Security::addresses_seen();
		$available = PSPGD_Security::turnstile_available();
		?>
		<h2><?php esc_html_e( 'Spam protection', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pspgd-ip-source"><?php esc_html_e( 'Visitor address', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
				<td>
					<select id="pspgd-ip-source" name="client_ip_source">
						<?php foreach ( $sources as $source => $label ) : ?>
							<option value="<?php echo esc_attr( $source ); ?>" <?php selected( $current, $source ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html( sprintf( /* translators: 1: number of attempts, 2: minutes. */ __( 'The form refuses more than %1$d donation attempts from one address in %2$d minutes. Behind a CDN or reverse proxy every visitor arrives from the proxy\'s address, so choose the header your proxy writes the real address into. Choose a header only if your proxy sets it: otherwise a visitor can write it themselves.', 'donations-for-planetstudio-payment-gateway' ), PSPGD_Security::rate_limit(), (int) ( PSPGD_Security::rate_window() / MINUTE_IN_SECONDS ) ) ); ?></p>
					<p><?php esc_html_e( 'What each source holds for your own visit right now -- pick the one that shows your own address:', 'donations-for-planetstudio-payment-gateway' ); ?></p>
					<ul style="margin-top:0;">
						<?php foreach ( $sources as $source => $label ) : ?>
							<li><?php echo esc_html( $label ); ?>: <code><?php echo esc_html( '' !== $seen[ $source ] ? $seen[ $source ] : __( 'not set', 'donations-for-planetstudio-payment-gateway' ) ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cloudflare Turnstile', 'donations-for-planetstudio-payment-gateway' ); ?></th>
				<td>
					<label><input type="checkbox" name="turnstile" value="1" <?php checked( '1' === (string) get_option( 'pspgd_turnstile', '0' ) ); ?>> <?php esc_html_e( 'Ask donors to pass a Turnstile check before donating', 'donations-for-planetstudio-payment-gateway' ); ?></label>
					<?php if ( $available ) : ?>
						<p class="description"><?php esc_html_e( 'Uses the Turnstile keys saved in PlanetStudio Payment Gateway. If Cloudflare cannot be reached the donation is refused, so make a test donation after switching this on.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
					<?php else : ?>
						<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: link to the PSPG Tools screen. */ __( 'No Turnstile keys are saved yet, so the check is not shown even when ticked. Enter them under %s; this form uses the same keys.', 'donations-for-planetstudio-payment-gateway' ), '<a href="' . esc_url( admin_url( 'admin.php?page=pspg-tools' ) ) . '">' . esc_html__( 'PlanetStudio Payment Gateway > Tools', 'donations-for-planetstudio-payment-gateway' ) . '</a>' ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_email_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		$templates = (array) get_option( 'pspgd_email_templates', array() );
		$defaults  = PSPGD_Emails::default_templates();
		$locales   = PSPGD_I18n::supported_locales();
		$sent_to   = wp_get_current_user()->user_email;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Templates', 'donations-for-planetstudio-payment-gateway' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email templates saved.', 'donations-for-planetstudio-payment-gateway' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['test_sent'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php printf( /* translators: %s: email address. */ esc_html__( 'Test email sent to %s.', 'donations-for-planetstudio-payment-gateway' ), esc_html( $sent_to ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['test_failed'] ) ) : ?><div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The test email could not be sent. Check the site mail configuration.', 'donations-for-planetstudio-payment-gateway' ); ?></p></div><?php endif; ?>
			<p><?php esc_html_e( 'Edit the outgoing donation and recurring payment emails here. Leave any field blank to fall back to the built-in wording. Each email is built from the subject, heading and body below, plus a details table of the donation itself, which is added automatically.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

			<h2><?php esc_html_e( 'Placeholders', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
			<p><?php esc_html_e( 'Use these anywhere in a subject, heading or body. A placeholder with nothing behind it -- a campaign name on a donation that had no campaign -- is replaced with nothing.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
			<p>
				<?php foreach ( PSPGD_Emails::placeholders() as $placeholder ) : ?>
					<code style="margin-right:8px;">{<?php echo esc_html( $placeholder ); ?>}</code>
				<?php endforeach; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Only the body may contain formatting: line breaks, links, bold and italic, and lists. Subjects and headings are always plain text.', 'donations-for-planetstudio-payment-gateway' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pspgd_save_email_settings">
				<?php wp_nonce_field( 'pspgd_save_email_settings' ); ?>

				<h2><?php esc_html_e( 'Sending', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pspgd-from-name"><?php esc_html_e( 'From name', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td><input class="regular-text" id="pspgd-from-name" name="from_name" value="<?php echo esc_attr( (string) get_option( 'pspgd_email_from_name', '' ) ); ?>" placeholder="<?php echo esc_attr( PSPGD_Emails::from_name() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-from-address"><?php esc_html_e( 'From address', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="regular-text" type="email" id="pspgd-from-address" name="from_address" value="<?php echo esc_attr( (string) get_option( 'pspgd_email_from_address', '' ) ); ?>" placeholder="<?php echo esc_attr( PSPGD_Emails::from_address() ); ?>">
							<p class="description"><?php esc_html_e( 'Must be an address at this site\'s own domain, or the mail is likely to be rejected or marked as spam.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-reply-to"><?php esc_html_e( 'Reply-to address', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="regular-text" type="email" id="pspgd-reply-to" name="reply_to" value="<?php echo esc_attr( (string) get_option( 'pspgd_email_reply_to', '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Where a donor\'s reply goes. Leave blank and replies go to the from address, which nobody reads.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-staff-notify"><?php esc_html_e( 'Notify staff', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="large-text" id="pspgd-staff-notify" name="staff_notify_emails" value="<?php echo esc_attr( implode( ', ', PSPGD_Notifications::staff_recipients() ) ); ?>" placeholder="donations@example.org, finance@example.org">
							<p class="description"><?php esc_html_e( 'Optional. These addresses get a short note for each new donation, and an alert when a payment is held because the bank reported a different amount. Separate several with commas. Leave blank to send none.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Logo', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'The image at the top of every email. Upload both to the media library and paste the URLs here. A reader whose mail app is in dark mode is shown the dark-mode version; everyone else sees the normal one. Left blank, the theme\'s logo is used, and with no theme logo the site name is shown instead. Colours and font come from Form Settings > Appearance.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<?php $logos = PSPGD_Emails::logo_urls(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pspgd-logo-light"><?php esc_html_e( 'Logo', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="large-text code" type="url" id="pspgd-logo-light" name="logo_light" value="<?php echo esc_attr( (string) get_option( 'pspgd_email_logo_light', '' ) ); ?>" placeholder="<?php echo esc_attr( $logos['light'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown on the white background of a normal email, so this wants to be the dark or coloured version of the logo.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pspgd-logo-dark"><?php esc_html_e( 'Logo for dark mode', 'donations-for-planetstudio-payment-gateway' ); ?></label></th>
						<td>
							<input class="large-text code" type="url" id="pspgd-logo-dark" name="logo_dark" value="<?php echo esc_attr( (string) get_option( 'pspgd_email_logo_dark', '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Shown on a dark background, so this wants to be the white version. Leave blank to use the same logo in both.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Social links', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Shown as a row of links in the footer of every email. Leave a field blank and that network is left out entirely.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
				<?php $socials = (array) get_option( 'pspgd_social_links', array() ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( PSPGD_Emails::social_networks() as $network => $label ) : ?>
						<tr>
							<th scope="row"><label for="pspgd-social-<?php echo esc_attr( $network ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input class="large-text code" type="url" id="pspgd-social-<?php echo esc_attr( $network ); ?>" name="social[<?php echo esc_attr( $network ); ?>]" value="<?php echo esc_attr( (string) ( $socials[ $network ] ?? '' ) ); ?>" placeholder="https://"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Wording', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
				<table class="widefat striped" style="max-width:1200px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Template', 'donations-for-planetstudio-payment-gateway' ); ?></th>
							<?php foreach ( $locales as $locale ) : ?>
								<th><?php echo esc_html( strtoupper( $locale ) ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $defaults as $key => $value ) : ?>
							<tr>
								<td><strong><?php echo esc_html( self::template_label( $key ) ); ?></strong><br><code><?php echo esc_html( $key ); ?></code></td>
								<?php foreach ( $locales as $locale ) : ?>
									<td><textarea class="large-text" rows="3" name="templates[<?php echo esc_attr( $locale ); ?>][<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $templates[ $locale ][ $key ] ?? '' ); ?></textarea><p class="description"><?php echo esc_html( sprintf( /* translators: %s: the built-in wording. */ __( 'Default: %s', 'donations-for-planetstudio-payment-gateway' ), PSPGD_Emails::default_template( $key, $locale ) ) ); ?></p></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save email templates', 'donations-for-planetstudio-payment-gateway' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Send yourself a test', 'donations-for-planetstudio-payment-gateway' ); ?></h2>
			<p><?php printf( /* translators: %s: email address. */ esc_html__( 'Sends one email, filled in with sample donation details, to %s. Save your changes first -- the test uses the saved wording.', 'donations-for-planetstudio-payment-gateway' ), '<code>' . esc_html( $sent_to ) . '</code>' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pspgd_send_test_email">
				<?php wp_nonce_field( 'pspgd_send_test_email' ); ?>
				<select name="template_key">
					<?php foreach ( self::testable_templates() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="locale">
					<?php foreach ( PSPGD_I18n::supported_locales() as $locale ) : ?>
						<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( strtoupper( $locale ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Send test email', 'donations-for-planetstudio-payment-gateway' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Email groups that can be previewed, keyed by template prefix.
	 *
	 * @return array<string, string>
	 */
	private static function testable_templates(): array {
		return array(
			'donation_receipt'                   => __( 'Donation receipt', 'donations-for-planetstudio-payment-gateway' ),
			'campaign_receipt'                   => __( 'Campaign donation receipt', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_receipt'                  => __( 'Recurring charge receipt', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_activation'               => __( 'Recurring donation activated', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_payment_failed'           => __( 'Recurring payment failed', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_after_failures' => __( 'Recurring donation cancelled after failures', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_campaign_closed' => __( 'Recurring donation ended with its campaign', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_setup_failed'             => __( 'Recurring setup failed', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancel'                   => __( 'Recurring donation cancelled by donor', 'donations-for-planetstudio-payment-gateway' ),
		);
	}

	/**
	 * Send one email to the logged-in administrator, filled in with sample
	 * data, so wording can be checked without waiting for a real donation.
	 */
	public static function handle_send_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}
		check_admin_referer( 'pspgd_send_test_email' );

		$template_key = sanitize_key( wp_unslash( $_POST['template_key'] ?? '' ) );
		$locale       = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );

		if ( ! array_key_exists( $template_key, self::testable_templates() ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'pspgd-email-settings', 'test_failed' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$sent = PSPGD_Emails::send_preview( $template_key, $locale, (string) wp_get_current_user()->user_email );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'pspgd-email-settings',
					$sent ? 'test_sent' : 'test_failed' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function save_form_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}
		check_admin_referer( 'pspgd_save_form_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in form_settings_from_post().
		foreach ( self::form_settings_from_post( wp_unslash( $_POST ) ) as $option => $value ) {
			update_option( $option, $value, false );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'pspgd-form-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save_email_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}
		check_admin_referer( 'pspgd_save_email_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in email_settings_from_post().
		foreach ( self::email_settings_from_post( wp_unslash( $_POST ) ) as $option => $value ) {
			update_option( $option, $value, false );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'pspgd-email-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The options the Form Settings screen saves, cleaned from what was
	 * posted, as option name => value. Reads the current settings where a
	 * field only updates part of a stored value; writes nothing.
	 *
	 * @param array<string, mixed> $post Posted fields, already unslashed.
	 * @return array<string, mixed>
	 */
	public static function form_settings_from_post( array $post ): array {
		$text = static function ( string $key, string $default = '' ) use ( $post ): string {
			return isset( $post[ $key ] ) ? ( is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '' ) : $default;
		};
		$list = static function ( string $key ) use ( $post ): array {
			return isset( $post[ $key ] ) && is_array( $post[ $key ] ) ? $post[ $key ] : array();
		};

		$options = array(
			'pspgd_test_mode'         => empty( $post['test_mode'] ) ? '0' : '1',
			'pspgd_recurring_enabled' => empty( $post['recurring_enabled'] ) ? '0' : '1',
			'pspgd_bank_id'           => sanitize_key( $text( 'bank_id' ) ),
		);

		// Everything per-currency below is read against the currencies just
		// chosen, not the ones stored before this save.
		$posted     = array_map( 'strtoupper', array_map( 'sanitize_text_field', array_filter( $list( 'currencies' ), 'is_scalar' ) ) );
		$currencies = array_values( array_intersect( PSPGD_Settings::known_currencies(), $posted ) );
		$currencies = $currencies ? $currencies : array( PSPGD_Settings::default_currency() );

		$options['pspgd_currencies']       = $currencies;
		$options['pspgd_default_currency'] = strtoupper( sanitize_text_field( $text( 'default_currency' ) ) );
		$options['pspgd_goal_currency']    = strtoupper( sanitize_text_field( $text( 'goal_currency' ) ) );

		$intervals                  = array_map( 'sanitize_key', array_filter( $list( 'intervals' ), 'is_scalar' ) );
		$options['pspgd_intervals'] = array_values( array_intersect( array( 'monthly', 'weekly' ), $intervals ) );

		// Form options.
		$options['pspgd_newsletter_optin']  = empty( $post['newsletter_optin'] ) ? '0' : '1';
		$options['pspgd_show_supporters']   = empty( $post['show_supporters'] ) ? '0' : '1';
		$options['pspgd_payment_image_url'] = esc_url_raw( $text( 'payment_image_url' ) );

		// Spam protection.
		$ip_source                         = sanitize_key( $text( 'client_ip_source' ) );
		$options['pspgd_client_ip_source'] = in_array( $ip_source, PSPGD_Security::IP_SOURCES, true ) ? $ip_source : 'remote_addr';
		$options['pspgd_turnstile']        = empty( $post['turnstile'] ) ? '0' : '1';

		$languages                  = array_filter( array_map( array( 'PSPGD_I18n', 'language_code' ), explode( ',', sanitize_text_field( $text( 'languages' ) ) ) ) );
		$options['pspgd_languages'] = array_values( array_unique( $languages ) );

		// Appearance.
		$posted_colors = $list( 'colors' );
		$colors        = array();
		foreach ( array( 'primary', 'accent', 'text' ) as $key ) {
			$color = PSPGD_Settings::sanitize_hex( is_scalar( $posted_colors[ $key ] ?? '' ) ? (string) ( $posted_colors[ $key ] ?? '' ) : '' );
			if ( '' !== $color ) {
				$colors[ $key ] = $color;
			}
		}
		$options['pspgd_colors']      = $colors;
		$options['pspgd_font_family'] = sanitize_text_field( $text( 'font_family' ) );
		$options['pspgd_radius']      = max( 0, min( 32, absint( $text( 'radius', '6' ) ) ) );

		// Wording, one column per donor language.
		$known_labels = array_keys( PSPGD_I18n::english_labels() );
		$posted       = $list( 'labels' );
		$labels       = (array) get_option( 'pspgd_form_labels', array() );
		foreach ( PSPGD_I18n::supported_locales() as $locale ) {
			$labels[ $locale ] = array();
			foreach ( $known_labels as $key ) {
				$value                     = $posted[ $locale ][ $key ] ?? '';
				$labels[ $locale ][ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}
		$options['pspgd_form_labels'] = $labels;

		// Per-currency amounts. Only the currencies shown on the screen are
		// touched, so switching a currency off and on again keeps its values.
		$posted_presets = $list( 'presets' );
		$presets        = (array) get_option( 'pspgd_amount_presets', array() );
		foreach ( $currencies as $currency ) {
			if ( ! isset( $posted_presets[ $currency ] ) || ! is_scalar( $posted_presets[ $currency ] ) ) {
				continue;
			}
			$values = array_filter( array_map( 'trim', explode( ',', (string) $posted_presets[ $currency ] ) ) );
			$values = array_values( array_filter( array_map( 'floatval', $values ), static function ( float $value ): bool { return $value > 0; } ) );
			if ( ! empty( $values ) ) {
				$presets[ $currency ] = array_slice( $values, 0, 8 );
			} else {
				unset( $presets[ $currency ] );
			}
		}
		$options['pspgd_amount_presets'] = $presets;

		foreach ( array( 'min_amounts', 'max_amounts' ) as $field ) {
			$posted_amounts = $list( $field );
			$amounts        = (array) get_option( 'pspgd_' . $field, array() );
			foreach ( $currencies as $currency ) {
				if ( ! isset( $posted_amounts[ $currency ] ) || ! is_scalar( $posted_amounts[ $currency ] ) ) {
					continue;
				}
				$value = (float) $posted_amounts[ $currency ];
				if ( $value > 0 ) {
					$amounts[ $currency ] = $value;
				} else {
					unset( $amounts[ $currency ] );
				}
			}
			$options[ 'pspgd_' . $field ] = $amounts;
		}

		$options['pspgd_event_retention_days']     = max( 0, absint( $text( 'event_retention_days', '180' ) ) );
		$options['pspgd_delete_data_on_uninstall'] = empty( $post['delete_data_on_uninstall'] ) ? '0' : '1';

		return $options;
	}

	/**
	 * The options the Emails screen saves, cleaned from what was posted, as
	 * option name => value. Writes nothing.
	 *
	 * @param array<string, mixed> $post Posted fields, already unslashed.
	 * @return array<string, mixed>
	 */
	public static function email_settings_from_post( array $post ): array {
		$text = static function ( string $key ) use ( $post ): string {
			return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		};

		$known_templates = array_keys( PSPGD_Emails::default_templates() );
		$posted          = isset( $post['templates'] ) && is_array( $post['templates'] ) ? $post['templates'] : array();
		$templates       = (array) get_option( 'pspgd_email_templates', array() );
		foreach ( PSPGD_I18n::supported_locales() as $locale ) {
			$templates[ $locale ] = array();
			foreach ( $known_templates as $key ) {
				$raw_value = $posted[ $locale ][ $key ] ?? '';
				$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

				// Only the body is rendered as markup, so only the body keeps
				// its tags -- that is what lets an editor use a line break or a
				// link. Subjects go into a mail header and headings are escaped
				// into the layout, so both stay single-line plain text.
				$templates[ $locale ][ $key ] = '_body' === substr( $key, -5 )
					? wp_kses( $raw_value, PSPGD_Emails::allowed_body_html() )
					: sanitize_text_field( $raw_value );
			}
		}

		// Only the networks the plugin knows about are stored, and each value is
		// reduced to a URL: the footer renders these as links, so an unchecked
		// value here would be an injection point in every email that goes out.
		$posted_social = isset( $post['social'] ) && is_array( $post['social'] ) ? $post['social'] : array();
		$social        = array();
		foreach ( array_keys( PSPGD_Emails::social_networks() ) as $network ) {
			$value = $posted_social[ $network ] ?? '';
			$url   = esc_url_raw( trim( is_scalar( $value ) ? (string) $value : '' ) );
			if ( '' !== $url && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				$social[ $network ] = $url;
			}
		}

		return array(
			'pspgd_email_templates'     => $templates,
			'pspgd_email_from_name'     => sanitize_text_field( $text( 'from_name' ) ),
			'pspgd_email_from_address'  => sanitize_email( $text( 'from_address' ) ),
			'pspgd_email_reply_to'      => sanitize_email( $text( 'reply_to' ) ),
			'pspgd_staff_notify_emails' => self::sanitize_email_list( sanitize_textarea_field( $text( 'staff_notify_emails' ) ) ),
			'pspgd_email_logo_light'    => esc_url_raw( $text( 'logo_light' ) ),
			'pspgd_email_logo_dark'     => esc_url_raw( $text( 'logo_dark' ) ),
			'pspgd_social_links'        => $social,
		);
	}

	/**
	 * Cancel a recurring donation from the admin panel: cancels locally and
	 * revokes the saved card (binding) at the gateway, mirroring the
	 * auto-cancel-after-failures path in PSPGD_Recurring.
	 */
	public static function handle_cancel_subscription(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		$subscription_id = absint( $_POST['subscription_id'] ?? 0 );
		check_admin_referer( 'pspgd_cancel_subscription_' . $subscription_id );

		$subscription = $subscription_id > 0 ? PSPGD_Repository::find_subscription( $subscription_id ) : null;

		if ( ! $subscription ) {
			self::redirect_to_recurring_page( 'not_found' );
		}

		$notice = 'cancelled';

		if ( ! empty( $subscription->binding_id ) ) {
			$result = PSPGD_PSPG::unbind_subscription( $subscription );
			if ( empty( $result['ok'] ) ) {
				$notice = 'cancel_failed';
			}
			PSPGD_Repository::log_event(
				'subscription',
				$subscription_id,
				'recurring_cancelled_by_admin',
				sprintf( 'Cancelled manually by %s from the admin panel.', wp_get_current_user()->user_login ),
				is_array( $result ) ? $result : array()
			);
		} else {
			PSPGD_Repository::log_event(
				'subscription',
				$subscription_id,
				'recurring_cancelled_by_admin',
				sprintf( 'Cancelled manually by %s from the admin panel (no saved card to revoke).', wp_get_current_user()->user_login )
			);
		}

		PSPGD_Repository::cancel_subscription( $subscription_id );

		self::redirect_to_recurring_page( $notice );
	}

	private static function redirect_to_recurring_page( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'pspgd-recurring',
					'pspgd_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function template_label( string $key ): string {
		$labels = array(
			'donation_receipt_subject' => __( 'Donation receipt subject', 'donations-for-planetstudio-payment-gateway' ),
			'donation_receipt_heading' => __( 'Donation receipt heading', 'donations-for-planetstudio-payment-gateway' ),
			'donation_receipt_body'    => __( 'Donation receipt body', 'donations-for-planetstudio-payment-gateway' ),
			'campaign_receipt_subject' => __( 'Campaign receipt subject', 'donations-for-planetstudio-payment-gateway' ),
			'campaign_receipt_heading' => __( 'Campaign receipt heading', 'donations-for-planetstudio-payment-gateway' ),
			'campaign_receipt_body'    => __( 'Campaign receipt body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_receipt_subject' => __( 'Recurring charge receipt subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_receipt_heading' => __( 'Recurring charge receipt heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_receipt_body'    => __( 'Recurring charge receipt body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_activation_subject' => __( 'Recurring activation subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_activation_heading' => __( 'Recurring activation heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_activation_body'    => __( 'Recurring activation body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_setup_failed_subject' => __( 'Recurring setup failed subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_setup_failed_heading' => __( 'Recurring setup failed heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_setup_failed_body'    => __( 'Recurring setup failed body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancel_subject'  => __( 'Recurring cancellation subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancel_heading'  => __( 'Recurring cancellation heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancel_body'     => __( 'Recurring cancellation body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancel_label'    => __( 'Cancel link label', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_payment_failed_subject'           => __( 'Recurring payment failed subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_payment_failed_heading'           => __( 'Recurring payment failed heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_payment_failed_body'              => __( 'Recurring payment failed body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_after_failures_subject' => __( 'Recurring cancelled after failures subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_after_failures_heading' => __( 'Recurring cancelled after failures heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_after_failures_body'    => __( 'Recurring cancelled after failures body', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_campaign_closed_subject' => __( 'Recurring ended with campaign subject', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_campaign_closed_heading' => __( 'Recurring ended with campaign heading', 'donations-for-planetstudio-payment-gateway' ),
			'recurring_cancelled_campaign_closed_body'    => __( 'Recurring ended with campaign body', 'donations-for-planetstudio-payment-gateway' ),
		);

		return $labels[ $key ] ?? $key;
	}
}
