<?php
/**
 * KarMCP Themer PHP-Template MCP abilities — 5 tools.
 *
 * AI authors + validates DRAFT PHP templates; there is intentionally no attach
 * tool — a human selects a template in the Themer metabox (the execution gate).
 * Writes need manage_options + unfiltered_html; reads need manage_options.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
class KarMCP_Themer_PHP_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	public function register(): void {
		$this->register_create();
		$this->register_list();
		$this->register_get();
		$this->register_update();
		$this->register_delete();
	}

	/** @param array|null $input @return bool */
	public function check_write_permission( $input = null ): bool {
		return KarMCP_Themer_PHP_Store::can_edit();
	}

	/** @param array|null $input @return bool */
	public function check_read_permission( $input = null ): bool {
		return KarMCP_Themer_PHP_Store::can_read();
	}

	/** Normalize a store result (array|WP_Error) to a tool payload. */
	private function payload( $result ): array {
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}
		return is_array( $result ) ? $result : array( 'error' => __( 'Unexpected result.', 'karmcp' ) );
	}

	// ---- create ------------------------------------------------------------

	private function register_create(): void {
		$this->ability_names[] = 'karmcp/create-theme-php-template';
		karmcp_register_ability(
			'karmcp/create-theme-php-template',
			array(
				'label'               => __( 'Create Theme PHP Template', 'karmcp' ),
				'description'         => __( 'Create a DRAFT PHP template for a Themer region (header|footer|single|archive|any). Emit markup with echo/heredoc, a closing PHP tag is not allowed. Validated; critical constructs (code execution, shell, file loading, network, file writes) are rejected. Never runs until a human selects it in a template metabox.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_create' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array( 'type' => 'string' ),
						'code'  => array( 'type' => 'string', 'description' => __( 'PHP body. echo markup; no closing tag; the WordPress loop + template tags are available.', 'karmcp' ) ),
						'type'  => array( 'type' => 'string', 'enum' => array( 'header', 'footer', 'single', 'archive', 'any' ) ),
					),
					'required'   => array( 'code', 'type' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/** @param array $input @return array */
	public function execute_create( $input ): array {
		return $this->payload( KarMCP_Themer_PHP_Store::create_draft( array(
			'title' => isset( $input['title'] ) ? (string) $input['title'] : '',
			'code'  => isset( $input['code'] ) ? (string) $input['code'] : '',
			'type'  => isset( $input['type'] ) ? (string) $input['type'] : 'any',
		) ) );
	}

	// ---- list --------------------------------------------------------------

	private function register_list(): void {
		$this->ability_names[] = 'karmcp/list-theme-php-templates';
		karmcp_register_ability(
			'karmcp/list-theme-php-templates',
			array(
				'label'               => __( 'List Theme PHP Templates', 'karmcp' ),
				'description'         => __( 'List draft PHP templates (id, title, type, compiled state, last error). Optional type filter.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'type' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'templates' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/** @param array $input @return array */
	public function execute_list( $input ): array {
		$type = isset( $input['type'] ) ? (string) $input['type'] : '';
		return array( 'templates' => KarMCP_Themer_PHP_Store::list_templates( $type ) );
	}

	// ---- get ---------------------------------------------------------------

	private function register_get(): void {
		$this->ability_names[] = 'karmcp/get-theme-php-template';
		karmcp_register_ability(
			'karmcp/get-theme-php-template',
			array(
				'label'               => __( 'Get Theme PHP Template', 'karmcp' ),
				'description'         => __( 'Return one PHP template: code, type, compiled state, and validation report.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'template_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'template_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/** @param array $input @return array */
	public function execute_get( $input ): array {
		return $this->payload( KarMCP_Themer_PHP_Store::get( absint( $input['template_id'] ?? 0 ) ) );
	}

	// ---- update ------------------------------------------------------------

	private function register_update(): void {
		$this->ability_names[] = 'karmcp/update-theme-php-template';
		karmcp_register_ability(
			'karmcp/update-theme-php-template',
			array(
				'label'               => __( 'Update Theme PHP Template', 'karmcp' ),
				'description'         => __( 'Update a PHP template title/code/type; re-validates. If compiled (attached), recompiles from the new code.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id' => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'code'        => array( 'type' => 'string' ),
						'type'        => array( 'type' => 'string', 'enum' => array( 'header', 'footer', 'single', 'archive', 'any' ) ),
					),
					'required'   => array( 'template_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/** @param array $input @return array */
	public function execute_update( $input ): array {
		$id   = absint( $input['template_id'] ?? 0 );
		$args = array();
		foreach ( array( 'title', 'code', 'type' ) as $k ) {
			if ( array_key_exists( $k, $input ) ) {
				$args[ $k ] = (string) $input[ $k ];
			}
		}
		return $this->payload( KarMCP_Themer_PHP_Store::update( $id, $args ) );
	}

	// ---- delete ------------------------------------------------------------

	private function register_delete(): void {
		$this->ability_names[] = 'karmcp/delete-theme-php-template';
		karmcp_register_ability(
			'karmcp/delete-theme-php-template',
			array(
				'label'               => __( 'Delete Theme PHP Template', 'karmcp' ),
				'description'         => __( 'Delete a PHP template and its sandbox file.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'template_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'template_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/** @param array $input @return array */
	public function execute_delete( $input ): array {
		return $this->payload( KarMCP_Themer_PHP_Store::delete( absint( $input['template_id'] ?? 0 ) ) );
	}
}
