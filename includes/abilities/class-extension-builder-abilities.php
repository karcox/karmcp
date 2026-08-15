<?php
/**
 * Element Extension MCP abilities.
 *
 * Lets an agent add its own options to Elementor 4.2+ elements: a section in
 * the panel of any container, with controls that put classes and data
 * attributes on the element and bring in CSS and JavaScript when they are
 * actually used.
 *
 * The agent supplies a spec, never code. Two rules make that defensible and
 * both are enforced by the compiler: prop names carry the `karmcp_` prefix
 * because Elementor's props schema is shared with the core and with Pro, and
 * only `data-` and `aria-` attributes can be written, because an extension able
 * to emit `onclick` or `style` would be an XSS vector with a nice UI.
 *
 * Requires manage_options, like editing plugin code would.
 *
 * @package KarMCP
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the element extension abilities.
 *
 * @since 1.13.0
 */
class KarMCP_Extension_Builder_Abilities {

	/**
	 * Ability names registered by this class.
	 *
	 * @since 1.13.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/list-element-targets',
			'karmcp/validate-extension-spec',
			'karmcp/create-element-extension',
			'karmcp/update-element-extension',
			'karmcp/get-element-extension',
			'karmcp/list-element-extensions',
			'karmcp/set-extension-status',
			'karmcp/delete-element-extension',
		);
	}

	/**
	 * Registers every ability.
	 *
	 * @since 1.13.0
	 */
	public function register(): void {
		$this->register_list_targets();
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
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return class_exists( 'KarMCP_Extension_Store' ) && KarMCP_Extension_Store::user_has_access();
	}

	/**
	 * @return KarMCP_Extension_Store
	 */
	private function store(): KarMCP_Extension_Store {
		return KarMCP_Extension_Store::instance();
	}

	// -------------------------------------------------------------------------
	// Schemas
	// -------------------------------------------------------------------------

