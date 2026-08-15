<?php
/**
 * Extension Store — source of truth + sandbox for AI-generated element
 * extensions.
 *
 * Same shape as the block store: a private `karmcp_extension` post holds the
 * spec, the compiled PHP lives under `wp-content/karmcp-sandbox/extensions/<id>/`,
 * and a manifest lists only the active ones so the loader never queries the
 * database on a render.
 *
 * One rule is specific to this artifact kind. An extension declares props into
 * Elementor's shared schema, so **two active extensions may not declare the
 * same prop name**: the second filter would win and the first extension would
 * quietly stop working. The check happens at activation, where it can still be
 * refused, and the manifest carries each extension's prop names so it costs no
 * queries.
 *
 * @package KarMCP
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, compiles, and tracks AI-generated element extensions.
 *
 * @since 1.13.0
 */
class KarMCP_Extension_Store extends KarMCP_Sandbox_Store {

	const POST_TYPE = 'karmcp_extension';

	const META_SPEC       = '_karmcp_spec';
	const META_CLASS_NAME = '_karmcp_class_name';
	const META_PHP_HASH   = '_karmcp_php_hash';
	const META_CSS_HASH   = '_karmcp_css_hash';
	const META_JS_HASH    = '_karmcp_js_hash';
	const META_PROPS      = '_karmcp_props';
	const META_LAST_ERROR = '_karmcp_last_error';

