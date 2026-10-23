<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Recurring Donations screen, and cancelling a recurring donation from
 * it.
 */
final class PSPGD_Admin_Recurring {

	/**
	 * "Recurring Donations" panel: lists subscriptions with their current
	 * binding status and lets an admin cancel a subscription or trigger an
	 * immediate charge (useful to verify a saved card / binding actually
	 * works right after setup, without waiting for the next scheduled run).
	 */
	public static function render_recurring_page(): void {
		if ( ! current_user_can( PSPGD_Capability::MANAGE_DONATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donations-for-planetstudio-payment-gateway' ), 403 );
		}

		// A subscription row exists before the donor reaches the bank, so a
		// pending one is an unfinished payment attempt rather than a
		// recurring donation. Hidden unless explicitly asked for.
		$show_incomplete = ! empty( $_GET['show_incomplete'] );

		$sub_total = PSPGD_Repository::count_subscriptions( $show_incomplete );
		$sub_pages = max( 1, (int) ceil( $sub_total / PSPGD_Admin::PER_PAGE ) );
		$sub_page  = min( $sub_pages, max( 1, absint( $_GET['s_page'] ?? 1 ) ) );
		$subscriptions   = PSPGD_Repository::list_subscriptions( PSPGD_Admin::PER_PAGE, ( $sub_page - 1 ) * PSPGD_Admin::PER_PAGE, $show_incomplete );

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
			<?php PSPGD_Admin::render_pager( 's_page', $sub_page, $sub_pages, $sub_total ); ?>
		</div>
		<?php
	}

	/**
	 * Cancel a recurring donation from the admin panel: cancels locally and
	 * revokes the saved card (binding) at the gateway, mirroring the
	 * auto-cancel-after-failures path in PSPGD_Recurring.
	 */
	public static function handle_cancel_subscription(): void {
		if ( ! current_user_can( PSPGD_Capability::MANAGE_DONATIONS ) ) {
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
}
