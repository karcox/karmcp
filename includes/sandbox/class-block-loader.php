<?php
/**
 * Block Loader — safely registers active sandbox blocks with WordPress.
 *
 * Manifest-only, like the widget loader: it never scans a directory. It reads
 * the manifest of active blocks, verifies each file against its recorded
 * sha256 (tamper guard), and only then registers the block type. The render
 * file is included inside fatal-error isolation, and a shutdown handler
 * attributes a fatal to the block that was rendering and deactivates it — so a
 * bad block cannot repeatedly white-screen a page.
 *
 * The editor side needs no build step and no per-block JavaScript: every block
 * is server-rendered, and one shared script turns the manifest's editor
 * descriptors into an inspector panel with a ServerSideRender preview. That is
 * the same approach the Themer blocks already use here.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the block category and loads active generated blocks.
 *
 * @since 1.12.0
 */
class KarMCP_Block_Loader {

	/** Inserter category slug. */
	const CATEGORY = 'karmcp-custom';

	/** Handle of the shared editor script. */
	const SCRIPT = 'karmcp-sandbox-blocks';

	/**
	 * Post ID of the block currently rendering, read by the shutdown handler.
	 *
	 * @var int|null
	 */
	private $rendering = null;

	/**
	 * Whether the shutdown handler has been registered.
	 *
	 * @var bool
	 */
	private $shutdown_armed = false;

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.12.0
	 */
	public function register_hooks(): void {
		add_filter( 'block_categories_all', array( $this, 'register_category' ) );
		// Priority 20: after the CPT registers on init, and late enough that
		// the editor assets exist to depend on.
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
	}

	/**
	 * Whether generated blocks may load on this site.
	 *
	 * Not a per-user decision — a block renders for logged-out visitors too. The
	 * gate is the manifest (only what an administrator activated is in it) plus
	 * the per-file hash check. This filter is the site-wide kill switch.
	 *
	 * @since 1.12.0
	 *
	 * @return bool
	 */
	private function has_access(): bool {
		/**
		 * Filters whether generated blocks are registered at all.
		 *
		 * @since 1.12.0
		 *
		 * @param bool $enabled Whether to load generated blocks.
		 */
		return (bool) apply_filters( 'karmcp_load_generated_blocks', true );
	}

