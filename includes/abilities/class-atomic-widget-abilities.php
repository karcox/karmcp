<?php
/**
 * Atomic widget MCP abilities for Elementor 4.0+.
 *
 * Registers universal add/update tools plus convenience shortcut tools
 * for atomic widgets (e-heading, e-paragraph, e-button, e-image, etc.).
 * Only registers when Elementor >= 4.0 is active.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements atomic widget abilities.
 *
 * @since 1.5.0
 */
class KarMCP_Atomic_Widget_Abilities {

	/** @var KarMCP_Data */
	private $data;

	/** @var KarMCP_Element_Factory */
	private $factory;

	/** @var string[] */
	private $ability_names = array();

	/**
	 * @param KarMCP_Data            $data    The data access layer.
	 * @param KarMCP_Element_Factory $factory The element factory.
	 */
	public function __construct( KarMCP_Data $data, KarMCP_Element_Factory $factory ) {
		$this->data    = $data;
		$this->factory = $factory;
	}

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers all atomic widget abilities.
	 *
	 * Skips registration entirely if Elementor < 4.0.
	 */
	public function register(): void {
		if ( ! KarMCP_Atomic_Props::is_atomic_supported() ) {
			return;
		}

		$this->register_add_atomic_widget();
		$this->register_update_atomic_widget();
		$this->register_add_atomic_heading();
		$this->register_add_atomic_paragraph();
		$this->register_add_atomic_button();
		$this->register_add_atomic_image();
		$this->register_add_atomic_svg();
		$this->register_add_atomic_youtube();
		$this->register_add_atomic_video();
		$this->register_add_atomic_divider();
	}

	// =========================================================================
	// Permission check
	// =========================================================================

