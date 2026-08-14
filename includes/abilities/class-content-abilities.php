<?php
/**
 * General WordPress Content MCP abilities.
 *
 * Eight tools for managing posts, pages, and any custom post type via MCP —
 * the plugin's first step beyond Elementor. Built on WP core functions, gated
 * by WordPress capabilities, and deliberately Elementor-agnostic: these tools
 * operate on post_content (classic HTML or block markup) and never touch
 * `_elementor_data`. To edit an Elementor-built page, use the Elementor tools.
 *
 * @package KarMCP
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the WordPress content abilities.
 *
 * @since 3.0.0
 */
class KarMCP_Content_Abilities {

	/**
	 * Names of the abilities actually registered by register().
	 *
	 * Populated at the top of each register_* method so get_ability_names()
	 * reports only the tools that exist this build — no phantom names leak to
	 * the MCP server's create_server() call.
	 *
	 * @since 3.0.0
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Returns the names of all abilities registered by this group.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers this group's MCP abilities.
	 *
	 * @since 3.0.0
	 */
	public function register(): void {
		$this->register_list_post_types();
		$this->register_list_taxonomies();
		$this->register_create_post();
		$this->register_get_post();
		$this->register_update_post();
		$this->register_delete_post();
		$this->register_list_posts();
		$this->register_set_post_terms();
	}

	// ---------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------

	/**
	 * Read/query permission: the user must be able to edit posts.
	 *
	 * @since 3.0.0
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Create permission: the `create_posts` capability of the TARGET type.
	 *
	 * For `post` and `page` that resolves to `edit_posts`, which is the meta-cap
	 * floor core uses for authoring a draft (publishing is gated separately at
	 * save time). But a type registered with its own capabilities — KarMCP's own
	 * skills want `manage_options`, WooCommerce products want `edit_products` —
	 * publishes different names, and `wp_insert_post()` enforces none of them.
	 * Checking the generic capability alone would let anyone who can write a
	 * blog post create a post of any registered type.
	 *
	 * @since 3.0.0
	 * @since 1.2.0 Resolves the capability against the requested post type.
	 * @param array|null $input Tool input; may carry a `post_type`.
	 * @return bool
	 */
	public function check_create_permission( $input = null ): bool {
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( '' === $post_type ) {
			$post_type = 'post';
		}
		return current_user_can( self::type_cap( $post_type, 'create_posts', 'edit_posts' ) );
	}

	/**
	 * The capability a post type requires for one of the write gates.
	 *
	 * Falls back to the generic post capability, which is the right answer for a
	 * type that never declared any of its own.
	 *
	 * @since 1.2.0
	 * @param string $post_type Post type name.
	 * @param string $key       Cap key, e.g. `create_posts`, `publish_posts`.
	 * @param string $fallback  Generic capability to use when the type has none.
	 * @return string
	 */
	public static function type_cap( string $post_type, string $key, string $fallback ): string {
		$object = post_type_exists( $post_type ) ? get_post_type_object( $post_type ) : null;
		$caps   = is_object( $object ) && isset( $object->cap ) ? $object->cap : null;

		if ( is_object( $caps ) && ! empty( $caps->$key ) && is_string( $caps->$key ) ) {
			return $caps->$key;
		}
		if ( is_array( $caps ) && ! empty( $caps[ $key ] ) && is_string( $caps[ $key ] ) ) {
			return $caps[ $key ];
		}
		return $fallback;
	}

	/**
	 * Edit permission: `edit_posts` plus per-post ownership when a post_id is given.
	 *
	 * Returns WP_Error rather than false on failure. The adapter renders a bare
	 * `false` as "Permission denied" with nothing else — not the tool, not the
	 * post, not the capability — and a per-post meta capability is precisely the
	 * kind that fails for reasons the caller cannot guess: `edit_post` resolves
	 * against the target's post type, so a capability manager can revoke it for
	 * one type while `edit_posts` still passes. Naming the post and the
	 * capability is the whole difference between a dead end and a fix.
	 *
	 * @since 3.0.0
	 * @param array|null $input Tool input; may carry a `post_id`.
	 * @return true|\WP_Error
	 */
	public function check_edit_permission( $input = null ) {
		return $this->check_post_capability( $input, 'edit_posts', 'edit_post' );
	}

	/**
	 * Delete permission: `delete_posts` plus per-post ownership when a post_id is given.
	 *
	 * @since 3.0.0
	 * @param array|null $input Tool input; may carry a `post_id`.
	 * @return true|\WP_Error
	 */
	public function check_delete_permission( $input = null ) {
		return $this->check_post_capability( $input, 'delete_posts', 'delete_post' );
	}

	/**
	 * Shared body of the two permission checks above: a general capability, then
	 * the per-post meta capability when the input names a post.
	 *
	 * @since 1.2.1
	 *
	 * @param array|null $input        Tool input; may carry a `post_id`.
	 * @param string     $general_cap  Capability required regardless of target.
	 * @param string     $meta_cap     Meta capability checked against the target.
	 * @return true|\WP_Error
	 */
	private function check_post_capability( $input, string $general_cap, string $meta_cap ) {
		if ( ! current_user_can( $general_cap ) ) {
			return new \WP_Error(
				'missing_capability',
				sprintf(
					/* translators: %s: capability name */
					__( 'The current user does not have the "%s" capability.', 'karmcp' ),
					$general_cap
				),
				array( 'required_capability' => $general_cap )
			);
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id || current_user_can( $meta_cap, $post_id ) ) {
			return true;
		}

		$post_type = get_post_type( $post_id );

		return new \WP_Error(
			'cannot_edit_post',
			sprintf(
				/* translators: 1: meta capability name, 2: post ID, 3: post type */
				__( 'The current user has "%1$s" in general but not on post %2$d (post type %3$s). This is a meta capability resolved against that post type and the post\'s author and status, so a capability manager can revoke it for one post type while the generic capability still passes.', 'karmcp' ),
				$meta_cap,
				$post_id,
				false !== $post_type ? $post_type : 'unknown'
			),
			array(
				'required_capability' => $meta_cap,
				'post_id'             => $post_id,
				'post_type'           => false !== $post_type ? $post_type : null,
			)
		);
	}

