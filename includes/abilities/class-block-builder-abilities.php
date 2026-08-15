<?php
/**
 * Block Builder MCP abilities.
 *
 * The Gutenberg counterpart of the Widget Builder: an agent describes a block
 * as structured data and the plugin compiles it into `block.json` +
 * `render.php` inside the sandbox. The agent never supplies PHP or JavaScript;
 * escaping is decided by the declared attribute types, the block is
 * server-rendered, and an administrator can pause or delete any of them from
 * KarMCP → Sandbox → Blocks.
 *
 * Not Elementor-dependent: these work on any WordPress site.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the Block Builder abilities.
 *
 * @since 1.12.0
 */
class KarMCP_Block_Builder_Abilities {

	/**
	 * Ability names registered by this class.
	 *
	 * @since 1.12.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/list-block-control-types',
			'karmcp/validate-block-spec',
			'karmcp/create-custom-block',
			'karmcp/update-custom-block',
			'karmcp/get-custom-block',
			'karmcp/list-custom-blocks',
			'karmcp/set-block-status',
			'karmcp/delete-custom-block',
		);
	}

	/**
	 * Registers every ability.
	 *
	 * @since 1.12.0
	 */
	public function register(): void {
		$this->register_list_types();
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
	 * A generated block is executable code, so even reading its compiled source
	 * is an administrator's business.
	 *
	 * @since 1.12.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return class_exists( 'KarMCP_Block_Store' ) && KarMCP_Block_Store::user_has_access();
	}

	/**
	 * The store, resolved once per call.
	 *
	 * @return KarMCP_Block_Store
	 */
	private function store(): KarMCP_Block_Store {
		return KarMCP_Block_Store::instance();
	}

	// -------------------------------------------------------------------------
	// Schemas
	// -------------------------------------------------------------------------

