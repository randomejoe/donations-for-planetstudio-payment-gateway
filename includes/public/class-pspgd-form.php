<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The donation form: rendering, the tokens it fetches, and the submission
 * handler that validates a donation and hands the donor to the bank.
 */
final class PSPGD_Form {

	/** How long a returned error and the donor's typed values are kept. */
	private const ERROR_TTL = 10 * MINUTE_IN_SECONDS;

	/** A form submitted faster than this was not filled in by a person. */
	private const MIN_FILL_SECONDS = 3;

	/** After this, the rendered form is stale and its timing token expires. */
	private const FORM_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Turnstile is loaded from Cloudflare, as Cloudflare requires. pspgd.js
	 * renders it when the donor reaches the review step, and the onload
	 * callback covers a donor who gets there before the script has loaded.
	 * Cloudflare does not allow the script to be bundled or self-hosted; see
	 * readme, External services.
	 */
	private const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=pspgdTurnstileReady'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent

	/** @var bool Whether the scripts, styles and their data have been enqueued on this request. */
	private static $enqueued = false;

	public static function init(): void {
		add_shortcode( 'pspgd_donation_form', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_post_nopriv_pspgd_donation_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_pspgd_donation_submit', array( __CLASS__, 'handle_submit' ) );

		// The nonce and the timing token are fetched by the browser instead of
		// being printed into the page, so they survive any page cache.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_ajax_nopriv_pspgd_donation_token', array( __CLASS__, 'ajax_form_token' ) );
		add_action( 'wp_ajax_pspgd_donation_token', array( __CLASS__, 'ajax_form_token' ) );

		// The page a failed submission returns to carries the donor's own name
		// and email, so it must not be stored by a cache anyone else reads.
		add_action( 'send_headers', array( __CLASS__, 'no_store_recovery_page' ) );
	}

	// -----------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------

	/**
	 * Register the form's script and stylesheet, and enqueue them straight
	 * away on pages known to show a form: a campaign, or a post whose content
	 * has the shortcode. Anywhere else (a widget, a page builder) the
	 * shortcode enqueues them itself when it renders, so no other page of the
	 * site loads them.
	 */
	public static function register_assets(): void {
		self::register_assets_only();

		$post  = is_singular() ? get_post() : null;
		$needs = is_singular( 'pspgd_campaign' ) || ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'pspgd_donation_form' ) );

