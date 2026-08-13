<?php
/**
 * PHP Snippet Loader — runs ACTIVE snippets, isolated.
 *
 * Loading is MANIFEST-ONLY (never a directory scan): each active snippet's file
 * is checked to live inside the sandbox and to match its recorded sha256 before
 * it is included. Defining the wrapped function does not execute user code; the
 * code only runs when its hook fires or its [karmcp_snippet] shortcode renders,
 * and each of those runs inside try/catch + a shutdown handler that deactivates
 * a snippet which fatals — so a bad snippet can't repeatedly break the site.
 *
 * Snippets are a free, capability-gated feature: activation is the admin gate;
 * once active, a snippet runs for site visitors like any plugin code.
 *
 * @package KarMCP
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and executes active PHP snippets.
 *
 * @since 2.1.0
 */
class KarMCP_PHP_Snippet_Loader {

	/**
	 * Post ID currently being included or executed, for fatal attribution.
	 *
	 * @var int|null
	 */
	private $active = null;

	/**
	 * Whether the shutdown handler is registered.
	 *
	 * @var bool
	 */
	private $shutdown_armed = false;

	/**
	 * Loaded snippet contexts, keyed by post ID. Used by the shortcode dispatcher.
	 *
	 * @var array<int,string>
	 */
	private $contexts = array();

	/**
	 * Function name per loaded snippet, keyed by post ID.
	 *
	 * @var array<int,string>
	 */
	private $funcs = array();

	/**
	 * Registers the shortcode and loads active snippets.
	 *
	 * @since 2.1.0
	 */
	public function register_hooks(): void {
		add_shortcode( 'karmcp_snippet', array( $this, 'render_shortcode' ) );
		// Load now: defining the wrapped functions runs no user code, and any
		// hooks we attach below still fire (we run during plugins_loaded).
		$this->load();
	}

	/**
	 * Reads the manifest, includes each active snippet (hash-verified, inside the
	 * sandbox), and wires hook-context snippets to their hooks.
	 *
	 * @since 2.1.0
	 */
	public function load(): void {
		if ( ! class_exists( 'KarMCP_PHP_Snippet_Store' ) ) {
			return;
		}
		$manifest = KarMCP_PHP_Snippet_Store::read_manifest();
		if ( empty( $manifest ) ) {
			return;
		}

		$this->arm_shutdown();
		$sandbox = KarMCP_PHP_Snippet_Store::sandbox_dir() . '/';

		foreach ( $manifest as $entry ) {
			$post_id  = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			$func     = isset( $entry['func'] ) ? (string) $entry['func'] : '';
			$rel      = isset( $entry['php_path'] ) ? (string) $entry['php_path'] : '';
			$hash     = isset( $entry['hash'] ) ? (string) $entry['hash'] : '';
			$context  = isset( $entry['context'] ) ? (string) $entry['context'] : 'shortcode';
			$hook     = isset( $entry['hook'] ) ? (string) $entry['hook'] : '';
			$priority = isset( $entry['priority'] ) ? (int) $entry['priority'] : 10;

			if ( ! $post_id || '' === $func || '' === $rel ) {
				continue;
			}

			// Path must stay inside the sandbox (defends a poisoned manifest).
			$path = $sandbox . $rel;
			if ( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $sandbox ) ) || ! is_file( $path ) ) {
				continue;
			}

			// Tamper guard: contents must match the recorded hash.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( '' === $hash || hash( 'sha256', (string) file_get_contents( $path ) ) !== $hash ) {
				continue;
			}

			// Define the wrapped function (no user code runs here).
			$this->active = $post_id;
			try {
				include_once $path;
			} catch ( \Throwable $e ) {
				KarMCP_PHP_Snippet_Store::mark_error( $post_id, $e->getMessage() );
				$this->active = null;
				continue;
			}
			$this->active = null;

			if ( ! function_exists( $func ) ) {
				continue;
			}

			$this->funcs[ $post_id ]    = $func;
			$this->contexts[ $post_id ] = $context;

			// Wire hook-context snippets.
			if ( ( 'hook' === $context || 'both' === $context ) && '' !== $hook ) {
				$snippet_id = $post_id;
				add_action(
					$hook,
					function () use ( $snippet_id ) {
						$this->run_on_hook( $snippet_id );
					},
					$priority
				);
			}
		}
	}

	/**
	 * Executes a hook-context snippet (output goes inline, e.g. wp_footer).
	 *
	 * @since 2.1.0
	 *
	 * @param int $post_id Snippet ID.
	 */
	public function run_on_hook( int $post_id ): void {
		if ( empty( $this->funcs[ $post_id ] ) ) {
			return;
		}
		$func = $this->funcs[ $post_id ];
		if ( ! function_exists( $func ) ) {
			return;
		}
		$this->active = $post_id;
		try {
			$func();
		} catch ( \Throwable $e ) {
			KarMCP_PHP_Snippet_Store::mark_error( $post_id, $e->getMessage() );
		}
		$this->active = null;
	}

	/**
	 * Renders a shortcode-context snippet: [karmcp_snippet id="123"].
	 *
	 * @since 2.1.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array() );
		$post_id = absint( $atts['id'] );

		if ( ! $post_id || empty( $this->funcs[ $post_id ] ) ) {
			return '';
		}
		$context = isset( $this->contexts[ $post_id ] ) ? $this->contexts[ $post_id ] : '';
		if ( 'shortcode' !== $context && 'both' !== $context ) {
			return '';
		}
		$func = $this->funcs[ $post_id ];
		if ( ! function_exists( $func ) ) {
			return '';
		}

		$this->active = $post_id;
		ob_start();
		$ret = null;
		try {
			$ret = $func();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->active = null;
			KarMCP_PHP_Snippet_Store::mark_error( $post_id, $e->getMessage() );
			return '';
		}
		$out = (string) ob_get_clean();
		$this->active = null;

		if ( is_string( $ret ) || is_numeric( $ret ) ) {
			$out .= (string) $ret;
		}
		return $out;
	}

	/**
	 * Arms the shutdown handler once (catches fatals a try/catch cannot).
	 *
	 * @since 2.1.0
	 */
	private function arm_shutdown(): void {
		if ( $this->shutdown_armed ) {
			return;
		}
		$this->shutdown_armed = true;
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	/**
	 * Shutdown callback: if a snippet was mid-include or mid-execution when a
	 * fatal occurred, deactivate it so the next request recovers.
	 *
	 * @since 2.1.0
	 */
	public function on_shutdown(): void {
		if ( null === $this->active ) {
			return;
		}
		$err = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( is_array( $err ) && in_array( $err['type'], $fatal_types, true ) && class_exists( 'KarMCP_PHP_Snippet_Store' ) ) {
			KarMCP_PHP_Snippet_Store::mark_error(
				$this->active,
				isset( $err['message'] ) ? (string) $err['message'] : 'Fatal error while running snippet.'
			);
		}
	}
}
