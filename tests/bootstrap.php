<?php
/**
 * Minimal WordPress test doubles for focused plugin tests.
 *
 * These tests deliberately avoid requiring a full WordPress installation so
 * they can be run before packaging the plugin. Everything here is a stand-in
 * for a WordPress API the plugin actually calls -- nothing models behaviour
 * the plugin does not depend on.
 *
 * The clock is frozen at 2026-08-23 12:00:00 by default so timestamps in
 * assertions stay stable; call test_set_now() to move it.
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}

// ---------------------------------------------------------------------
// Clock
// ---------------------------------------------------------------------

$GLOBALS['test_now']      = strtotime( '2026-08-23 12:00:00 UTC' );
$GLOBALS['test_timezone'] = 'UTC';

/**
 * Move the frozen clock. Accepts a timestamp or a strtotime() string, which
 * is read as UTC -- the moment, not anybody's wall clock.
 */
function test_set_now( $when ): void {
	$GLOBALS['test_now'] = is_int( $when ) ? $when : strtotime( $when . ' UTC' );

	if ( class_exists( 'PSPGD_Clock' ) ) {
		PSPGD_Clock::freeze( $GLOBALS['test_now'] );
	}
}

function test_now(): int {
	return (int) $GLOBALS['test_now'];
}

/**
 * The site's time zone. UTC unless a test says otherwise; the tests that
 * care about deadlines and windows run in Asia/Yerevan, where every PSPG
 * bank is, and in a zone behind UTC.
 */
function test_set_timezone( string $timezone ): void {
	$GLOBALS['test_timezone'] = $timezone;
}

function wp_timezone(): DateTimeZone {
	return new DateTimeZone( (string) $GLOBALS['test_timezone'] );
}

/**
 * Behaves like WordPress: 'timestamp' without $gmt is the Unix time shifted
 * by the site's offset (which is exactly why the plugin must not use it),
 * and 'mysql' is the site's local wall clock.
 *
 * @return string|int
 */
function current_time( string $type = 'mysql', $gmt = 0 ) {
	$now = test_now();

	if ( 'timestamp' === $type || 'U' === $type ) {
		return $gmt ? $now : $now + wp_timezone()->getOffset( new DateTimeImmutable( '@' . $now ) );
	}

	$format = 'mysql' === $type ? 'Y-m-d H:i:s' : $type;
	if ( $gmt ) {
		return gmdate( $format, $now );
	}

	return ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->format( $format );
}

/** Formats a real timestamp in the site's (or the given) time zone, as WordPress does. */
function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
	return ( new DateTimeImmutable( '@' . ( $timestamp ?? test_now() ) ) )
		->setTimezone( $timezone ?? wp_timezone() )
		->format( $format );
}

/** Reads a site-local datetime string, as WordPress does. @return string|false */
function mysql2date( string $format, string $date, bool $translate = true ) {
	if ( '' === trim( $date ) ) {
		return false;
	}

	$datetime = date_create( $date, wp_timezone() );
	if ( false === $datetime ) {
		return false;
	}

	return wp_date( $format, $datetime->getTimestamp() );
}

// ---------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------

$GLOBALS['test_options'] = array();

/**
 * Settings every test starts from unless it sets its own: a site offering
 * three currencies with goals in AMD, writing to donors in English and
 * Armenian. Most payment and campaign tests are about what happens when
 * currencies and languages mix, so that is the baseline; tests of a plain
 * single-currency, single-language install override these explicitly.
 */
$GLOBALS['test_base_options'] = array(
	'pspgd_currencies'    => array( 'AMD', 'USD', 'EUR' ),
	'pspgd_goal_currency' => 'AMD',
	'pspgd_languages'     => array( 'en', 'hy' ),
	'pspgd_bank_id'       => 'arca_acba',
);

/** @param mixed $default @return mixed */
function get_option( string $name, $default = false ) {
	if ( array_key_exists( $name, $GLOBALS['test_options'] ) ) {
		return $GLOBALS['test_options'][ $name ];
	}

	return array_key_exists( $name, $GLOBALS['test_base_options'] ) ? $GLOBALS['test_base_options'][ $name ] : $default;
}

/** @param mixed $value */
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['test_options'][ $name ] = $value;
	return true;
}