	/**
	 * @param array $input Input parameters.
	 * @return true|\WP_Error
	 */
	public function check_edit_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit posts.', 'karmcp' ) );
		}

		$post_id = $input['post_id'] ?? 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'karmcp' ) );
		}

		return true;
	}

	// =========================================================================
	// Universal tools
	// =========================================================================

	private function register_add_atomic_widget(): void {
		$name                  = 'karmcp/add-atomic-widget';
		$this->ability_names[] = $name;

		karmcp_register_ability(
			$name,
			array(
				'label'               => __( 'Add Atomic Widget', 'karmcp' ),
				'description'         => __( 'Adds any Elementor 4.0+ atomic widget to a container. Settings must use the $$type prop format. For simpler usage, prefer the convenience tools (add-atomic-heading, etc.).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_add_atomic_widget' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'karmcp' ) ),
						'parent_id'   => array( 'type' => 'string', 'description' => __( 'Parent container element ID.', 'karmcp' ) ),
						'position'    => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'karmcp' ) ),
						'widget_type' => array( 'type' => 'string', 'description' => __( 'Atomic widget type name (e.g. e-heading, e-button).', 'karmcp' ) ),
						'settings'    => array( 'type' => 'object', 'description' => __( 'Widget settings with $$type-wrapped values.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id', 'parent_id', 'widget_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'element_id' => array( 'type' => 'string' ) ),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_atomic_widget( $input ) {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$parent_id   = sanitize_text_field( $input['parent_id'] ?? '' );
		$position    = (int) ( $input['position'] ?? -1 );
		$widget_type = sanitize_text_field( $input['widget_type'] ?? '' );
		$settings    = $input['settings'] ?? array();

		if ( empty( $widget_type ) ) {
			return new \WP_Error( 'missing_widget_type', __( 'widget_type is required.', 'karmcp' ) );
		}

		$element = $this->factory->create_atomic_widget( $widget_type, $settings );

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// insert_element() mutates $page_data by reference and returns a bool;
		// save the modified $page_data, never the bool (issue #36).
		$ok = $this->data->insert_element( $page_data, $parent_id, $element, $position );
		if ( ! $ok ) {
			return new \WP_Error( 'not_found', "Parent element '{$parent_id}' not found in page {$post_id}." );
		}

		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			return $save;
		}

		return array( 'element_id' => $element['id'] );
	}

	private function register_update_atomic_widget(): void {
		$name                  = 'karmcp/update-atomic-widget';
		$this->ability_names[] = $name;

		karmcp_register_ability(
			$name,
			array(
				'label'               => __( 'Update Atomic Widget', 'karmcp' ),
				'description'         => __( 'Updates settings on an existing Elementor 4.0+ atomic widget. Performs a partial merge, only provided keys are changed.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_atomic_widget' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'karmcp' ) ),
						'element_id' => array( 'type' => 'string', 'description' => __( 'The element ID to update.', 'karmcp' ) ),
						'settings'   => array( 'type' => 'object', 'description' => __( 'Partial settings to merge ($$type-wrapped values).', 'karmcp' ) ),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_atomic_widget( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$settings   = $input['settings'] ?? array();

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// update_element_settings() mutates $page_data by reference and returns a
		// bool; save the modified $page_data, never the bool (issue #36).
		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );
		if ( ! $updated ) {
			return new \WP_Error( 'not_found', "Element '{$element_id}' not found in page {$post_id}." );
		}

		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			return $save;
		}

		return array( 'success' => true );
	}

	// =========================================================================
	// Convenience tools
	// =========================================================================

	/**
	 * Shared registration for atomic convenience tools.
	 *
	 * @param string   $name         Tool name without prefix.
	 * @param string   $label        Human-readable label.
	 * @param string   $description  Tool description.
	 * @param array    $extra_props  Additional JSON Schema properties.
	 * @param array    $required     Additional required fields.
	 * @param string   $widget_type  The atomic widget type (e.g. 'e-heading').
	 * @param callable $settings_fn  Builds $$type settings from flat input.
	 */
	private function register_atomic_convenience(
		string $name,
		string $label,
		string $description,
		array $extra_props,
		array $required,
		string $widget_type,
		callable $settings_fn
	): void {
		$full_name             = 'karmcp/' . $name;
		$this->ability_names[] = $full_name;

		$base_props = array(
			'post_id'   => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'karmcp' ) ),
			'parent_id' => array( 'type' => 'string', 'description' => __( 'Parent container element ID (e-flexbox or e-div-block).', 'karmcp' ) ),
			'position'  => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'karmcp' ) ),
		);

		$all_required = array_unique( array_merge( array( 'post_id', 'parent_id' ), $required ) );

		karmcp_register_ability(
			$full_name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'karmcp',
				'execute_callback'    => function ( $input ) use ( $widget_type, $settings_fn ) {
					$settings = $settings_fn( $input );
					$element  = $this->factory->create_atomic_widget( $widget_type, $settings );

					// Apply styles if style params are present.
					$common_css = KarMCP_Atomic_Styles::build_common_props( $input );
					if ( ! empty( $common_css ) ) {
						$style = KarMCP_Atomic_Styles::create_local_class( $element['id'], $common_css );
						KarMCP_Atomic_Styles::apply_to_element( $element, $style['class_id'], $style['style_def'] );
					}

					$post_id   = absint( $input['post_id'] ?? 0 );
					$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
					$position  = (int) ( $input['position'] ?? -1 );

					$page_data = $this->data->get_page_data( $post_id );
					if ( is_wp_error( $page_data ) ) {
						return $page_data;
					}

					$ok = $this->data->insert_element( $page_data, $parent_id, $element, $position );
					if ( ! $ok ) {
						return new \WP_Error( 'not_found', "Parent element '{$parent_id}' not found in page {$post_id}." );
					}

					$save = $this->data->save_page_data( $post_id, $page_data );
					if ( is_wp_error( $save ) ) {
						return $save;
					}

					return array( 'element_id' => $element['id'] );
				},
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge( $base_props, $extra_props ),
					'required'   => $all_required,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'element_id' => array( 'type' => 'string' ) ),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	// -------------------------------------------------------------------------

	private function register_add_atomic_heading(): void {
		$this->register_atomic_convenience(
			'add-atomic-heading',
			__( 'Add Atomic Heading', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic heading element. Accepts plain text and tag; $$type wrapping is handled automatically.', 'karmcp' ),
			array(
				'title'  => array( 'type' => 'string', 'description' => __( 'Heading text content.', 'karmcp' ) ),
				'tag'    => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => __( 'HTML tag. Default: h2.', 'karmcp' ) ),
				'link'   => array( 'type' => 'string', 'description' => __( 'Optional URL to link the heading.', 'karmcp' ) ),
				'css_id' => array( 'type' => 'string', 'description' => __( 'Optional CSS ID for the element.', 'karmcp' ) ),
			),
			array(),
			'e-heading',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-heading', $input );
			}
		);
	}

	private function register_add_atomic_paragraph(): void {
		$this->register_atomic_convenience(
			'add-atomic-paragraph',
			__( 'Add Atomic Paragraph', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic paragraph element.', 'karmcp' ),
			array(
				'content' => array( 'type' => 'string', 'description' => __( 'Paragraph text content.', 'karmcp' ) ),
				'link'    => array( 'type' => 'string', 'description' => __( 'Optional URL to link the paragraph.', 'karmcp' ) ),
				'css_id'  => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array(),
			'e-paragraph',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-paragraph', $input );
			}
		);
	}

	private function register_add_atomic_button(): void {
		$this->register_atomic_convenience(
			'add-atomic-button',
			__( 'Add Atomic Button', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic button element.', 'karmcp' ),
			array(
				'text'         => array( 'type' => 'string', 'description' => __( 'Button label text.', 'karmcp' ) ),
				'link'         => array( 'type' => 'string', 'description' => __( 'Button URL.', 'karmcp' ) ),
				'target_blank' => array( 'type' => 'boolean', 'description' => __( 'Open in new tab.', 'karmcp' ) ),
				'css_id'       => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array(),
			'e-button',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-button', $input );
			}
		);
	}

	private function register_add_atomic_image(): void {
		$this->register_atomic_convenience(
			'add-atomic-image',
			__( 'Add Atomic Image', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic image element. Provide either image_id (from media library) or image_url.', 'karmcp' ),
			array(
				'image_id'  => array( 'type' => 'integer', 'description' => __( 'WordPress media library attachment ID.', 'karmcp' ) ),
				'image_url' => array( 'type' => 'string', 'description' => __( 'Image URL (if not using media library).', 'karmcp' ) ),
				'alt'       => array( 'type' => 'string', 'description' => __( 'Alt text for the image.', 'karmcp' ) ),
				'link'      => array( 'type' => 'string', 'description' => __( 'Optional link URL.', 'karmcp' ) ),
				'css_id'    => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array(),
			'e-image',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-image', $input );
			}
		);
	}

	private function register_add_atomic_svg(): void {
		$this->register_atomic_convenience(
			'add-atomic-svg',
			__( 'Add Atomic SVG', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic SVG element.', 'karmcp' ),
			array(
				'svg_id'  => array( 'type' => 'integer', 'description' => __( 'WordPress media library SVG attachment ID.', 'karmcp' ) ),
				'svg_url' => array( 'type' => 'string', 'description' => __( 'SVG URL (if not using media library).', 'karmcp' ) ),
				'css_id'  => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array(),
			'e-svg',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-svg', $input );
			}
		);
	}

	private function register_add_atomic_youtube(): void {
		$this->register_atomic_convenience(
			'add-atomic-youtube',
			__( 'Add Atomic YouTube', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic YouTube video element.', 'karmcp' ),
			array(
				'video_url' => array( 'type' => 'string', 'description' => __( 'YouTube video URL.', 'karmcp' ) ),
				'css_id'    => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array( 'video_url' ),
			'e-youtube',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-youtube', $input );
			}
		);
	}

	private function register_add_atomic_video(): void {
		$this->register_atomic_convenience(
			'add-atomic-video',
			__( 'Add Atomic Video', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic self-hosted video element.', 'karmcp' ),
			array(
				'video_url' => array( 'type' => 'string', 'description' => __( 'Self-hosted video URL.', 'karmcp' ) ),
				'video_id'  => array( 'type' => 'integer', 'description' => __( 'Media library video attachment ID.', 'karmcp' ) ),
				'css_id'    => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array(),
			'e-self-hosted-video',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-self-hosted-video', $input );
			}
		);
	}

	private function register_add_atomic_divider(): void {
		$this->register_atomic_convenience(
			'add-atomic-divider',
			__( 'Add Atomic Divider', 'karmcp' ),
			__( 'Adds an Elementor 4.0 atomic divider element.', 'karmcp' ),
			array(
				'css_id' => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
			),
			array(),
			'e-divider',
			function ( $input ) {
				return KarMCP_Atomic_Widget_Map::settings( 'e-divider', $input );
			}
		);
	}
}
