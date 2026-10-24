<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Donation Log screen: the figures above it, its filters and pages, and
 * the CSV export of exactly the rows on screen.
 */
final class PSPGD_Admin_Donations {

	public static function render_donations_page(): void {
		if ( ! current_user_can( PSPGD_Capability::MANAGE_DONATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		$filters        = self::donation_filters();
		$donation_total = PSPGD_Repository::count_donations( $filters );
		$donation_pages = max( 1, (int) ceil( $donation_total / PSPGD_Admin::PER_PAGE ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A page number in a link; it only picks which rows are shown.
		$donation_page  = min( $donation_pages, max( 1, absint( $_GET['d_page'] ?? 1 ) ) );
		$donations      = PSPGD_Repository::list_donations( PSPGD_Admin::PER_PAGE, ( $donation_page - 1 ) * PSPGD_Admin::PER_PAGE, $filters );

		$event_total = PSPGD_Repository::count_events();
		$event_pages = max( 1, (int) ceil( $event_total / PSPGD_Admin::PER_PAGE ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A page number in a link; it only picks which rows are shown.
		$event_page  = min( $event_pages, max( 1, absint( $_GET['e_page'] ?? 1 ) ) );
		$events      = PSPGD_Repository::list_recent_events( PSPGD_Admin::PER_PAGE, ( $event_page - 1 ) * PSPGD_Admin::PER_PAGE );
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
			<?php PSPGD_Admin::render_pager( 'd_page', $donation_page, $donation_pages, $donation_total ); ?>

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
			<?php PSPGD_Admin::render_pager( 'e_page', $event_page, $event_pages, $event_total ); ?>
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The log's own GET filter form; filters only narrow what is shown.
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
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
		if ( ! current_user_can( PSPGD_Capability::MANAGE_DONATIONS ) ) {
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
}
