<?php
/**
 * Block Store — source of truth + sandbox for AI-generated Gutenberg blocks.
 *
 * The Gutenberg counterpart of `KarMCP_Widget_Store`, built on
 * `KarMCP_Sandbox_Store` so it inherits the cloud-ready artifact plumbing
 * (uuid, version, sync state) the widget store predates.
 *
 * The canonical record is a private `karmcp_block` post whose `_karmcp_spec`
 * meta holds the structured spec the files are compiled from, so the code is
 * always regenerable. The compiled `block.json` + `render.php` live in an
 * isolated sandbox under `wp-content/karmcp-sandbox/blocks/<id>/`, guarded
 * from direct web access. A derived manifest lists only ACTIVE blocks; the
 * loader reads it (no DB query per request) while the post stays authoritative.
 *
 * Post status is the activation flag: `publish` = active, `draft` = inactive.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, compiles, and tracks AI-generated custom blocks.
 *
 * @since 1.12.0
 */
class KarMCP_Block_Store extends KarMCP_Sandbox_Store {

	const POST_TYPE = 'karmcp_block';

	const META_SPEC        = '_karmcp_spec';
	const META_BLOCK_NAME  = '_karmcp_block_name';
	const META_JSON_HASH   = '_karmcp_json_hash';
	const META_RENDER_HASH = '_karmcp_render_hash';
	const META_CSS_HASH    = '_karmcp_css_hash';
	const META_JS_HASH     = '_karmcp_js_hash';
	const META_LAST_ERROR  = '_karmcp_last_error';

	/** The files a block owns inside its sandbox directory. */
	const ASSETS = array( 'block.json', 'render.php', 'style.css', 'script.js' );

