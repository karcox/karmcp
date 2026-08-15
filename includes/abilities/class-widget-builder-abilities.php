<?php
/**
 * Widget Builder MCP abilities.
 *
 * Lets an AI agent design a custom Elementor widget as structured data and have
 * the plugin compile it. The agent never supplies PHP: it supplies a spec, and
 * `KarMCP_Widget_Generator` decides every escape from the declared control
 * types. A generated widget lives in the sandbox under wp-content, is loaded
 * only from a hash-verified manifest, and is deactivated automatically if it
 * ever fatals — and a human administrator can pause or delete any of them from
 * KarMCP → Sandbox → Widgets.
 *
 * Requires manage_options, like editing plugin code would.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the Widget Builder abilities.
 *
 * @since 1.12.0
 */
class KarMCP_Widget_Builder_Abilities {

	/**
	 * Ability names registered by this class.
	 *
	 * @since 1.12.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/list-control-types',
			'karmcp/validate-widget-spec',
			'karmcp/create-custom-widget',
			'karmcp/update-custom-widget',
			'karmcp/get-custom-widget',
			'karmcp/list-custom-widgets',
			'karmcp/set-widget-status',
			'karmcp/delete-custom-widget',
		);
	}

	/**
	 * Registers every ability.
	 *
	 * @since 1.12.0
	 */
	public function register(): void {
		$this->register_list_control_types();
		$this->register_validate();
		$this->register_create();
		$this->register_update();
		$this->register_get();
		$this->register_list();
		$this->register_set_status();
		$this->register_delete();
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Reads and writes share one gate: a generated widget is executable code, so
	 * even reading its compiled source is an administrator's business.
	 *
	 * @since 1.12.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return class_exists( 'KarMCP_Widget_Store' ) && KarMCP_Widget_Store::user_has_access();
	}

	// -------------------------------------------------------------------------
	// Schemas
	// -------------------------------------------------------------------------

