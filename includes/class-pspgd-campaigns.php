<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PSPGD_Campaigns {
	/**
	 * The currency a campaign's goal was entered in. Stamped when the
	 * campaign is saved, so changing the site's goal currency later never
	 * silently turns a goal of 1,000,000 of one currency into 1,000,000 of
	 * another.
	 */
	private const GOAL_CURRENCY_META = '_pspgd_campaign_goal_currency';

	/**
	 * Why a campaign stopped taking donations, and when.
	 *
	 * Written once and never recalculated. A campaign that reached its goal
	 * must stay closed even if next week's exchange rate puts the converted
	 * total back under it -- donors were told it was funded, and a campaign
	 * that reopens itself would have to cancel and then re-solicit the same
	 * recurring donors.
	 */
	private const CLOSED_REASON_META = '_pspgd_campaign_closed_reason';
	private const CLOSED_AT_META     = '_pspgd_campaign_closed_at';

	/**
	 * Set once a closed campaign's recurring donations have been ended.
	 *
	 * Separate from the closing stamp because the two happen at different
	 * moments: a campaign can be closed the instant a payment crosses its
	 * goal, but cancelling its donors means unbinding cards at the bank and
	 * sending email, which does not belong in a payment callback.
	 */
	private const SETTLED_META = '_pspgd_campaign_closed_settled';

	/**
	 * Whether reaching the goal closes the campaign. Off unless an editor
	 * ticks it: closing cancels the campaign's recurring donors and releases
	 * their cards, which cannot be undone, so it is a choice made per
	 * campaign. Unticked, only the end date closes it and it can raise more
	 * than its goal.
	 */
	private const CLOSE_AT_GOAL_META = '_pspgd_campaign_close_at_goal';

	public const CRON_HOOK = 'pspgd_close_campaigns';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'close_finished_campaigns' ) );
		add_filter( 'pll_get_post_types', array( __CLASS__, 'polylang_post_type' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_pspgd_campaign', array( __CLASS__, 'save_campaign' ) );
		// After do_shortcode (priority 11): the campaign's own content arrives
		// with its shortcodes already expanded, and what is added here --
		// supporter names donors typed themselves -- is never run as one.
		add_filter( 'the_content', array( __CLASS__, 'append_campaign_fundraising' ), 12 );
	}

	/** Whether reaching the goal closes this campaign. */
	public static function closes_at_goal( int $campaign_id ): bool {
		return $campaign_id > 0 && '1' === (string) get_post_meta( $campaign_id, self::CLOSE_AT_GOAL_META, true );
	}

	/** The currency a campaign's goal is measured in. */
	public static function goal_currency( int $campaign_id ): string {
		$stored = $campaign_id > 0 ? strtoupper( (string) get_post_meta( $campaign_id, self::GOAL_CURRENCY_META, true ) ) : '';

		return '' !== $stored ? $stored : PSPGD_Settings::goal_currency();
	}

	/**
	 * What has been raised in the goal currency alone. This is the figure a
	 * campaign falls back to when no exchange rate is available.
	 *
	 * @param array{by_currency?: array<string, array{total: float, count: int}>} $progress
	 */
	public static function base_subtotal( array $progress, string $base ): float {
		return isset( $progress['by_currency'][ $base ]['total'] ) ? (float) $progress['by_currency'][ $base ]['total'] : 0.0;
	}

	public static function polylang_post_type( array $post_types, bool $is_settings ): array {
		$post_types['pspgd_campaign'] = 'pspgd_campaign';

		return $post_types;
	}

	public static function register_post_type(): void {
		register_post_type(
			'pspgd_campaign',
			array(
				'labels' => array(
					'name'          => __( 'Campaigns', 'donations-for-planetstudio-payment-gateway' ),
					'singular_name' => __( 'Campaign', 'donations-for-planetstudio-payment-gateway' ),
					'add_new_item'  => __( 'Add New Campaign', 'donations-for-planetstudio-payment-gateway' ),
					'edit_item'     => __( 'Edit Campaign', 'donations-for-planetstudio-payment-gateway' ),
				),
				'public'       => true,
				'show_in_menu' => 'pspgd',
				'rewrite'      => array( 'slug' => (string) apply_filters( 'pspgd_campaign_slug', 'campaigns' ) ),
				'has_archive'  => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
				'show_in_rest' => true,
			)
		);
	}

	public static function add_meta_boxes(): void {
		add_meta_box( 'pspgd-campaign-fundraising', __( 'Fundraising details', 'donations-for-planetstudio-payment-gateway' ), array( __CLASS__, 'render_meta_box' ), 'pspgd_campaign', 'side', 'high' );
		add_meta_box( 'pspgd-campaign-status', __( 'Campaign status', 'donations-for-planetstudio-payment-gateway' ), array( __CLASS__, 'render_status_box' ), 'pspgd_campaign', 'side', 'high' );
	}

	public static function render_meta_box( WP_Post $post ): void {
		$goal     = get_post_meta( $post->ID, '_pspgd_campaign_goal', true );
		$deadline = get_post_meta( $post->ID, '_pspgd_campaign_deadline', true );
		$currency = self::goal_currency( $post->ID );
		wp_nonce_field( 'pspgd_campaign_save', 'pspgd_campaign_nonce' );
		?>
		<p><label for="pspgd-campaign-goal"><strong><?php echo esc_html( sprintf( /* translators: %s: currency code, e.g. EUR. */ __( 'Goal amount (%s)', 'donations-for-planetstudio-payment-gateway' ), $currency ) ); ?></strong></label><input class="widefat" type="number" min="1" step="1" id="pspgd-campaign-goal" name="pspgd_campaign_goal" value="<?php echo esc_attr( $goal ); ?>"></p>
		<p><label for="pspgd-campaign-deadline"><strong><?php esc_html_e( 'End date', 'donations-for-planetstudio-payment-gateway' ); ?></strong></label><input class="widefat" type="date" id="pspgd-campaign-deadline" name="pspgd_campaign_deadline" value="<?php echo esc_attr( $deadline ); ?>"></p>
		<p><label><input type="checkbox" name="pspgd_campaign_close_at_goal" value="1" <?php checked( self::closes_at_goal( $post->ID ) ); ?>> <?php esc_html_e( 'Stop taking donations when the goal is reached', 'donations-for-planetstudio-payment-gateway' ); ?></label></p>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'The goal is in %s. Donations in other currencies are converted at the daily exchange rate and counted toward it. The campaign stops taking donations when the end date passes, or when the goal is reached if the box above is ticked. Its recurring donations are then cancelled. Pushing the end date out, raising the goal or unticking the box reopens it, but donors already cancelled are not restored. Leave the end date blank for an ongoing campaign.', 'donations-for-planetstudio-payment-gateway' ), $currency ) ); ?></p>
		<?php
	}

	public static function render_status_box( WP_Post $post ): void {
		$campaign_ids = self::translation_ids( $post->ID );
		$goal         = (float) get_post_meta( $post->ID, '_pspgd_campaign_goal', true );
		$deadline     = (string) get_post_meta( $post->ID, '_pspgd_campaign_deadline', true );
		$currency     = self::goal_currency( $post->ID );
		$progress     = PSPGD_Repository::campaign_progress( $campaign_ids );
		$view         = self::progress_view( $progress, $goal, $currency );
		$status       = self::status( $post->ID );
		$events       = self::campaign_events( $campaign_ids );
		?>
		<p><strong><?php esc_html_e( 'Collected', 'donations-for-planetstudio-payment-gateway' ); ?></strong><br>
			<?php if ( $view['combined'] ) : ?>
				<?php echo esc_html( PSPGD_I18n::format_amount( $view['total'], $view['base'] ) ); ?>
				<?php if ( $view['converted'] ) : ?><span class="description"><?php esc_html_e( '(all currencies)', 'donations-for-planetstudio-payment-gateway' ); ?></span><?php endif; ?>
			<?php else : ?>
				<em><?php esc_html_e( 'No exchange rate available, so currencies are listed separately.', 'donations-for-planetstudio-payment-gateway' ); ?></em>
			<?php endif; ?>
		</p>
		<p><strong><?php esc_html_e( 'By currency', 'donations-for-planetstudio-payment-gateway' ); ?></strong><br>
			<?php if ( empty( $progress['by_currency'] ) ) : ?>
				<?php echo esc_html( PSPGD_I18n::format_amount( 0.0, $currency ) ); ?>
			<?php else : ?>
				<?php foreach ( $progress['by_currency'] as $currency => $totals ) : ?>
					<?php echo esc_html( PSPGD_I18n::format_amount( (float) $totals['total'], (string) $currency ) ); ?><br>
				<?php endforeach; ?>
			<?php endif; ?>
		</p>
		<p><strong><?php esc_html_e( 'Goal', 'donations-for-planetstudio-payment-gateway' ); ?></strong><br><?php echo esc_html( $goal > 0 ? PSPGD_I18n::format_amount( $goal, $currency ) : '-' ); ?></p>
		<p><strong><?php esc_html_e( 'End date', 'donations-for-planetstudio-payment-gateway' ); ?></strong><br><?php echo esc_html( $deadline ? mysql2date( (string) get_option( 'date_format' ), $deadline ) : __( 'Ongoing', 'donations-for-planetstudio-payment-gateway' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Accepting donations', 'donations-for-planetstudio-payment-gateway' ); ?></strong><br>
			<?php if ( $status['open'] ) : ?>
				<?php esc_html_e( 'Yes', 'donations-for-planetstudio-payment-gateway' ); ?>
			<?php else : ?>
				<?php echo esc_html( 'goal_reached' === $status['reason'] ? __( 'No - goal reached', 'donations-for-planetstudio-payment-gateway' ) : __( 'No - end date passed', 'donations-for-planetstudio-payment-gateway' ) ); ?>
				<br><span class="description"><?php esc_html_e( 'Recurring donations to this campaign have been cancelled, and the bank was asked to release their saved cards.', 'donations-for-planetstudio-payment-gateway' ); ?></span>
			<?php endif; ?>
		</p>
		<hr>
		<p><strong><?php esc_html_e( 'Recent campaign logs', 'donations-for-planetstudio-payment-gateway' ); ?></strong></p>
		<?php if ( $events ) : ?>
			<ul style="margin:0;padding-left:18px;max-height:280px;overflow:auto;">
				<?php foreach ( $events as $event ) : ?>
					<li style="margin:0 0 10px;">
						<div><code><?php echo esc_html( $event->event_type ); ?></code></div>
						<div><?php echo esc_html( $event->message ?: $event->created_at ); ?></div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No campaign logs yet.', 'donations-for-planetstudio-payment-gateway' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function save_campaign( int $post_id ): void {
		if ( ! isset( $_POST['pspgd_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pspgd_campaign_nonce'] ) ), 'pspgd_campaign_save' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$goal          = max( 0, (float) sanitize_text_field( wp_unslash( $_POST['pspgd_campaign_goal'] ?? '0' ) ) );
		$deadline      = sanitize_text_field( wp_unslash( $_POST['pspgd_campaign_deadline'] ?? '' ) );
		$deadline      = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deadline ) ? $deadline : '';
		$close_at_goal = ! empty( $_POST['pspgd_campaign_close_at_goal'] );
		$group_id      = (int) get_post_meta( $post_id, '_pspgd_campaign_group_id', true );
		if ( $group_id <= 0 ) {
			$group_id = $post_id;
		}

		// The goal is stamped with the currency it was typed in, once. An
		// existing campaign keeps its own currency even if the site's goal
		// currency is changed later.
		$currency = self::goal_currency( $post_id );

		self::sync_meta_across_translations( $post_id, $group_id, $goal, $deadline, $currency, $close_at_goal );
		self::reopen_if_settings_allow( $post_id );
	}

	/**
	 * Let an editor put a closed campaign back on sale by changing its terms.
	 *
	 * An exchange rate moving must never reopen a campaign -- that is what
	 * the closing stamp is for -- but an editor pushing the end date out or
	 * raising the goal is a deliberate decision to keep fundraising, and
	 * leaving the campaign silently shut with no way back is the kind of
	 * thing that gets debugged by email. So is unticking "stop taking
	 * donations when the goal is reached". Recurring donors cancelled while it
	 * was closed are not restored: their cards were released at the bank and
	 * cannot be re-bound without them entering the details again.
	 */
	private static function reopen_if_settings_allow( int $campaign_id ): void {
		if ( '' === (string) get_post_meta( $campaign_id, self::CLOSED_REASON_META, true ) ) {
			return;
		}

		if ( self::deadline_passed( (string) get_post_meta( $campaign_id, '_pspgd_campaign_deadline', true ) ) ) {
			return;
		}

		$campaign_ids = self::translation_ids( $campaign_id );
		$goal         = (float) get_post_meta( $campaign_id, '_pspgd_campaign_goal', true );
		if ( $goal > 0 && self::closes_at_goal( $campaign_id ) ) {
			$currency = self::goal_currency( $campaign_id );
			$progress = PSPGD_Repository::campaign_progress( $campaign_ids );
			$view     = self::progress_view( $progress, $goal, $currency );
			$funded   = $view['combined'] ? $view['total'] : 0.0;
			if ( max( $funded, self::base_subtotal( $progress, $currency ) ) >= $goal ) {
				return;
			}
		}

		foreach ( $campaign_ids as $id ) {
			delete_post_meta( $id, self::CLOSED_REASON_META );
			delete_post_meta( $id, self::CLOSED_AT_META );
			delete_post_meta( $id, self::SETTLED_META );
		}

		PSPGD_Repository::log_event(
			'campaign',
			$campaign_id,
			'campaign_reopened',
			'Reopened by an editor changing the end date, the goal or whether the goal closes it. Recurring donations cancelled while it was closed were not restored.'
		);
	}

	// -----------------------------------------------------------------
	// Opening and closing
	// -----------------------------------------------------------------

	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Whether a campaign is still taking donations, and if not, why.
	 *
	 * A pure read of two post meta values and the clock -- no totals query
	 * and no exchange rate -- because this runs on every render of the
	 * donation form and on every submission. Reaching the goal is detected
	 * elsewhere (after a payment, and hourly) and recorded as the stamp this
	 * reads; a deadline needs no detecting, it is just a date.
	 *
	 * @return array{open: bool, reason: string, closed_at: string}
	 */
	public static function status( int $campaign_id ): array {
		$open = array( 'open' => true, 'reason' => '', 'closed_at' => '' );
		if ( $campaign_id <= 0 ) {
			return $open;
		}

		$reason = (string) get_post_meta( $campaign_id, self::CLOSED_REASON_META, true );
		if ( '' !== $reason ) {
			return array(
				'open'      => false,
				'reason'    => $reason,
				'closed_at' => (string) get_post_meta( $campaign_id, self::CLOSED_AT_META, true ),
			);
		}

		$deadline = (string) get_post_meta( $campaign_id, '_pspgd_campaign_deadline', true );
		if ( self::deadline_passed( $deadline ) ) {
			// The stamp is written by the hourly job, which also has to
			// cancel the recurring donors. Until it runs, the date alone is
			// enough to stop taking money.
			return array( 'open' => false, 'reason' => 'ended', 'closed_at' => $deadline );
		}

		return $open;
	}

	public static function is_open( int $campaign_id ): bool {
		return (bool) self::status( $campaign_id )['open'];
	}

	/**
	 * Whether a campaign end date is in the past.
	 *
	 * The date is inclusive: a campaign ending on the 30th takes donations
	 * until the end of the 30th in the site's own timezone, which is what an
	 * editor picking that date means by it.
	 */
	public static function deadline_passed( string $deadline ): bool {
		$deadline = trim( $deadline );
		if ( '' === $deadline || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deadline ) ) {
			return false;
		}

		try {
			$end = new DateTimeImmutable( $deadline . ' 23:59:59', wp_timezone() );
		} catch ( Exception $error ) {
			return false;
		}

		return PSPGD_Clock::now() > $end->getTimestamp();
	}

	/**
	 * The message shown where the donation form was.
	 */
	public static function closed_notice( string $reason, ?string $locale = null ): string {
		if ( 'goal_reached' === $reason ) {
			return PSPGD_I18n::t( 'campaign_closed_goal', 'This campaign has reached its goal. Thank you to everyone who gave.', $locale );
		}

		return PSPGD_I18n::t( 'campaign_closed_ended', 'This campaign has ended. Thank you to everyone who gave.', $locale );
	}

	/**
	 * Decide whether a campaign has finished, and if it has, close it.
	 *
	 * Closing is the consequential half: it stamps the reason across every
	 * translation and cancels the recurring donations pointed at it, which
	 * revokes saved cards at the bank and emails the donors. It is therefore
	 * written to be safe to call repeatedly -- an already-closed campaign
	 * returns its existing reason and does nothing else.
	 *
	 * @return string The reason, or '' if the campaign is still running.
	 */
	public static function close_if_finished( int $campaign_id, bool $settle = true ): string {
		if ( $campaign_id <= 0 ) {
			return '';
		}

		$existing = (string) get_post_meta( $campaign_id, self::CLOSED_REASON_META, true );
		if ( '' !== $existing ) {
			if ( $settle ) {
				self::settle_campaign( $campaign_id );
			}

			return $existing;
		}

		$campaign_ids = self::translation_ids( $campaign_id );
		$reason       = '';

		if ( self::deadline_passed( (string) get_post_meta( $campaign_id, '_pspgd_campaign_deadline', true ) ) ) {
			$reason = 'ended';
		} elseif ( self::closes_at_goal( $campaign_id ) ) {
			$goal = (float) get_post_meta( $campaign_id, '_pspgd_campaign_goal', true );
			if ( $goal > 0 ) {
				$currency = self::goal_currency( $campaign_id );
				$progress = PSPGD_Repository::campaign_progress( $campaign_ids );
				$view     = self::progress_view( $progress, $goal, $currency );
				// A campaign is only retired on a figure that is certainly
				// past the goal: one that counts every currency, or a
				// goal-currency subtotal already over the line on its own.
				// Without rates the combined figure is that subtotal standing
				// in for the whole, and closing on it would retire a campaign
				// whose other-currency donations have not been counted yet.
				$funded = $view['combined'] ? $view['total'] : 0.0;
				if ( max( $funded, self::base_subtotal( $progress, $currency ) ) >= $goal ) {
					$reason = 'goal_reached';
				}
			}
		}

		if ( '' === $reason ) {
			return '';
		}

		$closed_at = current_time( 'mysql' );
		foreach ( $campaign_ids as $id ) {
			update_post_meta( $id, self::CLOSED_REASON_META, $reason );
			update_post_meta( $id, self::CLOSED_AT_META, $closed_at );
		}

		if ( $settle ) {
			self::settle_campaign( $campaign_id );
		}

		return $reason;
	}

	/**
	 * End the recurring donations of a campaign that has closed.
	 *
	 * Idempotent and safe to call on an already-settled campaign, because the
	 * hourly sweep calls it for anything closed but not yet settled -- which
	 * is how a campaign closed inside a payment callback gets its donors
	 * cancelled on a later request instead of in front of the bank.
	 */
	private static function settle_campaign( int $campaign_id ): void {
		$reason = (string) get_post_meta( $campaign_id, self::CLOSED_REASON_META, true );
		if ( '' === $reason || get_post_meta( $campaign_id, self::SETTLED_META, true ) ) {
			return;
		}

		$campaign_ids = self::translation_ids( $campaign_id );
		$cancelled    = self::cancel_campaign_subscriptions( $campaign_ids, $reason );

		foreach ( $campaign_ids as $id ) {
			update_post_meta( $id, self::SETTLED_META, current_time( 'mysql' ) );
		}

		/**
		 * Fires once when a campaign has closed and its recurring donations
		 * have been ended -- for example to invite those donors to give
		 * monthly to the organisation instead. Each donor has already been
		 * emailed that their donation ended with the campaign.
		 *
		 * @param int      $campaign_id   The campaign (one of its translations).
		 * @param string   $reason        'goal_reached' or 'ended'.
		 * @param object[] $subscriptions The recurring donations that were cancelled.
		 * @param int[]    $campaign_ids  Every translation of the campaign.
		 */
		do_action( 'pspgd_campaign_closed', $campaign_id, $reason, $cancelled, $campaign_ids );
	}

	/**
	 * The hourly sweep.
	 *
	 * Two passes: close whatever has run out of time or reached its goal,
	 * then finish off anything that was closed elsewhere -- by a payment
	 * callback, or by hand -- without its donors having been cancelled yet.
	 */
	public static function close_finished_campaigns(): void {
		foreach ( self::campaign_ids_missing( self::CLOSED_REASON_META ) as $campaign_id ) {
			self::close_if_finished( $campaign_id );
		}

		foreach ( self::campaign_ids_missing( self::SETTLED_META ) as $campaign_id ) {
			self::settle_campaign( $campaign_id );
		}
	}

	/**
	 * Published campaigns without a given meta key.
	 *
	 * @return int[]
	 */
	private static function campaign_ids_missing( string $meta_key ): array {
		$ids = get_posts(
			array(
				'post_type'        => 'pspgd_campaign',
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'fields'           => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hourly cron, at most 200 campaigns.
				'meta_query'       => array(
					array(
						'key'     => $meta_key,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * End every recurring donation pointed at a campaign that has closed.
	 *
	 * A donor who pledged monthly to one campaign did not pledge to the
	 * organisation forever, so the schedule ends with the campaign: the row
	 * is cancelled, the saved card is revoked at the bank rather than left live,
	 * and the donor is told. Anything that fails here is logged and skipped
	 * -- one donor's unbind failing must not leave the rest still chargeable.
	 *
	 * @param int[] $campaign_ids
	 * @return object[] The subscriptions that were cancelled.
	 */
	private static function cancel_campaign_subscriptions( array $campaign_ids, string $reason ): array {
		$cancelled = array();

		foreach ( PSPGD_Repository::active_subscriptions_for_campaigns( $campaign_ids ) as $subscription ) {
			PSPGD_Repository::cancel_subscription( (int) $subscription->id );
			$result = PSPGD_PSPG::unbind_subscription( $subscription );
			PSPGD_Repository::log_event(
				'subscription',
				(int) $subscription->id,
				'recurring_cancelled_campaign_closed',
				sprintf( 'Campaign closed (%s), so the schedule was ended and the card binding revoked.', $reason ),
				is_array( $result ) ? $result : array()
			);
			PSPGD_Emails::notify_recurring_cancelled_campaign_closed( $subscription, $reason );
			$cancelled[] = $subscription;
		}

		return $cancelled;
	}

	/**
	 * Everything the progress bar needs, in every currency it can offer.
	 *
	 * A campaign can collect in several currencies, so "raised so far" is
	 * only meaningful once they are expressed in one unit. Totals are
	 * combined in the goal currency, and then that
	 * one figure is restated in each of the other currencies, so switching
	 * the display never moves the bar: numerator and denominator convert at
	 * the same rate, which leaves the percentage identical everywhere.
	 *
	 * When a rate is missing, `combined` is false and the caller falls back
	 * to the plainer display: the goal-currency figure on the bar, the rest listed
	 * beside it. That is a worse page, not a wrong one -- which is the right
	 * way round for a number donors are reading.
	 *
	 * @param array{by_currency: array<string, array{total: float, count: int}>} $progress
	 * @param string $base The goal currency; defaults to the site setting.
	 *
	 * @return array{
	 *     combined: bool, converted: bool, base: string, total: float, percent: int,
	 *     currencies: array<string, array{raised: float, raised_label: string, goal_label: string}>,
	 *     other: string[]
	 * }
	 */
	public static function progress_view( array $progress, float $goal, string $base = '' ): array {
		$by_currency = isset( $progress['by_currency'] ) && is_array( $progress['by_currency'] ) ? $progress['by_currency'] : array();
		$base        = '' !== $base ? strtoupper( $base ) : PSPGD_Settings::goal_currency();
		$combined    = PSPGD_Exchange_Rates::combine( $by_currency, $base );

		$view = array(
			'combined'   => (bool) $combined['ok'],
			'converted'  => (bool) $combined['ok'] && ! empty( $combined['converted'] ),
			'base'       => $base,
			'total'      => $combined['ok'] ? (float) $combined['total'] : self::base_subtotal( $progress, $base ),
			'percent'    => 0,
			'currencies' => array(),
			'other'      => array(),
		);

		if ( $goal > 0 ) {
			$view['percent'] = (int) min( 100, max( 0, round( $view['total'] / $goal * 100 ) ) );
		}

		// Only offer a currency the whole figure -- raised and goal alike --
		// can actually be expressed in.
		$offered = $view['combined'] ? array_values( array_unique( array_merge( array( $base ), PSPGD_PSPG::allowed_currencies() ) ) ) : array( $base );
		foreach ( $offered as $currency ) {
			$currency = strtoupper( (string) $currency );
			$raised   = PSPGD_Exchange_Rates::convert( $view['total'], $base, $currency );
			if ( empty( $raised['ok'] ) ) {
				continue;
			}

			$goal_label = PSPGD_I18n::t( 'campaign_raised', 'raised so far' );
			if ( $goal > 0 ) {
				$converted_goal = PSPGD_Exchange_Rates::convert( $goal, $base, $currency );
				if ( empty( $converted_goal['ok'] ) ) {
					continue;
				}
				$goal_label = sprintf(
					PSPGD_I18n::t( 'campaign_goal', 'of %s goal' ),
					PSPGD_I18n::format_amount( (float) $converted_goal['amount'], $currency )
				);
			}

			$view['currencies'][ $currency ] = array(
				'raised'       => (float) $raised['amount'],
				'raised_label' => PSPGD_I18n::format_amount( (float) $raised['amount'], $currency ),
				'goal_label'   => $goal_label,
			);
		}

		if ( ! $view['combined'] ) {
			foreach ( $by_currency as $currency => $totals ) {
				if ( $base !== strtoupper( (string) $currency ) ) {
					$view['other'][] = PSPGD_I18n::format_amount( (float) $totals['total'], strtoupper( (string) $currency ) );
				}
			}
		}

		return $view;
	}

	public static function append_campaign_fundraising( string $content ): string {
		if ( ! is_singular( 'pspgd_campaign' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$campaign_id = get_the_ID();
		$goal         = (float) get_post_meta( $campaign_id, '_pspgd_campaign_goal', true );
		$deadline     = (string) get_post_meta( $campaign_id, '_pspgd_campaign_deadline', true );
		$campaign_ids = self::translation_ids( $campaign_id );
		$progress     = PSPGD_Repository::campaign_progress( $campaign_ids );
		$view         = self::progress_view( $progress, $goal, self::goal_currency( $campaign_id ) );
		$active       = $view['base'];
		$switchable   = count( $view['currencies'] ) > 1 ? array_keys( $view['currencies'] ) : array();
		$figures      = $view['currencies'][ $active ] ?? array(
			'raised_label' => PSPGD_I18n::format_amount( $view['total'], $active ),
			'goal_label'   => PSPGD_I18n::t( 'campaign_raised', 'raised so far' ),
		);
		$switch_data  = array();
		foreach ( $view['currencies'] as $currency => $currency_figures ) {
			$switch_data[ $currency ] = array(
				'raised' => $currency_figures['raised_label'],
				'goal'   => $currency_figures['goal_label'],
			);
		}
		$campaign_status = self::status( $campaign_id );
		$donors       = PSPGD_Settings::show_supporters() ? PSPGD_Repository::campaign_donors( $campaign_ids ) : array();
		$deadline_text = $deadline ? sprintf( PSPGD_I18n::t( 'campaign_end', 'Campaign ends %s' ), mysql2date( (string) get_option( 'date_format' ), $deadline ) ) : __( 'Ongoing campaign', 'donations-for-planetstudio-payment-gateway' );
		if ( ! $campaign_status['open'] ) {
			$deadline_text = 'goal_reached' === $campaign_status['reason']
				? PSPGD_I18n::t( 'campaign_eyebrow_goal', 'Goal reached' )
				: PSPGD_I18n::t( 'campaign_eyebrow_ended', 'Campaign closed' );
		}
		$form_label    = sprintf( PSPGD_I18n::t( 'campaign_support', 'Support %s' ), get_the_title( $campaign_id ) );

		ob_start();
		?>
		<section class="pspgd-campaign" aria-label="<?php esc_attr_e( 'Campaign donation progress', 'donations-for-planetstudio-payment-gateway' ); ?>">
			<div class="pspgd-campaign__progress" data-pspgd-campaign-currencies="<?php echo esc_attr( (string) wp_json_encode( $switch_data ) ); ?>">
				<p class="pspgd-campaign__eyebrow"><?php echo esc_html( $deadline_text ); ?></p>
				<?php if ( $switchable ) : ?>
					<div class="pspgd-campaign__currency" role="group" aria-label="<?php echo esc_attr( PSPGD_I18n::t( 'campaign_currency', 'Display currency' ) ); ?>">
						<?php foreach ( $switchable as $currency ) : ?>
							<button type="button" class="pspgd-campaign__currency-option" data-pspgd-currency="<?php echo esc_attr( (string) $currency ); ?>" aria-pressed="<?php echo $currency === $active ? 'true' : 'false'; ?>"><?php echo esc_html( (string) $currency ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="pspgd-campaign__numbers"><strong data-pspgd-campaign-raised><?php echo esc_html( (string) $figures['raised_label'] ); ?></strong><span data-pspgd-campaign-goal><?php echo esc_html( (string) $figures['goal_label'] ); ?></span></div>
				<div class="pspgd-campaign__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $view['percent'] ); ?>"><span style="width:<?php echo esc_attr( (string) $view['percent'] ); ?>%"></span></div>
				<?php if ( $view['other'] ) : ?><p class="pspgd-campaign__other-currencies"><?php echo esc_html( sprintf( PSPGD_I18n::t( 'campaign_also_raised', 'Also raised: %s' ), implode( ' · ', $view['other'] ) ) ); ?></p><?php endif; ?>
				<p class="pspgd-campaign__count"><?php echo esc_html( sprintf( PSPGD_I18n::t( 'campaign_donations', '%s donations' ), number_format_i18n( $progress['donation_count'] ) ) ); ?></p>
			</div>
			<?php if ( has_post_thumbnail( $campaign_id ) ) : ?><figure class="pspgd-campaign__image"><?php echo get_the_post_thumbnail( $campaign_id, 'large', array( 'loading' => 'eager' ) ); ?></figure><?php endif; ?>
			<?php // Already through the_content's own filters (blocks, embeds, paragraphs, shortcodes) and safe as WordPress itself would print it. ?>
			<div class="pspgd-campaign__content"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="pspgd-campaign__form">
				<?php if ( $campaign_status['open'] ) : ?>
					<div class="pspgd-campaign__form-heading">
						<p class="pspgd-campaign__eyebrow"><?php echo esc_html( $form_label ); ?></p>
					</div>
					<?php echo do_shortcode( '[pspgd_donation_form campaign_id="' . (int) $campaign_id . '"]' ); ?>
				<?php else : ?>
					<p class="pspgd-campaign__closed"><?php echo esc_html( self::closed_notice( (string) $campaign_status['reason'] ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( PSPGD_Settings::show_supporters() ) : ?>
			<div class="pspgd-campaign__donors">
				<h2><?php echo esc_html( PSPGD_I18n::t( 'campaign_supporters', 'Supporters' ) ); ?></h2>
				<?php if ( $donors ) : ?><ul><?php foreach ( $donors as $donation ) : ?><li><span><?php echo esc_html( ! empty( $donation->is_anonymous ) ? PSPGD_I18n::t( 'anonymous_donor', 'Anonymous donor' ) : self::public_name( (string) $donation->first_name, (string) $donation->last_name ) ); ?></span><strong><?php echo esc_html( PSPGD_I18n::format_amount( (float) $donation->amount, (string) $donation->currency ) ); ?></strong></li><?php endforeach; ?></ul><?php else : ?><p><?php esc_html_e( 'Be the first to support this campaign.', 'donations-for-planetstudio-payment-gateway' ); ?></p><?php endif; ?></div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * How a supporter who did not ask to be anonymous is shown in public:
	 * first name and last initial. Filterable for sites that want full names
	 * or first names only.
	 */
	public static function public_name( string $first_name, string $last_name ): string {
		$last_name = trim( $last_name );
		$initial   = '' !== $last_name ? ( function_exists( 'mb_substr' ) ? mb_substr( $last_name, 0, 1 ) : substr( $last_name, 0, 1 ) ) . '.' : '';

		return (string) apply_filters( 'pspgd_supporter_name', trim( trim( $first_name ) . ' ' . $initial ), $first_name, $last_name );
	}

	private static function translation_ids( int $campaign_id ): array {
		$ids = array( $campaign_id );
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = array_filter( array_map( 'absint', (array) pll_get_post_translations( $campaign_id ) ) );
			if ( $translations ) {
				$ids = array_merge( $ids, $translations );
			}
		}

		$group_id = (int) get_post_meta( $campaign_id, '_pspgd_campaign_group_id', true );
		if ( $group_id > 0 ) {
			global $wpdb;
			$group_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
					'_pspgd_campaign_group_id',
					(string) $group_id
				)
			);
			if ( $group_ids ) {
				$ids = array_merge( $ids, array_map( 'absint', $group_ids ) );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private static function sync_meta_across_translations( int $post_id, int $group_id, float $goal, string $deadline, string $currency, bool $close_at_goal ): void {
		update_post_meta( $post_id, '_pspgd_campaign_group_id', $group_id );

		foreach ( self::translation_ids( $post_id ) as $target_id ) {
			update_post_meta( $target_id, '_pspgd_campaign_group_id', $group_id );
			update_post_meta( $target_id, '_pspgd_campaign_goal', $goal );
			update_post_meta( $target_id, self::GOAL_CURRENCY_META, $currency );
			if ( $deadline ) {
				update_post_meta( $target_id, '_pspgd_campaign_deadline', $deadline );
			} else {
				delete_post_meta( $target_id, '_pspgd_campaign_deadline' );
			}
			if ( $close_at_goal ) {
				update_post_meta( $target_id, self::CLOSE_AT_GOAL_META, '1' );
			} else {
				delete_post_meta( $target_id, self::CLOSE_AT_GOAL_META );
			}
		}
	}

	private static function campaign_events( array $campaign_ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $campaign_ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		// One %d per campaign id; the ids themselves go through prepare().
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is only %d markers.
		$donation_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CONCAT('donation #', d.id, ' ', d.status) AS event_type, d.created_at, COALESCE(CONCAT('Donation amount: ', d.amount, ' ', d.currency), '') AS message
				FROM %i d
				WHERE d.campaign_id IN ({$placeholders})
				ORDER BY d.created_at DESC
				LIMIT 10",
				array_merge( array( $wpdb->prefix . 'pspgd_donations' ), $ids )
			)
		);
		// The campaign's own events: closing, reopening, settling.
		$event_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_type, e.created_at, COALESCE(e.message, '') AS message
				FROM %i e
				WHERE e.object_type = %s AND e.object_id IN ({$placeholders})
				ORDER BY e.created_at DESC
				LIMIT 10",
				array_merge( array( $wpdb->prefix . 'pspgd_events', 'campaign' ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = array_merge( (array) $donation_rows, (array) $event_rows );
		usort(
			$rows,
			static function ( $left, $right ): int {
				return strcmp( (string) $right->created_at, (string) $left->created_at );
			}
		);

		return array_slice( $rows, 0, 10 );
	}
}
