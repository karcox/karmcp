<?php
/**
 * Atomic layout container MCP abilities for Elementor 4.0+.
 *
 * Registers tools for creating flexbox and div-block containers.
 * Only registers when Elementor >= 4.0 is active.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements atomic layout abilities.
 *
 * @since 1.5.0
 */
class KarMCP_Atomic_Layout_Abilities {

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
	 * Registers all atomic layout abilities.
	 *
	 * Skips registration if Elementor < 4.0.
	 */
	public function register(): void {
		if ( ! KarMCP_Atomic_Props::is_atomic_supported() ) {
			return;
		}

		$this->register_add_flexbox();
		$this->register_add_div_block();
		$this->register_detect_elementor_version();
	}

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
	// Flexbox
	// =========================================================================

	private function register_add_flexbox(): void {
		$name                  = 'karmcp/add-flexbox';
		$this->ability_names[] = $name;

		karmcp_register_ability(
			$name,
			array(
				'label'               => __( 'Add Flexbox', 'karmcp' ),
				'description'         => __( 'Adds an Elementor 4.0 flexbox container. Layout properties (direction, justify, align, gap) are applied as local styles automatically. Use this instead of add-container for Elementor 4.0+ sites.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_add_flexbox' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'         => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'karmcp' ) ),
						'parent_id'       => array( 'type' => 'string', 'description' => __( 'Parent element ID. Empty for top-level.', 'karmcp' ) ),
						'position'        => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'karmcp' ) ),
						'tag'             => array( 'type' => 'string', 'enum' => array( 'div', 'header', 'section', 'article', 'aside', 'footer' ), 'description' => __( 'HTML tag. Default: div.', 'karmcp' ) ),
						'direction'       => array( 'type' => 'string', 'enum' => array( 'row', 'column', 'row-reverse', 'column-reverse' ), 'description' => __( 'Flex direction. Default: column.', 'karmcp' ) ),
						'justify'         => array( 'type' => 'string', 'enum' => array( 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly' ), 'description' => __( 'Justify content.', 'karmcp' ) ),
						'align'           => array( 'type' => 'string', 'enum' => array( 'flex-start', 'center', 'flex-end', 'stretch', 'baseline' ), 'description' => __( 'Align items.', 'karmcp' ) ),
						'gap'             => array( 'type' => 'number', 'description' => __( 'Gap between children (px by default).', 'karmcp' ) ),
						'gap_unit'        => array( 'type' => 'string', 'enum' => array( 'px', 'em', 'rem', '%', 'vw' ), 'description' => __( 'Gap unit. Default: px.', 'karmcp' ) ),
						'wrap'            => array( 'type' => 'string', 'enum' => array( 'nowrap', 'wrap', 'wrap-reverse' ), 'description' => __( 'Flex wrap.', 'karmcp' ) ),
						'css_id'          => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
						'padding'         => array( 'type' => 'number', 'description' => __( 'Padding on all sides (px by default).', 'karmcp' ) ),
						'background_color' => array( 'type' => 'string', 'description' => __( 'Background color (hex/rgba).', 'karmcp' ) ),
						'min_height'      => array( 'type' => 'number', 'description' => __( 'Minimum height (px by default).', 'karmcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id' => array( 'type' => 'string' ),
						'post_id'    => array( 'type' => 'integer' ),
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
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_flexbox( $input ) {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
		$position  = (int) ( $input['position'] ?? -1 );

		$settings = array();

		if ( ! empty( $input['tag'] ) ) {
			$settings['tag'] = KarMCP_Atomic_Props::string( sanitize_text_field( $input['tag'] ) );
		}
		if ( ! empty( $input['css_id'] ) ) {
			$settings['_cssid'] = KarMCP_Atomic_Props::string( sanitize_text_field( $input['css_id'] ) );
		}

		// Style props extracted from input.
		$style_params = array();
		$style_keys   = array( 'direction', 'flex_direction', 'justify', 'justify_content', 'align', 'align_items', 'wrap', 'flex_wrap', 'gap', 'gap_unit', 'row_gap', 'column_gap', 'padding', 'padding_unit', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_bottom', 'background_color', 'color', 'min_height', 'width', 'border_radius' );

		foreach ( $style_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$style_params[ $key ] = $input[ $key ];
			}
		}

		$element = $this->factory->create_flexbox( $settings, array(), $style_params );

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		if ( ! empty( $parent_id ) ) {
			$ok = $this->data->insert_element( $page_data, $parent_id, $element, $position );
			if ( ! $ok ) {
				return new \WP_Error( 'not_found', "Parent element '{$parent_id}' not found in page {$post_id}." );
			}
		} else {
			// Top-level element.
			if ( -1 === $position || $position >= count( $page_data ) ) {
				$page_data[] = $element;
			} else {
				array_splice( $page_data, max( 0, $position ), 0, array( $element ) );
			}
		}

		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			return $save;
		}

		return array(
			'element_id' => $element['id'],
			'post_id'    => $post_id,
		);
	}

	// =========================================================================
	// Div Block
	// =========================================================================

	private function register_add_div_block(): void {
		$name                  = 'karmcp/add-div-block';
		$this->ability_names[] = $name;

		karmcp_register_ability(
			$name,
			array(
				'label'               => __( 'Add Div Block', 'karmcp' ),
				'description'         => __( 'Adds an Elementor 4.0 div-block container (block flow layout). Use for non-flex containers.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_add_div_block' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'karmcp' ) ),
						'parent_id'        => array( 'type' => 'string', 'description' => __( 'Parent element ID. Empty for top-level.', 'karmcp' ) ),
						'position'         => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'karmcp' ) ),
						'tag'              => array( 'type' => 'string', 'enum' => array( 'div', 'header', 'section', 'article', 'aside', 'footer' ), 'description' => __( 'HTML tag. Default: div.', 'karmcp' ) ),
						'css_id'           => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'karmcp' ) ),
						'padding'          => array( 'type' => 'number', 'description' => __( 'Padding on all sides (px by default).', 'karmcp' ) ),
						'background_color' => array( 'type' => 'string', 'description' => __( 'Background color (hex/rgba).', 'karmcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id' => array( 'type' => 'string' ),
						'post_id'    => array( 'type' => 'integer' ),
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
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_div_block( $input ) {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
		$position  = (int) ( $input['position'] ?? -1 );

		$settings = array();

		if ( ! empty( $input['tag'] ) ) {
			$settings['tag'] = KarMCP_Atomic_Props::string( sanitize_text_field( $input['tag'] ) );
		}
		if ( ! empty( $input['css_id'] ) ) {
			$settings['_cssid'] = KarMCP_Atomic_Props::string( sanitize_text_field( $input['css_id'] ) );
		}

		$style_params = array();
		$style_keys   = array( 'padding', 'padding_unit', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_bottom', 'background_color', 'color', 'min_height', 'width', 'border_radius' );

		foreach ( $style_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$style_params[ $key ] = $input[ $key ];
			}
		}

		$element = $this->factory->create_div_block( $settings, array(), $style_params );

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// insert_element() mutates $page_data by reference and returns a bool;
		// save the modified $page_data, never the bool (issue #36).
		if ( ! empty( $parent_id ) ) {
			$ok = $this->data->insert_element( $page_data, $parent_id, $element, $position );
			if ( ! $ok ) {
				return new \WP_Error( 'not_found', "Parent element '{$parent_id}' not found in page {$post_id}." );
			}
		} elseif ( -1 === $position || $position >= count( $page_data ) ) {
			$page_data[] = $element;
		} else {
			array_splice( $page_data, max( 0, $position ), 0, array( $element ) );
		}

		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			return $save;
		}

		return array(
			'element_id' => $element['id'],
			'post_id'    => $post_id,
		);
	}

	// =========================================================================
	// Detect version (always registers, even on < 4.0)
	// =========================================================================

	private function register_detect_elementor_version(): void {
		$name                  = 'karmcp/detect-elementor-version';
		$this->ability_names[] = $name;

		karmcp_register_ability(
			$name,
			array(
				'label'               => __( 'Detect Elementor Version', 'karmcp' ),
				'description'         => __( 'Returns the Elementor version and whether atomic elements (v4.0+) are supported. Call this first to decide whether to use legacy tools (add-free-widget, add-container) or atomic tools (add-atomic-heading, add-flexbox).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => function () {
					$core_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown';
					$pro_version  = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null;

					$supports_atomic    = KarMCP_Atomic_Props::is_atomic_supported();
					$supports_container = KarMCP_Atomic_Props::is_container_supported();

					if ( $supports_atomic ) {
						$mode = 'atomic';
					} elseif ( $supports_container ) {
						$mode = 'legacy';
					} else {
						$mode = 'unsupported';
					}

					$out = array(
						'elementor_version'     => $core_version,
						'elementor_pro_version' => $pro_version,
						'supports_atomic'       => $supports_atomic,
						'supports_container'    => $supports_container,
						'recommended_mode'      => $mode,
					);

					if ( 'unsupported' === $mode ) {
						$out['warning'] = __( 'Both the Flexbox Container and Atomic Elements experiments are disabled on this site, so pages built with add-container / build-page / the atomic tools will store data but render empty. Enable Elementor -> Settings -> Features -> "Flexbox Container" (or Atomic Elements) before creating pages via MCP.', 'karmcp' );
					}

					return $out;
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' ) ? true : new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'karmcp' ) );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'elementor_version'     => array( 'type' => 'string' ),
						'elementor_pro_version' => array( 'type' => 'string' ),
						'supports_atomic'       => array( 'type' => 'boolean' ),
						'supports_container'    => array( 'type' => 'boolean' ),
						'recommended_mode'      => array( 'type' => 'string' ),
						'warning'               => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}
}