/** add_option() must fail when the option already exists -- the recurring lock depends on it. */
function add_option( string $name, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
	if ( array_key_exists( $name, $GLOBALS['test_options'] ) ) {
		return false;
	}
	$GLOBALS['test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	if ( ! array_key_exists( $name, $GLOBALS['test_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['test_options'][ $name ] );
	return true;
}

function test_reset_options( array $options = array() ): void {
	$GLOBALS['test_options'] = $options;
}

// ---------------------------------------------------------------------
// Transients (the stat band is cached in one)
// ---------------------------------------------------------------------

$GLOBALS['test_transients'] = array();

/** @return mixed */
function get_transient( string $key ) {
	return array_key_exists( $key, $GLOBALS['test_transients'] ) ? $GLOBALS['test_transients'][ $key ] : false;
}

/** @param mixed $value */
function set_transient( string $key, $value, int $expiration = 0 ): bool {
	$GLOBALS['test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['test_transients'][ $key ] );
	return true;
}

function test_reset_transients(): void {
	$GLOBALS['test_transients'] = array();
}

// ---------------------------------------------------------------------
// Object cache (the recurring lock takes a different path when one exists)
// ---------------------------------------------------------------------

$GLOBALS['test_ext_object_cache'] = false;
$GLOBALS['test_cache']            = array();

function wp_using_ext_object_cache(): bool {
	return (bool) $GLOBALS['test_ext_object_cache'];
}

function wp_cache_add( string $key, $value, string $group = '', int $expire = 0 ): bool {
	if ( isset( $GLOBALS['test_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['test_cache'][ $group ][ $key ] = $value;
	return true;
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $GLOBALS['test_cache'][ $group ][ $key ] );
	return true;
}

// ---------------------------------------------------------------------
// Mail
// ---------------------------------------------------------------------

$GLOBALS['test_mail']        = array();
$GLOBALS['test_mail_result'] = true;

function wp_mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
	$GLOBALS['test_mail'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
	);

	return (bool) $GLOBALS['test_mail_result'];
}

/** @return array<int, array<string, mixed>> */
function test_sent_mail(): array {
	return $GLOBALS['test_mail'];
}

function test_reset_mail(): void {
	$GLOBALS['test_mail']        = array();
	$GLOBALS['test_mail_result'] = true;
}

// ---------------------------------------------------------------------
// Sanitizers, escaping and small helpers
// ---------------------------------------------------------------------

function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

/** @param mixed $value */
function sanitize_text_field( $value ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}

/** @param mixed $value */
function sanitize_textarea_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

/** @param mixed $value */
function sanitize_email( $value ): string {
	$email = filter_var( trim( (string) $value ), FILTER_SANITIZE_EMAIL );
	return is_email( $email ) ? $email : '';
}

/** @param mixed $value */
function is_email( $value ): bool {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

/** @param mixed $value */
function absint( $value ): int {
	return abs( (int) $value );
}

/** @param mixed $value */
function wp_json_encode( $value ): string {
	return (string) json_encode( $value );
}

function wp_generate_uuid4(): string {
	return sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', 0, 0, 0, 0, 0, 0, 0, 0 );
}

function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
	return str_repeat( 'a', $length );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function esc_url( string $url ): string {
	return $url;
}

function esc_url_raw( string $url ): string {
	return $url;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}

function add_query_arg( array $args, string $url ): string {
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
}

// ---------------------------------------------------------------------
// Pieces of WordPress the email templates reach for
//
// Each is guarded, because a few tests declare their own to assert on one
// specific behaviour; theirs wins and these are the plain fallbacks.
// ---------------------------------------------------------------------

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'Example Charity';
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/** @param mixed $quote_style */
	function wp_specialchars_decode( string $text, $quote_style = null ): string {
		return html_entity_decode( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-value';
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( string $text, bool $br = true ): string {
		return '<p>' . str_replace( "\n\n", '</p><p>', trim( $text ) ) . '</p>';
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/** @param array<string, mixed> $allowed */
	function wp_kses( string $text, array $allowed ): string {
		return strip_tags( $text, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/** @return object|null */
	function get_post( int $id ) {
		return null;
	}
}

// ---------------------------------------------------------------------
// Posts and post meta
// ---------------------------------------------------------------------

/** @var array<int, array<string, mixed>> */
$GLOBALS['test_post_meta'] = array();
/** @var array<int, string> */
$GLOBALS['test_posts'] = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	/** @return mixed */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		$value = $GLOBALS['test_post_meta'][ $post_id ][ $key ] ?? '';

		return $single ? $value : ( '' === $value ? array() : array( $value ) );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/** @param mixed $value */
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( $GLOBALS['test_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Only the shape the plugin asks for: published campaign ids, optionally
	 * filtered to those without one meta key.
	 *
	 * @return int[]
	 */
	function get_posts( array $args = array() ): array {
		$missing = $args['meta_query'][0]['key'] ?? '';
		$ids     = array();

		foreach ( array_keys( $GLOBALS['test_posts'] ) as $post_id ) {
			if ( '' !== $missing && ! empty( $GLOBALS['test_post_meta'][ $post_id ][ $missing ] ) ) {
				continue;
			}
			$ids[] = (int) $post_id;
		}

		return $ids;
	}
}

/** Register a published campaign the stubs above can find. */
function test_add_campaign( int $post_id, array $meta = array() ): void {
	$GLOBALS['test_posts'][ $post_id ]     = 'pspgd_campaign';
	$GLOBALS['test_post_meta'][ $post_id ] = $meta;
}

function test_reset_posts(): void {
	$GLOBALS['test_posts']     = array();
	$GLOBALS['test_post_meta'] = array();
}

// ---------------------------------------------------------------------
// Hooks and filters
// ---------------------------------------------------------------------

$GLOBALS['test_hooks'] = array();

if ( ! function_exists( 'add_action' ) ) {
	/** @param mixed $callback */
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['test_hooks'][ $hook ][] = $callback;
		return true;
	}
}

/**
 * Some tests declare their own apply_filters() before pulling this file in,
 * to assert on one specific hook. Theirs wins; this is the generic fallback.
 *
 * @param mixed $value @return mixed
 */
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filter = $GLOBALS['test_filters'][ $hook ] ?? null;

		return is_callable( $filter ) ? $filter( $value, ...$args ) : $value;
	}
}

/** @var array<string, callable> */
$GLOBALS['test_filters'] = array();

/** Every action fired, in order, so a test can assert one was (or was not). */
$GLOBALS['test_actions'] = array();

if ( ! function_exists( 'do_action' ) ) {
	/** @param mixed ...$args */
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['test_actions'][] = array( 'hook' => $hook, 'args' => $args );
	}
}

/** @return array<int, array{hook: string, args: array<int, mixed>}> */
function test_fired_actions( string $hook = '' ): array {
	return array_values(
		array_filter(
			$GLOBALS['test_actions'],
			static function ( array $action ) use ( $hook ): bool {
				return '' === $hook || $hook === $action['hook'];
			}
		)
	);
}

/** Register a filter for one test. */
function test_set_filter( string $hook, callable $callback ): void {
	$GLOBALS['test_filters'][ $hook ] = $callback;
}

function test_reset_filters(): void {
	$GLOBALS['test_filters'] = array();
	$GLOBALS['test_hooks']   = array();
	$GLOBALS['test_actions'] = array();
}

// ---------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------

$GLOBALS['test_cron'] = array();

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/** @return int|false */
	function wp_next_scheduled( string $hook ) {
		return $GLOBALS['test_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		$GLOBALS['test_cron'][ $hook ] = $timestamp;
		$GLOBALS['test_cron_recurrence'][ $hook ] = $recurrence;
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook ): void {
		unset( $GLOBALS['test_cron'][ $hook ], $GLOBALS['test_cron_recurrence'][ $hook ] );
	}
}

function test_reset_cron(): void {
	$GLOBALS['test_cron'] = array();
	$GLOBALS['test_cron_recurrence'] = array();
}

// ---------------------------------------------------------------------
// Outbound HTTP
//
// Responses are queued per URL prefix so a test can say "this provider is
// down, the next one answers" without caring about request order, and every
// request is logged so a test can prove a cached value was reused instead of
// re-fetched.
// ---------------------------------------------------------------------

$GLOBALS['test_http_responses'] = array();
$GLOBALS['test_http_requests']  = array();

/**
 * Queue a response for any request whose URL contains $match.
 *
 * @param int|string $status An int status with $body, or 'error' for a WP_Error.
 */
function test_queue_http( string $match, $status, string $body = '' ): void {
	$GLOBALS['test_http_responses'][] = array( 'match' => $match, 'status' => $status, 'body' => $body );
}

function test_reset_http(): void {
	$GLOBALS['test_http_responses'] = array();
	$GLOBALS['test_http_requests']  = array();
}

/** @return array<int, string> */
function test_http_requests(): array {
	return $GLOBALS['test_http_requests'];
}

final class PSPGD_Test_WP_Error {
	public string $message;

	public function __construct( string $message = 'http error' ) {
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	/** @return array|PSPGD_Test_WP_Error */
	function wp_safe_remote_get( string $url, array $args = array() ) {
		$GLOBALS['test_http_requests'][] = $url;

		foreach ( $GLOBALS['test_http_responses'] as $index => $response ) {
			if ( false === strpos( $url, $response['match'] ) ) {
				continue;
			}

			unset( $GLOBALS['test_http_responses'][ $index ] );
			if ( 'error' === $response['status'] ) {
				return new PSPGD_Test_WP_Error();
			}

			return array( 'response' => array( 'code' => (int) $response['status'] ), 'body' => $response['body'] );
		}

		// Nothing queued: the provider is unreachable.
		return new PSPGD_Test_WP_Error( 'no response queued' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/** @param mixed $response */
	function is_wp_error( $response ): bool {
		return $response instanceof PSPGD_Test_WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/** @param mixed $response */
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/** @param mixed $response */
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

// ---------------------------------------------------------------------
// $wpdb
// ---------------------------------------------------------------------

/**
 * The columns PSPGD_Installer creates, per table, read from its source.
 *
 * The stand-in below refuses any other column, as MySQL does. When it
 * accepted anything, a column the installer never created passed every
 * test and then failed every real recurring sign-up.
 *
 * @return array<string, string[]> Unprefixed table name => column names.
 */
function test_installer_schema(): array {
	static $schema = null;
	if ( null !== $schema ) {
		return $schema;
	}

	$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-pspgd-installer.php' );
	preg_match_all( "/\\$(\\w+)\\s*=\\s*\\\$wpdb->prefix \\. '(\\w+)';/", $source, $tables, PREG_SET_ORDER );
	$names = array();
	foreach ( $tables as $table ) {
		$names[ $table[1] ] = $table[2];
	}

	$schema = array();
	preg_match_all( '/CREATE TABLE \{\$(\w+)\} \((.*?)\) \{\$charset_collate\}/s', $source, $creates, PREG_SET_ORDER );
	foreach ( $creates as $create ) {
		preg_match_all( '/^\s*([a-z_]+) (?:bigint|int|varchar|char|decimal|tinyint|datetime|text|longtext)\b/m', $create[2], $columns );
		$schema[ $names[ $create[1] ] ?? $create[1] ] = $columns[1];
	}

	return $schema;
}

final class PSPGD_Test_WPDB {
	public string $prefix = 'wp_';
	public string $posts  = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';

	/** Writes issued through update(). @var array<int, array<string, mixed>> */
	public array $updates = array();
	/** Raw statements issued through query(). @var array<int, string> */
	public array $queries = array();
	/** Statements issued through the read helpers. @var array<int, string> */
	public array $reads = array();
	/** @var array<int, array<string, mixed>> */
	public array $inserts = array();
	/** @var array<int, array<string, mixed>> */
	public array $deletes = array();

	public int $insert_id = 101;
	/** @var int|false */
	public $update_result = 1;
	/** @var int|false */
	public $insert_result = 1;
	/** @var int|false */
	public $delete_result = 1;
	public int $query_result = 1;

	/**
	 * Queued return values for the read helpers. Each call shifts one entry;
	 * an exhausted queue returns the empty value for that helper, which is
	 * what wpdb does for a query that matches nothing.
	 *
	 * @var array<int, mixed>
	 */
	public array $rows = array();
	/** @var array<int, mixed> */
	public array $vars = array();
	/** @var array<int, mixed> */
	public array $result_sets = array();
	/** @var array<int, mixed> */
	public array $cols = array();

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		// A single array argument is spread, matching wpdb's own behaviour.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		// One placeholder at a time, left to right, as wpdb does: %i is an
		// identifier (backticked), %d/%f a number, %s a quoted string.
		foreach ( $args as $argument ) {
			if ( ! preg_match( '/%[sdfi]/', $query, $match, PREG_OFFSET_CAPTURE ) ) {
				break;
			}

			$placeholder = $match[0][0];
			if ( '%i' === $placeholder ) {
				$value = '`' . str_replace( '`', '``', (string) $argument ) . '`';
			} elseif ( is_int( $argument ) || is_float( $argument ) ) {
				$value = (string) $argument;
			} else {
				$value = "'" . $argument . "'";
			}

			$query = substr_replace( $query, $value, $match[0][1], 2 );
		}

		return $query;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return $this->query_result;
	}

	/** Refuse a column the installer never created, as MySQL does. @param array<string, mixed> $columns */
	private function check_columns( string $table, array $columns ): void {
		$name   = 0 === strpos( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : $table;
		$schema = test_installer_schema();
		if ( ! isset( $schema[ $name ] ) ) {
			return;
		}

		foreach ( array_keys( $columns ) as $column ) {
			if ( ! in_array( $column, $schema[ $name ], true ) ) {
				throw new RuntimeException( sprintf( "Unknown column '%s' in %s: PSPGD_Installer never creates it.", $column, $table ) );
			}
		}
	}

	/** @param array<string, mixed> $data @return int|false */
	public function insert( string $table, array $data ) {
		$this->check_columns( $table, $data );
		$this->inserts[] = array( 'table' => $table, 'data' => $data );
		return $this->insert_result;
	}

	/** @param array<string, mixed> $data @param array<string, mixed> $where @return int|false */
	public function update( string $table, array $data, array $where ) {
		$this->check_columns( $table, $data + $where );
		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		return $this->update_result;
	}

	/** @param array<string, mixed> $where @return int|false */
	public function delete( string $table, array $where ) {
		$this->check_columns( $table, $where );
		$this->deletes[] = array( 'table' => $table, 'where' => $where );
		return $this->delete_result;
	}

	/** @return mixed */
	public function get_row( string $query ) {
		$this->reads[] = $query;
		return $this->rows ? array_shift( $this->rows ) : null;
	}

	/** @return mixed */
	public function get_var( string $query ) {
		$this->reads[] = $query;
		return $this->vars ? array_shift( $this->vars ) : null;
	}

	/** @return array<int, mixed> */
	public function get_results( string $query ): array {
		$this->reads[] = $query;
		return $this->result_sets ? (array) array_shift( $this->result_sets ) : array();
	}

	/** @return array<int, mixed> */
	public function get_col( string $query ): array {
		$this->reads[] = $query;
		return $this->cols ? (array) array_shift( $this->cols ) : array();
	}

	/** Escape LIKE wildcards, as wpdb does before a search term is interpolated. */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/** Forget every recorded statement without disturbing queued return values. */
	public function reset_log(): void {
		$this->updates = array();
		$this->queries = array();
		$this->reads   = array();
		$this->inserts = array();
		$this->deletes = array();
	}
}

/** Build a stdClass row the way $wpdb->get_row() returns one. @param array<string, mixed> $fields */
function test_row( array $fields ): object {
	return (object) $fields;
}

// ---------------------------------------------------------------------
// Assertions and the shared runner
// ---------------------------------------------------------------------

/** @param mixed $expected @param mixed $actual */
function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function assert_true( bool $actual, string $message ): void {
	assert_same( true, $actual, $message );
}

function assert_false( bool $actual, string $message ): void {
	assert_same( false, $actual, $message );
}

/** Assert that $haystack contains $needle, reporting the haystack when it does not. */
function assert_contains( string $needle, string $haystack, string $message ): void {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message . "\nExpected to find: " . $needle . "\nIn: " . $haystack );
	}
}

function assert_not_contains( string $needle, string $haystack, string $message ): void {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message . "\nExpected NOT to find: " . $needle . "\nIn: " . $haystack );
	}
}

/**
 * Run a name => callable map, printing one line per test.
 *
 * @param array<string, callable> $tests
 */
function run_tests( array $tests ): void {
	$failures = 0;

	foreach ( $tests as $name => $test ) {
		try {
			$test();
			echo "PASS: {$name}\n";
		} catch ( Throwable $error ) {
			$failures++;
			echo "FAIL: {$name}\n{$error->getMessage()}\n";
		}
	}

	exit( $failures > 0 ? 1 : 0 );
}

// Settings are read by nearly every class, so every test has them. The
// clock starts frozen at the default test moment.
require_once dirname( __DIR__ ) . '/includes/class-pspgd-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-clock.php';
require_once dirname( __DIR__ ) . '/includes/class-pspgd-notifications.php';
PSPGD_Clock::freeze( test_now() );