		/**
		 * Filters whether the donation form's script and stylesheet are loaded
		 * in the page head. The shortcode loads them anyway when it renders;
		 * this only decides whether the stylesheet arrives early.
		 *
		 * @param bool $needs Whether this page is known to show a form.
		 */
		if ( (bool) apply_filters( 'pspgd_load_assets', $needs ) ) {
			self::enqueue_assets();
		}
	}

	/** Enqueue the form's assets and the data its script reads. Safe to call more than once. */
	public static function enqueue_assets(): void {
		if ( self::$enqueued ) {
			return;
		}

		if ( ! wp_script_is( 'pspgd', 'registered' ) ) {
			self::register_assets_only();
		}

		self::$enqueued = true;

		wp_enqueue_style( 'pspgd' );
		wp_enqueue_script( 'pspgd' );
		wp_add_inline_style( 'pspgd', self::brand_css() );
		wp_localize_script(
			'pspgd',
			'pspgdDonation',
			array(
				'tokenEndpoint'  => rest_url( 'pspgd/v1/form-token' ),
				'ajaxEndpoint'   => admin_url( 'admin-ajax.php' ),
				'errorToken'     => self::translate( 'error_token', 'We could not prepare the donation form. Please reload the page and try again.' ),
				'errorTurnstile' => self::translate( 'error_turnstile', 'Please complete the security check before donating.' ),
				'oneTimeLabel'   => self::translate( 'one_time', 'One-time' ),
				'weeklyLabel'    => self::translate( 'weekly', 'Weekly' ),
				'monthlyLabel'   => self::translate( 'monthly', 'Monthly' ),
				'errorAmount'    => self::translate( 'error_amount', 'Choose or enter a donation amount.' ),
				'errorMinimum'   => self::translate( 'error_min', 'The smallest donation we can accept is %s.' ),
				'errorMaximum'   => self::translate( 'error_max', 'The largest donation we can accept online is %s.' ),
				'zeroDecimal'    => array_values(
					array_filter(
						PSPGD_PSPG::allowed_currencies(),
						static function ( string $currency ): bool {
							return 0 === PSPGD_I18n::display_decimals( $currency );
						}
					)
				),
			)
		);
	}

	/** Register the handles; also used by a shortcode rendered before wp_enqueue_scripts ran. */
	private static function register_assets_only(): void {
		wp_register_style( 'pspgd', PSPGD_URL . 'assets/css/pspgd.css', array(), PSPGD_VERSION );
		wp_register_script( 'pspgd', PSPGD_URL . 'assets/js/pspgd.js', array(), PSPGD_VERSION, true );
	}

	/**
	 * The brand settings as CSS custom properties. Everything in the
	 * stylesheet reads these, so a theme can also override any of them with
	 * a more specific selector without touching the plugin.
	 */
	public static function brand_css(): string {
		$colors = PSPGD_Settings::colors();

		return sprintf(
			'.pspgd-donation-form,.pspgd-campaign{--pspgd-primary:%1$s;--pspgd-accent:%2$s;--pspgd-ink:%3$s;--pspgd-font:%4$s;--pspgd-radius:%5$dpx;}',
			esc_attr( $colors['primary'] ),
			esc_attr( $colors['accent'] ),
			esc_attr( $colors['text'] ),
			esc_attr( PSPGD_Settings::font_family() ),
			PSPGD_Settings::radius()
		);
	}

	// -----------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------

	public static function render_shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'privacy_policy_url' => get_privacy_policy_url(),
				'campaign_id'        => 0,
			),
			(array) $atts,
			'pspgd_donation_form'
		);

		$campaign_status = PSPGD_Campaigns::status( (int) $atts['campaign_id'] );
		if ( empty( $campaign_status['open'] ) ) {
			return sprintf(
				'<p class="pspgd-donation-form__closed">%s</p>',
				esc_html( PSPGD_Campaigns::closed_notice( (string) $campaign_status['reason'] ) )
			);
		}

		self::enqueue_assets();

		$turnstile = PSPGD_Security::turnstile_active();
		if ( $turnstile ) {
			// Cloudflare serves Turnstile from one unversioned address and
			// asks sites not to copy or pin it.
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_enqueue_script( 'pspgd-turnstile', self::TURNSTILE_SCRIPT, array(), null, true );
		}

		$amount_presets    = self::amount_presets();
		$currencies        = PSPGD_PSPG::allowed_currencies();
		$default_currency  = PSPGD_Settings::default_currency();
		$intervals         = PSPGD_Settings::intervals();
		$recurring_enabled = self::recurring_enabled();
		$payment_image     = PSPGD_Settings::payment_image_url();
		$returned          = self::returned_error();
		$values            = $returned['values'];
		$t                 = array( __CLASS__, 'translate' );
		$turnstile_keys    = $turnstile ? PSPGD_Security::turnstile_keys() : array();

		ob_start();
		?>
		<form class="pspgd-donation-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-amount-presets="<?php echo esc_attr( wp_json_encode( $amount_presets ) ); ?>" data-min-amounts="<?php echo esc_attr( wp_json_encode( PSPGD_I18n::min_amounts() ) ); ?>" data-max-amounts="<?php echo esc_attr( wp_json_encode( PSPGD_I18n::max_amounts() ) ); ?>" data-campaign-support-label="<?php echo esc_attr( $atts['campaign_id'] ? sprintf( PSPGD_I18n::t( 'campaign_support', 'Support %s' ), get_the_title( (int) $atts['campaign_id'] ) ) : '' ); ?>" data-recurring-enabled="<?php echo esc_attr( $recurring_enabled ? '1' : '0' ); ?>" data-restore-amount="<?php echo esc_attr( (string) ( $values['amount'] ?? '' ) ); ?>" data-restore-currency="<?php echo esc_attr( (string) ( $values['currency'] ?? '' ) ); ?>" data-restore-type="<?php echo esc_attr( (string) ( $values['donation_type'] ?? '' ) ); ?>">
			<input type="hidden" name="action" value="pspgd_donation_submit">
			<input type="hidden" name="amount" value="">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) absint( $atts['campaign_id'] ) ); ?>">
			<?php // Resolved here, while the donor is on a page whose language is known. The form posts to admin-post.php, where Polylang has no page to read it from. ?>
			<input type="hidden" name="donor_language" value="<?php echo esc_attr( PSPGD_I18n::current_locale() ); ?>">
			<?php
			// Deliberately empty. A nonce and a signed render timestamp printed
			// here would be frozen into whatever copy of this page a cache is
			// holding -- the host's page cache, a CDN, or the donor's own
			// browser -- and every donor would submit the same stale pair. By
			// the time that copy is a day old the nonce no longer verifies and
			// the first attempt is always refused. Both are fetched from an
			// uncacheable endpoint once this page is actually on screen, so
			// they are as fresh as the donor is.
			?>
			<input type="hidden" name="pspgd_form_time" value="">
			<input type="hidden" name="pspgd_donation_nonce" value="">
			<?php wp_referer_field(); ?>

			<?php // Left empty by a person, filled in by a bot that submits every field it finds. ?>
			<div class="pspgd-donation-form__hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
				<label><?php esc_html_e( 'Leave this field empty', 'donations-for-planetstudio-payment-gateway' ); ?><input type="text" name="pspgd_website" value="" tabindex="-1" autocomplete="off"></label>
			</div>

			<div class="pspgd-donation-form__error" data-role="error" role="alert" aria-live="assertive" <?php echo '' === $returned['message'] ? 'hidden' : ''; ?>><?php echo esc_html( $returned['message'] ); ?></div>

			<div class="pspgd-donation-form__progress" aria-label="<?php esc_attr_e( 'Donation steps', 'donations-for-planetstudio-payment-gateway' ); ?>">
				<span class="is-active" data-step-indicator="1">1. <?php echo esc_html( $t( 'amount', 'Amount' ) ); ?></span>
				<span data-step-indicator="2">2. <?php echo esc_html( $t( 'details', 'Your details' ) ); ?></span>
				<span data-step-indicator="3">3. <?php echo esc_html( $t( 'review', 'Review' ) ); ?></span>
			</div>

			<section class="pspgd-donation-form__step" data-step-panel="1">
				<div class="pspgd-donation-form__intro">
					<p class="pspgd-donation-form__eyebrow"><?php echo esc_html( $t( 'support', 'Support us' ) ); ?></p>
					<h2><?php echo esc_html( $t( 'choose', 'Choose your donation' ) ); ?></h2>
				</div>

				<?php if ( $recurring_enabled ) : ?>
					<fieldset class="pspgd-donation-form__choice-group">
						<legend><?php echo esc_html( $t( 'donation_type', 'Donation frequency' ) ); ?></legend>
						<div class="pspgd-donation-form__segmented">
							<label><input type="radio" name="donation_type" value="one_time" checked><span><?php echo esc_html( $t( 'one_time', 'One-time' ) ); ?></span></label>
							<?php foreach ( $intervals as $interval ) : ?>
								<label><input type="radio" name="donation_type" value="<?php echo esc_attr( $interval ); ?>"><span><?php echo esc_html( 'weekly' === $interval ? $t( 'weekly', 'Weekly' ) : $t( 'monthly', 'Monthly' ) ); ?></span></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php else : ?>
					<input type="hidden" name="donation_type" value="one_time">
				<?php endif; ?>

				<?php if ( count( $currencies ) > 1 ) : ?>
					<fieldset class="pspgd-donation-form__choice-group">
						<legend><?php echo esc_html( $t( 'currency', 'Currency' ) ); ?></legend>
						<div class="pspgd-donation-form__segmented">
							<?php foreach ( $currencies as $currency ) : ?><label><input type="radio" name="currency" value="<?php echo esc_attr( $currency ); ?>" <?php checked( $default_currency, $currency ); ?>><span><?php echo esc_html( PSPGD_I18n::currency_symbol( $currency ) ); ?></span></label><?php endforeach; ?>
						</div>
					</fieldset>
				<?php else : ?>
					<input type="hidden" name="currency" value="<?php echo esc_attr( $default_currency ); ?>">
				<?php endif; ?>

				<div class="pspgd-donation-form__amounts" data-role="amount-buttons"></div>
				<label class="pspgd-donation-form__field">
					<span><?php echo esc_html( $t( 'custom_amount', 'Or enter a custom amount' ) ); ?></span>
					<input type="number" data-role="custom-amount" min="1" step="0.01" inputmode="decimal" placeholder="0.00">
				</label>
				<div class="pspgd-donation-form__actions"><button type="button" data-action="next"><?php echo esc_html( $t( 'continue', 'Continue' ) ); ?></button></div>
			</section>

			<section class="pspgd-donation-form__step" data-step-panel="2" hidden>
				<div class="pspgd-donation-form__intro"><h2><?php echo esc_html( $t( 'details_title', 'A few details' ) ); ?></h2></div>
				<div class="pspgd-donation-form__field-grid">
					<label class="pspgd-donation-form__field"><span><?php echo esc_html( $t( 'first_name', 'First name' ) ); ?></span><input type="text" name="first_name" maxlength="100" autocomplete="given-name" value="<?php echo esc_attr( (string) ( $values['first_name'] ?? '' ) ); ?>" required></label>
					<label class="pspgd-donation-form__field"><span><?php echo esc_html( $t( 'last_name', 'Last name' ) ); ?></span><input type="text" name="last_name" maxlength="100" autocomplete="family-name" value="<?php echo esc_attr( (string) ( $values['last_name'] ?? '' ) ); ?>" required></label>
				</div>
				<label class="pspgd-donation-form__field"><span><?php echo esc_html( $t( 'email', 'Email' ) ); ?></span><input type="email" name="email" maxlength="190" autocomplete="email" value="<?php echo esc_attr( (string) ( $values['email'] ?? '' ) ); ?>" required></label>
				<?php if ( PSPGD_Settings::newsletter_optin() ) : ?>
					<?php // Read straight from the POST by Mailchimp for WordPress's own forms integration, and passed to the pspgd_newsletter_optin action for anything else. ?>
					<label class="pspgd-donation-form__consent"><input type="checkbox" name="mc4wp-subscribe" value="1"><span><?php echo esc_html( $t( 'newsletter_subscribe', 'Subscribe to our newsletter.' ) ); ?></span></label>
				<?php endif; ?>
				<label class="pspgd-donation-form__consent"><input type="checkbox" name="consent" value="1" required><span><?php echo esc_html( $t( 'consent', 'I agree to payment processing for this donation.' ) ); ?> <?php if ( $atts['privacy_policy_url'] ) : ?><a href="<?php echo esc_url( $atts['privacy_policy_url'] ); ?>"><?php echo esc_html( $t( 'privacy', 'Privacy policy' ) ); ?></a><?php endif; ?></span></label>
				<label class="pspgd-donation-form__consent pspgd-donation-form__anonymous"><input type="checkbox" name="is_anonymous" value="1"><span><?php echo esc_html( $t( 'anonymous', 'Keep this donation anonymous' ) ); ?></span></label>
				<div class="pspgd-donation-form__actions"><button type="button" class="pspgd-donation-form__back" data-action="previous"><?php echo esc_html( $t( 'back', 'Back' ) ); ?></button><button type="button" data-action="next"><?php echo esc_html( $t( 'review_donation', 'Review donation' ) ); ?></button></div>
			</section>

			<section class="pspgd-donation-form__step" data-step-panel="3" hidden>
				<p class="pspgd-donation-form__eyebrow" data-role="review-eyebrow"></p>
				<div class="pspgd-donation-form__review"><span><?php echo esc_html( $t( 'your_donation', 'Your donation' ) ); ?></span><strong data-review="amount"></strong><span data-review="type"></span></div>
				<?php if ( '' !== $payment_image ) : ?>
					<img class="pspgd-donation-form__payment-image" src="<?php echo esc_url( $payment_image ); ?>" alt="">
				<?php endif; ?>
				<?php if ( $turnstile ) : ?>
					<div class="pspgd-donation-form__turnstile" data-role="turnstile" data-sitekey="<?php echo esc_attr( (string) $turnstile_keys['site_key'] ); ?>" data-theme="<?php echo esc_attr( (string) $turnstile_keys['theme'] ); ?>"></div>
				<?php endif; ?>
				<div class="pspgd-donation-form__actions"><button type="button" class="pspgd-donation-form__back" data-action="previous"><?php echo esc_html( $t( 'back', 'Back' ) ); ?></button><button type="submit"><?php echo esc_html( $t( 'donate', 'Donate' ) ); ?></button></div>
			</section>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	private static function translate( string $key, string $fallback, ?string $locale = null ): string {
		return PSPGD_I18n::t( $key, $fallback, $locale );
	}

	// -----------------------------------------------------------------
	// Submission
	// -----------------------------------------------------------------

	/**
	 * Validate a donation and send the donor to the bank.
	 *
	 * Every refusal goes back to the form, in the language the donor filled
	 * it in, with what they typed still there. Nothing is written and the
	 * bank is not contacted until every check has passed.
	 */
	public static function handle_submit(): void {
		$language = self::submitted_language();

		// The nonce is fetched by the browser from an uncacheable endpoint;
		// see register_rest_routes(). It is the first thing checked.
		if ( ! isset( $_POST['pspgd_donation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pspgd_donation_nonce'] ) ), 'pspgd_donation_submit' ) ) {
			self::fail( self::translate( 'error_expired', 'The donation form expired while it was open. Please check your details and try again.', $language ) );
		}

		$address = PSPGD_Security::client_ip();

		// A generous ceiling on submissions of any kind, so a flood of
		// invalid posts from one address is cut off before it costs more
		// than a transient read.
		if ( ! PSPGD_Security::within_rate_limit( $address, 'submit' ) ) {
			self::fail( self::translate( 'error_rate_limit', 'Too many donation attempts from this connection. Please wait a few minutes and try again.', $language ) );
		}

		// Honeypot and fill time, before anything is written or the bank is
		// contacted.
		self::reject_bots( $language );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in validate_submission().
		$check = self::validate_submission( wp_unslash( $_POST ), $language );
		if ( empty( $check['ok'] ) ) {
			self::fail( (string) $check['message'] );
		}

		$amount        = (float) $check['amount'];
		$currency      = (string) $check['currency'];
		$bank_id       = (string) $check['bank_id'];
		$donation_type = (string) $check['donation_type'];
		$campaign_id   = (int) $check['campaign_id'];
		$first_name    = (string) $check['first_name'];
		$last_name     = (string) $check['last_name'];
		$email         = (string) $check['email'];
		$is_anonymous  = (bool) $check['is_anonymous'];
		$test_mode     = (bool) $check['test_mode'];

		if ( PSPGD_Security::turnstile_active() ) {
			$turnstile_token = sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) );
			if ( ! PSPGD_Security::verify_turnstile( $turnstile_token, $address ) ) {
				self::fail( self::translate( 'error_turnstile', 'Please complete the security check before donating.', $language ) );
			}
		}

		// The strict limit counts only attempts that passed every check and
		// are about to reach the bank: a person correcting a typo five times
		// is not five donations, but a script testing card numbers is.
		if ( ! PSPGD_Security::within_rate_limit( $address, 'attempt' ) ) {
			self::fail( self::translate( 'error_rate_limit', 'Too many donation attempts from this connection. Please wait a few minutes and try again.', $language ) );
		}

		$donor_id = PSPGD_Repository::upsert_donor(
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $email,
				'language'   => $language,
			)
		);

		if ( $donor_id <= 0 ) {
			self::fail( self::translate( 'error_email', 'Please enter a valid email address so we can send your receipt.', $language ) );
		}

		// The donation is registered and charged in whatever currency the
		// donor selected -- no conversion.
		$subscription_id = 0;
		if ( 'one_time' !== $donation_type ) {
			$subscription_id = PSPGD_Repository::create_subscription(
				array(
					'donor_id'     => $donor_id,
					'campaign_id'  => $campaign_id,
					'email'        => $email,
					'amount'       => number_format( $amount, 2, '.', '' ),
					'currency'     => $currency,
					'bank_id'      => $bank_id,
					'interval_key' => $donation_type,
					'language'     => $language,
					'is_anonymous' => $is_anonymous,
					// A test-mode signup gets a simulated binding the bank has
					// never seen, so it must never enter the live charge schedule.
					'is_test'      => $test_mode,
				)
			);
		}

		$donation_id = PSPGD_Repository::create_donation(
			array(
				'donor_id'        => $donor_id,
				'subscription_id' => $subscription_id,
				'campaign_id'     => $campaign_id,
				'bank_id'         => $bank_id,
				'amount'          => number_format( $amount, 2, '.', '' ),
				'currency'        => $currency,
				'type'            => 'one_time' === $donation_type ? 'one_time' : 'recurring_initial',
				'status'          => 'pending',
				'source_id'       => wp_generate_uuid4(),
				'language'        => $language,
				'is_anonymous'    => $is_anonymous,
				'is_test'         => $test_mode,
			)
		);

		if ( $donation_id <= 0 ) {
			if ( $subscription_id > 0 ) {
				PSPGD_Repository::delete_pending_subscription( $subscription_id );
			}
			self::fail( self::translate( 'error_start', 'We could not start your donation. Please try again in a moment.', $language ) );
		}

		if ( PSPGD_Settings::newsletter_optin() && ! empty( $_POST['mc4wp-subscribe'] ) ) {
			/**
			 * Fires when a donor ticks the newsletter box, for a newsletter or
			 * CRM plugin to pick up. Mailchimp for WordPress reads the same
			 * box from the request on its own.
			 *
			 * @param array{email: string, first_name: string, last_name: string, language: string, campaign_id: int, donation_id: int} $donor
			 */
			do_action(
				'pspgd_newsletter_optin',
				array(
					'email'       => $email,
					'first_name'  => $first_name,
					'last_name'   => $last_name,
					'language'    => $language,
					'campaign_id' => $campaign_id,
					'donation_id' => $donation_id,
				)
			);
		}

		$result = PSPGD_PSPG::create_initial_payment(
			$donation_id,
			array(
				'bank_id'         => $bank_id,
				'amount'          => number_format( $amount, 2, '.', '' ),
				'currency'        => $currency,
				'is_recurring'    => 'one_time' !== $donation_type,
				'subscription_id' => $subscription_id,
			)
		);

		$redirect_url = self::extract_redirect_url( $result );
		if ( ! empty( $result['ok'] ) && $redirect_url ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}

		PSPGD_Payments::mark_failed( $donation_id );
		self::fail( self::bank_refusal_message( $result, $language ) );
	}

	/**
	 * Check a submitted donation against the site's settings, before anything
	 * is written or the bank is contacted.
	 *
	 * Reads settings and the campaign, and nothing else: no request, no
	 * redirect. handle_submit() turns a refusal into fail(), after the nonce,
	 * the throttle and the bot checks; the tests call it directly.
	 *
	 * @param array<string, mixed> $post Posted fields, already unslashed.
	 * @return array<string, mixed> ok=true with the cleaned fields, or
	 *                              ok=false with an error key and the
	 *                              message for the donor.
	 */
	public static function validate_submission( array $post, string $language ): array {
		$field = static function ( string $key ) use ( $post ): string {
			return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		};
		$refuse = static function ( string $error, string $message ): array {
			return array(
				'ok'      => false,
				'error'   => $error,
				'message' => $message,
			);
		};

		$amount        = self::normalize_amount( sanitize_text_field( $field( 'amount' ) ) );
		$currency      = strtoupper( sanitize_text_field( $field( 'currency' ) ) );
		$bank_id       = PSPGD_PSPG::bank_id();
		$donation_type = sanitize_key( isset( $post['donation_type'] ) ? $field( 'donation_type' ) : 'one_time' );
		$campaign_id   = absint( $field( 'campaign_id' ) );
		$test_mode     = PSPGD_PSPG::donations_test_mode();

		if ( $campaign_id > 0 ) {
			$campaign = get_post( $campaign_id );
			if ( ! $campaign || 'pspgd_campaign' !== $campaign->post_type || 'trash' === $campaign->post_status ) {
				return $refuse( 'error_campaign_closed', self::translate( 'error_campaign_closed', 'This campaign is no longer accepting donations.', $language ) );
			}

			// A campaign can close between the page rendering and the donor
			// finishing the form, so the deadline and the goal are checked
			// again here rather than trusted from the posted page.
			$campaign_status = PSPGD_Campaigns::status( $campaign_id );
			if ( empty( $campaign_status['open'] ) ) {
				return $refuse( 'error_campaign_closed', PSPGD_Campaigns::closed_notice( (string) $campaign_status['reason'], $language ) );
			}
		}

		if ( $amount <= 0 || ! in_array( $currency, PSPGD_PSPG::allowed_currencies(), true ) ) {
			return $refuse( 'error_amount', self::translate( 'error_amount', 'Choose or enter a donation amount.', $language ) );
		}

		$min_amounts = PSPGD_I18n::min_amounts();
		$min_amount  = isset( $min_amounts[ $currency ] ) ? (float) $min_amounts[ $currency ] : 0.0;
		if ( $min_amount > 0 && $amount < $min_amount ) {
			return $refuse( 'error_min', sprintf( self::translate( 'error_min', 'The smallest donation we can accept is %s.', $language ), PSPGD_I18n::format_amount( $min_amount, $currency ) ) );
		}

		$max_amounts = PSPGD_I18n::max_amounts();
		$max_amount  = isset( $max_amounts[ $currency ] ) ? (float) $max_amounts[ $currency ] : 0.0;
		if ( $max_amount > 0 && $amount > $max_amount ) {
			return $refuse( 'error_max', sprintf( self::translate( 'error_max', 'The largest donation we can accept online is %s.', $language ), PSPGD_I18n::format_amount( $max_amount, $currency ) ) );
		}

		$email = sanitize_email( $field( 'email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return $refuse( 'error_email', self::translate( 'error_email', 'Please enter a valid email address so we can send your receipt.', $language ) );
		}

		if ( '' === $field( 'consent' ) || '0' === $field( 'consent' ) ) {
			return $refuse( 'error_consent', self::translate( 'error_consent', 'Please agree to payment processing before continuing.', $language ) );
		}

		if ( ! $test_mode && '' === $bank_id ) {
			return $refuse( 'error_not_ready', self::translate( 'error_not_ready', 'Online donations are not set up yet. Please try again later.', $language ) );
		}

		if ( ! $test_mode && ! PSPGD_PSPG::bank_supports_currency( $bank_id, $currency ) ) {
			return $refuse( 'error_currency', self::translate( 'error_currency', 'Card payment is not available for the selected currency right now.', $language ) );
		}

		if ( ! in_array( $donation_type, array_merge( array( 'one_time' ), PSPGD_Settings::intervals() ), true ) ) {
			return $refuse( 'error_frequency', self::translate( 'error_frequency', 'Please choose a donation frequency.', $language ) );
		}

		if ( 'one_time' !== $donation_type && ( ! self::recurring_enabled() || ( ! $test_mode && ! PSPGD_PSPG::bank_supports_recurring( $bank_id ) ) ) ) {
			return $refuse( 'error_recurring', self::translate( 'error_recurring', 'Recurring donations are not available right now. Please choose a one-time donation.', $language ) );
		}

		return array(
			'ok'            => true,
			'amount'        => $amount,
			'currency'      => $currency,
			'bank_id'       => $bank_id,
			'donation_type' => $donation_type,
			'campaign_id'   => $campaign_id,
			'first_name'    => sanitize_text_field( $field( 'first_name' ) ),
			'last_name'     => sanitize_text_field( $field( 'last_name' ) ),
			'email'         => $email,
			'is_anonymous'  => '' !== $field( 'is_anonymous' ) && '0' !== $field( 'is_anonymous' ),
			'test_mode'     => $test_mode,
		);
	}

	/**
	 * What the donor is told when the bank would not register the payment.
	 *
	 * Donors get the plain message in their own language: the bank's own
	 * error is in English, names configuration a donor can do nothing about,
	 * and is nobody's business but the site's. Someone who manages donations
	 * and is testing the form while logged in sees it as well, so a missing
	 * credential is found on the first test.
	 *
	 * @param array<string, mixed> $result
	 */
	private static function bank_refusal_message( array $result, string $language ): string {
		$message = self::translate( 'error_generic', 'Your donation could not be started. Please check the form and try again.', $language );
		$detail  = trim( wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ) );

		if ( '' !== $detail && current_user_can( PSPGD_Capability::MANAGE_DONATIONS ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the error message the payment gateway returned. */
				__( '(Shown to staff only: the gateway said "%s".)', 'donations-for-planetstudio-payment-gateway' ),
				$detail
			);
		}

		return $message;
	}

	// -----------------------------------------------------------------
	// Form tokens, served outside the page cache
	// -----------------------------------------------------------------

	/**
	 * The REST route the form asks for its tokens.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'pspgd/v1',
			'/form-token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_form_token' ),
				// Public on purpose: anonymous donors need a fresh nonce, and
				// the nonce only lets them submit the same public form.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A fresh nonce and render timestamp.
	 *
	 * @return array{nonce:string,form_time:string}
	 */
	public static function form_token(): array {
		return array(
			'nonce'     => wp_create_nonce( 'pspgd_donation_submit' ),
			'form_time' => self::form_timestamp(),
		);
	}

	/**
	 * REST response carrying a fresh pair of tokens.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_form_token(): WP_REST_Response {
		$response = new WP_REST_Response( self::form_token() );

		// A stored copy of this response would recreate exactly the problem it
		// exists to solve, so it is refused to every cache in the chain.
		foreach ( self::no_store_headers() as $header => $value ) {
			$response->header( $header, $value );
		}

		return $response;
	}

	/**
	 * The same tokens over admin-ajax, for sites where the REST API is closed
	 * to anonymous visitors. Without a fallback, locking down REST would take
	 * the donation form down with it.
	 *
	 * @return void
	 */
	public static function ajax_form_token(): void {
		foreach ( self::no_store_headers() as $header => $value ) {
			header( $header . ': ' . $value );
		}

		wp_send_json( self::form_token() );
	}

	/**
	 * @return array<string,string>
	 */
	private static function no_store_headers(): array {
		return array(
			'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'        => 'no-cache',
			'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
		);
	}

	/**
	 * Keep the page a failed submission returns to out of every cache: it
	 * redisplays the donor's own name, email and amount.
	 *
	 * @return void
	 */
	public static function no_store_recovery_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a marker, not acting on it.
		if ( isset( $_GET['pspgd_error'] ) && ! headers_sent() ) {
			nocache_headers();
		}
	}

	// -----------------------------------------------------------------
	// Bot protection
	// -----------------------------------------------------------------

	/**
	 * Reject submissions that did not come from a person filling in the form.
	 *
	 * Two cheap checks. Neither writes anything for a rejected request beyond
	 * one log line, because the point is that a flood costs nothing: without
	 * them, every anonymous POST created a donor row, a donation row and a
	 * registered order at the bank -- which is both a way to fill the tables
	 * with junk and a way to test stolen card numbers.
	 */
	private static function reject_bots( string $language ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called from handle_submit() after the nonce check.

		// 1. A field a person never sees and therefore never fills in.
		if ( '' !== trim( sanitize_text_field( wp_unslash( $_POST['pspgd_website'] ?? '' ) ) ) ) {
			self::log_rejection( 'honeypot' );
			self::fail( self::translate( 'error_blocked', 'Your donation could not be submitted. Please try again.', $language ) );
		}

		// 2. Nobody chooses an amount, types their name and email, and
		//    submits in under a few seconds. A form left open for hours is
		//    a person who stepped away, so that is refused without being
		//    logged as an attack.
		$elapsed = self::timestamp_age( sanitize_text_field( wp_unslash( $_POST['pspgd_form_time'] ?? '' ) ) );
		if ( null === $elapsed || $elapsed < self::MIN_FILL_SECONDS || $elapsed > self::FORM_TTL ) {
			if ( null === $elapsed || $elapsed < self::MIN_FILL_SECONDS ) {
				self::log_rejection( null === $elapsed ? 'timing_token' : 'timing' );
			}
			self::fail( self::translate( 'error_timing', 'This form has been open too long, or was submitted too quickly. Please try again.', $language ) );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function log_rejection( string $reason ): void {
		PSPGD_Repository::log_event(
			'security',
			0,
			'donation_submission_rejected',
			sprintf( 'A donation submission was rejected: %s.', $reason )
		);
	}

	/**
	 * A signed timestamp written into the form when it renders, so the time
	 * a donor spent on it can be checked without trusting a posted number.
	 */
	public static function form_timestamp(): string {
		$now = time();

		return $now . '.' . hash_hmac( 'sha256', 'pspgd_form|' . $now, wp_salt( 'auth' ) );
	}

	/**
	 * Read a signed render timestamp back.
	 *
	 * Returns null for anything this site did not sign, so an edited or
	 * invented timestamp is treated exactly like a missing one rather than
	 * being believed.
	 */
	public static function timestamp_age( string $token, ?int $now = null ): ?int {
		// str_contains() is PHP 8; this plugin still supports 7.4.
		if ( false === strpos( $token, '.' ) ) {
			return null;
		}

		list( $issued, $signature ) = explode( '.', $token, 2 );
		if ( ! ctype_digit( $issued ) ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', 'pspgd_form|' . $issued, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		return max( 0, ( null === $now ? time() : $now ) - (int) $issued );
	}

	// -----------------------------------------------------------------
	// Errors the donor can recover from
	// -----------------------------------------------------------------

	/**
	 * Send the donor back to the form with an explanation and the values they
	 * typed, instead of a bare white page that loses everything.
	 */
	private static function fail( string $message ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only echoes the donor's own values back to them; nothing is acted on.
		$token = wp_generate_password( 20, false, false );

		set_transient(
			'pspgd_donation_error_' . $token,
			array(
				'message' => $message,
				'values'  => array(
					'first_name'    => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
					'last_name'     => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
					'email'         => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
					'amount'        => sanitize_text_field( wp_unslash( $_POST['amount'] ?? '' ) ),
					'currency'      => sanitize_text_field( wp_unslash( $_POST['currency'] ?? '' ) ),
					'donation_type' => sanitize_key( wp_unslash( $_POST['donation_type'] ?? '' ) ),
				),
			),
			self::ERROR_TTL
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'pspgd_error', $token, $back ? $back : home_url( '/' ) ) );
		exit;
	}

	/**
	 * Pick up an error left by a failed submission. Single use: the stored
	 * values are deleted as soon as they are read, so a shared or bookmarked
	 * URL cannot show them again.
	 *
	 * @return array{message: string, values: array<string, string>}
	 */
	private static function returned_error(): array {
		$empty = array(
			'message' => '',
			'values'  => array(),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A random single-use key; it only reads back what this donor typed.
		$token = isset( $_GET['pspgd_error'] ) ? sanitize_key( wp_unslash( $_GET['pspgd_error'] ) ) : '';
		if ( '' === $token ) {
			return $empty;
		}

		$stored = get_transient( 'pspgd_donation_error_' . $token );
		delete_transient( 'pspgd_donation_error_' . $token );

		if ( ! is_array( $stored ) ) {
			return $empty;
		}

		return array(
			'message' => (string) ( $stored['message'] ?? '' ),
			'values'  => (array) ( $stored['values'] ?? array() ),
		);
	}

	/**
	 * The language the donor filled the form in.
	 *
	 * Taken from the hidden field the form rendered, which is the only point
	 * at which the page's language is known -- this handler runs on
	 * admin-post.php. Validated against the supported list rather than
	 * trusted, and falls back to whatever this request can work out if the
	 * field is missing.
	 */
	private static function submitted_language(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read before the nonce check so even that refusal is in the donor's language; validated against the supported list.
		$posted = isset( $_POST['donor_language'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_language'] ) ) : '';

		if ( '' !== $posted && in_array( PSPGD_I18n::normalize_locale( $posted ), PSPGD_I18n::supported_locales(), true ) ) {
			return PSPGD_I18n::normalize_locale( $posted );
		}

		return PSPGD_I18n::current_locale();
	}

	private static function normalize_amount( string $value ): float {
		return round( (float) str_replace( ',', '.', $value ), 2 );
	}

	private static function amount_presets(): array {
		return PSPGD_I18n::amount_presets();
	}

	private static function recurring_enabled(): bool {
		return '0' !== (string) get_option( 'pspgd_recurring_enabled', '1' ) && array() !== PSPGD_Settings::intervals();
	}

	private static function extract_redirect_url( array $result ): string {
		foreach ( array( 'redirect_url', 'payment_url', 'url', 'form_url' ) as $key ) {
			if ( ! empty( $result[ $key ] ) && filter_var( $result[ $key ], FILTER_VALIDATE_URL ) ) {
				return (string) $result[ $key ];
			}
		}

		return '';
	}
}
