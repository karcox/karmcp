<?php
/**
 * Standalone PHPUnit bootstrap for the test suite.
 *
 * A self-contained WordPress + ACF stub harness, so the suite runs with plain
 * PHPUnit and no WordPress install:
 *
 *     vendor/bin/phpunit
 *
 * Stubs are driven by the $GLOBALS['karmcp_test'] fixture array, reset per test
 * via karmcp_test_reset().
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
if ( ! defined( 'KARMCP_DIR' ) ) {
	define( 'KARMCP_DIR', dirname( __DIR__ ) . '/' );
}

// WordPress's time constants. Plugin code uses them in class constants, which
// are evaluated the first time the class is touched — without these, requiring
// such a file from a test is an "undefined constant" fatal.
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'MONTH_IN_SECONDS'  => 2592000,
	'YEAR_IN_SECONDS'   => 31536000,
) as $karmcp_const => $karmcp_value ) {
	if ( ! defined( $karmcp_const ) ) {
		define( $karmcp_const, $karmcp_value );
	}
}
unset( $karmcp_const, $karmcp_value );

/**
 * Resets the shared stub fixture. Call from setUp().
 */
function karmcp_test_reset(): void {
	$GLOBALS['karmcp_test'] = array(
		'caps'               => array( 'edit_posts', 'manage_options' ),
		'post_caps'          => array(),   // post_id => bool for edit_post checks.
		'acf_pro'            => true,
		'field_groups'       => array(),   // Every group, keyed numerically.
		'groups_for_post'    => array(),   // post_id => group arrays (location match).
		'group_fields'       => array(),   // group_key => top-level field arrays.
		'fields_by_key'      => array(),   // field_key => field array (acf_get_field).
		'field_objects'      => array(),   // target => name => field object (options targets).
		'values'             => array(),   // target => field_key => stored value.
		'update_field_calls' => array(),   // Recorded [key, value, target] triples.
		'imported_groups'    => array(),
		'updated_groups'     => array(),
		'updated_fields'     => array(),
		'posts'              => array(),   // post_id => post-ish object.
		'options_pages'      => array(),
		'abilities'          => array(),   // name => registration args.
		'registered_styles'  => array(),   // Handles wp_style_is() reports as registered.
		'registered_scripts' => array(),   // Handles wp_script_is() reports as registered.
		'enqueued_styles'    => array(),   // Handles the code under test enqueued.
		'enqueued_scripts'   => array(),
		'now'                => null,      // format => value, pinning current_time().
		'cpt_posts'          => array(),   // post_type => WP_Post[] for get_posts().
		'options'            => array(),   // option name => value (get_option/update_option).
		'option_autoload'    => array(),   // option name => the $autoload update_option() was called with.
		'upload_dir'         => null,      // wp_upload_dir() override: [ basedir, baseurl ].
		'cpt_tax_supported'  => true,      // Toggles the ACF 6.1+ CPT/tax API stubs.
		'acf_post_types'     => array(),   // key/ID => acf-post-type definition.
		'acf_taxonomies'     => array(),   // key/ID => acf-taxonomy definition.
		'existing_types'     => array(),   // slugs seen as already-registered post types.
		'existing_taxes'     => array(),   // slugs seen as already-registered taxonomies.
		'imported_types'     => array(),   // recorded acf_import_post_type() args.
		'imported_taxes'     => array(),   // recorded acf_import_taxonomy() args.
		'updated_internal'   => array(),   // recorded acf_update_internal_post_type() args.
		'max_upload'         => 0,         // wp_max_upload_size() in bytes; 0 = unknown.
		'allowed_ext'        => null,      // ext => mime for wp_check_filetype(); null = the default map.
	);
}
karmcp_test_reset();

