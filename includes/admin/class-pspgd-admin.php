<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_Admin {

	/** Rows per page on the donation log and the recurring list. */
	public const PER_PAGE = 20;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_pspgd_save_form_settings', array( 'PSPGD_Admin_Form_Settings', 'save_form_settings' ) );
		add_action( 'admin_post_pspgd_save_email_settings', array( 'PSPGD_Admin_Email_Settings', 'save_email_settings' ) );
		add_action( 'admin_post_pspgd_cancel_subscription', array( 'PSPGD_Admin_Recurring', 'handle_cancel_subscription' ) );
		add_action( 'admin_post_pspgd_send_test_email', array( 'PSPGD_Admin_Email_Settings', 'handle_send_test_email' ) );
		add_action( 'admin_post_pspgd_export_csv', array( 'PSPGD_Admin_Donations', 'handle_export_csv' ) );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The admin page being viewed; it only decides whether to show the notice.
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
			PSPGD_Capability::MANAGE_DONATIONS,
			'pspgd',
			array( 'PSPGD_Admin_Donations', 'render_donations_page' ),
			'dashicons-heart',
			56
		);
		add_submenu_page(
			'pspgd',
			__( 'Donation Log', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Donation Log', 'donations-for-planetstudio-payment-gateway' ),
			PSPGD_Capability::MANAGE_DONATIONS,
			'pspgd',
			array( 'PSPGD_Admin_Donations', 'render_donations_page' )
		);
		add_submenu_page(
			'pspgd',
			__( 'Recurring Donations', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Recurring Donations', 'donations-for-planetstudio-payment-gateway' ),
			PSPGD_Capability::MANAGE_DONATIONS,
			'pspgd-recurring',
			array( 'PSPGD_Admin_Recurring', 'render_recurring_page' )
		);
		add_submenu_page(
			'pspgd',
			__( 'Donation Form Settings', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Form Settings', 'donations-for-planetstudio-payment-gateway' ),
			'manage_options',
			'pspgd-form-settings',
			array( 'PSPGD_Admin_Form_Settings', 'render_form_settings_page' )
		);
		add_submenu_page(
			'pspgd',
			__( 'Email Templates', 'donations-for-planetstudio-payment-gateway' ),
			__( 'Emails', 'donations-for-planetstudio-payment-gateway' ),
			'manage_options',
			'pspgd-email-settings',
			array( 'PSPGD_Admin_Email_Settings', 'render_email_settings_page' )
		);
	}

	/**
	 * Minimal previous/next + page-count pager. Preserves the other
	 * table's page query arg so paging one table doesn't reset the other.
	 */
	public static function render_pager( string $query_arg, int $current_page, int $total_pages, int $total_items ): void {
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
}
