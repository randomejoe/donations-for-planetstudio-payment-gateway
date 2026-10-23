<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Emails screen: the templates in each donor language, the sender, the
 * staff notification addresses, and test sends.
 */
final class PSPGD_Admin_Email_Settings {

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
}
