<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Form Settings screen and what it saves.
 */
final class PSPGD_Admin_Form_Settings {

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
}