	/**
	 * JSON Schema for a widget spec. Deliberately explicit: an agent that can
	 * see the shape does not have to guess it, and a spec that is wrong fails
	 * here instead of halfway through a compile.
	 *
	 * @return array
	 */
	private function spec_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'The widget definition. Call list-control-types first for the control vocabulary and template syntax.', 'karmcp' ),
			'properties'  => array(
				'spec_version' => array(
					'type'        => 'integer',
					'description' => __( 'Spec format version. Omit to use the current one.', 'karmcp' ),
				),
				'meta'         => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'Widget name shown in the Elementor panel. Required.', 'karmcp' ),
						),
						'icon'        => array(
							'type'        => 'string',
							'description' => __( 'An Elementor panel icon class, e.g. "eicon-testimonial".', 'karmcp' ),
						),
						'keywords'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Search keywords for the panel.', 'karmcp' ),
						),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'title' ),
				),
				'controls'     => array(
					'type'        => 'array',
					'description' => __( 'The editable fields a person sees when the widget is selected.', 'karmcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array(
								'type'        => 'string',
								'description' => __( 'Machine name used in the template: lowercase letters, digits, underscores.', 'karmcp' ),
							),
							'type'        => array(
								'type'        => 'string',
								'enum'        => array_keys( KarMCP_Widget_Spec::control_types() ),
								'description' => __( 'Control type. This is what decides how the value is escaped on output.', 'karmcp' ),
							),
							'label'       => array( 'type' => 'string' ),
							'default'     => array( 'description' => __( 'Default value (scalar controls only).', 'karmcp' ) ),
							'placeholder' => array( 'type' => 'string' ),
							'section'     => array(
								'type'        => 'string',
								'enum'        => KarMCP_Widget_Spec::SECTIONS,
								'description' => __( 'Which panel tab the control appears in. Default: content.', 'karmcp' ),
							),
							'options'     => array(
								'type'        => 'object',
								'description' => __( 'For select controls: a map of value => label.', 'karmcp' ),
							),
						),
						'required'   => array( 'name', 'type' ),
					),
				),
				'template'     => array(
					'type'        => 'string',
					'description' => __( 'The widget markup, with {{control}} placeholders. HTML only — no PHP, no <script>, no inline event handlers.', 'karmcp' ),
				),
				'styles'       => array(
					'type'        => 'string',
					'description' => __( 'Optional CSS, served as a static file and enqueued only when the widget is on the page.', 'karmcp' ),
				),
				'scripts'      => array(
					'type'        => 'string',
					'description' => __( 'Optional JavaScript, served as a static file and enqueued only when the widget is on the page.', 'karmcp' ),
				),
			),
			'required'    => array( 'meta', 'template' ),
		);
	}

	/**
	 * Output schema for a widget record.
	 *
	 * @return array
	 */
	private function widget_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'     => array( 'type' => 'boolean' ),
				'widget_id'   => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'widget_name' => array( 'type' => 'string' ),
				'class_name'  => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'last_error'  => array( 'type' => 'string' ),
				'updated'     => array( 'type' => 'string' ),
				'note'        => array( 'type' => 'string' ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// list-control-types
	// -------------------------------------------------------------------------

	private function register_list_control_types(): void {
		karmcp_register_ability(
			'karmcp/list-control-types',
			array(
				'label'               => __( 'List Control Types', 'karmcp' ),
				'description'         => __( 'Returns the control types a widget spec may declare, the template placeholder syntax, and the size limits. Read this before writing your first spec: the control type you pick is what decides how a value is escaped when the widget renders.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_control_types' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Unused.
	 * @return array
	 */
	public function execute_list_control_types( $input ) {
		unset( $input );
		return KarMCP_Widget_Spec::describe();
	}

	// -------------------------------------------------------------------------
	// validate-widget-spec
	// -------------------------------------------------------------------------

	private function register_validate(): void {
		karmcp_register_ability(
			'karmcp/validate-widget-spec',
			array(
				'label'               => __( 'Validate Widget Spec', 'karmcp' ),
				'description'         => __( 'Runs the real compiler against a spec WITHOUT saving anything, and returns either the PHP it would generate or the first error. Use it to iterate before create-custom-widget.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_validate' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'spec' => $this->spec_schema() ),
					'required'   => array( 'spec' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'valid' => array( 'type' => 'boolean' ),
						'error' => array( 'type' => 'string' ),
						'code'  => array( 'type' => 'string' ),
						'php'   => array( 'type' => 'string' ),
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
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_validate( $input ) {
		$spec = $this->normalize_spec( $input );

		// Compiled against placeholder names: this is a dry run, so there is no
		// post ID yet to seed the real ones.
		$php = KarMCP_Widget_Generator::generate( $spec, 'KarMCP_Widget_Preview', 'karmcp_custom_preview' );

		if ( is_wp_error( $php ) ) {
			return array(
				'valid' => false,
				'code'  => $php->get_error_code(),
				'error' => $php->get_error_message(),
			);
		}

		return array(
			'valid' => true,
			'php'   => $php,
		);
	}

	// -------------------------------------------------------------------------
	// create-custom-widget
	// -------------------------------------------------------------------------

	private function register_create(): void {
		karmcp_register_ability(
			'karmcp/create-custom-widget',
			array(
				'label'               => __( 'Create Custom Widget', 'karmcp' ),
				'description'         => __( 'Compiles a spec into a custom Elementor widget inside the sandbox and, by default, activates it so it appears in the panel under "Custom (KarMCP)". If the compiled widget errors when Elementor builds it, it is kept as a draft with the error recorded rather than shipped broken.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_create' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'spec'     => $this->spec_schema(),
						'activate' => array(
							'type'        => 'boolean',
							'description' => __( 'Activate immediately (default true). Pass false to leave it as a draft for a human to review.', 'karmcp' ),
						),
					),
					'required'   => array( 'spec' ),
				),
				'output_schema'       => $this->widget_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_create( $input ) {
		$activate = ! isset( $input['activate'] ) || (bool) $input['activate'];
		$result   = KarMCP_Widget_Store::create( $this->normalize_spec( $input ), $activate );

		return $this->decorate( $result );
	}

	// -------------------------------------------------------------------------
	// update-custom-widget
	// -------------------------------------------------------------------------

	private function register_update(): void {
		karmcp_register_ability(
			'karmcp/update-custom-widget',
			array(
				'label'               => __( 'Update Custom Widget', 'karmcp' ),
				'description'         => __( 'Replaces a widget\'s spec and regenerates its code. An active widget is re-checked and demoted to draft if the new code no longer builds, so a bad edit cannot break the editor.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'widget_id' => array( 'type' => 'integer', 'description' => __( 'The widget ID.', 'karmcp' ) ),
						'spec'      => $this->spec_schema(),
					),
					'required'   => array( 'widget_id', 'spec' ),
				),
				'output_schema'       => $this->widget_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_update( $input ) {
		$id = isset( $input['widget_id'] ) ? absint( $input['widget_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'widget_id is required.', 'karmcp' ) );
		}

		return $this->decorate( KarMCP_Widget_Store::update( $id, $this->normalize_spec( $input ) ) );
	}

	// -------------------------------------------------------------------------
	// get-custom-widget
	// -------------------------------------------------------------------------

	private function register_get(): void {
		karmcp_register_ability(
			'karmcp/get-custom-widget',
			array(
				'label'               => __( 'Get Custom Widget', 'karmcp' ),
				'description'         => __( 'Returns a custom widget: its spec, the generated PHP, its status, and the last error if it was auto-deactivated.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'widget_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'widget_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_get( $input ) {
		$id = isset( $input['widget_id'] ) ? absint( $input['widget_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'widget_id is required.', 'karmcp' ) );
		}

		$summary = KarMCP_Widget_Store::summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$summary['spec']    = KarMCP_Widget_Store::get_spec( $id ) ?? array();
		$summary['php']     = KarMCP_Widget_Store::get_php( $id );
		$summary['styles']  = KarMCP_Widget_Store::get_css( $id );
		$summary['scripts'] = KarMCP_Widget_Store::get_js( $id );

		return $summary;
	}

	// -------------------------------------------------------------------------
	// list-custom-widgets
	// -------------------------------------------------------------------------

	private function register_list(): void {
		karmcp_register_ability(
			'karmcp/list-custom-widgets',
			array(
				'label'               => __( 'List Custom Widgets', 'karmcp' ),
				'description'         => __( 'Lists every generated custom widget with its status and last error.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type' => 'string',
							'enum' => array( 'any', 'active', 'draft' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'widgets' => array( 'type' => 'array' ),
						'count'   => array( 'type' => 'integer' ),
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
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_list( $input ) {
		$status  = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
		$widgets = KarMCP_Widget_Store::list_widgets( in_array( $status, array( 'any', 'active', 'draft' ), true ) ? $status : 'any' );

		return array(
			'widgets' => $widgets,
			'count'   => count( $widgets ),
		);
	}

	// -------------------------------------------------------------------------
	// set-widget-status
	// -------------------------------------------------------------------------

	private function register_set_status(): void {
		karmcp_register_ability(
			'karmcp/set-widget-status',
			array(
				'label'               => __( 'Set Widget Status', 'karmcp' ),
				'description'         => __( 'Activates or deactivates a custom widget. Activation is refused if the widget errors when Elementor builds it.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_set_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'widget_id' => array( 'type' => 'integer' ),
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'active', 'draft' ),
						),
					),
					'required'   => array( 'widget_id', 'status' ),
				),
				'output_schema'       => $this->widget_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_set_status( $input ) {
		$id     = isset( $input['widget_id'] ) ? absint( $input['widget_id'] ) : 0;
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		if ( ! $id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			return new \WP_Error( 'invalid_input', __( 'widget_id and a status of "active" or "draft" are required.', 'karmcp' ) );
		}

		return $this->decorate( KarMCP_Widget_Store::set_status( $id, $status ) );
	}

	// -------------------------------------------------------------------------
	// delete-custom-widget
	// -------------------------------------------------------------------------

	private function register_delete(): void {
		karmcp_register_ability(
			'karmcp/delete-custom-widget',
			array(
				'label'               => __( 'Delete Custom Widget', 'karmcp' ),
				'description'         => __( 'Permanently deletes a custom widget, its spec, and its sandbox files. Pages already using the widget lose it. Requires confirm:true.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'widget_id' => array( 'type' => 'integer' ),
						'confirm'   => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true. Deletion cannot be undone.', 'karmcp' ),
						),
					),
					'required'   => array( 'widget_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'widget_id' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_delete( $input ) {
		$id = isset( $input['widget_id'] ) ? absint( $input['widget_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'widget_id is required.', 'karmcp' ) );
		}
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error(
				'confirm_required',
				__( 'Deleting a custom widget is permanent and removes it from any page using it. Pass confirm:true to proceed.', 'karmcp' )
			);
		}

		return KarMCP_Widget_Store::delete( $id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Pulls the spec out of an ability input and stamps the format version when
	 * the agent left it out, so every stored spec records what it was written
	 * against.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array
	 */
	private function normalize_spec( $input ): array {
		$spec = ( is_array( $input ) && isset( $input['spec'] ) && is_array( $input['spec'] ) ) ? $input['spec'] : array();

		if ( ! isset( $spec['spec_version'] ) ) {
			$spec['spec_version'] = KarMCP_Widget_Spec::SPEC_VERSION;
		}

		return $spec;
	}

	/**
	 * Adds `success` and, when a widget did not go live, says why in the same
	 * breath — an agent that only sees status:"draft" tends to retry the create
	 * rather than read the error it already caused.
	 *
	 * @param array|\WP_Error $result Store result.
	 * @return array|\WP_Error
	 */
	private function decorate( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['success'] = true;

		if ( ! empty( $result['last_error'] ) ) {
			$result['note'] = sprintf(
				/* translators: %s: the error Elementor reported */
				__( 'The widget is saved but inactive: Elementor rejected it with "%s". Fix the spec and call update-custom-widget.', 'karmcp' ),
				(string) $result['last_error']
			);
		}

		return $result;
	}
}