// ---------------------------------------------------------------------------
// Minimal WordPress stubs
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		// The stub used to drop the third constructor argument, which made the
		// data payload of every WP_Error in the plugin untestable — and it is
		// the half a client can act on programmatically.
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID           = 0;
		public $post_title   = '';
		public $post_type    = 'post';
		public $post_name    = '';
		public $post_excerpt = '';
		public $post_content = '';
		public $post_status  = 'publish';
		public $menu_order   = 0;
		// Real WP_Post declares these; the stub used to omit them, which made
		// any code reading a field a fixture had not set look like a bug.
		public $post_author    = 1;
		public $post_parent    = 0;
		public $comment_status = 'closed';
		public $ping_status    = 'closed';
		public $post_password  = '';
		public $post_date      = '2026-01-01 00:00:00';
		public $post_modified  = '2026-01-01 00:00:00';
		public $guid           = '';
		public function __construct( array $props = array() ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_text_field( $value ): string {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}

function sanitize_textarea_field( $value ): string {
	// sanitize_text_field with the line breaks kept, same as WordPress.
	return trim( preg_replace( '/[	 ]+/', ' ', strip_tags( (string) $value ) ) );
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

/*
 * Output escaping. These are close enough to WordPress's behaviour for the
 * property the sandbox generators are tested on — that a stored value cannot
 * carry markup into a rendered widget or block.
 */
function esc_html( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $value ): string {
	$url = trim( (string) $value );
	if ( '' === $url ) {
		return '';
	}
	if ( preg_match( '#^\s*(javascript|data|vbscript)\s*:#i', $url ) ) {
		return '';
	}
	return str_replace( array( '"', "'", '<', '>' ), array( '&quot;', '&#039;', '&lt;', '&gt;' ), $url );
}

/*
 * Asset registration. The sandbox loaders register handles and enqueue them
 * only where an artifact is used, so the fixture just records the calls.
 */
function wp_style_is( $handle, $list = 'enqueued' ): bool {
	return in_array( $handle, $GLOBALS['karmcp_test']['registered_styles'] ?? array(), true );
}

function wp_script_is( $handle, $list = 'enqueued' ): bool {
	return in_array( $handle, $GLOBALS['karmcp_test']['registered_scripts'] ?? array(), true );
}

function wp_enqueue_style( $handle, ...$rest ): void {
	$GLOBALS['karmcp_test']['enqueued_styles'][] = $handle;
}

function wp_enqueue_script( $handle, ...$rest ): void {
	$GLOBALS['karmcp_test']['enqueued_scripts'][] = $handle;
}

function wp_kses_post( $value ): string {
	// Strips exactly what matters here: script/style elements and event handlers.
	$out = preg_replace( '#<\s*(script|style)\b.*?<\s*/\s*\1\s*>#is', '', (string) $value );
	$out = preg_replace( '#<\s*(script|style)\b[^>]*>#i', '', (string) $out );
	return (string) preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $out );
}

function esc_url_raw( $value ): string {
	return (string) $value;
}

/**
 * Reproduces core's character class and collapsing — the half the upload
 * filename resolver leans on — rather than standing in for it.
 */
function sanitize_file_name( $name ): string {
	$name = str_replace(
		array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr( 0 ) ),
		'',
		(string) $name
	);
	return trim( preg_replace( '/[\r\n\t -]+/', '-', $name ), '.-_' );
}

/**
 * Stands in for get_allowed_mime_types(): the extensions the fixture treats as
 * uploadable. Override the whole map via ['allowed_ext'].
 */
function wp_check_filetype( $filename, $mimes = null ): array {
	$allowed = $GLOBALS['karmcp_test']['allowed_ext'] ?? array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'pdf'  => 'application/pdf',
	);
	$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	return isset( $allowed[ $ext ] )
		? array( 'ext' => $ext, 'type' => $allowed[ $ext ] )
		: array( 'ext' => false, 'type' => false );
}

/** Bytes the fixture says this site accepts as an upload; 0 = unknown. */
function wp_max_upload_size(): int {
	return (int) ( $GLOBALS['karmcp_test']['max_upload'] ?? 0 );
}