	/**
	 * Adds the "Custom (KarMCP)" inserter category.
	 *
	 * @since 1.12.0
	 *
	 * @param array $categories Existing categories.
	 * @return array
	 */
	public function register_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}

		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && self::CATEGORY === $category['slug'] ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Custom (KarMCP)', 'karmcp' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * Registers every active block from the manifest.
	 *
	 * @since 1.12.0
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) || ! $this->has_access() || ! class_exists( 'KarMCP_Block_Store' ) ) {
			return;
		}

		$store    = KarMCP_Block_Store::instance();
		$manifest = $store->read_manifest();
		if ( empty( $manifest ) ) {
			return;
		}

		$this->register_editor_script();

		$sandbox  = wp_normalize_path( $store->sandbox_base() . '/' );
		$payloads = array();

		foreach ( $manifest as $entry ) {
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			$name    = isset( $entry['block_name'] ) ? (string) $entry['block_name'] : '';
			$dir_rel = isset( $entry['dir'] ) ? (string) $entry['dir'] : '';

			if ( ! $post_id || '' === $name || '' === $dir_rel ) {
				continue;
			}

			$dir = wp_normalize_path( $sandbox . $dir_rel );

			// The manifest is a file: treat its paths as untrusted and refuse
			// anything that resolves outside the sandbox.
			if ( 0 !== strpos( $dir, $sandbox ) ) {
				continue;
			}
			if ( ! $this->verify( $dir . '/block.json', (string) ( $entry['json_hash'] ?? '' ) )
				|| ! $this->verify( $dir . '/render.php', (string) ( $entry['render_hash'] ?? '' ) ) ) {
				continue;
			}
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}

			$args = array(
				'render_callback' => function ( $attributes ) use ( $post_id, $dir ) {
					return $this->render( $post_id, $dir . '/render.php', is_array( $attributes ) ? $attributes : array() );
				},
				'editor_script'   => self::SCRIPT,
			);

			$style_handle = $this->register_block_assets( $post_id, $entry );
			if ( '' !== $style_handle ) {
				$args['style'] = $style_handle;
			}

			register_block_type( $dir, $args );

			if ( ! empty( $entry['editor'] ) && is_array( $entry['editor'] ) ) {
				$payloads[ $name ] = $entry['editor'];
			}
		}

		if ( ! empty( $payloads ) ) {
			wp_localize_script(
				self::SCRIPT,
				'karmcpSandboxBlocks',
				array(
					'category' => self::CATEGORY,
					'blocks'   => $payloads,
				)
			);
		}
	}

	/**
	 * Registers the shared editor script.
	 */
	private function register_editor_script(): void {
		if ( wp_script_is( self::SCRIPT, 'registered' ) ) {
			return;
		}

		$path    = KARMCP_DIR . 'assets/js/sandbox-blocks.js';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : KARMCP_VERSION;

		wp_register_script(
			self::SCRIPT,
			KARMCP_URL . 'assets/js/sandbox-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			$version,
			true
		);
	}

	/**
	 * Registers a block's own stylesheet and script, if it ships any.
	 *
	 * @param int   $post_id Block post ID.
	 * @param array $entry   Manifest entry.
	 * @return string The style handle to attach, or '' when there is none.
	 */
	private function register_block_assets( int $post_id, array $entry ): string {
		$store  = KarMCP_Block_Store::instance();
		$base   = KarMCP_Block_Store::asset_handle( $post_id );
		$url    = $store->artifact_url( $post_id );
		$handle = '';

		if ( ! empty( $entry['css'] ) ) {
			$handle = $base . '-style';
			wp_register_style( $handle, $url . '/style.css', array(), (string) $entry['css'] );
		}

		if ( ! empty( $entry['js'] ) ) {
			// Registered only. The enqueue happens in render(), so a block's
			// script loads on the pages that actually use the block — the same
			// deal WordPress gives the stylesheet through the 'style' handle.
			wp_register_script( $base . '-script', $url . '/script.js', array(), (string) $entry['js'], true );
		}

		return $handle;
	}

	/**
	 * Renders one block by including its verified render file.
	 *
	 * @param int    $post_id    Block post ID.
	 * @param string $path       Absolute path to render.php.
	 * @param array  $attributes Block attributes.
	 * @return string
	 */
	private function render( int $post_id, string $path, array $attributes ): string {
		if ( ! is_file( $path ) ) {
			return '';
		}

		$this->arm_shutdown();
		$this->rendering = $post_id;

		// The block is on this page, so its behaviour is wanted here and only
		// here. Registered in the footer, so enqueuing mid-render is in time.
		$script = KarMCP_Block_Store::asset_handle( $post_id ) . '-script';
		if ( wp_script_is( $script, 'registered' ) && ! wp_script_is( $script, 'enqueued' ) ) {
			wp_enqueue_script( $script );
		}

		ob_start();
		try {
			// The generated file reads $attributes from this scope and echoes.
			include $path;
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->rendering = null;
			if ( class_exists( 'KarMCP_Block_Store' ) ) {
				KarMCP_Block_Store::instance()->mark_error( $post_id, $e->getMessage() );
			}
			return '';
		}
		$this->rendering = null;

		return (string) ob_get_clean();
	}

	/**
	 * Whether a file exists and matches its recorded hash.
	 *
	 * @param string $path Absolute path.
	 * @param string $hash Expected sha256.
	 * @return bool
	 */
	private function verify( string $path, string $hash ): bool {
		if ( '' === $hash || ! is_file( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return hash( 'sha256', (string) file_get_contents( $path ) ) === $hash;
	}

	/**
	 * Registers the shutdown handler once, to catch fatals a try/catch cannot.
	 */
	private function arm_shutdown(): void {
		if ( $this->shutdown_armed ) {
			return;
		}
		$this->shutdown_armed = true;
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	/**
	 * Shutdown callback: if a block was mid-render when a fatal happened,
	 * deactivate it so the page recovers on the next request.
	 *
	 * @since 1.12.0
	 */
	public function on_shutdown(): void {
		if ( null === $this->rendering ) {
			return;
		}

		$error = error_get_last();
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( is_array( $error ) && in_array( $error['type'], $fatal, true ) && class_exists( 'KarMCP_Block_Store' ) ) {
			KarMCP_Block_Store::instance()->mark_error(
				$this->rendering,
				isset( $error['message'] ) ? (string) $error['message'] : 'Fatal error while rendering block.'
			);
		}
	}
}