	/** The files an extension owns inside its sandbox directory. */
	const ASSETS = array( 'extension.php', 'style.css', 'script.js' );

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @since 1.13.0
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
		return 'extension';
	}

	protected function sandbox_subdir(): string {
		return 'extensions';
	}

	protected function manifest_filename(): string {
		return 'extensions-manifest.json';
	}

	// -------------------------------------------------------------------------
	// Registration and access
	// -------------------------------------------------------------------------

	/**
	 * Registers the CPT. Hooked on `init`.
	 *
	 * @since 1.13.0
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
					'name' => __( 'KarMCP Element Extensions', 'karmcp' ),
				),
			)
		);
	}

	/**
	 * An extension is executable PHP that runs on every page its target
	 * elements appear on, so this is the edit-code capability.
	 *
	 * @since 1.13.0
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
	 * Absolute path to one of an extension's files.
	 *
	 * @since 1.13.0
	 *
	 * @param int    $id    Extension post ID.
	 * @param string $asset One of self::ASSETS.
	 * @return string
	 */
	public function asset_path( int $id, string $asset ): string {
		return $this->artifact_dir( $id ) . '/' . $asset;
	}

	/**
	 * Directory relative to the sandbox base, as recorded in the manifest.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return string
	 */
	public function relative_dir( int $id ): string {
		return 'extensions/' . $id;
	}

	/**
	 * Base handle for an extension's assets.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return string
	 */
	public static function asset_handle( int $id ): string {
		return 'karmcp-extension-' . $id;
	}

	/**
	 * The generated class name for an extension.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return string
	 */
	public static function class_name( int $id ): string {
		return 'KarMCP_Extension_' . $id;
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Creates an extension from a spec.
	 *
	 * @since 1.13.0
	 *
	 * @param array $spec   Structured extension spec.
	 * @param bool  $active Whether to activate immediately (default true).
	 * @return array|WP_Error
	 */
	public function create( array $spec, bool $active = true ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to create element extensions.', 'karmcp' ) );
		}

		$ensured = $this->ensure_sandbox();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		// Compile before inserting anything: a spec that cannot compile should
		// not leave a post behind.
		$compiled = KarMCP_Extension_Generator::generate( $spec, 'KarMCP_Extension_Preview' );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}

		if ( $active ) {
			$clash = $this->find_prop_clash( $spec, 0 );
			if ( is_wp_error( $clash ) ) {
				return $clash;
			}
		}

		$title = isset( $spec['meta']['title'] ) ? sanitize_text_field( (string) $spec['meta']['title'] ) : __( 'Element extension', 'karmcp' );

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

		$written = $this->write_extension( (int) $post_id, $spec );
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
	 * Replaces an extension's spec and regenerates its code.
	 *
	 * @since 1.13.0
	 *
	 * @param int   $id   Extension post ID.
	 * @param array $spec New spec.
	 * @return array|WP_Error
	 */
	public function update( int $id, array $spec ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to update element extensions.', 'karmcp' ) );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Element extension not found.', 'karmcp' ) );
		}

		$ensured = $this->ensure_sandbox();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		if ( 'publish' === $post->post_status ) {
			$clash = $this->find_prop_clash( $spec, $id );
			if ( is_wp_error( $clash ) ) {
				return $clash;
			}
		}

		$written = $this->write_extension( $id, $spec );
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

		delete_post_meta( $id, self::META_LAST_ERROR );

		$this->bump_version( $id );
		$this->rebuild_manifest();

		return $this->summary( $id );
	}

	/**
	 * Compiles and writes an extension's files.
	 *
	 * @param int   $id   Extension post ID.
	 * @param array $spec The spec.
	 * @return true|WP_Error
	 */
	private function write_extension( int $id, array $spec ) {
		$handle = self::asset_handle( $id );

		$deferred = KarMCP_Extension_Generator::deferred_css( $spec );
		$script   = isset( $spec['scripts'] ) && is_string( $spec['scripts'] ) ? trim( $spec['scripts'] ) : '';

		$php = KarMCP_Extension_Generator::generate(
			$spec,
			self::class_name( $id ),
			array(
				'style_handle'  => '' !== $deferred ? $handle . '-style' : '',
				'script_handle' => '' !== $script ? $handle . '-script' : '',
			)
		);
		if ( is_wp_error( $php ) ) {
			return $php;
		}

		$syntax = self::syntax_check( $php );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}

		if ( ! $this->write_file( $this->asset_path( $id, 'extension.php' ), $php ) ) {
			return new WP_Error( 'write_failed', __( 'Could not write the extension file to the sandbox.', 'karmcp' ) );
		}

		foreach ( array( 'style.css' => $deferred, 'script.js' => $script ) as $filename => $source ) {
			$meta = ( 'style.css' === $filename ) ? self::META_CSS_HASH : self::META_JS_HASH;

			if ( '' === $source ) {
				$this->delete_file( $this->asset_path( $id, $filename ) );
				delete_post_meta( $id, $meta );
				continue;
			}

			// The validator already refuses PHP tags; this is the belt to that
			// braces, because these are served as static files.
			$clean = (string) preg_replace( '/<\?(?:php|=)?/i', '', $source );
			$this->write_file( $this->asset_path( $id, $filename ), $clean );
			update_post_meta( $id, $meta, hash( 'sha256', $clean ) );
		}

		update_post_meta( $id, self::META_SPEC, wp_slash( wp_json_encode( $spec ) ) );
		update_post_meta( $id, self::META_CLASS_NAME, self::class_name( $id ) );
		update_post_meta( $id, self::META_PHP_HASH, hash( 'sha256', $php ) );
		update_post_meta( $id, self::META_PROPS, wp_slash( wp_json_encode( array_keys( KarMCP_Extension_Spec::prop_map( $spec ) ) ) ) );

		return true;
	}

	/**
	 * Refuses a spec whose props are already claimed by another ACTIVE
	 * extension.
	 *
	 * Elementor's props schema is one shared array: two filters declaring the
	 * same key means the last one wins and the other extension silently stops
	 * working. Better to refuse the activation than to debug that later.
	 *
	 * @param array $spec    The incoming spec.
	 * @param int   $self_id Extension being updated, excluded from the check.
	 * @return true|WP_Error
	 */
	private function find_prop_clash( array $spec, int $self_id ) {
		$incoming = array_keys( KarMCP_Extension_Spec::prop_map( $spec ) );
		if ( empty( $incoming ) ) {
			return true;
		}

		foreach ( $this->read_manifest() as $entry ) {
			$other_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			if ( ! $other_id || $other_id === $self_id ) {
				continue;
			}

			$clashes = array_intersect( $incoming, (array) ( $entry['props'] ?? array() ) );
			if ( empty( $clashes ) ) {
				continue;
			}

			return new WP_Error(
				'prop_clash',
				sprintf(
					/* translators: 1: comma-separated prop names, 2: the other extension's title */
					__( 'These props are already declared by the active extension "%2$s": %1$s. Prop names are shared across the whole site, so rename them or deactivate the other extension first.', 'karmcp' ),
					implode( ', ', $clashes ),
					get_the_title( $other_id )
				)
			);
		}

		return true;
	}

	/**
	 * Activates or deactivates an extension.
	 *
	 * @since 1.13.0
	 *
	 * @param int    $id     Extension post ID.
	 * @param string $status 'active' or 'draft'.
	 * @return array|WP_Error
	 */
	public function set_status( int $id, string $status ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to change extension status.', 'karmcp' ) );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Element extension not found.', 'karmcp' ) );
		}

		$post_status = ( 'active' === $status ) ? 'publish' : 'draft';

		if ( 'publish' === $post_status ) {
			$php = $this->get_asset( $id, 'extension.php' );
			if ( '' === $php ) {
				return new WP_Error( 'missing_file', __( 'The generated file is missing; update the extension to regenerate it.', 'karmcp' ) );
			}

			$syntax = self::syntax_check( $php );
			if ( is_wp_error( $syntax ) ) {
				update_post_meta( $id, self::META_LAST_ERROR, sanitize_text_field( $syntax->get_error_message() ) );
				return $syntax;
			}

			$spec = $this->get_spec( $id );
			if ( is_array( $spec ) ) {
				$clash = $this->find_prop_clash( $spec, $id );
				if ( is_wp_error( $clash ) ) {
					return $clash;
				}
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
	 * Deletes an extension: post, sandbox directory, manifest entry.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return array|WP_Error
	 */
	public function delete( int $id ) {
		if ( ! self::user_has_access() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete element extensions.', 'karmcp' ) );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Element extension not found.', 'karmcp' ) );
		}

		$this->rmdir_recursive( $this->artifact_dir( $id ) );
		wp_delete_post( $id, true );
		$this->rebuild_manifest();

		return array(
			'success'      => true,
			'extension_id' => $id,
		);
	}

	/**
	 * Flags an extension as having crashed and deactivates it.
	 *
	 * @since 1.13.0
	 *
	 * @param int    $id    Extension post ID.
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
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
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
	 * @since 1.13.0
	 *
	 * @param int    $id    Extension post ID.
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
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return array|WP_Error
	 */
	public function summary( int $id ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Element extension not found.', 'karmcp' ) );
		}

		$spec = $this->get_spec( $id );

		return array(
			'extension_id' => (int) $id,
			'title'        => (string) $post->post_title,
			'status'       => ( 'publish' === $post->post_status ) ? 'active' : 'draft',
			'targets'      => is_array( $spec ) ? array_values( (array) ( $spec['targets'] ?? array() ) ) : array(),
			'props'        => is_array( $spec ) ? array_keys( KarMCP_Extension_Spec::prop_map( $spec ) ) : array(),
			'last_error'   => (string) get_post_meta( $id, self::META_LAST_ERROR, true ),
			'updated'      => (string) $post->post_modified,
		);
	}

	/**
	 * Lists generated extensions.
	 *
	 * @since 1.13.0
	 *
	 * @param string $status 'active' | 'draft' | 'any' (default 'any').
	 * @return array<int, array>
	 */
	public function list_extensions( string $status = 'any' ): array {
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
	 * Rebuilds the manifest from the active extensions.
	 *
	 * @since 1.13.0
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
			$id    = (int) $post->ID;
			$props = json_decode( (string) get_post_meta( $id, self::META_PROPS, true ), true );

			$entries[] = array(
				'post_id'    => $id,
				'class_name' => (string) get_post_meta( $id, self::META_CLASS_NAME, true ),
				'dir'        => $this->relative_dir( $id ),
				'hash'       => (string) get_post_meta( $id, self::META_PHP_HASH, true ),
				'css'        => (string) get_post_meta( $id, self::META_CSS_HASH, true ),
				'js'         => (string) get_post_meta( $id, self::META_JS_HASH, true ),
				// Carried so the clash check and the loader never need a query.
				'props'      => is_array( $props ) ? $props : array(),
			);
		}

		$this->ensure_sandbox();
		$this->write_file( $this->manifest_path(), (string) wp_json_encode( $entries ) );
	}

	/**
	 * Reads the manifest.
	 *
	 * @since 1.13.0
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
	// Sandbox_Artifact contract
	// -------------------------------------------------------------------------

	/**
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return string
	 */
	public function checksum( int $id ): string {
		return KarMCP_Sandbox_Bundle::checksum( $this->assets( $id ) );
	}

	/**
	 * @param int $id Extension post ID.
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
	 * @since 1.13.0
	 *
	 * @param int $id Extension post ID.
	 * @return array|WP_Error
	 */
	public function to_bundle( int $id ) {
		$summary = $this->summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$sync = $this->sync_meta( $id );

		return KarMCP_Sandbox_Bundle::build(
			'extension',
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
	 * Imports a bundle as a NEW draft extension, recompiling from the spec
	 * rather than trusting the bundled PHP.
	 *
	 * @since 1.13.0
	 *
	 * @param array $bundle Bundle to import.
	 * @return int|WP_Error
	 */
	public function apply_bundle( array $bundle ) {
		$valid = KarMCP_Sandbox_Bundle::validate( $bundle );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'extension' !== $bundle['kind'] ) {
			return new WP_Error( 'kind_mismatch', __( 'Bundle is not an element extension.', 'karmcp' ) );
		}

		$result = $this->create( is_array( $bundle['spec'] ) ? $bundle['spec'] : array(), false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id = (int) $result['extension_id'];
		update_post_meta( $new_id, self::META_UUID, sanitize_text_field( (string) $bundle['uuid'] ) );
		update_post_meta( $new_id, self::META_ORIGIN, 'imported' );

		return $new_id;
	}

	// -------------------------------------------------------------------------
	// Uninstall
	// -------------------------------------------------------------------------

	/**
	 * Deletes every generated extension.
	 *
	 * @since 1.13.0
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

		self::instance()->rmdir_recursive( self::instance()->sandbox_base() . '/extensions' );
	}
}