function size_format( $bytes, $decimals = 0 ) {
	return round( ( (int) $bytes ) / 1048576, $decimals ) . ' MB';
}

/**
 * Post query stub. Serves $GLOBALS['karmcp_test']['cpt_posts'][ post_type ],
 * honouring only the `name` filter — enough for slug lookups, and honest about
 * not being WP_Query.
 *
 * @param array $args Query args.
 * @return array
 */
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$type  = (string) ( $args['post_type'] ?? 'post' );
		$posts = $GLOBALS['karmcp_test']['cpt_posts'][ $type ] ?? array();

		if ( isset( $args['name'] ) && '' !== $args['name'] ) {
			$posts = array_values(
				array_filter(
					$posts,
					static function ( $post ) use ( $args ) {
						return isset( $post->post_name ) && $post->post_name === $args['name'];
					}
				)
			);
		}

		if ( isset( $args['numberposts'] ) && (int) $args['numberposts'] > 0 ) {
			$posts = array_slice( $posts, 0, (int) $args['numberposts'] );
		}

		return array_values( $posts );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ): string {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}

/**
 * Site-local time, pinned by the fixture so schedule-driven code is testable.
 * Falls back to the real clock when a test has not set one.
 *
 * @param string $type 'H:i', 'N', … — the same format strings WordPress accepts.
 * @return string
 */
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'H:i' ) {
		$now = $GLOBALS['karmcp_test']['now'] ?? null;
		if ( is_array( $now ) && isset( $now[ $type ] ) ) {
			return $now[ $type ];
		}
		return gmdate( 'mysql' === $type ? 'Y-m-d H:i:s' : (string) $type );
	}
}

/**
 * Ability lookup. Returns an object exposing get_meta() for the registrations
 * recorded in the fixture, so annotation-driven code can be exercised.
 *
 * @param string $name Ability name.
 * @return object|null
 */
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		$args = $GLOBALS['karmcp_test']['abilities'][ $name ] ?? null;
		if ( null === $args ) {
			return null;
		}
		return new class( (array) $args ) {
			/** @var array */
			private $args;
			public function __construct( array $args ) {
				$this->args = $args;
			}
			public function get_meta() {
				return $this->args['meta'] ?? array();
			}
		};
	}
}

/**
 * Minimal hook registry.
 *
 * This used to be a pass-through `apply_filters`. It is now a real (tiny)
 * registry so integration-style tests can wire a class's own filters and assert
 * the composed behaviour — the Themer condition layer is entirely filter-driven,
 * and a pass-through cannot exercise it.
 *
 * With nothing registered it behaves exactly like the old pass-through, so
 * existing tests are unaffected. Call karmcp_test_reset_hooks() in setUp() when
 * a test registers anything, since the registry is global.
 */
$GLOBALS['karmcp_test_hooks'] = array();

