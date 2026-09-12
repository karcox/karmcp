<?php
/**
 * Page CRUD MCP abilities for Elementor.
 *
 * Registers 5 tools for creating, updating, clearing, importing,
 * and exporting Elementor pages.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the page CRUD abilities.
 *
 * @since 1.0.0
 */
class KarMCP_Page_Abilities {

	/**
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * @var KarMCP_Element_Factory
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param KarMCP_Data            $data    The data access layer.
	 * @param KarMCP_Element_Factory $factory The element factory.
	 */
	public function __construct( KarMCP_Data $data, KarMCP_Element_Factory $factory ) {
		$this->data    = $data;
		$this->factory = $factory;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/create-page',
			'karmcp/update-page-settings',
			'karmcp/delete-page-content',
			'karmcp/import-template',
			'karmcp/export-page',
			'karmcp/regenerate-css',
		);
	}

	/**
	 * Registers all page abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_create_page();
		$this->register_update_page_settings();
		$this->register_delete_page_content();
		$this->register_import_template();
		$this->register_export_page();
		$this->register_regenerate_css();
	}

	/**
	 * Permission check for page creation.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function check_create_permission(): bool {
		return current_user_can( 'publish_pages' ) || current_user_can( 'edit_pages' );
	}

	/**
	 * Permission check for page editing.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $input The input data.
	 * @return bool
	 */
	public function check_edit_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Permission check for destructive page content deletion.
	 *
	 * Requires both edit and delete capabilities since this operation
	 * is destructive and removes all Elementor content from the page.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $input The input data.
	 * @return bool
	 */
	public function check_delete_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'delete_posts' ) ) {
			return false;
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'delete_post', $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// create-page
	// -------------------------------------------------------------------------

	private function register_create_page(): void {
		karmcp_register_ability(
			'karmcp/create-page',
			array(
				'label'               => __( 'Create Elementor Page', 'karmcp' ),
				'description'         => __( 'Creates a new WordPress page with Elementor enabled. Optionally provide initial element content.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_create_page' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'Page title.', 'karmcp' ),
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish' ),
							'description' => __( 'Post status. Default: draft.', 'karmcp' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'enum'        => array( 'page', 'post' ),
							'description' => __( 'Post type. Default: page.', 'karmcp' ),
						),
						'template'  => array(
							'type'        => 'string',
							'description' => __( 'Elementor template slug.', 'karmcp' ),
						),
						'content'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => __( 'Initial element tree.', 'karmcp' ),
						),
					),
					'required'   => array( 'title' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'edit_url'    => array( 'type' => 'string' ),
						'preview_url' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the create-page ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_create_page( $input ) {
		$title     = sanitize_text_field( $input['title'] ?? '' );
		$status    = sanitize_key( $input['status'] ?? 'draft' );
		$post_type = sanitize_key( $input['post_type'] ?? 'page' );

		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', __( 'The title parameter is required.', 'karmcp' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => $status,
				'post_type'   => $post_type,
				'meta_input'  => array(
					'_elementor_edit_mode'     => 'builder',
					'_elementor_template_type' => 'wp-' . $post_type,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set page template if provided.
		if ( ! empty( $input['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
		}

		// Save initial content if provided.
		if ( ! empty( $input['content'] ) && is_array( $input['content'] ) ) {
			$save_result = $this->data->save_page_data( $post_id, $input['content'] );
		} else {
			// Save empty Elementor data to initialize.
			$save_result = $this->data->save_page_data( $post_id, array() );
		}

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$edit_url    = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );
		$preview_url = get_permalink( $post_id );

		return array(
			'post_id'     => $post_id,
			'title'       => $title,
			'edit_url'    => $edit_url,
			'preview_url' => $preview_url ? $preview_url : '',
		);
	}

	// -------------------------------------------------------------------------
	// update-page-settings
	// -------------------------------------------------------------------------

	private function register_update_page_settings(): void {
		karmcp_register_ability(
			'karmcp/update-page-settings',
			array(
				'label'               => __( 'Update Page Settings', 'karmcp' ),
				'description'         => __( 'Updates page-level Elementor settings such as background, padding, custom CSS, and layout options.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_page_settings' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'settings' => array(
							'type'        => 'object',
							'description' => __( 'Page settings object.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_update_page_settings( $input ) {
		$post_id  = absint( $input['post_id'] ?? 0 );
		$settings = $input['settings'] ?? array();

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'karmcp' ) );
		}

		$result = $this->data->save_page_settings( $post_id, $settings );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
		);
	}

	// -------------------------------------------------------------------------
	// delete-page-content
	// -------------------------------------------------------------------------

	private function register_delete_page_content(): void {
		karmcp_register_ability(
			'karmcp/delete-page-content',
			array(
				'label'               => __( 'Delete Page Content', 'karmcp' ),
				'description'         => __( 'Clears all Elementor content from a page, resetting it to blank while keeping the page itself.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete_page_content' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_delete_page_content( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, array() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// import-template
	// -------------------------------------------------------------------------

	private function register_import_template(): void {
		karmcp_register_ability(
			'karmcp/import-template',
			array(
				'label'               => __( 'Import Template', 'karmcp' ),
				'description'         => __( 'Imports a JSON template structure into a page at an optional position.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_import_template' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'template_json' => array(
							'type'        => 'array',
							'description' => __( 'Elementor JSON element structure to import.', 'karmcp' ),
							'items'       => array(
								'type' => 'object',
							),
						),
						'position'      => array(
							'type'        => 'integer',
							'description' => __( 'Insert position. -1 = append.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'template_json' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'elements_count' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_import_template( $input ) {
		$post_id       = absint( $input['post_id'] ?? 0 );
		$template_json = $input['template_json'] ?? array();
		$position      = intval( $input['position'] ?? -1 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'karmcp' ) );
		}

		if ( empty( $template_json ) ) {
			return new \WP_Error( 'missing_template', __( 'The template_json parameter is required.', 'karmcp' ) );
		}

		$data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Assign new IDs to all imported elements.
		$template_json = $this->data->reassign_ids( $template_json );
		$count         = $this->data->count_elements( $template_json );

		// Insert at position.
		if ( $position < 0 || $position >= count( $data ) ) {
			$data = array_merge( $data, $template_json );
		} else {
			array_splice( $data, $position, 0, $template_json );
		}

		$result = $this->data->save_page_data( $post_id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'        => true,
			'elements_count' => $count,
		);
	}

	// -------------------------------------------------------------------------
	// export-page
	// -------------------------------------------------------------------------

	private function register_export_page(): void {
		karmcp_register_ability(
			'karmcp/export-page',
			array(
				'label'               => __( 'Export Page', 'karmcp' ),
				'description'         => __( 'Exports a page\'s full Elementor data as a JSON structure that can be imported elsewhere.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_export_page' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'json' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_export_page( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'karmcp' ) );
		}

		$data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array( 'json' => $data );
	}

	// -------------------------------------------------------------------------
	// regenerate-css
	// -------------------------------------------------------------------------

	private function register_regenerate_css(): void {
		karmcp_register_ability(
			'karmcp/regenerate-css',
			array(
				'label'               => __( 'Regenerate CSS', 'karmcp' ),
				'description'         => __( 'Throws away Elementor\'s generated CSS so it is rebuilt from the current data on the next front-end request. Use it when a page reads back correctly and still renders with the old styling — after writing global classes or global typography, after an edit made outside this plugin, or when a cached stylesheet outlived the data it described. scope "page" (default) needs a post_id and clears that page\'s stylesheet, its rendered-element cache and its CSS file. scope "site" clears them for every page and needs confirm:true. Nothing is deleted that cannot be rebuilt, and no CDN or third-party page cache is touched, so purge those separately if you run one.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_regenerate_css' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The page whose CSS to drop. Required for scope "page".', 'karmcp' ),
						),
						'scope'   => array(
							'type'        => 'string',
							'enum'        => array( 'page', 'site' ),
							'description' => __( 'page: one post (default). site: every post, which needs administrator rights and confirm:true.', 'karmcp' ),
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => __( 'Required for scope "site". The rebuild is lazy, so the first visitor to each page pays for it.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'scope'   => array( 'type' => 'string' ),
						'post_id' => array( 'type' => 'integer' ),
						'notes'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'What was cleared, and anything this cannot reach.', 'karmcp' ),
						),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// It removes only derived files: every one of them is
						// rebuilt from data this does not touch.
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_regenerate_css( $input ) {
		$scope = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : 'page';

		if ( ! in_array( $scope, array( 'page', 'site' ), true ) ) {
			return new \WP_Error( 'invalid_scope', __( 'scope must be "page" or "site".', 'karmcp' ) );
		}

		$notes = array();

		if ( 'site' === $scope ) {
			// Site scope touches every page on the site, so it takes the
			// capability that owns the whole site rather than the one that owns
			// a post, and it takes it here rather than at registration: the
			// registration gate gets editors in, which is right for scope page.
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'forbidden', __( 'Site-wide regeneration is for administrators.', 'karmcp' ) );
			}

			if ( empty( $input['confirm'] ) ) {
				return new \WP_Error(
					'confirm_required',
					__( 'Pass confirm:true. Every page loses its stylesheet at once, and each is rebuilt on its first visit afterwards.', 'karmcp' )
				);
			}

			// Deleting for object id 0 with $delete_all deletes the key for
			// every post in one query, rather than walking the posts table.
			delete_metadata( 'post', 0, '_elementor_css', '', true );
			delete_metadata( 'post', 0, KarMCP_Data::ELEMENT_CACHE_META, '', true );
			$notes[] = __( 'Dropped the cached stylesheet and rendered-element cache of every post.', 'karmcp' );

			if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				$notes[] = __( 'Cleared Elementor\'s generated files.', 'karmcp' );
			}

			return $this->with_third_party_css_notes(
				array(
					'success' => true,
					'scope'   => 'site',
				),
				$notes
			);
		}

		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required for scope "page".', 'karmcp' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'karmcp' ) );
		}

		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, KarMCP_Data::ELEMENT_CACHE_META );
		$notes[] = __( 'Dropped the cached stylesheet and rendered-element cache of this post.', 'karmcp' );

		$upload_dir = wp_get_upload_dir();
		$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';

		if ( file_exists( $css_path ) ) {
			wp_delete_file( $css_path );
			$notes[] = __( 'Deleted the generated CSS file for this post.', 'karmcp' );
		}

		return $this->with_third_party_css_notes(
			array(
				'success' => true,
				'scope'   => 'page',
				'post_id' => $post_id,
			),
			$notes
		);
	}

	/**
	 * Adds notes for the stylesheets this tool cannot reach.
	 *
	 * Saying what was cleared without saying what was not is how a tool gets
	 * believed past its own edges: the page still renders stale, and the
	 * response said success. Spectra is the one that bites here, because in its
	 * separate-file mode it writes its own CSS files on a schedule of its own —
	 * the admin already warns about that combination, and this is the same
	 * warning at the moment it matters.
	 *
	 * @since 1.40.0
	 *
	 * @param array $out   The response so far.
	 * @param array $notes The notes collected by the caller.
	 * @return array
	 */
	private function with_third_party_css_notes( array $out, array $notes ): array {
		if ( class_exists( 'KarMCP_Admin' ) && KarMCP_Admin::spectra_file_generation_on() ) {
			$notes[] = __( 'Spectra is set to generate separate CSS files; those are its own and were not touched. Switch it to inline CSS while building, or regenerate its assets from its own settings.', 'karmcp' );
		}

		$out['notes'] = $notes;

		return $out;
	}

}