	/**
	 * JSON Schema for an extension spec.
	 *
	 * @return array
	 */
	private function spec_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'The extension definition. Call list-element-targets first for this site\'s element types and the prop vocabulary.', 'karmcp' ),
			'properties'  => array(
				'spec_version' => array( 'type' => 'integer' ),
				'meta'         => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'Name of the extension, for the humans managing it. Required.', 'karmcp' ),
						),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'title' ),
				),
				'targets'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Element types this attaches to, e.g. ["e-div-block","e-flexbox"], or ["*"] for all atomic elements.', 'karmcp' ),
				),
				'section'      => array(
					'type'       => 'object',
					'properties' => array(
						'label' => array(
							'type'        => 'string',
							'description' => __( 'Heading of the section added to the element\'s settings panel. Defaults to meta.title.', 'karmcp' ),
						),
					),
				),
				'props'        => array(
					'type'        => 'array',
					'description' => __( 'The controls a person sees on the element.', 'karmcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'    => array(
								'type'        => 'string',
								'description' => __( 'Must start with karmcp_ — the props schema is shared with Elementor.', 'karmcp' ),
							),
							'type'    => array(
								'type' => 'string',
								'enum' => array_keys( KarMCP_Extension_Spec::prop_types() ),
							),
							'label'   => array( 'type' => 'string' ),
							'default' => array( 'description' => __( 'Resting value. It is also what an element that never touched this extension evaluates as.', 'karmcp' ) ),
							'options' => array(
								'type'        => 'object',
								'description' => __( 'For select props: a map of value => label.', 'karmcp' ),
							),
						),
						'required'   => array( 'name', 'type' ),
					),
				),
				'output'       => array(
					'type'        => 'array',
					'description' => __( 'What ends up on the element. Each rule may carry a condition, a CSS class, and data-/aria- attributes whose values can interpolate {{karmcp_prop}}.', 'karmcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'when'       => array(
								'type'        => 'object',
								'description' => __( 'Condition: { prop, equals } | { prop, not } | { prop } for truthy. Omit for always.', 'karmcp' ),
							),
							'class'      => array(
								'type'        => 'string',
								'description' => __( 'A single CSS class, added to the element wrapper.', 'karmcp' ),
							),
							'attributes' => array(
								'type'        => 'object',
								'description' => __( 'data-* / aria-* attributes to write.', 'karmcp' ),
							),
						),
					),
				),
				'styles'       => array(
					'description' => __( 'CSS: either a string, or { critical, deferred }. The critical part is inlined once per page so the effect does not flash; the rest is served as a file.', 'karmcp' ),
				),
				'scripts'      => array(
					'type'        => 'string',
					'description' => __( 'Optional JavaScript, served as a file and enqueued only where the extension applies.', 'karmcp' ),
				),
			),
			'required'    => array( 'meta', 'targets', 'props', 'output' ),
		);
	}

	/**
	 * Output schema for an extension record.
	 *
	 * @return array
	 */
	private function extension_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'extension_id' => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'targets'      => array( 'type' => 'array' ),
				'props'        => array( 'type' => 'array' ),
				'last_error'   => array( 'type' => 'string' ),
				'updated'      => array( 'type' => 'string' ),
				'note'         => array( 'type' => 'string' ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// list-element-targets
	// -------------------------------------------------------------------------

	private function register_list_targets(): void {
		karmcp_register_ability(
			'karmcp/list-element-targets',
			array(
				'label'               => __( 'List Element Targets', 'karmcp' ),
				'description'         => __( 'Returns the Elementor 4 atomic element types this site actually has (e-div-block, e-flexbox…), plus the prop vocabulary and output rules an extension spec may use. Read it before writing a spec: element type names are not guessable, and a wrong one silently never matches.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_targets' ),
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
	public function execute_list_targets( $input ) {
		unset( $input );

		return array_merge(
			KarMCP_Extension_Spec::describe(),
			array(
				'targets' => $this->atomic_element_types(),
			)
		);
	}

	/**
	 * The atomic element types registered on this site.
	 *
	 * Read from Elementor's own registries rather than a hard-coded list: an
	 * element type that is not registered here cannot be targeted, and the list
	 * grows with every Elementor release.
	 *
	 * @return array<int, array>
	 */
	private function atomic_element_types(): array {
		$out = array();

		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return $out;
		}

		$registries = array();

		if ( isset( \Elementor\Plugin::$instance->elements_manager ) ) {
			$registries[] = (array) \Elementor\Plugin::$instance->elements_manager->get_element_types();
		}
		if ( isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			$registries[] = (array) \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		}

		foreach ( $registries as $registry ) {
			foreach ( $registry as $type ) {
				// Atomic elements are the ones that answer get_element_type();
				// classic sections, columns and widgets do not.
				if ( ! is_object( $type ) || ! method_exists( $type, 'get_element_type' ) ) {
					continue;
				}

				$name = (string) $type::get_element_type();
				if ( '' === $name || isset( $out[ $name ] ) ) {
					continue;
				}

				$out[ $name ] = array(
					'type'  => $name,
					'title' => method_exists( $type, 'get_title' ) ? (string) $type->get_title() : $name,
				);
			}
		}

		ksort( $out );

		return array_values( $out );
	}

	// -------------------------------------------------------------------------
	// validate-extension-spec
	// -------------------------------------------------------------------------

	private function register_validate(): void {
		karmcp_register_ability(
			'karmcp/validate-extension-spec',
			array(
				'label'               => __( 'Validate Extension Spec', 'karmcp' ),
				'description'         => __( 'Runs the real compiler against a spec WITHOUT saving anything, and returns either the PHP it would generate or the first error. Use it to iterate before create-element-extension.', 'karmcp' ),
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
		$php = KarMCP_Extension_Generator::generate( $this->normalize_spec( $input ), 'KarMCP_Extension_Preview' );

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
	// create-element-extension
	// -------------------------------------------------------------------------

	private function register_create(): void {
		karmcp_register_ability(
			'karmcp/create-element-extension',
			array(
				'label'               => __( 'Create Element Extension', 'karmcp' ),
				'description'         => __( 'Compiles a spec into an element extension inside the sandbox and, by default, activates it — adding its section to the panel of every targeted element. Requires Elementor 4.2+ atomic elements; it does not touch classic sections or columns.', 'karmcp' ),
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
				'output_schema'       => $this->extension_schema(),
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

		return $this->decorate( $this->store()->create( $this->normalize_spec( $input ), $activate ) );
	}

	// -------------------------------------------------------------------------
	// update-element-extension
	// -------------------------------------------------------------------------

	private function register_update(): void {
		karmcp_register_ability(
			'karmcp/update-element-extension',
			array(
				'label'               => __( 'Update Element Extension', 'karmcp' ),
				'description'         => __( 'Replaces an extension\'s spec and regenerates its code. Renaming a prop loses the values already stored under the old name, so prefer adding a prop to renaming one.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'extension_id' => array( 'type' => 'integer' ),
						'spec'         => $this->spec_schema(),
					),
					'required'   => array( 'extension_id', 'spec' ),
				),
				'output_schema'       => $this->extension_schema(),
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
		$id = isset( $input['extension_id'] ) ? absint( $input['extension_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'extension_id is required.', 'karmcp' ) );
		}

		return $this->decorate( $this->store()->update( $id, $this->normalize_spec( $input ) ) );
	}

	// -------------------------------------------------------------------------
	// get-element-extension
	// -------------------------------------------------------------------------

	private function register_get(): void {
		karmcp_register_ability(
			'karmcp/get-element-extension',
			array(
				'label'               => __( 'Get Element Extension', 'karmcp' ),
				'description'         => __( 'Returns an extension: its spec, the generated PHP, its status, and the last error if it was auto-deactivated.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'extension_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'extension_id' ),
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
		$id = isset( $input['extension_id'] ) ? absint( $input['extension_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'extension_id is required.', 'karmcp' ) );
		}

		$summary = $this->store()->summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$summary['spec']    = $this->store()->get_spec( $id ) ?? array();
		$summary['php']     = $this->store()->get_asset( $id, 'extension.php' );
		$summary['styles']  = $this->store()->get_asset( $id, 'style.css' );
		$summary['scripts'] = $this->store()->get_asset( $id, 'script.js' );

		return $summary;
	}

	// -------------------------------------------------------------------------
	// list-element-extensions
	// -------------------------------------------------------------------------

	private function register_list(): void {
		karmcp_register_ability(
			'karmcp/list-element-extensions',
			array(
				'label'               => __( 'List Element Extensions', 'karmcp' ),
				'description'         => __( 'Lists every element extension with its status, its targets and the props it declares.', 'karmcp' ),
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
						'extensions' => array( 'type' => 'array' ),
						'count'      => array( 'type' => 'integer' ),
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
		$status     = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
		$extensions = $this->store()->list_extensions( in_array( $status, array( 'any', 'active', 'draft' ), true ) ? $status : 'any' );

		return array(
			'extensions' => $extensions,
			'count'      => count( $extensions ),
		);
	}

	// -------------------------------------------------------------------------
	// set-extension-status
	// -------------------------------------------------------------------------

	private function register_set_status(): void {
		karmcp_register_ability(
			'karmcp/set-extension-status',
			array(
				'label'               => __( 'Set Extension Status', 'karmcp' ),
				'description'         => __( 'Activates or deactivates an element extension. Activation is refused if another active extension already declares the same prop names.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_set_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'extension_id' => array( 'type' => 'integer' ),
						'status'       => array(
							'type' => 'string',
							'enum' => array( 'active', 'draft' ),
						),
					),
					'required'   => array( 'extension_id', 'status' ),
				),
				'output_schema'       => $this->extension_schema(),
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
		$id     = isset( $input['extension_id'] ) ? absint( $input['extension_id'] ) : 0;
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		if ( ! $id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			return new \WP_Error( 'invalid_input', __( 'extension_id and a status of "active" or "draft" are required.', 'karmcp' ) );
		}

		return $this->decorate( $this->store()->set_status( $id, $status ) );
	}

	// -------------------------------------------------------------------------
	// delete-element-extension
	// -------------------------------------------------------------------------

	private function register_delete(): void {
		karmcp_register_ability(
			'karmcp/delete-element-extension',
			array(
				'label'               => __( 'Delete Element Extension', 'karmcp' ),
				'description'         => __( 'Permanently deletes an element extension, its spec and its sandbox files. Elements keep whatever values were stored, but nothing reads them any more. Requires confirm:true.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'extension_id' => array( 'type' => 'integer' ),
						'confirm'      => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true. Deletion cannot be undone.', 'karmcp' ),
						),
					),
					'required'   => array( 'extension_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'extension_id' => array( 'type' => 'integer' ),
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
		$id = isset( $input['extension_id'] ) ? absint( $input['extension_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'extension_id is required.', 'karmcp' ) );
		}
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error(
				'confirm_required',
				__( 'Deleting an element extension is permanent and removes its option from every element that had it. Pass confirm:true to proceed.', 'karmcp' )
			);
		}

		return $this->store()->delete( $id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Pulls the spec out of an ability input and stamps the format version.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array
	 */
	private function normalize_spec( $input ): array {
		$spec = ( is_array( $input ) && isset( $input['spec'] ) && is_array( $input['spec'] ) ) ? $input['spec'] : array();

		if ( ! isset( $spec['spec_version'] ) ) {
			$spec['spec_version'] = KarMCP_Extension_Spec::SPEC_VERSION;
		}

		return $spec;
	}

	/**
	 * Adds `success`, and says why when an extension did not go live.
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
				/* translators: %s: the recorded error */
				__( 'The extension is saved but inactive: it failed with "%s". Fix the spec and call update-element-extension.', 'karmcp' ),
				(string) $result['last_error']
			);
		}

		return $result;
	}
}