	/**
	 * Shared instance. The admin screen, the loader and the cloud resolver all
	 * reach for the same store, and it holds no per-call state.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @since 1.12.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Sandbox_Store contract
	// -------------------------------------------------------------------------

	public function kind(): string {
		return 'block';
	}

	protected function sandbox_subdir(): string {
		return 'blocks';
	}

	protected function manifest_filename(): string {
		return 'blocks-manifest.json';
	}

	// -------------------------------------------------------------------------
	// Registration and access
	// -------------------------------------------------------------------------

	/**
	 * Registers the CPT. Hooked on `init`.
	 *
	 * @since 1.12.0
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'author' ),
				'labels'              => array(
					'name' => __( 'KarMCP Custom Blocks', 'karmcp' ),
				),
			)
		);
	}

	/**
	 * Whether the current user may manage generated blocks. Same reasoning as
	 * the widget store: a generated block is executable PHP.
	 *
	 * @since 1.12.0
	 *
	 * @return bool
	 */
	public static function user_has_access(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Paths
	// -------------------------------------------------------------------------

	/**
	 * Absolute path to one of a block's files.
	 *
	 * @since 1.12.0
	 *
	 * @param int    $id    Block post ID.
	 * @param string $asset One of self::ASSETS.
	 * @return string
	 */
	public function asset_path( int $id, string $asset ): string {
		return $this->artifact_dir( $id ) . '/' . $asset;
	}

	/**
	 * Path of a block's directory relative to the sandbox base, as recorded in
	 * the manifest so the sandbox can be relocated without a rebuild.
	 *
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return string
	 */
	public function relative_dir( int $id ): string {
		return 'blocks/' . $id;
	}

	/**
	 * Base handle for a block's assets; '-style' / '-script' are appended.
	 *
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return string
	 */
	public static function asset_handle( int $id ): string {
		return 'karmcp-block-' . $id;
	}

	/**
	 * The block type name for a post: unique, and stable for its lifetime.
	 *
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return string
	 */
	public static function block_name( int $id ): string {
		return 'karmcp/custom-' . $id;
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Creates a block from a spec.
	 *
	 * @since 1.12.0
	 *
	 * @param array $spec   Structured block spec.
	 * @param bool  $active Whether to activate immediately (default true).
	 * @return array|WP_Error Block summary on success.
	 */
	public function create( array $spec, bool $active = true ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to create blocks.', 'karmcp' ) );
		}

		$ensured = $this->ensure_sandbox();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		// Compile before inserting anything: a spec that cannot compile should
		// not leave a post behind.
		$compiled = KarMCP_Block_Generator::generate( $spec, 'karmcp/custom-preview' );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}

		$title = isset( $spec['meta']['title'] ) ? sanitize_text_field( (string) $spec['meta']['title'] ) : __( 'Custom Block', 'karmcp' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => $active ? 'publish' : 'draft',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$written = $this->write_block( (int) $post_id, $spec );
		if ( is_wp_error( $written ) ) {
			wp_delete_post( (int) $post_id, true );
			return $written;
		}

		$this->ensure_uuid( (int) $post_id );
		$this->bump_version( (int) $post_id );
		$this->rebuild_manifest();

		return $this->summary( (int) $post_id );
	}

	/**
	 * Replaces a block's spec and regenerates its files.
	 *
	 * @since 1.12.0
	 *
	 * @param int   $id   Block post ID.
	 * @param array $spec New spec.
	 * @return array|WP_Error
	 */
	public function update( int $id, array $spec ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to update blocks.', 'karmcp' ) );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Block not found.', 'karmcp' ) );
		}

		$ensured = $this->ensure_sandbox();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		$written = $this->write_block( $id, $spec );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$title = isset( $spec['meta']['title'] ) ? sanitize_text_field( (string) $spec['meta']['title'] ) : $post->post_title;
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $title,
			)
		);

		// A successful regenerate clears any prior failure.
		delete_post_meta( $id, self::META_LAST_ERROR );

		$this->bump_version( $id );
		$this->rebuild_manifest();

		return $this->summary( $id );
	}

	/**
	 * Compiles and writes a block's files, then records their hashes.
	 *
	 * @param int   $id   Block post ID.
	 * @param array $spec The spec.
	 * @return true|WP_Error
	 */
	private function write_block( int $id, array $spec ) {
		$compiled = KarMCP_Block_Generator::generate( $spec, self::block_name( $id ) );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}

		$syntax = self::syntax_check( $compiled['render.php'] );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}

		if ( ! $this->write_file( $this->asset_path( $id, 'block.json' ), $compiled['block.json'] ) ) {
			return new WP_Error( 'write_failed', __( 'Could not write block.json to the sandbox.', 'karmcp' ) );
		}
		if ( ! $this->write_file( $this->asset_path( $id, 'render.php' ), $compiled['render.php'] ) ) {
			return new WP_Error( 'write_failed', __( 'Could not write render.php to the sandbox.', 'karmcp' ) );
		}

		// Assets are served statically, so a PHP open tag in them could be
		// executed on a server with short_open_tag on. The spec validator
		// rejects them; this strip is the belt to that braces.
		foreach ( array( 'styles' => 'style.css', 'scripts' => 'script.js' ) as $key => $filename ) {
			$source = ( isset( $spec[ $key ] ) && is_string( $spec[ $key ] ) ) ? trim( $spec[ $key ] ) : '';
			$meta   = ( 'styles' === $key ) ? self::META_CSS_HASH : self::META_JS_HASH;

			if ( '' === $source ) {
				$this->delete_file( $this->asset_path( $id, $filename ) );
				delete_post_meta( $id, $meta );
				continue;
			}

			$clean = (string) preg_replace( '/<\?(?:php|=)?/i', '', $source );
			$this->write_file( $this->asset_path( $id, $filename ), $clean );
			update_post_meta( $id, $meta, hash( 'sha256', $clean ) );
		}

		update_post_meta( $id, self::META_SPEC, wp_slash( wp_json_encode( $spec ) ) );
		update_post_meta( $id, self::META_BLOCK_NAME, self::block_name( $id ) );
		update_post_meta( $id, self::META_JSON_HASH, hash( 'sha256', $compiled['block.json'] ) );
		update_post_meta( $id, self::META_RENDER_HASH, hash( 'sha256', $compiled['render.php'] ) );

		return true;
	}

	/**
	 * Parses generated PHP without running it, so a compiler bug becomes an
	 * error message here instead of a fatal on someone's page.
	 *
	 * @since 1.12.0
	 *
	 * @param string $php Generated source.
	 * @return true|WP_Error
	 */
	public static function syntax_check( string $php ) {
		try {
			token_get_all( $php, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return new WP_Error(
				'generated_syntax',
				sprintf(
					/* translators: %s: the parser's message */
					__( 'The generated block code does not parse: %s', 'karmcp' ),
					$e->getMessage()
				)
			);
		}
		return true;
	}

	/**
	 * Activates or deactivates a block.
	 *
	 * @since 1.12.0
	 *
	 * @param int    $id     Block post ID.
	 * @param string $status 'active' or 'draft'.
	 * @return array|WP_Error
	 */
	public function set_status( int $id, string $status ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to change block status.', 'karmcp' ) );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Block not found.', 'karmcp' ) );
		}

		$post_status = ( 'active' === $status ) ? 'publish' : 'draft';

		if ( 'publish' === $post_status ) {
			$render = $this->get_asset( $id, 'render.php' );
			if ( '' === $render ) {
				return new WP_Error( 'missing_file', __( 'The generated block files are missing; update the block to regenerate them.', 'karmcp' ) );
			}
			$syntax = self::syntax_check( $render );
			if ( is_wp_error( $syntax ) ) {
				update_post_meta( $id, self::META_LAST_ERROR, sanitize_text_field( $syntax->get_error_message() ) );
				return $syntax;
			}
			delete_post_meta( $id, self::META_LAST_ERROR );
		}

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $post_status,
			)
		);

		$this->rebuild_manifest();

		return $this->summary( $id );
	}

	/**
	 * Deletes a block: its post, its sandbox directory, and its manifest entry.
	 *
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return array|WP_Error
	 */
	public function delete( int $id ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete blocks.', 'karmcp' ) );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Block not found.', 'karmcp' ) );
		}

		$this->rmdir_recursive( $this->artifact_dir( $id ) );
		wp_delete_post( $id, true );
		$this->rebuild_manifest();

		return array(
			'success'  => true,
			'block_id' => $id,
		);
	}

	/**
	 * Flags a block as having crashed and deactivates it. Called by the loader's
	 * shutdown handler.
	 *
	 * @since 1.12.0
	 *
	 * @param int    $id    Block post ID.
	 * @param string $error The captured error message.
	 */
	public function mark_error( int $id, string $error ): void {
		update_post_meta( $id, self::META_LAST_ERROR, sanitize_text_field( $error ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$this->rebuild_manifest();
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Returns the decoded spec for a block.
	 *
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return array|null
	 */
	public function get_spec( int $id ): ?array {
		$raw = get_post_meta( $id, self::META_SPEC, true );
		if ( empty( $raw ) ) {
			return null;
		}
		$spec = json_decode( $raw, true );
		return is_array( $spec ) ? $spec : null;
	}

	/**
	 * Returns one of a block's generated files, or '' if absent.
	 *
	 * @since 1.12.0
	 *
	 * @param int    $id    Block post ID.
	 * @param string $asset One of self::ASSETS.
	 * @return string
	 */
	public function get_asset( int $id, string $asset ): string {
		if ( ! in_array( $asset, self::ASSETS, true ) ) {
			return '';
		}
		return $this->read_file( $this->asset_path( $id, $asset ) );
	}

	/**
	 * Summary for one block.
	 *
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return array|WP_Error
	 */
	public function summary( int $id ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Block not found.', 'karmcp' ) );
		}

		return array(
			'block_id'   => (int) $id,
			'title'      => (string) $post->post_title,
			'block_name' => (string) get_post_meta( $id, self::META_BLOCK_NAME, true ),
			'status'     => ( 'publish' === $post->post_status ) ? 'active' : 'draft',
			'last_error' => (string) get_post_meta( $id, self::META_LAST_ERROR, true ),
			'updated'    => (string) $post->post_modified,
		);
	}

	/**
	 * Lists generated blocks.
	 *
	 * @since 1.12.0
	 *
	 * @param string $status 'active' | 'draft' | 'any' (default 'any').
	 * @return array<int, array>
	 */
	public function list_blocks( string $status = 'any' ): array {
		$post_status = 'any';
		if ( 'active' === $status ) {
			$post_status = 'publish';
		} elseif ( 'draft' === $status ) {
			$post_status = 'draft';
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $post_status,
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$summary = $this->summary( (int) $post->ID );
			if ( ! is_wp_error( $summary ) ) {
				$out[] = $summary;
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Manifest
	// -------------------------------------------------------------------------

	/**
	 * Rebuilds the manifest from the active blocks.
	 *
	 * @since 1.12.0
	 */
	public function rebuild_manifest(): void {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);

		$entries = array();
		foreach ( $query->posts as $post ) {
			$id   = (int) $post->ID;
			$name = (string) get_post_meta( $id, self::META_BLOCK_NAME, true );

			// The editor descriptor is baked in here, at rebuild time, so the
			// loader never has to read a spec out of the database on a request
			// that is only rendering a page.
			$spec   = $this->get_spec( $id );
			$editor = is_array( $spec ) ? KarMCP_Block_Generator::editor_payload( $spec, $name ) : array();

			$entries[] = array(
				'post_id'     => $id,
				'block_name'  => $name,
				'dir'         => $this->relative_dir( $id ),
				'json_hash'   => (string) get_post_meta( $id, self::META_JSON_HASH, true ),
				'render_hash' => (string) get_post_meta( $id, self::META_RENDER_HASH, true ),
				'css'         => (string) get_post_meta( $id, self::META_CSS_HASH, true ),
				'js'          => (string) get_post_meta( $id, self::META_JS_HASH, true ),
				'editor'      => $editor,
			);
		}

		$this->ensure_sandbox();
		$this->write_file( $this->manifest_path(), (string) wp_json_encode( $entries ) );
	}

	/**
	 * Reads the manifest.
	 *
	 * @since 1.12.0
	 *
	 * @return array<int, array>
	 */
	public function read_manifest(): array {
		$raw = $this->read_file( $this->manifest_path() );
		if ( '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	// -------------------------------------------------------------------------
	// Sandbox_Artifact contract (export / import)
	// -------------------------------------------------------------------------

	/**
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return string
	 */
	public function checksum( int $id ): string {
		return KarMCP_Sandbox_Bundle::checksum( $this->assets( $id ) );
	}

	/**
	 * The block's generated files, for the bundle envelope.
	 *
	 * @param int $id Block post ID.
	 * @return array<string, string>
	 */
	private function assets( int $id ): array {
		$assets = array();
		foreach ( self::ASSETS as $asset ) {
			$contents = $this->get_asset( $id, $asset );
			if ( '' !== $contents ) {
				$assets[ $asset ] = $contents;
			}
		}
		return $assets;
	}

	/**
	 * @since 1.12.0
	 *
	 * @param int $id Block post ID.
	 * @return array|WP_Error
	 */
	public function to_bundle( int $id ) {
		$summary = $this->summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$sync = $this->sync_meta( $id );

		return KarMCP_Sandbox_Bundle::build(
			'block',
			$sync['uuid'],
			array(
				'title'       => (string) ( $summary['title'] ?? '' ),
				'description' => '',
				'author'      => (string) ( wp_get_current_user()->user_login ?? '' ),
				'license'     => 'GPL-2.0-or-later',
			),
			$this->get_spec( $id ) ?? array(),
			$this->assets( $id ),
			max( 1, $sync['version'] ),
			$sync['updated_at'] ?: gmdate( 'c' )
		);
	}

	/**
	 * Imports a bundle as a NEW draft block. The spec is recompiled locally
	 * rather than trusting the bundled files — an imported artifact is code, and
	 * this way the code that runs is always the code this build produces.
	 *
	 * @since 1.12.0
	 *
	 * @param array $bundle Bundle to import.
	 * @return int|WP_Error New local block post ID.
	 */
	public function apply_bundle( array $bundle ) {
		$valid = KarMCP_Sandbox_Bundle::validate( $bundle );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'block' !== $bundle['kind'] ) {
			return new WP_Error( 'kind_mismatch', __( 'Bundle is not a block.', 'karmcp' ) );
		}

		$result = $this->create( is_array( $bundle['spec'] ) ? $bundle['spec'] : array(), false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id = (int) $result['block_id'];
		update_post_meta( $new_id, self::META_UUID, sanitize_text_field( (string) $bundle['uuid'] ) );
		update_post_meta( $new_id, self::META_ORIGIN, 'imported' );

		return $new_id;
	}

	// -------------------------------------------------------------------------
	// Uninstall
	// -------------------------------------------------------------------------

	/**
	 * Deletes every generated block. The sandbox tree itself is removed by the
	 * widget store's cleanup, which owns the shared base directory — this only
	 * has to take the posts with it, because a block post IS the source of
	 * executable code.
	 *
	 * @since 1.12.0
	 */
	public static function uninstall_cleanup(): void {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $id ) {
			wp_delete_post( (int) $id, true );
		}

		self::instance()->rmdir_recursive( self::instance()->sandbox_base() . '/blocks' );
	}
}
