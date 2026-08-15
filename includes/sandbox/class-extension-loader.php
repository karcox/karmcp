<?php
/**
 * Extension Loader — safely loads active element extensions.
 *
 * Manifest-only, like the widget and block loaders: it never scans a directory.
 * It reads the manifest of active extensions, verifies each file against its
 * recorded sha256, includes it inside fatal-error isolation, and calls the
 * generated class's `register_hooks()`.
 *
 * An extension that fatals while loading is attributed and deactivated by the
 * shutdown handler, so the next request is clean — which matters more here than
 * for the other two artifacts, because an extension hooks into the render of
 * elements the site already uses everywhere.
 *
 * @package KarMCP
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and registers active element extensions.
 *
 * @since 1.13.0
 */
class KarMCP_Extension_Loader {

	/**
	 * Post ID of the extension currently being loaded, read by the shutdown
	 * handler to attribute a fatal.
	 *
	 * @var int|null
	 */
	private $loading = null;

	/**
	 * Whether the shutdown handler has been registered.
	 *
	 * @var bool
	 */
	private $shutdown_armed = false;

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.13.0
	 */
	public function register_hooks(): void {
		// Early on init: the extensions' own filters must be in place before
		// Elementor asks any element for its schema or its controls.
		add_action( 'init', array( $this, 'load' ), 5 );
	}

	/**
	 * Whether element extensions may load on this site.
	 *
	 * Not a per-user decision: an extension affects what visitors get. What
	 * gates it is the manifest — only what an administrator activated is in it
	 * — plus the per-file hash check. This filter is the site-wide kill switch,
	 * for when something misbehaves and the admin screen is not reachable.
	 *
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	private function has_access(): bool {
		/**
		 * Filters whether generated element extensions are loaded at all.
		 *
		 * @since 1.13.0
		 *
		 * @param bool $enabled Whether to load element extensions.
		 */
		return (bool) apply_filters( 'karmcp_load_element_extensions', true );
	}

	/**
	 * Loads every active extension from the manifest.
	 *
	 * @since 1.13.0
	 */
	public function load(): void {
		if ( ! $this->has_access() || ! class_exists( 'KarMCP_Extension_Store' ) ) {
			return;
		}

		$store    = KarMCP_Extension_Store::instance();
		$manifest = $store->read_manifest();
		if ( empty( $manifest ) ) {
			return;
		}

		$this->arm_shutdown();

		$sandbox = wp_normalize_path( $store->sandbox_base() . '/' );
		$claimed = array();

		foreach ( $manifest as $entry ) {
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			$class   = isset( $entry['class_name'] ) ? (string) $entry['class_name'] : '';
			$dir_rel = isset( $entry['dir'] ) ? (string) $entry['dir'] : '';

			if ( ! $post_id || '' === $class || '' === $dir_rel ) {
				continue;
			}

			// A second extension declaring a prop another one already declared
			// would silently win the filter. The store refuses that at
			// activation; this is the belt to that braces, for a manifest that
			// was edited or restored out of band.
			$props = (array) ( $entry['props'] ?? array() );
			if ( array_intersect( $props, $claimed ) ) {
				continue;
			}

			$path = wp_normalize_path( $sandbox . $dir_rel . '/extension.php' );

			if ( 0 !== strpos( $path, $sandbox ) || ! is_file( $path ) ) {
				continue;
			}
			if ( ! $this->verify( $path, (string) ( $entry['hash'] ?? '' ) ) ) {
				continue;
			}

			$this->loading = $post_id;

			try {
				include_once $path;
			} catch ( \Throwable $e ) {
				$this->loading = null;
				KarMCP_Extension_Store::instance()->mark_error( $post_id, $e->getMessage() );
				continue;
			}

			$this->loading = null;

			if ( ! class_exists( $class ) ) {
				continue;
			}

			try {
				$extension = new $class();
				$extension->register_hooks();
			} catch ( \Throwable $e ) {
				KarMCP_Extension_Store::instance()->mark_error( $post_id, $e->getMessage() );
				continue;
			}

			$this->register_assets( $post_id, $entry );
			$claimed = array_merge( $claimed, $props );
		}
	}

	/**
	 * Registers an extension's stylesheet and script. The generated code
	 * enqueues them when — and only when — one of its rules applies.
	 *
	 * @param int   $post_id Extension post ID.
	 * @param array $entry   Manifest entry.
	 */
	private function register_assets( int $post_id, array $entry ): void {
		$store  = KarMCP_Extension_Store::instance();
		$handle = KarMCP_Extension_Store::asset_handle( $post_id );
		$url    = $store->artifact_url( $post_id );

		if ( ! empty( $entry['css'] ) ) {
			wp_register_style( $handle . '-style', $url . '/style.css', array(), (string) $entry['css'] );
		}
		if ( ! empty( $entry['js'] ) ) {
			wp_register_script( $handle . '-script', $url . '/script.js', array(), (string) $entry['js'], true );
		}
	}

	/**
	 * Whether a file exists and matches its recorded hash.
	 *
	 * @param string $path Absolute path.
	 * @param string $hash Expected sha256.
	 * @return bool
	 */
	private function verify( string $path, string $hash ): bool {
		if ( '' === $hash ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return hash( 'sha256', (string) file_get_contents( $path ) ) === $hash;
	}

	/**
	 * Registers the shutdown handler once, to catch compile fatals a try/catch
	 * cannot.
	 */
	private function arm_shutdown(): void {
		if ( $this->shutdown_armed ) {
			return;
		}
		$this->shutdown_armed = true;
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	/**
	 * Shutdown callback: deactivate whatever was mid-load when a fatal hit.
	 *
	 * @since 1.13.0
	 */
	public function on_shutdown(): void {
		if ( null === $this->loading ) {
			return;
		}

		$error = error_get_last();
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( is_array( $error ) && in_array( $error['type'], $fatal, true ) && class_exists( 'KarMCP_Extension_Store' ) ) {
			KarMCP_Extension_Store::instance()->mark_error(
				$this->loading,
				isset( $error['message'] ) ? (string) $error['message'] : 'Fatal error while loading an element extension.'
			);
		}
	}
}
