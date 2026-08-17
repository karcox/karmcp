<?php
/**
 * Layout/container MCP abilities for Elementor.
 *
 * Registers 4 tools for adding containers, moving, removing,
 * and duplicating elements within Elementor page trees.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the layout abilities.
 *
 * @since 1.0.0
 */
class KarMCP_Layout_Abilities {

	/**
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * @var KarMCP_Element_Factory
	 */
	private $factory;

	/**
	 * Optional: absent when Elementor can't be introspected, in which case the
	 * writes simply report no unknown keys.
	 *
	 * @var KarMCP_Settings_Validator|null
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.20.0 Takes the settings validator, to report unknown keys.
	 *
	 * @param KarMCP_Data                    $data      The data access layer.
	 * @param KarMCP_Element_Factory         $factory   The element factory.
	 * @param KarMCP_Settings_Validator|null $validator The settings validator.
	 */
	public function __construct( KarMCP_Data $data, KarMCP_Element_Factory $factory, ?KarMCP_Settings_Validator $validator = null ) {
		$this->data      = $data;
		$this->factory   = $factory;
		$this->validator = $validator;
	}

	/**
	 * Reports settings keys that match no control the element is known to have.
	 *
	 * Writes accept any key: it lands in `_elementor_data`, the response says
	 * success, and reading the element back shows it there — so a wrong name
	 * looks applied everywhere except on the page. Two of them in one session
	 * (`button_background_color` for `background_color`, and `button_padding`,
	 * which is the kit's control, for the widget's `text_padding`) cost a full
	 * debugging pass each.
	 *
	 * Advisory on purpose, never a rejection: dynamic tags, third-party addons
	 * and Elementor's own incomplete headless control list all produce keys that
	 * are unrecognised and perfectly valid.
	 *
	 * @since 1.20.0
	 *
	 * @param array $element  The element being written to.
	 * @param array $settings The settings being written.
	 * @return array<int,array<string,mixed>> One entry per unknown key, with suggestions.
	 */
	private function unknown_key_report( array $element, array $settings ): array {
		if ( null === $this->validator ) {
			return array();
		}

		// Only widgets carry a control schema we can check against; containers
		// resolve to no schema and would flag every key they were given.
		$widget_type = ( 'widget' === ( $element['elType'] ?? '' ) ) ? (string) ( $element['widgetType'] ?? '' ) : '';

		if ( '' === $widget_type ) {
			return array();
		}

		$report = array();
		foreach ( $this->validator->unknown_keys( $widget_type, $settings ) as $key ) {
			$entry = array( 'key' => $key );

			$suggestions = $this->validator->suggest_controls( $widget_type, $key );
			if ( $suggestions ) {
				$entry['did_you_mean'] = $suggestions;
			}

			$report[] = $entry;
		}

		return $report;
	}