function karmcp_test_reset_hooks(): void {
	$GLOBALS['karmcp_test_hooks'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ): bool {
		$GLOBALS['karmcp_test_hooks'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => (int) $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null ) {
		$args = func_get_args();
		array_shift( $args ); // Drop the hook name; $args[0] is now $value.

		if ( empty( $GLOBALS['karmcp_test_hooks'][ $hook ] ) ) {
			return $value;
		}

		$by_priority = $GLOBALS['karmcp_test_hooks'][ $hook ];
		ksort( $by_priority );

		foreach ( $by_priority as $entries ) {
			foreach ( $entries as $entry ) {
				$call    = array_slice( $args, 0, max( 1, $entry['accepted_args'] ) );
				$call[0] = $value;
				$value   = call_user_func_array( $entry['callback'], $call );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ): void {
		if ( empty( $GLOBALS['karmcp_test_hooks'][ $hook ] ) ) {
			return;
		}
		$by_priority = $GLOBALS['karmcp_test_hooks'][ $hook ];
		ksort( $by_priority );
		foreach ( $by_priority as $entries ) {
			foreach ( $entries as $entry ) {
				call_user_func_array( $entry['callback'], array_slice( $args, 0, $entry['accepted_args'] ) );
			}
		}
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

// Fixture-driven: $GLOBALS['karmcp_test']['rest_url_base'] sets the REST base so a
// staging-style split (rest_url host != home_url host) can be simulated.
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '', $scheme = 'rest' ) {
		$base = $GLOBALS['karmcp_test']['rest_url_base'] ?? ( home_url() . '/wp-json' );
		$base = rtrim( (string) $base, '/' );
		return '' === (string) $path ? $base . '/' : $base . '/' . ltrim( (string) $path, '/' );
	}
}

function get_option( $name, $default = false ) {
	return $GLOBALS['karmcp_test']['options'][ $name ] ?? $default;
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return $GLOBALS['karmcp_test']['upload_dir'] ?? array(
			'basedir' => sys_get_temp_dir() . '/karmcp-uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		);
	}
}

function update_option( $name, $value, $autoload = null ): bool {
	$GLOBALS['karmcp_test']['options'][ $name ] = $value;
	// Recorded, not just discarded: whether an option is autoloaded is a
	// performance property with no visible symptom when it regresses — the code
	// keeps working and quietly costs a query per request.
	$GLOBALS['karmcp_test']['option_autoload'][ $name ] = $autoload;
	return true;
}

function current_user_can( $cap, $object_id = null ): bool {
	if ( 'edit_post' === $cap ) {
		$map = $GLOBALS['karmcp_test']['post_caps'];
		if ( array_key_exists( (int) $object_id, $map ) ) {
			return (bool) $map[ (int) $object_id ];
		}
		return in_array( 'edit_posts', $GLOBALS['karmcp_test']['caps'], true );
	}
	return in_array( $cap, $GLOBALS['karmcp_test']['caps'], true );
}

function get_post( $post_id ) {
	return $GLOBALS['karmcp_test']['posts'][ (int) $post_id ] ?? null;
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	// Defaults to false: the interesting paths are the ones an anonymous caller
	// reaches. Set $GLOBALS['karmcp_test']['logged_in'] to flip it.
	function is_user_logged_in(): bool {
		return (bool) ( $GLOBALS['karmcp_test']['logged_in'] ?? false );
	}
}

if ( ! function_exists( 'is_protected_meta' ) ) {
	// Core's rule, minus the filter: a leading underscore means protected.
	function is_protected_meta( $meta_key, $meta_type = '' ): bool {
		return '_' === substr( (string) $meta_key, 0, 1 );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	// Derived from the same ['posts'] fixture get_post() serves.
	function get_post_type( $post = null ) {
		$post = $GLOBALS['karmcp_test']['posts'][ (int) $post ] ?? null;
		return ( $post && isset( $post->post_type ) ) ? $post->post_type : false;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	// Records deletions in $GLOBALS['karmcp_test']['deleted_meta'] as [post_id, key].
	function delete_post_meta( $post_id, $key, $value = '' ) {
		$GLOBALS['karmcp_test']['deleted_meta'][] = array( (int) $post_id, (string) $key );
		return true;
	}
}

function get_permalink( $post = null ): string {
	return 'http://example.test/?p=' . ( is_object( $post ) ? (int) $post->ID : (int) $post );
}

function admin_url( $path = '' ): string {
	return 'http://example.test/wp-admin/' . $path;
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . '/';
	}
}

function home_url( $path = '' ): string {
	// Fixture-driven so a test can put the site in a subdirectory; unchanged by default.
	$base = (string) ( $GLOBALS['karmcp_test']['home_url'] ?? 'http://example.test' );
	return rtrim( $base, '/' ) . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

// Fixture-driven: $GLOBALS['karmcp_test']['url_to_postid'][ normalized-path ] => post id.
function url_to_postid( $url ) {
	$path = parse_url( (string) $url, PHP_URL_PATH ); // phpcs:ignore
	$path = '/' . trim( strtolower( (string) $path ), '/' );
	return (int) ( $GLOBALS['karmcp_test']['url_to_postid'][ $path ] ?? 0 );
}

// Fixture-driven: $GLOBALS['karmcp_test']['post_status'][ id ] => 'publish'|'trash'|...
function get_post_status( $id ) {
	return $GLOBALS['karmcp_test']['post_status'][ (int) $id ] ?? false;
}

// ---------------------------------------------------------------------------
// Post write stubs. Fixture-driven through $GLOBALS['karmcp_test']['posts'],
// ['post_meta'] and ['object_terms'], so a test can assert what a copy carried
// over without a database.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		$id = 1 + ( $GLOBALS['karmcp_test']['next_post_id'] ?? 1000 );
		$GLOBALS['karmcp_test']['next_post_id'] = $id;

		$post = new WP_Post( array( 'ID' => $id ) );
		foreach ( $postarr as $key => $value ) {
			$post->$key = $value;
		}
		if ( empty( $post->post_name ) ) {
			$post->post_name = sanitize_title( (string) ( $postarr['post_title'] ?? '' ) );
		}

		$GLOBALS['karmcp_test']['posts'][ $id ]       = $post;
		$GLOBALS['karmcp_test']['post_status'][ $id ] = $post->post_status;
		$GLOBALS['karmcp_test']['inserted_posts'][]   = $postarr;

		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr, $wp_error = false ) {
		$id   = (int) ( $postarr['ID'] ?? 0 );
		$post = $GLOBALS['karmcp_test']['posts'][ $id ] ?? null;
		if ( ! $post ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'No post.' ) : 0;
		}
		foreach ( $postarr as $key => $value ) {
			if ( 'ID' === $key ) {
				continue;
			}
			// WordPress unslashes on the way in, same as the metadata API.
			$post->$key = is_string( $value ) ? wp_unslash( $value ) : $value;
		}
		$GLOBALS['karmcp_test']['updated_posts'][] = $postarr;
		return $id;
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id, $context = 'display' ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$all = $GLOBALS['karmcp_test']['post_meta'][ (int) $post_id ] ?? array();

		if ( '' === $key ) {
			// List mode: WordPress hands back every value serialized.
			$out = array();
			foreach ( $all as $meta_key => $values ) {
				$out[ $meta_key ] = array_map(
					static function ( $value ) {
						return is_scalar( $value ) ? $value : serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					},
					(array) $values
				);
			}
			return $out;
		}

		$values = (array) ( $all[ $key ] ?? array() );
		if ( $single ) {
			return $values ? reset( $values ) : '';
		}
		return $values;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $post_id, $key, $value, $unique = false ) {
		$GLOBALS['karmcp_test']['post_meta'][ (int) $post_id ][ $key ][] = wp_unslash( $value );
		return true;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value, $prev = '' ) {
		$GLOBALS['karmcp_test']['post_meta'][ (int) $post_id ][ $key ] = array( wp_unslash( $value ) );
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $value ) {
		if ( is_string( $value ) && preg_match( '/^[aOs]:\d+:/', $value ) ) {
			$restored = @unserialize( $value ); // phpcs:ignore
			return false === $restored ? $value : $restored;
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $object_type, $output = 'names' ) {
		$type = is_object( $object_type ) ? $object_type->post_type : (string) $object_type;
		return $GLOBALS['karmcp_test']['taxonomies_for_type'][ $type ] ?? array();
	}
}

/**
 * Object terms. The fixture may hold plain ids or term objects — nav menus need
 * objects, taxonomies elsewhere only need ids — so `fields => ids` normalizes
 * whichever is stored, the way WordPress does.
 */
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
		$id    = (int) ( is_array( $object_ids ) ? reset( $object_ids ) : $object_ids );
		$tx    = (string) ( is_array( $taxonomies ) ? reset( $taxonomies ) : $taxonomies );
		$terms = $GLOBALS['karmcp_test']['object_terms'][ $id ][ $tx ] ?? array();

		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return array_map(
				static function ( $term ) {
					return is_object( $term ) ? (int) $term->term_id : (int) $term;
				},
				(array) $terms
			);
		}

		return $terms;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$existing = $append ? ( $GLOBALS['karmcp_test']['object_terms'][ (int) $object_id ][ $taxonomy ] ?? array() ) : array();
		$GLOBALS['karmcp_test']['object_terms'][ (int) $object_id ][ $taxonomy ] = array_values( array_unique( array_merge( $existing, (array) $terms ) ) );
		return (array) $terms;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$id   = is_object( $post ) ? (int) $post->ID : (int) $post;
		$item = $GLOBALS['karmcp_test']['posts'][ $id ] ?? null;
		return $item ? (string) $item->post_title : '';
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['karmcp_test']['current_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) {
		return $GLOBALS['karmcp_test']['post_type_objects'][ (string) $post_type ] ?? null;
	}
}

// ---------------------------------------------------------------------------
// ACF stubs (fixture-driven)
// ---------------------------------------------------------------------------

function acf_get_field_groups( $args = array() ): array {
	if ( isset( $args['post_id'] ) ) {
		return $GLOBALS['karmcp_test']['groups_for_post'][ (int) $args['post_id'] ] ?? array();
	}
	return $GLOBALS['karmcp_test']['field_groups'];
}

function acf_get_field_group( $key ) {
	foreach ( $GLOBALS['karmcp_test']['field_groups'] as $group ) {
		if ( ( $group['key'] ?? '' ) === $key || ( isset( $group['ID'] ) && $group['ID'] === $key ) ) {
			return $group;
		}
	}
	return false;
}

function acf_get_fields( $group ): array {
	$key = is_array( $group ) ? ( $group['key'] ?? '' ) : (string) $group;
	return $GLOBALS['karmcp_test']['group_fields'][ $key ] ?? array();
}

function acf_get_field( $key ) {
	return $GLOBALS['karmcp_test']['fields_by_key'][ $key ] ?? false;
}

function acf_get_field_type( $type ) {
	return 'unknown_type' !== $type;
}

function acf_get_setting( $name ) {
	if ( 'pro' === $name ) {
		return $GLOBALS['karmcp_test']['acf_pro'];
	}
	return null;
}

function acf_get_options_pages() {
	return $GLOBALS['karmcp_test']['options_pages'];
}

function get_field( $key, $target = false, $format = true ) {
	return $GLOBALS['karmcp_test']['values'][ (string) $target ][ $key ] ?? null;
}

function get_field_objects( $target = false, $format = true ) {
	return $GLOBALS['karmcp_test']['field_objects'][ (string) $target ] ?? array();
}

function get_field_object( $name, $target = false, $format = true, $load_value = true ) {
	$objects = $GLOBALS['karmcp_test']['field_objects'][ (string) $target ] ?? array();
	return $objects[ $name ] ?? false;
}

function update_field( $key, $value, $target = false ): bool {
	$GLOBALS['karmcp_test']['update_field_calls'][]                    = array( $key, $value, $target );
	$GLOBALS['karmcp_test']['values'][ (string) $target ][ $key ] = $value;
	return true;
}

function acf_import_field_group( $group ) {
	$group['ID']                                = 101 + count( $GLOBALS['karmcp_test']['imported_groups'] );
	$GLOBALS['karmcp_test']['imported_groups'][] = $group;
	return $group;
}

function acf_update_field_group( $group ) {
	$GLOBALS['karmcp_test']['updated_groups'][] = $group;
	return $group;
}

function acf_update_field( $field ) {
	if ( empty( $field['key'] ) ) {
		$field['key'] = uniqid( 'field_' );
	}
	$GLOBALS['karmcp_test']['updated_fields'][] = $field;
	return $field;
}

// ---------------------------------------------------------------------------
// WordPress post-type / taxonomy registry stubs
// ---------------------------------------------------------------------------

function post_type_exists( $slug ): bool {
	return in_array( (string) $slug, $GLOBALS['karmcp_test']['existing_types'], true );
}

function taxonomy_exists( $slug ): bool {
	return in_array( (string) $slug, $GLOBALS['karmcp_test']['existing_taxes'], true );
}

// ---------------------------------------------------------------------------
// ACF 6.1+ CPT / taxonomy stubs (present in the harness = "ACF 6.1+"; the
// KarMCP_ACF_Abilities::cpt_tax_supported() gate keys off function_exists).
// ---------------------------------------------------------------------------

function acf_get_acf_post_types(): array {
	return array_values( $GLOBALS['karmcp_test']['acf_post_types'] );
}

function acf_get_acf_taxonomies(): array {
	return array_values( $GLOBALS['karmcp_test']['acf_taxonomies'] );
}

function acf_get_internal_post_type( $id, $post_type ) {
	$store = 'acf-post-type' === $post_type ? 'acf_post_types' : 'acf_taxonomies';
	foreach ( $GLOBALS['karmcp_test'][ $store ] as $item ) {
		if ( ( $item['key'] ?? '' ) === $id || ( isset( $item['ID'] ) && (int) $item['ID'] === (int) $id ) ) {
			return $item;
		}
	}
	return false;
}

function acf_import_post_type( $def ) {
	$def['ID']                                = 201 + count( $GLOBALS['karmcp_test']['imported_types'] );
	$GLOBALS['karmcp_test']['imported_types'][] = $def;
	$GLOBALS['karmcp_test']['acf_post_types'][ $def['key'] ] = $def;
	return $def;
}

function acf_import_taxonomy( $def ) {
	$def['ID']                                = 301 + count( $GLOBALS['karmcp_test']['imported_taxes'] );
	$GLOBALS['karmcp_test']['imported_taxes'][] = $def;
	$GLOBALS['karmcp_test']['acf_taxonomies'][ $def['key'] ] = $def;
	return $def;
}

function acf_update_internal_post_type( $item, $post_type ) {
	$GLOBALS['karmcp_test']['updated_internal'][] = array( 'post_type' => $post_type, 'item' => $item );
	return $item;
}

// ---------------------------------------------------------------------------
// Plugin shim + class under test
// ---------------------------------------------------------------------------

function karmcp_register_ability( string $name, array $args ) {
	$GLOBALS['karmcp_test']['abilities'][ $name ] = $args;
	return true;
}

// ---------------------------------------------------------------------------
// Meta Box (rwmb_*) stubs, fixture-driven via $GLOBALS['karmcp_test']['metabox']
// ---------------------------------------------------------------------------

if ( ! defined( 'RWMB_VER' ) ) { define( 'RWMB_VER', '5.13.1' ); }

if ( ! class_exists( 'KarMCP_Test_MB' ) ) {
	/** Minimal RW_Meta_Box test double. */
	class KarMCP_Test_MB {
		public $meta_box;
		private $object_type;
		public function __construct( array $meta_box, string $object_type = 'post' ) {
			$this->meta_box = $meta_box; $this->object_type = $object_type;
		}
		public function __get( $k ) { return $this->meta_box[ $k ] ?? null; }
		public function get_object_type() { return $this->object_type; }
	}
}
if ( ! class_exists( 'KarMCP_Test_MB_Registry' ) ) {
	class KarMCP_Test_MB_Registry {
		public function all() { return $GLOBALS['karmcp_test']['metabox']['boxes'] ?? array(); }
		public function get_by( $filter ) {
			$ot = $filter['object_type'] ?? null;
			return array_filter( $this->all(), static function ( $mb ) use ( $ot ) {
				return null === $ot || $mb->get_object_type() === $ot;
			} );
		}
	}
}
if ( ! function_exists( 'rwmb_get_registry' ) ) {
	function rwmb_get_registry( $type ) { return new KarMCP_Test_MB_Registry(); }
}
if ( ! function_exists( 'rwmb_meta' ) ) {
	function rwmb_meta( $key, $args = array(), $object_id = null ) {
		$ot  = $args['object_type'] ?? 'post';
		return $GLOBALS['karmcp_test']['metabox']['values'][ $ot ][ (string) $object_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'rwmb_set_meta' ) ) {
	function rwmb_set_meta( $object_id, $key, $value, $args = array() ) {
		$ot = $args['object_type'] ?? 'post';
		// Emulate MB: no-op for unregistered fields.
		$known = false;
		foreach ( $GLOBALS['karmcp_test']['metabox']['boxes'] ?? array() as $mb ) {
			foreach ( (array) $mb->fields as $f ) { if ( ( $f['id'] ?? '' ) === $key ) { $known = true; break 2; } }
		}
		if ( $known ) { $GLOBALS['karmcp_test']['metabox']['values'][ $ot ][ (string) $object_id ][ $key ] = $value; }
	}
}

// ---------------------------------------------------------------------------
// Contact Form 7 stubs, fixture-driven via $GLOBALS['karmcp_test']['cf7']['forms']
// ---------------------------------------------------------------------------

if ( ! defined( 'WPCF7_VERSION' ) ) { define( 'WPCF7_VERSION', '6.1.6' ); }

if ( ! class_exists( 'WPCF7_FormTag' ) ) {
	class WPCF7_FormTag {
		public $name = '';
		public $type = '';
		public $basetype = '';
		public $values = array();
		public $labels = array();
		public function __construct( array $d = array() ) {
			foreach ( $d as $k => $v ) {
				if ( property_exists( $this, $k ) ) {
					$this->{$k} = $v;
				}
			}
		}
		public function is_required(): bool {
			return str_ends_with( (string) $this->type, '*' );
		}
	}
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	class WPCF7_ContactForm {
		private $id_;
		public function __construct( $id ) {
			$this->id_ = (int) $id;
		}
		public static function find( $args = array() ) {
			$out = array();
			foreach ( array_keys( $GLOBALS['karmcp_test']['cf7']['forms'] ?? array() ) as $id ) {
				$out[] = new self( $id );
			}
			return $out;
		}
		public static function get_instance( $id ) {
			$id = (int) ( is_object( $id ) ? ( $id->ID ?? 0 ) : $id );
			return isset( $GLOBALS['karmcp_test']['cf7']['forms'][ $id ] ) ? new self( $id ) : null;
		}
		private function store(): array {
			return $GLOBALS['karmcp_test']['cf7']['forms'][ $this->id_ ] ?? array();
		}
		public function id() {
			return $this->id_;
		}
		public function name() {
			return $this->store()['slug'] ?? '';
		}
		public function title() {
			return $this->store()['title'] ?? '';
		}
		public function prop( $k ) {
			return $this->store()['props'][ $k ] ?? '';
		}
		public function set_properties( $props ) {
			foreach ( (array) $props as $k => $v ) {
				$GLOBALS['karmcp_test']['cf7']['forms'][ $this->id_ ]['props'][ $k ] = $v;
			}
		}
		public function save() {
			return true;
		}
		public function scan_form_tags() {
			$tags = array();
			foreach ( $this->store()['tags'] ?? array() as $t ) {
				$tags[] = new WPCF7_FormTag( $t );
			}
			return $tags;
		}
	}
}

require_once KARMCP_DIR . 'includes/abilities/forms/class-form-integration.php';
require_once KARMCP_DIR . 'includes/abilities/forms/class-cf7-form-builder.php';
require_once KARMCP_DIR . 'includes/abilities/forms/class-cf7-integration.php';
require_once KARMCP_DIR . 'includes/abilities/class-acf-abilities.php';
require_once KARMCP_DIR . 'includes/abilities/class-metabox-abilities.php';
require_once KARMCP_DIR . 'includes/redirects/class-redirect-store.php';
require_once KARMCP_DIR . 'includes/abilities/class-redirect-abilities.php';
