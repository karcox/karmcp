<?php
/**
 * PHP Snippet MCP abilities.
 *
 * Lets an AI agent author, validate, read, and manage PHP snippets — but NEVER
 * run them. There is intentionally no "activate" tool: a snippet created via MCP
 * is an inactive draft until a human administrator reviews it and activates it
 * in the Sandbox admin screen. Create/update run the security validator and
 * refuse anything with a critical finding (returning the findings so the agent
 * can fix the code).
 *
 * Free feature, capability-gated: write tools require manage_options +
 * unfiltered_html (the caps that already permit editing plugin code); read tools
 * require manage_options.
 *
 * @package KarMCP
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the PHP snippet abilities.
 *
 * @since 2.1.0
 */
class KarMCP_PHP_Snippet_Abilities {

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/validate-php-snippet',
			'karmcp/create-php-snippet',
			'karmcp/update-php-snippet',
			'karmcp/get-php-snippet',
			'karmcp/list-php-snippets',
			'karmcp/delete-php-snippet',
		);
	}

	/**
	 * Registers all PHP snippet abilities.
	 *
	 * @since 2.1.0
	 */
	public function register(): void {
		$this->register_validate();
		$this->register_create();
		$this->register_update();
		$this->register_get();
		$this->register_list();
		$this->register_delete();
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function check_read_permission(): bool {
		return KarMCP_PHP_Snippet_Store::can_read();
	}

	public function check_edit_permission(): bool {
		return KarMCP_PHP_Snippet_Store::can_edit();
	}

	/**
	 * JSON Schema fragment for a validation report (shared by several tools).
	 *
	 * @return array
	 */
	private function validation_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'valid'       => array( 'type' => 'boolean' ),
				'safe'        => array( 'type' => 'boolean' ),
				'parse_error' => array( 'type' => 'string' ),
				'findings'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'severity' => array( 'type' => 'string' ),
							'rule'     => array( 'type' => 'string' ),
							'message'  => array( 'type' => 'string' ),
							'line'     => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Common snippet input properties (title/code/context/hook/priority).
	 *
	 * @return array
	 */
	private function snippet_input_props(): array {
		return array(
			'title'    => array(
				'type'        => 'string',
				'description' => __( 'A label for the snippet.', 'karmcp' ),
			),
			'code'     => array(
				'type'        => 'string',
				'description' => __( 'The PHP code (no <?php tags needed). Runs inside an isolated function. Use return or echo for shortcode output.', 'karmcp' ),
			),
			'context'  => array(
				'type'        => 'string',
				'enum'        => array( 'shortcode', 'hook', 'both' ),
				'description' => __( 'How the snippet runs: "shortcode" via [karmcp_snippet id="N"], "hook" on a WordPress action, or "both". Default: shortcode.', 'karmcp' ),
			),
			'hook'     => array(
				'type'        => 'string',
				'description' => __( 'WordPress action to attach to when context is hook/both (e.g. wp_footer, init).', 'karmcp' ),
			),
			'priority' => array(
				'type'        => 'integer',
				'description' => __( 'Hook priority (default 10).', 'karmcp' ),
			),
		);
	}

	/**
	 * Output schema for a snippet record.
	 *
	 * @return array
	 */
	private function snippet_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'snippet_id' => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'context'    => array( 'type' => 'string' ),
				'hook'       => array( 'type' => 'string' ),
				'priority'   => array( 'type' => 'integer' ),
				'shortcode'  => array( 'type' => 'string' ),
				'code'       => array( 'type' => 'string' ),
				'last_error' => array( 'type' => 'string' ),
				'validation' => $this->validation_schema(),
			),
		);
	}

	// -------------------------------------------------------------------------
	// validate-php-snippet
	// -------------------------------------------------------------------------

	private function register_validate(): void {
		karmcp_register_ability(
			'karmcp/validate-php-snippet',
			array(
				'label'               => __( 'Validate PHP Snippet', 'karmcp' ),
				'description'         => __( 'Statically checks PHP snippet code WITHOUT storing or running it: confirms it parses, then scans for dangerous constructs (code execution, shell, file writes, network, obfuscation, destructive SQL). Returns a report of critical (blocking) and warning findings. Use this to iterate before create-php-snippet.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_validate' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => __( 'The PHP code to validate.', 'karmcp' ),
						),
					),
					'required'   => array( 'code' ),
				),
				'output_schema'       => $this->validation_schema(),
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
	public function execute_validate( $input ) {
		$code = isset( $input['code'] ) ? (string) $input['code'] : '';
		return KarMCP_PHP_Snippet_Validator::validate( $code );
	}

	// -------------------------------------------------------------------------
	// create-php-snippet
	// -------------------------------------------------------------------------

	private function register_create(): void {
		karmcp_register_ability(
			'karmcp/create-php-snippet',
			array(
				'label'               => __( 'Create PHP Snippet (draft)', 'karmcp' ),
				'description'         => __( 'Creates a PHP snippet as an INACTIVE DRAFT. It does NOT run: a site administrator must review and activate it in KarMCP → Sandbox before it executes. The code is validated first and rejected if it trips a critical security finding (the findings are returned so you can fix it).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_create' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $this->snippet_input_props(),
					'required'   => array( 'code' ),
				),
				'output_schema'       => $this->snippet_output_schema(),
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
		$result = KarMCP_PHP_Snippet_Store::create_draft( is_array( $input ) ? $input : array() );
		return $this->normalize_write_result( $result, 'created' );
	}

	// -------------------------------------------------------------------------
	// update-php-snippet
	// -------------------------------------------------------------------------

	private function register_update(): void {
		karmcp_register_ability(
			'karmcp/update-php-snippet',
			array(
				'label'               => __( 'Update PHP Snippet', 'karmcp' ),
				'description'         => __( 'Updates a snippet\'s code or settings. Re-validates and rejects critical findings. If the snippet is currently active it is re-compiled (or demoted to draft if it no longer passes). Activation still requires an admin.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array( 'snippet_id' => array( 'type' => 'integer', 'description' => __( 'The snippet ID.', 'karmcp' ) ) ),
						$this->snippet_input_props()
					),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => $this->snippet_output_schema(),
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
		$id = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'snippet_id is required.', 'karmcp' ) );
		}
		$result = KarMCP_PHP_Snippet_Store::update( $id, is_array( $input ) ? $input : array() );
		return $this->normalize_write_result( $result, 'updated' );
	}

	// -------------------------------------------------------------------------
	// get-php-snippet
	// -------------------------------------------------------------------------

	private function register_get(): void {
		karmcp_register_ability(
			'karmcp/get-php-snippet',
			array(
				'label'               => __( 'Get PHP Snippet', 'karmcp' ),
				'description'         => __( 'Returns a snippet: its code, status (draft/active), run context, shortcode, and the latest validation report.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'snippet_id' => array( 'type' => 'integer', 'description' => __( 'The snippet ID.', 'karmcp' ) ) ),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => $this->snippet_output_schema(),
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
		$id = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'snippet_id is required.', 'karmcp' ) );
		}
		return KarMCP_PHP_Snippet_Store::get( $id );
	}

	// -------------------------------------------------------------------------
	// list-php-snippets
	// -------------------------------------------------------------------------

	private function register_list(): void {
		karmcp_register_ability(
			'karmcp/list-php-snippets',
			array(
				'label'               => __( 'List PHP Snippets', 'karmcp' ),
				'description'         => __( 'Lists PHP snippets with their status (draft/active), run context, and shortcode.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'draft', 'any' ),
							'description' => __( 'Filter by status. Default: any.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'    => array( 'type' => 'integer' ),
						'snippets' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
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
	 * @return array|\WP_Error
	 */
	public function execute_list( $input ) {
		$status   = isset( $input['status'] ) && in_array( $input['status'], array( 'active', 'draft', 'any' ), true ) ? (string) $input['status'] : 'any';
		$snippets = KarMCP_PHP_Snippet_Store::list_snippets( $status );
		return array(
			'count'    => count( $snippets ),
			'snippets' => $snippets,
		);
	}

	// -------------------------------------------------------------------------
	// delete-php-snippet
	// -------------------------------------------------------------------------

	private function register_delete(): void {
		karmcp_register_ability(
			'karmcp/delete-php-snippet',
			array(
				'label'               => __( 'Delete PHP Snippet', 'karmcp' ),
				'description'         => __( 'Permanently deletes a PHP snippet and removes its sandbox file.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'snippet_id' => array( 'type' => 'integer', 'description' => __( 'The snippet ID.', 'karmcp' ) ) ),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet_id' => array( 'type' => 'integer' ),
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
		$id = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'missing_id', __( 'snippet_id is required.', 'karmcp' ) );
		}
		return KarMCP_PHP_Snippet_Store::delete( $id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Turns a validation-rejection WP_Error into a structured response carrying
	 * the findings (so the agent can fix the code), and adds a reminder that the
	 * draft still needs admin activation. Other errors pass through.
	 *
	 * @param array|\WP_Error $result Store result.
	 * @param string          $verb   'created' | 'updated'.
	 * @return array|\WP_Error
	 */
	private function normalize_write_result( $result, string $verb ) {
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'invalid_php' === $code || 'unsafe_php' === $code ) {
				$data = $result->get_error_data();
				return array(
					'success'    => false,
					'reason'     => $result->get_error_message(),
					'validation' => is_array( $data ) && isset( $data['validation'] ) ? $data['validation'] : array(),
				);
			}
			return $result;
		}

		$result['success'] = true;
		$result['note']    = __( 'Saved as an INACTIVE draft. A site administrator must activate it in KarMCP → Sandbox before it runs.', 'karmcp' );
		unset( $verb );
		return $result;
	}
}