	/**
	 * Whether an elType is a container the layout tools operate on. Accepts the
	 * legacy `container` plus the Elementor 4.0+ atomic containers `e-flexbox`
	 * and `e-div-block` (see issue #104 / same class as #72).
	 *
	 * @param string $el_type The element's elType.
	 * @return bool
	 */
	private static function is_container_type( string $el_type ): bool {
		return in_array( $el_type, array( 'container', 'e-flexbox', 'e-div-block' ), true );
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
			'karmcp/add-container',
			'karmcp/update-container',
			'karmcp/update-element',
			'karmcp/batch-update',
			'karmcp/set-element-label',
			'karmcp/reorder-elements',
			'karmcp/move-element',
			'karmcp/remove-element',
			'karmcp/duplicate-element',
		);
	}

	/**
	 * Registers all layout abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_add_container();
		$this->register_update_container();
		$this->register_update_element();
		$this->register_batch_update();
		$this->register_set_element_label();
		$this->register_reorder_elements();
		$this->register_move_element();
		$this->register_remove_element();
		$this->register_duplicate_element();
	}

	/**
	 * Permission check for element editing.
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

	// -------------------------------------------------------------------------
	// add-container
	// -------------------------------------------------------------------------

	private function register_add_container(): void {
		karmcp_register_ability(
			'karmcp/add-container',
			array(
				'label'               => __( 'Add Container', 'karmcp' ),
				'description'         => __( 'Adds a container to a page. Supports both flex (default) and grid layouts via container_type. Omit parent_id for top-level, or provide a parent container ID for nesting. Flex tips: Use flex_direction=row for side-by-side children, flex_wrap=wrap for wrapping, flex_justify_content for main-axis alignment (e.g. space-between, center), flex_align_items for cross-axis alignment. (The shorthand justify_content / align_items are also accepted and remapped to flex_justify_content / flex_align_items.) Grid tips: Set container_type=grid with grid_columns_grid, grid_rows_grid, grid_gaps. Background: set background_background=classic and background_color=#hex. Border: set border_border=solid, border_width, border_color. Also supports min_height, overflow, html_tag, padding, margin, position, z_index, animation.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_add_container' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'parent_id' => array(
							'type'        => 'string',
							'description' => __( 'Parent container ID for nesting. Omit for top-level.', 'karmcp' ),
						),
						'position'  => array(
							'type'        => 'integer',
							'description' => __( 'Insert position. -1 = append (default).', 'karmcp' ),
						),
						'settings'  => array(
							'type'        => 'object',
							'description' => __( 'Container settings: flex_direction, flex_wrap, flex_justify_content, flex_align_items, gap, content_width, padding, margin, background, border, etc. (Unprefixed justify_content / align_items / align_content are accepted and remapped to the flex_-prefixed keys.)', 'karmcp' ),
						),
						'full_bleed' => array(
							'type'        => 'boolean',
							'description' => __( 'When true, seed an edge-to-edge full-bleed container (full content width, 100% width, zero padding, zero flex/gap, column + stretch). Use for the top-level container on Canvas-template pages so headers/footers and full-width sections have no white strips. Any explicit `settings` you pass still override the preset.', 'karmcp' ),
						),
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
	 * Executes the add-container ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_container( $input ) {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
		$position  = intval( $input['position'] ?? -1 );
		$settings  = $input['settings'] ?? array();

		// full_bleed preset: a top-level edge-to-edge container. On Canvas-template
		// pages (the natural template for AI-built full pages) the boxed defaults
		// leave white strips around headers/footers and edge-to-edge sections
		// (#83). The preset seeds the known-good recipe; any explicit `settings`
		// the caller passes still win, so it's a starting point, not a lock.
		if ( ! empty( $input['full_bleed'] ) ) {
			$settings = array_merge( self::full_bleed_preset(), (array) $settings );
		}

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'karmcp' ) );
		}

		// A `container` element only renders when Elementor's Flexbox Container
		// experiment is active. On long-lived installs where it is OFF, Elementor
		// silently skips the element on render, leaving an empty page with no error
		// (#111). Refuse up front with an actionable message instead of writing an
		// unrenderable document. Atomic sites (e-flexbox) are unaffected — those go
		// through add-flexbox, which registers only when atomic is supported.
		if ( ! KarMCP_Atomic_Props::is_container_supported() ) {
			return new \WP_Error(
				'container_unsupported',
				__( 'This site has Elementor\'s Flexbox Container experiment disabled, so a container element would be stored but render empty. Enable Elementor → Settings → Features → "Flexbox Container" (or Atomic Elements and use the add-flexbox / atomic tools) before building pages via MCP. Editing existing pages is unaffected.', 'karmcp' )
			);
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// When nesting inside a parent, mark as inner container.
		$container = $this->factory->create_container( $settings );
		if ( ! empty( $parent_id ) ) {
			$container['isInner'] = true;
		}

		$inserted = $this->data->insert_element( $page_data, $parent_id, $container, $position );

		if ( ! $inserted ) {
			return new \WP_Error(
				'parent_not_found',
				sprintf(
					/* translators: %s: parent element ID */
					__( 'Parent element "%s" not found.', 'karmcp' ),
					$parent_id
				)
			);
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'element_id' => $container['id'],
			'post_id'    => $post_id,
		);
	}

	/**
	 * The full-bleed container recipe: full content width, 100% width, zero
	 * padding, zero flex/gap, column direction, stretch alignment. This is the
	 * known-good top-level container for Canvas-template pages, where Elementor's
	 * boxed defaults otherwise leave white strips (#83).
	 *
	 * @since 3.2.1
	 * @return array<string,mixed>
	 */
	public static function full_bleed_preset(): array {
		return array(
			'content_width'    => 'full',
			'flex_direction'   => 'column',
			'flex_align_items' => 'stretch',
			'width'            => array( 'unit' => '%', 'size' => 100 ),
			'padding'          => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
			'gap'              => array( 'unit' => 'px', 'size' => 0, 'column' => '0', 'row' => '0' ),
			'flex_gap'         => array( 'unit' => 'px', 'size' => 0, 'column' => '0', 'row' => '0' ),
		);
	}

	// -------------------------------------------------------------------------
	// update-container
	// -------------------------------------------------------------------------

	private function register_update_container(): void {
		karmcp_register_ability(
			'karmcp/update-container',
			array(
				'label'               => __( 'Update Container', 'karmcp' ),
				'description'         => __( 'Updates settings on an existing container. Settings are merged (partial update). Supports all container controls: flex_direction, flex_justify_content, flex_align_items, flex_wrap, flex_align_content, gap, content_width, min_height, overflow, html_tag, container_type, grid controls, background (set background_background=classic first), border (set border_border=solid first), border_radius, box_shadow, padding, margin, position, z_index, animation, shape dividers, etc. (The unprefixed justify_content / align_items / align_content are accepted and remapped to the flex_-prefixed keys.)', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_container' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'The container element ID.', 'karmcp' ),
						),
						'settings'   => array(
							'type'        => 'object',
							'description' => __( 'Partial settings to merge into the container.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
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
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the update-container ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_container( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$settings   = $input['settings'] ?? array();

		if ( ! $post_id || empty( $element_id ) || empty( $settings ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, element_id, and settings are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'karmcp' ) );
		}

		if ( ! self::is_container_type( $element['elType'] ?? '' ) ) {
			return new \WP_Error( 'not_container', __( 'Element is not a container. Use update-widget for widgets.', 'karmcp' ) );
		}

		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update container settings.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// update-element (universal — works for both containers and widgets)
	// -------------------------------------------------------------------------

	private function register_update_element(): void {
		karmcp_register_ability(
			'karmcp/update-element',
			array(
				'label'               => __( 'Update Element', 'karmcp' ),
				'description'         => __( 'Updates settings on any element (container or widget). Settings are merged (partial update). Works for all element types, no need to know if the target is a container or widget. For v4 atomic elements you may also include a `styles` map (the element\'s local CSS classes) and/or `editor_settings` (e.g. `{ "title": "Hero" }` for the Navigator label) in the settings object, these are routed to the element root automatically. Use set-element-label for just the Navigator name.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'The element ID (container or widget).', 'karmcp' ),
						),
						'settings'   => array(
							'type'        => 'object',
							'description' => __( 'Partial settings to merge into the element.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'element_id'  => array( 'type' => 'string' ),
						'element_type' => array( 'type' => 'string' ),
						'unknown_keys' => array(
							'type'        => 'array',
							'description' => __( 'Present only when some setting matched no known control. The write still happened: the keys are stored, they simply will not render. Advisory — dynamic tags and addons legitimately produce names we cannot see.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'          => array( 'type' => 'string' ),
									'did_you_mean' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
							),
						),
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

	public function execute_update_element( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$settings   = $input['settings'] ?? array();

		if ( ! $post_id || empty( $element_id ) || empty( $settings ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, element_id, and settings are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'karmcp' ) );
		}

		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update element settings.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array(
			'success'      => true,
			'element_id'   => $element_id,
			'element_type' => $element['elType'] ?? 'unknown',
		);

		$unknown = $this->unknown_key_report( $element, $settings );
		if ( $unknown ) {
			$out['unknown_keys'] = $unknown;
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// batch-update
	// -------------------------------------------------------------------------

	private function register_batch_update(): void {
		karmcp_register_ability(
			'karmcp/batch-update',
			array(
				'label'               => __( 'Batch Update Elements', 'karmcp' ),
				'description'         => __( 'Updates multiple elements in a single save operation. Each operation specifies an element_id and settings to merge. Much more efficient than calling update-element multiple times. As with update-element, a per-operation settings object may include a `styles` map and/or `editor_settings` for v4 atomic elements, these are routed to the element root automatically.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_batch_update' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'operations' => array(
							'type'        => 'array',
							'description' => __( 'Array of update operations.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'element_id' => array( 'type' => 'string', 'description' => __( 'Element ID to update.', 'karmcp' ) ),
									'settings'   => array( 'type' => 'object', 'description' => __( 'Settings to merge.', 'karmcp' ) ),
								),
								'required'   => array( 'element_id', 'settings' ),
							),
						),
					),
					'required'   => array( 'post_id', 'operations' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'updated'  => array( 'type' => 'integer' ),
						'failed'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'unknown_keys' => array(
							'type'        => 'array',
							'description' => __( 'Per element, any setting that matched no known control. The writes still happened. Advisory only.', 'karmcp' ),
							'items'       => array( 'type' => 'object' ),
						),
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

	public function execute_batch_update( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$operations = $input['operations'] ?? array();

		if ( ! $post_id || empty( $operations ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and operations are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$updated_count = 0;
		$failed        = array();
		$unknown       = array();

		foreach ( $operations as $op ) {
			$eid      = sanitize_text_field( $op['element_id'] ?? '' );
			$settings = $op['settings'] ?? array();

			if ( empty( $eid ) || empty( $settings ) ) {
				$failed[] = array( 'element_id' => $eid, 'reason' => 'missing element_id or settings' );
				continue;
			}

			$element = $this->data->find_element_by_id( $page_data, $eid );

			if ( null === $element ) {
				$failed[] = array( 'element_id' => $eid, 'reason' => 'element not found' );
				continue;
			}

			$ok = $this->data->update_element_settings( $page_data, $eid, $settings );

			if ( $ok ) {
				$updated_count++;

				// Reported per element: a batch is exactly where an unknown key
				// disappears, since one summary count of successes says nothing
				// about which of twenty elements got a name wrong.
				$report = $this->unknown_key_report( $element, $settings );
				if ( $report ) {
					$unknown[] = array(
						'element_id' => $eid,
						'keys'       => $report,
					);
				}
			} else {
				$failed[] = array( 'element_id' => $eid, 'reason' => 'update failed' );
			}
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array(
			'success' => empty( $failed ),
			'updated' => $updated_count,
			'failed'  => $failed,
		);

		if ( $unknown ) {
			$out['unknown_keys'] = $unknown;
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// set-element-label (Navigator label — editor_settings.title)
	// -------------------------------------------------------------------------

	private function register_set_element_label(): void {
		karmcp_register_ability(
			'karmcp/set-element-label',
			array(
				'label'               => __( 'Set Element Label', 'karmcp' ),
				'description'         => __( 'Sets an element\'s Navigator label (stored in editor_settings.title). Works for any element; especially useful on v4 atomic elements to keep the layout readable. A convenience wrapper, the same result can be had via update-element with editor_settings.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_set_element_label' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'The element ID to label.', 'karmcp' ),
						),
						'title'      => array(
							'type'        => 'string',
							'description' => __( 'The Navigator label to set.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'title' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'element_id' => array( 'type' => 'string' ),
						'title'      => array( 'type' => 'string' ),
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

	public function execute_set_element_label( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$title      = sanitize_text_field( $input['title'] ?? '' );

		if ( ! $post_id || empty( $element_id ) || '' === $title ) {
			return new \WP_Error( 'missing_params', __( 'post_id, element_id, and title are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		if ( null === $this->data->find_element_by_id( $page_data, $element_id ) ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'karmcp' ) );
		}

		// editor_settings is a sibling-root key; update_element_settings() hoists
		// it out of the settings payload and deep-merges it into the element root.
		$updated = $this->data->update_element_settings(
			$page_data,
			$element_id,
			array( 'editor_settings' => array( 'title' => $title ) )
		);

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to set element label.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'element_id' => $element_id,
			'title'      => $title,
		);
	}

	// -------------------------------------------------------------------------
	// reorder-elements
	// -------------------------------------------------------------------------

	private function register_reorder_elements(): void {
		karmcp_register_ability(
			'karmcp/reorder-elements',
			array(
				'label'               => __( 'Reorder Elements', 'karmcp' ),
				'description'         => __( 'Reorders the children of a container by providing an ordered array of element IDs. All IDs must be direct children of the specified container.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_reorder_elements' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'container_id' => array(
							'type'        => 'string',
							'description' => __( 'The parent container element ID.', 'karmcp' ),
						),
						'element_ids'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Ordered array of child element IDs in the desired order.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'container_id', 'element_ids' ),
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
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_reorder_elements( $input ) {
		$post_id      = absint( $input['post_id'] ?? 0 );
		$container_id = sanitize_text_field( $input['container_id'] ?? '' );
		$element_ids  = $input['element_ids'] ?? array();

		if ( ! $post_id || empty( $container_id ) || empty( $element_ids ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, container_id, and element_ids are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$container = $this->data->find_element_by_id( $page_data, $container_id );

		if ( null === $container ) {
			return new \WP_Error( 'element_not_found', __( 'Container not found.', 'karmcp' ) );
		}

		if ( ! self::is_container_type( $container['elType'] ?? '' ) ) {
			return new \WP_Error( 'not_container', __( 'Element is not a container.', 'karmcp' ) );
		}

		$children = $container['elements'] ?? array();

		// Build lookup of children by ID.
		$children_by_id = array();
		foreach ( $children as $child ) {
			$children_by_id[ $child['id'] ] = $child;
		}

		// Validate all IDs are actual children.
		foreach ( $element_ids as $eid ) {
			if ( ! isset( $children_by_id[ $eid ] ) ) {
				return new \WP_Error(
					'invalid_element_id',
					/* translators: %s: the element id. */
				sprintf( __( 'Element "%s" is not a direct child of the container.', 'karmcp' ), $eid )
				);
			}
		}

		// Build reordered children array.
		$reordered = array();
		foreach ( $element_ids as $eid ) {
			$reordered[] = $children_by_id[ $eid ];
			unset( $children_by_id[ $eid ] );
		}

		// Append any children not in the provided list (preserve them at end).
		foreach ( $children_by_id as $remaining ) {
			$reordered[] = $remaining;
		}

		// Apply reorder.
		$applied = $this->reorder_children( $page_data, $container_id, $reordered );

		if ( ! $applied ) {
			return new \WP_Error( 'reorder_failed', __( 'Failed to reorder elements.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	/**
	 * Recursively finds a container and replaces its children array.
	 *
	 * @param array  &$data         The page data tree (by reference).
	 * @param string $container_id  The container element ID.
	 * @param array  $new_children  The reordered children array.
	 * @return bool
	 */
	private function reorder_children( array &$data, string $container_id, array $new_children ): bool {
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $container_id ) {
				$item['elements'] = $new_children;
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->reorder_children( $item['elements'], $container_id, $new_children ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// move-element
	// -------------------------------------------------------------------------

	private function register_move_element(): void {
		karmcp_register_ability(
			'karmcp/move-element',
			array(
				'label'               => __( 'Move Element', 'karmcp' ),
				'description'         => __( 'Moves an element to a new parent container and/or position within the page tree.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_move_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'element_id'       => array(
							'type'        => 'string',
							'description' => __( 'The element ID to move.', 'karmcp' ),
						),
						'target_parent_id' => array(
							'type'        => 'string',
							'description' => __( 'Target parent container ID. Empty string for top-level.', 'karmcp' ),
						),
						'position'         => array(
							'type'        => 'integer',
							'description' => __( 'Position within target parent. -1 = append.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'target_parent_id', 'position' ),
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
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the move-element ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_move_element( $input ) {
		$post_id          = absint( $input['post_id'] ?? 0 );
		$element_id       = sanitize_text_field( $input['element_id'] ?? '' );
		$target_parent_id = sanitize_text_field( $input['target_parent_id'] ?? '' );
		$position         = intval( $input['position'] ?? -1 );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Find the element first.
		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'karmcp' ) );
		}

		// Remove from current position.
		$removed = $this->data->remove_element( $page_data, $element_id );

		if ( ! $removed ) {
			return new \WP_Error( 'remove_failed', __( 'Failed to remove element from current position.', 'karmcp' ) );
		}

		// Insert at new position.
		$inserted = $this->data->insert_element( $page_data, $target_parent_id, $element, $position );

		if ( ! $inserted ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to insert element at target position.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// remove-element
	// -------------------------------------------------------------------------

	private function register_remove_element(): void {
		karmcp_register_ability(
			'karmcp/remove-element',
			array(
				'label'               => __( 'Remove Element', 'karmcp' ),
				'description'         => __( 'Removes an element and all its children from a page.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_remove_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'The element ID to remove.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id' ),
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

	/**
	 * Executes the remove-element ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_remove_element( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$removed = $this->data->remove_element( $page_data, $element_id );

		if ( ! $removed ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// duplicate-element
	// -------------------------------------------------------------------------

	private function register_duplicate_element(): void {
		karmcp_register_ability(
			'karmcp/duplicate-element',
			array(
				'label'               => __( 'Duplicate Element', 'karmcp' ),
				'description'         => __( 'Duplicates an element (including all children) with fresh IDs. The duplicate is placed immediately after the original.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_duplicate_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'The element ID to duplicate.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'new_element_id' => array( 'type' => 'string' ),
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
	 * Executes the duplicate-element ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_duplicate_element( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'karmcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'karmcp' ) );
		}

		// Deep-clone and reassign all IDs.
		$clone = $this->data->reassign_element_ids( $element );

		// Find parent and insert after original.
		$inserted = $this->insert_after( $page_data, $element_id, $clone );

		if ( ! $inserted ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to insert duplicate.', 'karmcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'new_element_id' => $clone['id'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Inserts an element immediately after a target element in the tree.
	 *
	 * @param array  &$data     The page data tree (by reference).
	 * @param string $target_id The element ID to insert after.
	 * @param array  $element   The element to insert.
	 * @return bool True if inserted successfully.
	 */
	private function insert_after( array &$data, string $target_id, array $element ): bool {
		foreach ( $data as $index => &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $target_id ) {
				array_splice( $data, $index + 1, 0, array( $element ) );
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->insert_after( $item['elements'], $target_id, $element ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