	/**
	 * JSON Schema for a block spec.
	 *
	 * @return array
	 */
	private function spec_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'The block definition. Call list-block-control-types first for the attribute vocabulary and template syntax.', 'karmcp' ),
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
							'description' => __( 'Block name shown in the inserter. Required.', 'karmcp' ),
						),
						'icon'        => array(
							'type'        => 'string',
							'description' => __( 'A Dashicon slug without the "dashicons-" prefix, e.g. "format-quote".', 'karmcp' ),
						),
						'keywords'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'title' ),
				),
				'attributes'   => array(
					'type'        => 'array',
					'description' => __( 'The editable fields a person sees in the block inspector.', 'karmcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'    => array(
								'type'        => 'string',
								'description' => __( 'Machine name used in the template: lowercase letters, digits, underscores.', 'karmcp' ),
							),
							'type'    => array(
								'type'        => 'string',
								'enum'        => array_keys( KarMCP_Block_Spec::attribute_types() ),
								'description' => __( 'Attribute type. This is what decides how the value is escaped on output.', 'karmcp' ),
							),
							'label'   => array( 'type' => 'string' ),
							'default' => array( 'description' => __( 'Default value (scalar types only).', 'karmcp' ) ),
							'options' => array(
								'type'        => 'object',
								'description' => __( 'For select attributes: a map of value => label.', 'karmcp' ),
							),
						),
						'required'   => array( 'name', 'type' ),
					),
				),
				'template'     => array(
					'type'        => 'string',
					'description' => __( 'The block markup, with {{attribute}} placeholders. HTML only — no PHP, no <script>, no inline event handlers.', 'karmcp' ),
				),
				'styles'       => array(
					'type'        => 'string',
					'description' => __( 'Optional CSS, served as a static file and enqueued only where the block appears.', 'karmcp' ),
				),
				'scripts'      => array(
					'type'        => 'string',
					'description' => __( 'Optional JavaScript, served as a static file.', 'karmcp' ),
				),
			),
			'required'    => array( 'meta', 'template' ),
		);
	}

	/**
	 * Output schema for a block record.
	 *
	 * @return array
	 */
	private function block_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'block_id'   => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'block_name' => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'last_error' => array( 'type' => 'string' ),
				'updated'    => array( 'type' => 'string' ),
				'note'       => array( 'type' => 'string' ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// list-block-control-types
	// -------------------------------------------------------------------------

	private function register_list_types(): void {
		karmcp_register_ability(
			'karmcp/list-block-control-types',
			array(
				'label'               => __( 'List Block Control Types', 'karmcp' ),
				'description'         => __( 'Returns the attribute types a block spec may declare, the template placeholder syntax, and the size limits. Read this before writing your first block spec: the attribute type you pick is what decides how a value is escaped when the block renders.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_types' ),
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
	public function execute_list_types( $input ) {
		unset( $input );
		return KarMCP_Block_Spec::describe();
	}

	// -------------------------------------------------------------------------
	// validate-block-spec
	// -------------------------------------------------------------------------

	private function register_validate(): void {
		karmcp_register_ability(
			'karmcp/validate-block-spec',
			array(
				'label'               => __( 'Validate Block Spec', 'karmcp' ),
				'description'         => __( 'Runs the real compiler against a spec WITHOUT saving anything, and returns either the block.json and render.php it would generate or the first error. Use it to iterate before create-custom-block.', 'karmcp' ),
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
						'valid'      => array( 'type' => 'boolean' ),
						'error'      => array( 'type' => 'string' ),
						'code'       => array( 'type' => 'string' ),
						'block_json' => array( 'type' => 'string' ),
						'render_php' => array( 'type' => 'string' ),
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
		$compiled = KarMCP_Block_Generator::generate( $this->normalize_spec( $input ), 'karmcp/custom-preview' );

		if ( is_wp_error( $compiled ) ) {
			return array(
				'valid' => false,
				'code'  => $compiled->get_error_code(),
				'error' => $compiled->get_error_message(),
			);
		}

		return array(
			'valid'      => true,
			'block_json' => $compiled['block.json'],
			'render_php' => $compiled['render.php'],
		);
	}

	// -------------------------------------------------------------------------
	// create-custom-block
	// -------------------------------------------------------------------------

	private function register_create(): void {
		karmcp_register_ability(
			'karmcp/create-custom-block',
			array(
				'label'               => __( 'Create Custom Block', 'karmcp' ),
				'description'         => __( 'Compiles a spec into a custom Gutenberg block inside the sandbox and, by default, activates it so it appears in the inserter under "Custom (KarMCP)". The block is server-rendered: the editor previews the same PHP the front end runs.', 'karmcp' ),
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
				'output_schema'       => $this->block_schema(),
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
	// update-custom-block
	// -------------------------------------------------------------------------

	private function register_update(): void {
		karmcp_register_ability(
			'karmcp/update-custom-block',
			array(
				'label'               => __( 'Update Custom Block', 'karmcp' ),
				'description'         => __( 'Replaces a block\'s spec and regenerates its files. Every place the block is already used picks the change up, because the markup lives in the render file rather than in post content.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'block_id' => array( 'type' => 'integer', 'description' => __( 'The block ID.', 'karmcp' ) ),
						'spec'     => $this->spec_schema(),
					),
					'required'   => array( 'block_id', 'spec' ),
				),
				'output_schema'       => $this->block_schema(),
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
		$id = isset( $input['block_id'] ) ? absint( $input['block_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'block_id is required.', 'karmcp' ) );
		}

		return $this->decorate( $this->store()->update( $id, $this->normalize_spec( $input ) ) );
	}

	// -------------------------------------------------------------------------
	// get-custom-block
	// -------------------------------------------------------------------------

	private function register_get(): void {
		karmcp_register_ability(
			'karmcp/get-custom-block',
			array(
				'label'               => __( 'Get Custom Block', 'karmcp' ),
				'description'         => __( 'Returns a custom block: its spec, the generated block.json and render.php, its status, and the last error if it was auto-deactivated.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'block_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'block_id' ),
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
		$id = isset( $input['block_id'] ) ? absint( $input['block_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'block_id is required.', 'karmcp' ) );
		}

		$summary = $this->store()->summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$summary['spec']       = $this->store()->get_spec( $id ) ?? array();
		$summary['block_json'] = $this->store()->get_asset( $id, 'block.json' );
		$summary['render_php'] = $this->store()->get_asset( $id, 'render.php' );
		$summary['styles']     = $this->store()->get_asset( $id, 'style.css' );
		$summary['scripts']    = $this->store()->get_asset( $id, 'script.js' );

		return $summary;
	}

	// -------------------------------------------------------------------------
	// list-custom-blocks
	// -------------------------------------------------------------------------

	private function register_list(): void {
		karmcp_register_ability(
			'karmcp/list-custom-blocks',
			array(
				'label'               => __( 'List Custom Blocks', 'karmcp' ),
				'description'         => __( 'Lists every generated custom block with its status and last error.', 'karmcp' ),
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
						'blocks' => array( 'type' => 'array' ),
						'count'  => array( 'type' => 'integer' ),
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
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
		$blocks = $this->store()->list_blocks( in_array( $status, array( 'any', 'active', 'draft' ), true ) ? $status : 'any' );

		return array(
			'blocks' => $blocks,
			'count'  => count( $blocks ),
		);
	}

	// -------------------------------------------------------------------------
	// set-block-status
	// -------------------------------------------------------------------------

	private function register_set_status(): void {
		karmcp_register_ability(
			'karmcp/set-block-status',
			array(
				'label'               => __( 'Set Block Status', 'karmcp' ),
				'description'         => __( 'Activates or deactivates a custom block. A deactivated block stops rendering everywhere it is used.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_set_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'block_id' => array( 'type' => 'integer' ),
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'active', 'draft' ),
						),
					),
					'required'   => array( 'block_id', 'status' ),
				),
				'output_schema'       => $this->block_schema(),
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
		$id     = isset( $input['block_id'] ) ? absint( $input['block_id'] ) : 0;
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		if ( ! $id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			return new \WP_Error( 'invalid_input', __( 'block_id and a status of "active" or "draft" are required.', 'karmcp' ) );
		}

		return $this->decorate( $this->store()->set_status( $id, $status ) );
	}

	// -------------------------------------------------------------------------
	// delete-custom-block
	// -------------------------------------------------------------------------

	private function register_delete(): void {
		karmcp_register_ability(
			'karmcp/delete-custom-block',
			array(
				'label'               => __( 'Delete Custom Block', 'karmcp' ),
				'description'         => __( 'Permanently deletes a custom block, its spec, and its sandbox files. Posts already using the block lose it. Requires confirm:true.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'block_id' => array( 'type' => 'integer' ),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true. Deletion cannot be undone.', 'karmcp' ),
						),
					),
					'required'   => array( 'block_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'block_id' => array( 'type' => 'integer' ),
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
		$id = isset( $input['block_id'] ) ? absint( $input['block_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'block_id is required.', 'karmcp' ) );
		}
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error(
				'confirm_required',
				__( 'Deleting a custom block is permanent and removes it from any post using it. Pass confirm:true to proceed.', 'karmcp' )
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
			$spec['spec_version'] = KarMCP_Block_Spec::SPEC_VERSION;
		}

		return $spec;
	}

	/**
	 * Adds `success`, and says why when a block did not go live.
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
				__( 'The block is saved but inactive: it failed with "%s". Fix the spec and call update-custom-block.', 'karmcp' ),
				(string) $result['last_error']
			);
		}

		return $result;
	}
}