	// ---------------------------------------------------------------------
	// list-post-types
	// ---------------------------------------------------------------------

	private function register_list_post_types(): void {
		$this->ability_names[] = 'karmcp/list-post-types';
		karmcp_register_ability(
			'karmcp/list-post-types',
			array(
				'label'               => __( 'List Post Types', 'karmcp' ),
				'description'         => __( 'Lists registered WordPress post types (posts, pages, and any custom post type) so you can target the right one with create-post / list-posts. Returns name, label, whether it is hierarchical, and its taxonomies.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_post_types' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'public_only' => array( 'type' => 'boolean', 'description' => __( 'Only public, non-internal types. Default: true.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_types' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_post_types( $input ): array {
		$public_only = ! isset( $input['public_only'] ) || (bool) $input['public_only'];
		$args        = $public_only ? array( 'public' => true ) : array();
		$objects     = get_post_types( $args, 'objects' );

		$internal = $this->internal_post_types();
		$rows     = array();
		foreach ( $objects as $name => $obj ) {
			if ( $public_only && in_array( $name, $internal, true ) ) {
				continue;
			}
			$rows[] = array(
				'name'         => (string) $name,
				'label'        => (string) ( $obj->label ?? $name ),
				'hierarchical' => (bool) ( $obj->hierarchical ?? false ),
				'public'       => (bool) ( $obj->public ?? false ),
				'supports'     => function_exists( 'get_all_post_type_supports' ) ? array_keys( get_all_post_type_supports( $name ) ) : array(),
				'taxonomies'   => function_exists( 'get_object_taxonomies' ) ? array_values( get_object_taxonomies( $name ) ) : array(),
			);
		}
		return array( 'post_types' => $rows );
	}

	// ---------------------------------------------------------------------
	// list-taxonomies
	// ---------------------------------------------------------------------

	private function register_list_taxonomies(): void {
		$this->ability_names[] = 'karmcp/list-taxonomies';
		karmcp_register_ability(
			'karmcp/list-taxonomies',
			array(
				'label'               => __( 'List Taxonomies', 'karmcp' ),
				'description'         => __( 'Lists registered taxonomies (categories, tags, custom taxonomies) and optionally their terms, so you can categorize content with set-post-terms or the create-post "terms" param.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_taxonomies' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'     => array( 'type' => 'string', 'description' => __( 'Only taxonomies attached to this post type.', 'karmcp' ) ),
						'include_terms' => array( 'type' => 'boolean', 'description' => __( 'Embed each taxonomy\'s terms (capped). Default: false.', 'karmcp' ) ),
						'terms_limit'   => array( 'type' => 'integer', 'description' => __( 'Max terms per taxonomy when include_terms. Default: 100.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomies' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_taxonomies( $input ): array {
		$post_type     = sanitize_key( $input['post_type'] ?? '' );
		$include_terms = ! empty( $input['include_terms'] );
		$limit         = max( 1, min( 500, absint( $input['terms_limit'] ?? 100 ) ) );

		$objects = $post_type
			? get_taxonomies( array( 'object_type' => array( $post_type ) ), 'objects' )
			: get_taxonomies( array(), 'objects' );

		$rows = array();
		foreach ( $objects as $name => $obj ) {
			$row = array(
				'name'         => (string) $name,
				'label'        => (string) ( $obj->label ?? $name ),
				'hierarchical' => (bool) ( $obj->hierarchical ?? false ),
				'object_types' => array_values( (array) ( $obj->object_type ?? array() ) ),
			);
			if ( $include_terms ) {
				$terms        = get_terms( array( 'taxonomy' => $name, 'hide_empty' => false, 'number' => $limit ) );
				$row['terms'] = array();
				foreach ( (array) $terms as $t ) {
					if ( is_object( $t ) ) {
						$row['terms'][] = array(
							'term_id' => (int) $t->term_id,
							'name'    => (string) $t->name,
							'slug'    => (string) $t->slug,
							'parent'  => (int) ( $t->parent ?? 0 ),
							'count'   => (int) ( $t->count ?? 0 ),
						);
					}
				}
			}
			$rows[] = $row;
		}
		return array( 'taxonomies' => $rows );
	}

	// ---------------------------------------------------------------------
	// Shared write helpers
	// ---------------------------------------------------------------------

	/** Internal/non-writable post types (never targets for create/update/delete). */
	private function internal_post_types(): array {
		return array_merge(
			array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'attachment' ),
			self::managed_post_types()
		);
	}

	/**
	 * The post types KarMCP stores its own artifacts in.
	 *
	 * Each has a dedicated tool with a guard the generic content tools do not
	 * run: a PHP snippet passes the validator before it is stored, a skill needs
	 * an administrator, a Themer template carries render conditions. Writing one
	 * through create-post would be a way around that guard, so these are never
	 * targets here.
	 *
	 * @since 1.2.0
	 * @return string[]
	 */
	public static function managed_post_types(): array {
		return array( 'karmcp_skill', 'karmcp_php_snippet', 'karmcp_widget', 'karmcp_theme_tpl', 'karmcp_theme_php', 'karmcp_kit_backup' );
	}

	/** Whether a post type may be written to. */
	private function is_writable_post_type( string $post_type ): bool {
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return false;
		}
		return ! in_array( $post_type, $this->internal_post_types(), true );
	}

	/**
	 * Validate a meta map against the protected-meta guard.
	 *
	 * @param array $meta
	 * @return true|\WP_Error
	 */
	private function reject_protected_meta( array $meta ) {
		$allowed = (array) apply_filters( 'karmcp_content_allowed_protected_meta', array() );
		foreach ( array_keys( $meta ) as $key ) {
			$key = (string) $key;
			if ( in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, 'post' ) ) {
				return new \WP_Error( 'protected_meta', sprintf( /* translators: %s: meta key */ __( 'Refusing to write protected meta key "%s". Use the featured_image param for thumbnails; Elementor data is never writable here.', 'karmcp' ), $key ) );
			}
		}
		return true;
	}

	/**
	 * Apply terms / meta / featured image to a post after create/update.
	 *
	 * @param int   $post_id
	 * @param array $input
	 * @param array $warnings     By reference; non-fatal problems are appended.
	 * @param bool  $append_terms
	 */
	private function apply_write_extras( int $post_id, array $input, array &$warnings, bool $append_terms = false ): void {
		if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
			foreach ( $input['terms'] as $taxonomy => $terms ) {
				$taxonomy = sanitize_key( $taxonomy );
				$res      = wp_set_object_terms( $post_id, array_values( (array) $terms ), $taxonomy, $append_terms );
				if ( is_wp_error( $res ) ) {
					$warnings[] = sprintf( 'terms[%s]: %s', $taxonomy, $res->get_error_message() );
				}
			}
		}
		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			foreach ( $input['meta'] as $key => $value ) {
				update_post_meta( $post_id, sanitize_key( $key ), $value );
			}
		}
		if ( array_key_exists( 'featured_image', $input ) ) {
			$fi = $input['featured_image'];
			if ( null === $fi ) {
				delete_post_thumbnail( $post_id );
			} elseif ( is_array( $fi ) && ! empty( $fi['id'] ) ) {
				set_post_thumbnail( $post_id, absint( $fi['id'] ) );
			} elseif ( is_array( $fi ) && ! empty( $fi['url'] ) ) {
				if ( ! current_user_can( 'upload_files' ) ) {
					$warnings[] = 'featured_image: upload_files capability required to sideload a URL.';
				} else {
					// media_handle_sideload() and its deps live in wp-admin/includes,
					// which are NOT loaded on the REST/WP-CLI requests the MCP server
					// runs in — load them on demand (matches stock-image-abilities).
					if ( ! function_exists( 'media_handle_sideload' ) ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
						require_once ABSPATH . 'wp-admin/includes/media.php';
						require_once ABSPATH . 'wp-admin/includes/image.php';
					}
					// SSRF-guarded download: KarMCP_Url_Guard::safe_download blocks
					// private/reserved/loopback hosts (e.g. cloud metadata
					// 169.254.169.254) and re-validates every redirect hop, which
					// plain media_sideload_image()/download_url() do not.
					$fi_url   = esc_url_raw( (string) $fi['url'] );
					$tmp_file = $fi_url ? KarMCP_Url_Guard::safe_download( $fi_url, 30 ) : new \WP_Error( 'invalid_url', 'empty url' );
					if ( is_wp_error( $tmp_file ) ) {
						$warnings[] = 'featured_image: URL rejected or download failed (' . $tmp_file->get_error_message() . ').';
					} else {
						$url_path  = wp_parse_url( $fi_url, PHP_URL_PATH );
						$fi_name   = $url_path ? basename( $url_path ) : 'image.jpg';
						if ( ! preg_match( '/\.\w+$/', $fi_name ) ) {
							$fi_name .= '.jpg';
						}
						$att = media_handle_sideload(
							array( 'name' => sanitize_file_name( $fi_name ), 'tmp_name' => $tmp_file ),
							$post_id
						);
						if ( is_wp_error( $att ) ) {
							if ( file_exists( $tmp_file ) ) {
								wp_delete_file( $tmp_file );
							}
							$warnings[] = 'featured_image: ' . $att->get_error_message();
						} else {
							set_post_thumbnail( $post_id, (int) $att );
						}
					}
				}
			}
		}
	}

	/** Allowed post statuses for create/update. */
	private function valid_statuses(): array {
		return array( 'draft', 'publish', 'pending', 'private', 'future' );
	}

	// ---------------------------------------------------------------------
	// create-post
	// ---------------------------------------------------------------------

	private function register_create_post(): void {
		$this->ability_names[] = 'karmcp/create-post';
		karmcp_register_ability(
			'karmcp/create-post',
			array(
				'label'               => __( 'Create Post', 'karmcp' ),
				'description'         => __( 'Creates a post, page, or any custom post type. Sets title, content (classic HTML or Gutenberg block markup), excerpt, status, slug, author, date, parent/menu_order, plus optional taxonomy terms, custom-field meta, and a featured image, in one call. This writes post_content; to build an Elementor page use the Elementor tools instead.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_create_post' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'      => array( 'type' => 'string', 'description' => __( 'Target type (post, page, or a CPT from list-post-types). Default: post.', 'karmcp' ) ),
						'title'          => array( 'type' => 'string', 'description' => __( 'Post title.', 'karmcp' ) ),
						'content'        => array( 'type' => 'string', 'description' => __( 'post_content, classic HTML or Gutenberg block markup, stored verbatim.', 'karmcp' ) ),
						'excerpt'        => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ), 'description' => __( 'Default: draft.', 'karmcp' ) ),
						'slug'           => array( 'type' => 'string' ),
						'author'         => array( 'type' => 'integer', 'description' => __( 'User ID. Default: current user.', 'karmcp' ) ),
						'date'           => array( 'type' => 'string', 'description' => __( 'Y-m-d H:i:s. Required for status=future.', 'karmcp' ) ),
						'parent'         => array( 'type' => 'integer', 'description' => __( 'Parent ID (hierarchical types).', 'karmcp' ) ),
						'menu_order'     => array( 'type' => 'integer' ),
						'comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
						'terms'          => array( 'type' => 'object', 'description' => __( 'Map of taxonomy → array of term IDs or names. Names are created if missing.', 'karmcp' ) ),
						'meta'           => array( 'type' => 'object', 'description' => __( 'Custom fields. Protected/underscore-prefixed keys are rejected.', 'karmcp' ) ),
						'featured_image' => array( 'type' => 'object', 'description' => __( 'Featured image: { id } (attachment) or { url } (sideloaded). null clears.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'status'    => array( 'type' => 'string' ),
						'permalink' => array( 'type' => 'string' ),
						'edit_link' => array( 'type' => 'string' ),
						'warnings'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_create_post( $input ) {
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( '' === $post_type ) {
			$post_type = 'post';
		}
		if ( ! $this->is_writable_post_type( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', sprintf( /* translators: %s: type */ __( '"%s" is not a writable post type.', 'karmcp' ), $post_type ) );
		}

		// The permission callback already resolved the capability for the type it
		// was handed, but the execute path is reachable on its own (the compact
		// dispatcher, a direct ability call), so it re-checks rather than trusts.
		if ( ! current_user_can( self::type_cap( $post_type, 'create_posts', 'edit_posts' ) ) ) {
			return new \WP_Error( 'cannot_create', sprintf( /* translators: %s: type */ __( 'You do not have permission to create "%s" content.', 'karmcp' ), $post_type ) );
		}

		$status = sanitize_key( $input['status'] ?? 'draft' );
		if ( ! in_array( $status, $this->valid_statuses(), true ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid status.', 'karmcp' ) );
		}
		if ( 'publish' === $status && ! current_user_can( self::type_cap( $post_type, 'publish_posts', 'publish_posts' ) ) ) {
			return new \WP_Error( 'cannot_publish', __( 'You do not have permission to publish.', 'karmcp' ) );
		}

		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$guard = $this->reject_protected_meta( $input['meta'] );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}

		$author = absint( $input['author'] ?? 0 );
		if ( $author && (int) $author !== get_current_user_id() && ! current_user_can( self::type_cap( $post_type, 'edit_others_posts', 'edit_others_posts' ) ) ) {
			return new \WP_Error( 'cannot_set_author', __( 'You cannot assign another author.', 'karmcp' ) );
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( $input['title'] ?? '' ),
			'post_content' => (string) ( $input['content'] ?? '' ),
			'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
		);
		if ( ! empty( $input['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $input['slug'] );
		}
		if ( $author ) {
			$postarr['post_author'] = $author;
		}
		if ( ! empty( $input['date'] ) ) {
			$postarr['post_date'] = sanitize_text_field( $input['date'] );
		}
		if ( isset( $input['parent'] ) ) {
			$postarr['post_parent'] = absint( $input['parent'] );
		}
		if ( isset( $input['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $input['menu_order'];
		}
		if ( ! empty( $input['comment_status'] ) ) {
			$postarr['comment_status'] = ( 'open' === $input['comment_status'] ) ? 'open' : 'closed';
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		$warnings = array();
		$this->apply_write_extras( $post_id, $input, $warnings, false );

		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			$karmcp_title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
			KarMCP_Change_Recorder::record_post_create(
				$post_id,
				sprintf( 'Created %s #%d', (string) ( $postarr['post_type'] ?? 'post' ), $post_id ),
				trim( $karmcp_title . ' (#' . $post_id . ')' )
			);
		}

		$result = array(
			'post_id'   => $post_id,
			'status'    => $status,
			'permalink' => (string) get_permalink( $post_id ),
			'edit_link' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
		if ( $warnings ) {
			$result['warnings'] = $warnings;
		}
		return $result;
	}

	// ---------------------------------------------------------------------
	// get-post
	// ---------------------------------------------------------------------

	private function register_get_post(): void {
		$this->ability_names[] = 'karmcp/get-post';
		karmcp_register_ability(
			'karmcp/get-post',
			array(
				'label'               => __( 'Get Post', 'karmcp' ),
				'description'         => __( 'Returns a single post/page/CPT: title, content, status, author, dates, terms, non-protected meta, and featured image. The is_elementor flag tells you whether the page is built with Elementor (edit those with the Elementor tools, not update-post).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_post' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer', 'description' => __( 'The post ID.', 'karmcp' ) ) ),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'post_id' => array( 'type' => 'integer' ), 'post_type' => array( 'type' => 'string' ),
					'title' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ),
					'status' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ),
					'excerpt' => array( 'type' => 'string' ), 'date' => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ), 'parent' => array( 'type' => 'integer' ),
					'menu_order' => array( 'type' => 'integer' ), 'comment_status' => array( 'type' => 'string' ),
					'permalink' => array( 'type' => 'string' ),
					'edit_link' => array( 'type' => 'string' ), 'author' => array( 'type' => 'object' ),
					'terms' => array( 'type' => 'object' ), 'meta' => array( 'type' => 'object' ),
					'featured_image' => array( 'type' => array( 'object', 'null' ) ),
					'is_elementor' => array( 'type' => 'boolean' ),
				) ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_post( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'karmcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'karmcp' ) );
		}
		// `edit_posts` (the tool's gate) says the caller writes content somewhere,
		// not that they may read THIS row. Without the per-post meta cap, an ID is
		// enough to pull back another author's draft or the body of a private
		// KarMCP type — a PHP snippet's code, a skill. map_meta_cap resolves it
		// against the post's own type, status and author.
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to read that post.', 'karmcp' ) );
		}
		return $this->format_post( $post );
	}

	/**
	 * Full serialization of a post for get-post.
	 *
	 * @param object $post WP_Post.
	 * @return array
	 */
	private function format_post( $post ): array {
		$post_id = (int) $post->ID;

		$terms = array();
		foreach ( (array) get_object_taxonomies( $post->post_type ) as $tax ) {
			$tobjs = get_the_terms( $post, $tax );
			if ( is_array( $tobjs ) ) {
				$terms[ $tax ] = array();
				foreach ( $tobjs as $t ) {
					$terms[ $tax ][] = array( 'term_id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug );
				}
			}
		}

		$meta_raw = get_post_meta( $post_id );
		$meta     = array();
		if ( is_array( $meta_raw ) ) {
			$allowed = (array) apply_filters( 'karmcp_content_allowed_protected_meta', array() );
			foreach ( $meta_raw as $key => $vals ) {
				$key = (string) $key;
				if ( ! in_array( $key, $allowed, true ) && ( '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, 'post' ) ) ) {
					continue;
				}
				$meta[ $key ] = is_array( $vals ) && 1 === count( $vals ) ? maybe_unserialize( $vals[0] ) : array_map( 'maybe_unserialize', (array) $vals );
			}
		}

		$thumb_id = (int) get_post_thumbnail_id( $post );
		$featured = $thumb_id ? array(
			'id'  => $thumb_id,
			'url' => (string) wp_get_attachment_image_url( $thumb_id, 'full' ),
			'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
		) : null;

		$author_id  = (int) ( $post->post_author ?? 0 );
		$author_obj = $author_id ? get_userdata( $author_id ) : null;

		return array(
			'post_id'        => $post_id,
			'post_type'      => (string) $post->post_type,
			'title'          => (string) $post->post_title,
			'slug'           => (string) $post->post_name,
			'status'         => (string) $post->post_status,
			'content'        => (string) $post->post_content,
			'excerpt'        => (string) $post->post_excerpt,
			'date'           => (string) $post->post_date,
			'modified'       => (string) ( $post->post_modified ?? '' ),
			'parent'         => (int) ( $post->post_parent ?? 0 ),
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
			'comment_status' => (string) ( $post->comment_status ?? '' ),
			'permalink'      => (string) get_permalink( $post_id ),
			'edit_link'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'author'         => array( 'id' => $author_id, 'name' => $author_obj ? (string) $author_obj->display_name : '' ),
			'terms'          => $terms,
			'meta'           => $meta,
			'featured_image' => $featured,
			'is_elementor'   => 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ),
		);
	}

	// ---------------------------------------------------------------------
	// update-post
	// ---------------------------------------------------------------------

	private function register_update_post(): void {
		$this->ability_names[] = 'karmcp/update-post';
		karmcp_register_ability(
			'karmcp/update-post',
			array(
				'label'               => __( 'Update Post', 'karmcp' ),
				'description'         => __( 'Partial update of a post/page/CPT. Only the fields you pass change. terms_mode controls replace/append; meta upserts the given keys; featured_image:null clears it. Does not touch Elementor data, use the Elementor tools for builder pages.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_post' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer', 'description' => __( 'The post ID.', 'karmcp' ) ),
						'title'          => array( 'type' => 'string' ),
						'content'        => array( 'type' => 'string', 'description' => __( 'post_content, classic HTML or block markup.', 'karmcp' ) ),
						'excerpt'        => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ) ),
						'slug'           => array( 'type' => 'string' ),
						'author'         => array( 'type' => 'integer' ),
						'date'           => array( 'type' => 'string' ),
						'parent'         => array( 'type' => 'integer' ),
						'menu_order'     => array( 'type' => 'integer' ),
						'comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
						'terms'          => array( 'type' => 'object' ),
						'terms_mode'     => array( 'type' => 'string', 'enum' => array( 'replace', 'append' ), 'description' => __( 'Default: replace.', 'karmcp' ) ),
						'meta'           => array( 'type' => 'object' ),
						'featured_image' => array( 'type' => array( 'object', 'null' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'post_id' => array( 'type' => 'integer' ), 'status' => array( 'type' => 'string' ),
					'permalink' => array( 'type' => 'string' ),
					'warnings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				) ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_post( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'karmcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'karmcp' ) );
		}
		if ( ! $this->is_writable_post_type( (string) $post->post_type ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'That post type is not writable here.', 'karmcp' ) );
		}

		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$guard = $this->reject_protected_meta( $input['meta'] );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}

		$postarr = array( 'ID' => $post_id );
		if ( array_key_exists( 'title', $input ) )          { $postarr['post_title'] = sanitize_text_field( (string) $input['title'] ); }
		if ( array_key_exists( 'content', $input ) )        { $postarr['post_content'] = (string) $input['content']; }
		if ( array_key_exists( 'excerpt', $input ) )        { $postarr['post_excerpt'] = (string) $input['excerpt']; }
		if ( ! empty( $input['slug'] ) )                    { $postarr['post_name'] = sanitize_title( $input['slug'] ); }
		if ( isset( $input['parent'] ) )                    { $postarr['post_parent'] = absint( $input['parent'] ); }
		if ( isset( $input['menu_order'] ) )                { $postarr['menu_order'] = (int) $input['menu_order']; }
		if ( ! empty( $input['date'] ) )                    { $postarr['post_date'] = sanitize_text_field( $input['date'] ); }
		if ( ! empty( $input['comment_status'] ) )          { $postarr['comment_status'] = ( 'open' === $input['comment_status'] ) ? 'open' : 'closed'; }

		if ( ! empty( $input['status'] ) ) {
			$status = sanitize_key( $input['status'] );
			if ( ! in_array( $status, $this->valid_statuses(), true ) ) {
				return new \WP_Error( 'invalid_status', __( 'Invalid status.', 'karmcp' ) );
			}
			if ( 'publish' === $status && ! current_user_can( self::type_cap( (string) $post->post_type, 'publish_posts', 'publish_posts' ) ) ) {
				return new \WP_Error( 'cannot_publish', __( 'You do not have permission to publish.', 'karmcp' ) );
			}
			$postarr['post_status'] = $status;
		}
		if ( ! empty( $input['author'] ) ) {
			$author = absint( $input['author'] );
			if ( (int) $author !== get_current_user_id() && ! current_user_can( self::type_cap( (string) $post->post_type, 'edit_others_posts', 'edit_others_posts' ) ) ) {
				return new \WP_Error( 'cannot_set_author', __( 'You cannot assign another author.', 'karmcp' ) );
			}
			$postarr['post_author'] = $author;
		}

		// Capture the before-image of everything this update can change, so the
		// change ledger can offer a rollback.
		$karmcp_before = null;
		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			$karmcp_bf = array();
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_date', 'comment_status', 'post_author' ) as $karmcp_col ) {
				if ( array_key_exists( $karmcp_col, $postarr ) ) {
					$karmcp_bf[ $karmcp_col ] = $post->$karmcp_col;
				}
			}
			$karmcp_bm = array();
			if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
				foreach ( array_keys( $input['meta'] ) as $karmcp_mk ) {
					$karmcp_mk   = sanitize_key( $karmcp_mk );
					$karmcp_cur  = get_post_meta( $post_id, $karmcp_mk, true );
					$karmcp_bm[ $karmcp_mk ] = ( '' === $karmcp_cur || array() === $karmcp_cur ) ? '__DELETE__' : $karmcp_cur;
				}
			}
			$karmcp_bt = array();
			if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
				foreach ( array_keys( $input['terms'] ) as $karmcp_tax ) {
					$karmcp_tax  = sanitize_key( $karmcp_tax );
					$karmcp_ids  = wp_get_object_terms( $post_id, $karmcp_tax, array( 'fields' => 'ids' ) );
					$karmcp_bt[ $karmcp_tax ] = is_wp_error( $karmcp_ids ) ? array() : array_map( 'intval', $karmcp_ids );
				}
			}
			$karmcp_before = array( 'fields' => $karmcp_bf, 'meta' => $karmcp_bm, 'terms' => $karmcp_bt );
		}

		// A slug change on a published post kills its old permalink — capture it
		// (before the update) for a suggested redirect.
		$karmcp_slug_old_url = '';
		if ( ! empty( $input['slug'] )
			&& isset( $postarr['post_name'] )
			&& $postarr['post_name'] !== $post->post_name
			&& 'publish' === $post->post_status ) {
			$karmcp_slug_old_url = (string) get_permalink( $post_id );
		}

		$res = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$warnings = array();
		$append   = isset( $input['terms_mode'] ) && 'append' === $input['terms_mode'];
		$this->apply_write_extras( $post_id, $input, $warnings, $append );

		if ( null !== $karmcp_before ) {
			KarMCP_Change_Recorder::record_post_fields(
				$post_id,
				$karmcp_before,
				sprintf( 'Updated post #%d', $post_id ),
				trim( (string) get_the_title( $post_id ) . ' (#' . $post_id . ')' )
			);
		}

		$result = array(
			'post_id'   => $post_id,
			'status'    => (string) ( $postarr['post_status'] ?? $post->post_status ),
			'permalink' => (string) get_permalink( $post_id ),
		);
		if ( $warnings ) {
			$result['warnings'] = $warnings;
		}
		if ( '' !== $karmcp_slug_old_url ) {
			$result = $this->with_redirect_suggestion( $result, $karmcp_slug_old_url, 'slug-changed', $post_id );
		}
		return $result;
	}

	// ---------------------------------------------------------------------
	// delete-post
	// ---------------------------------------------------------------------

	private function register_delete_post(): void {
		$this->ability_names[] = 'karmcp/delete-post';
		karmcp_register_ability(
			'karmcp/delete-post',
			array(
				'label'               => __( 'Delete Post', 'karmcp' ),
				'description'         => __( 'Deletes a post/page/CPT. By default it is moved to Trash (recoverable); pass force:true to permanently delete. Destructive.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete_post' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'The post ID.', 'karmcp' ) ),
						'force'   => array( 'type' => 'boolean', 'description' => __( 'Permanently delete instead of trashing. Default: false.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'success' => array( 'type' => 'boolean' ), 'post_id' => array( 'type' => 'integer' ),
					'deleted' => array( 'type' => 'string' ),
				) ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_delete_post( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'karmcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'karmcp' ) );
		}
		$force   = ! empty( $input['force'] );
		$has_rec = class_exists( 'KarMCP_Change_Recorder' );
		$target  = trim( (string) get_the_title( $post_id ) . ' (#' . $post_id . ')' );
		// The now-dead URL, captured before deletion, for a suggested redirect.
		$old_url = (string) get_permalink( $post_id );

		if ( $force ) {
			// Snapshot the whole post BEFORE deleting so it can be re-inserted.
			$snapshot = $has_rec ? KarMCP_Change_Recorder::snapshot_post( $post_id ) : array();
			$res      = wp_delete_post( $post_id, true );
			if ( $has_rec && $res ) {
				KarMCP_Change_Recorder::record_post_delete( $post_id, $snapshot, true, sprintf( 'Deleted post #%d', $post_id ), $target );
			}
			$out = array( 'success' => (bool) $res, 'post_id' => $post_id, 'deleted' => 'deleted' );
			return $res ? $this->with_redirect_suggestion( $out, $old_url, 'post-deleted', $post_id ) : $out;
		}
		$res = wp_trash_post( $post_id );
		if ( $has_rec && $res ) {
			KarMCP_Change_Recorder::record_post_delete( $post_id, array(), false, sprintf( 'Trashed post #%d', $post_id ), $target );
		}
		$out = array( 'success' => (bool) $res, 'post_id' => $post_id, 'deleted' => 'trashed' );
		return $res ? $this->with_redirect_suggestion( $out, $old_url, 'post-deleted', $post_id ) : $out;
	}

	/**
	 * Queue a suggested redirect for a now-dead URL and annotate the tool
	 * response. Suggest-only: it never writes a redirect. Safe no-op when the
	 * Redirect Manager is absent.
	 *
	 * @param array  $out    The tool response to annotate.
	 * @param string $old_url The dead permalink.
	 * @param string $reason 'post-deleted' | 'slug-changed'.
	 * @param int    $post_id The source post.
	 * @return array
	 */
	private function with_redirect_suggestion( array $out, string $old_url, string $reason, int $post_id ): array {
		if ( '' === $old_url || ! class_exists( 'KarMCP_Redirect_Abilities' ) ) {
			return $out;
		}
		// Only suggest when the Redirect Manager module is active.
		if ( class_exists( 'KarMCP_Redirect_Module' ) && ! KarMCP_Redirect_Module::is_enabled() ) {
			return $out;
		}
		KarMCP_Redirect_Abilities::push_suggestion( $old_url, $reason, $post_id );
		$out['redirect_suggestion'] = array(
			'old_url' => $old_url,
			'message' => __( 'This URL is now dead — call create-redirect to point it somewhere so visitors and search engines do not hit a 404.', 'karmcp' ),
		);
		return $out;
	}

	// ---------------------------------------------------------------------
	// list-posts
	// ---------------------------------------------------------------------

	private function register_list_posts(): void {
		$this->ability_names[] = 'karmcp/list-posts';
		karmcp_register_ability(
			'karmcp/list-posts',
			array(
				'label'               => __( 'List Posts', 'karmcp' ),
				'description'         => __( 'Lists/searches posts, pages, or any CPT. Filter by type, status, search text, taxonomy term, author, or parent; paginated. Returns compact rows (no content body), call get-post for the full content. The is_elementor flag flags builder pages.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_posts' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => array( 'string', 'array' ), 'description' => __( 'Type(s) to query. Default: post.', 'karmcp' ) ),
						'status'    => array( 'type' => array( 'string', 'array' ), 'description' => __( 'Status(es). Default: any.', 'karmcp' ) ),
						'search'    => array( 'type' => 'string' ),
						'taxonomy'  => array( 'type' => 'object', 'description' => __( 'Map of taxonomy → array of term IDs or slugs (AND).', 'karmcp' ) ),
						'author'    => array( 'type' => 'integer' ),
						'parent'    => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer', 'description' => __( '1-100. Default: 20.', 'karmcp' ) ),
						'page'      => array( 'type' => 'integer', 'description' => __( 'Default: 1.', 'karmcp' ) ),
						'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'menu_order', 'ID' ) ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'posts' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'total' => array( 'type' => 'integer' ), 'pages' => array( 'type' => 'integer' ),
					'page' => array( 'type' => 'integer' ),
				) ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_posts( $input ): array {
		$per_page = max( 1, min( 100, absint( $input['per_page'] ?? 20 ) ) );
		$page     = max( 1, absint( $input['page'] ?? 1 ) );
		$orderby  = in_array( $input['orderby'] ?? '', array( 'date', 'modified', 'title', 'menu_order', 'ID' ), true ) ? $input['orderby'] : 'date';
		$order    = ( isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ) ? 'ASC' : 'DESC';

		$pt_raw    = $input['post_type'] ?? 'post';
		$post_type = is_array( $pt_raw )
			? array_values( array_filter( array_map( 'sanitize_key', $pt_raw ) ) )
			: sanitize_key( (string) $pt_raw );
		if ( empty( $post_type ) ) {
			$post_type = 'post';
		}

		// Same reasoning as get-post, one level up: the tool's `edit_posts` gate
		// does not say the caller may see THIS type. Named explicitly, a type
		// they have no capability for would otherwise come back as a list of
		// titles and slugs — including the plugin's own private types.
		$requested = (array) $post_type;
		$allowed   = array();
		foreach ( $requested as $type ) {
			if ( current_user_can( self::type_cap( (string) $type, 'edit_posts', 'edit_posts' ) ) ) {
				$allowed[] = (string) $type;
			}
		}
		if ( empty( $allowed ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to list that content type.', 'karmcp' ) );
		}
		$post_type = is_array( $pt_raw ) ? $allowed : $allowed[0];
		$valid_status = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'any' );
		$status_raw   = $input['status'] ?? 'any';
		if ( is_array( $status_raw ) ) {
			$status = array_values( array_intersect( array_map( 'sanitize_key', $status_raw ), $valid_status ) );
			if ( empty( $status ) || in_array( 'any', $status, true ) ) {
				$status = 'any';
			}
		} else {
			$status = in_array( (string) $status_raw, $valid_status, true ) ? (string) $status_raw : 'any';
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
			// Makes WP_Query apply the read capability to private posts, so
			// `status: "private"` cannot be used to read past another author.
			'perm'           => 'readable',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = absint( $input['author'] );
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = absint( $input['parent'] );
		}
		if ( ! empty( $input['taxonomy'] ) && is_array( $input['taxonomy'] ) ) {
			$tax_query = array( 'relation' => 'AND' );
			foreach ( $input['taxonomy'] as $tax => $terms ) {
				$terms       = array_values( (array) $terms );
				$field       = ( ! empty( $terms ) && is_numeric( $terms[0] ) ) ? 'term_id' : 'slug';
				$tax_query[] = array( 'taxonomy' => sanitize_key( $tax ), 'field' => $field, 'terms' => $terms );
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new \WP_Query( $args );
		$rows  = array();
		foreach ( $query->posts as $p ) {
			$rows[] = array(
				'post_id'      => (int) $p->ID,
				'post_type'    => (string) $p->post_type,
				'title'        => (string) $p->post_title,
				'slug'         => (string) $p->post_name,
				'status'       => (string) $p->post_status,
				'date'         => (string) $p->post_date,
				'modified'     => (string) ( $p->post_modified ?? '' ),
				'author_id'    => (int) ( $p->post_author ?? 0 ),
				'permalink'    => (string) get_permalink( (int) $p->ID ),
				'is_elementor' => 'builder' === get_post_meta( (int) $p->ID, '_elementor_edit_mode', true ),
			);
		}
		return array(
			'posts' => $rows,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		);
	}

	// ---------------------------------------------------------------------
	// set-post-terms
	// ---------------------------------------------------------------------

	private function register_set_post_terms(): void {
		$this->ability_names[] = 'karmcp/set-post-terms';
		karmcp_register_ability(
			'karmcp/set-post-terms',
			array(
				'label'               => __( 'Set Post Terms', 'karmcp' ),
				'description'         => __( 'Assigns taxonomy terms (categories, tags, custom) to a post. mode controls replace (default), append, or remove. Terms may be IDs or names; missing names are created when create_missing is true and you can manage that taxonomy.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_set_post_terms' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer', 'description' => __( 'The post ID.', 'karmcp' ) ),
						'taxonomy'       => array( 'type' => 'string', 'description' => __( 'Taxonomy name (e.g. category, post_tag).', 'karmcp' ) ),
						'terms'          => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => __( 'Term IDs or names.', 'karmcp' ) ),
						'mode'           => array( 'type' => 'string', 'enum' => array( 'replace', 'append', 'remove' ), 'description' => __( 'Default: replace.', 'karmcp' ) ),
						'create_missing' => array( 'type' => 'boolean', 'description' => __( 'Create term names that do not exist. Default: true.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id', 'taxonomy', 'terms' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'post_id' => array( 'type' => 'integer' ), 'taxonomy' => array( 'type' => 'string' ),
					'terms' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'created' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				) ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_set_post_terms( $input ) {
		$post_id  = absint( $input['post_id'] ?? 0 );
		$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
		if ( ! $post_id || '' === $taxonomy ) {
			return new \WP_Error( 'missing_params', __( 'post_id and taxonomy are required.', 'karmcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'karmcp' ) );
		}
		$mode  = $input['mode'] ?? 'replace';
		$mode  = in_array( $mode, array( 'replace', 'append', 'remove' ), true ) ? $mode : 'replace';
		$terms = array_values( (array) ( $input['terms'] ?? array() ) );

		$create_missing = ! isset( $input['create_missing'] ) || (bool) $input['create_missing'];

		// When not auto-creating, resolve names to existing term IDs and drop
		// any name that doesn't exist (numeric IDs pass through untouched).
		if ( 'remove' !== $mode && ! $create_missing ) {
			$resolved = array();
			foreach ( $terms as $term ) {
				if ( is_numeric( $term ) ) {
					$resolved[] = (int) $term;
					continue;
				}
				$existing = get_term_by( 'name', (string) $term, $taxonomy );
				if ( ! $existing ) {
					$existing = get_term_by( 'slug', sanitize_title( (string) $term ), $taxonomy );
				}
				if ( $existing && ! is_wp_error( $existing ) ) {
					$resolved[] = (int) $existing->term_id;
				}
			}
			$terms = $resolved;
		}

		if ( 'remove' === $mode ) {
			$res = function_exists( 'wp_remove_object_terms' ) ? wp_remove_object_terms( $post_id, $terms, $taxonomy ) : true;
		} else {
			$res = wp_set_object_terms( $post_id, $terms, $taxonomy, 'append' === $mode );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$current = array();
		$tobjs   = get_the_terms( $post, $taxonomy );
		if ( is_array( $tobjs ) ) {
			foreach ( $tobjs as $t ) {
				$current[] = array( 'term_id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug );
			}
		}
		return array(
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy,
			'terms'    => $current,
			// v1: wp_set_object_terms auto-creates missing names but does not report
			// which were new; 'created' is reserved for a future per-name diff.
			'created'  => array(),
		);
	}
}
