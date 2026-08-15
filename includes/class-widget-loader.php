<?php
/**
 * Widget Loader — safely loads active sandbox widgets into Elementor.
 *
 * Loading is MANIFEST-ONLY: the loader never scans-and-includes a directory. It
 * reads the manifest of active widgets, verifies each file against its recorded
 * sha256 (tamper guard), and includes it inside fatal-error isolation. If an
 * include triggers a compile/parse fatal, a shutdown handler attributes it to
 * the offending widget and deactivates it, so the next request is clean — a bad
 * widget can never repeatedly white-screen the site.
 *
 * The whole loader is Pro-gated: on a free/unlicensed site nothing is loaded.
 *
 * @package KarMCP
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the custom category and loads active generated widgets.
 *
 * @since 1.9.0
 */
class KarMCP_Widget_Loader {

	/**
	 * Category slug for generated widgets in the Elementor panel.
	 *
	 * @var string
	 */
	const CATEGORY = 'karmcp-custom';

	/**
	 * Post ID of the widget currently being included, if any. Read by the
	 * shutdown handler to attribute a fatal to the right widget.
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
	 * Whether generated widgets may load on this site (Pro gate).
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	private function has_access(): bool {
		/**
		 * Whether generated widgets may load on this site.
		 *
		 * Loading is not a per-user decision — a widget renders for logged-out
		 * visitors too — so there is no capability check here. What gates a
		 * widget is the manifest: only widgets an administrator activated reach
		 * it, and only files matching their recorded hash are included. This
		 * filter is the site-wide kill switch, for an owner who wants the
		 * builder available in wp-admin but nothing of it executing on the
		 * front end.
		 *
		 * @since 1.12.0
		 *
		 * @param bool $enabled Whether to load generated widgets.
		 */
		return (bool) apply_filters( 'karmcp_load_generated_widgets', true );
	}

	/**
	 * Registers Elementor hooks.
	 *
	 * @since 1.9.0
	 */
	public function register_hooks(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		// Register per-widget CSS/JS handles so Elementor can enqueue them via
		// get_style_depends()/get_script_depends() when a widget is on the page.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers the style/script handles for active widgets that ship assets.
	 * Elementor enqueues a registered handle only when its widget renders, so
	 * this stays cheap (registration is just metadata).
	 *
	 * @since 1.9.0
	 */
	public function register_assets(): void {
		if ( ! $this->has_access() || ! class_exists( 'KarMCP_Widget_Store' ) ) {
			return;
		}
		foreach ( KarMCP_Widget_Store::read_manifest() as $entry ) {
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			if ( ! $post_id ) {
				continue;
			}
			$base = KarMCP_Widget_Store::asset_handle( $post_id );
			$url  = KarMCP_Widget_Store::widget_url( $post_id );

			if ( ! empty( $entry['css'] ) ) {
				wp_register_style( $base . '-style', $url . '/style.css', array(), (string) $entry['css'] );
			}
			if ( ! empty( $entry['js'] ) ) {
				wp_register_script( $base . '-script', $url . '/script.js', array( 'jquery' ), (string) $entry['js'], true );
			}
		}
	}

	/**
	 * Registers the "Custom (KarMCP)" widget category.
	 *
	 * @since 1.9.0
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		if ( ! $this->has_access() ) {
			return;
		}
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Custom (KarMCP)', 'karmcp' ),
				'icon'  => 'eicon-code',
			)
		);
	}

	/**
	 * Loads and registers all active generated widgets.
	 *
	 * @since 1.9.0
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		if ( ! $this->has_access() || ! class_exists( 'KarMCP_Widget_Store' ) ) {
			return;
		}

		$manifest = KarMCP_Widget_Store::read_manifest();
		if ( empty( $manifest ) ) {
			return;
		}

		$this->arm_shutdown();
		$sandbox = KarMCP_Widget_Store::sandbox_dir() . '/';

		foreach ( $manifest as $entry ) {
			$post_id    = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			$class_name = isset( $entry['class_name'] ) ? (string) $entry['class_name'] : '';
			$rel        = isset( $entry['php_path'] ) ? (string) $entry['php_path'] : '';
			$hash       = isset( $entry['hash'] ) ? (string) $entry['hash'] : '';

			if ( ! $post_id || '' === $class_name || '' === $rel ) {
				continue;
			}

			// Path must stay inside the sandbox (defense against a poisoned manifest).
			$path = $sandbox . $rel;
			if ( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $sandbox ) ) || ! is_file( $path ) ) {
				continue;
			}

			// Tamper guard: only load files whose contents match the recorded hash.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( '' !== $hash && hash( 'sha256', (string) file_get_contents( $path ) ) !== $hash ) {
				continue;
			}

			$this->loading = $post_id;
			try {
				include_once $path;
			} catch ( \Throwable $e ) {
				// Runtime throwable during include — record and skip.
				if ( class_exists( 'KarMCP_Widget_Store' ) ) {
					KarMCP_Widget_Store::mark_error( $post_id, $e->getMessage() );
				}
				$this->loading = null;
				continue;
			}
			$this->loading = null;

			if ( class_exists( $class_name ) ) {
				try {
					$widgets_manager->register( new $class_name() );
				} catch ( \Throwable $e ) {
					KarMCP_Widget_Store::mark_error( $post_id, $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Registers the shutdown handler once, to catch compile/parse fatals that a
	 * try/catch cannot (a parse error in an included file halts the request).
	 *
	 * @since 1.9.0
	 */
	private function arm_shutdown(): void {
		if ( $this->shutdown_armed ) {
			return;
		}
		$this->shutdown_armed = true;
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	/**
	 * Shutdown callback. If a widget was mid-include when a fatal occurred,
	 * deactivate it so the site recovers on the next request.
	 *
	 * @since 1.9.0
	 */
	public function on_shutdown(): void {
		if ( null === $this->loading ) {
			return;
		}
		$err = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( is_array( $err ) && in_array( $err['type'], $fatal_types, true ) ) {
			if ( class_exists( 'KarMCP_Widget_Store' ) ) {
				KarMCP_Widget_Store::mark_error(
					$this->loading,
					isset( $err['message'] ) ? (string) $err['message'] : 'Fatal error while loading widget.'
				);
			}
		}
	}
}
