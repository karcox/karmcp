<?php
/**
 * Compact tool mode — the meta-tool dispatcher.
 *
 * When dispatcher mode is on (KarMCP_Plugin::is_dispatcher_mode()), the MCP
 * server surfaces only these three tools instead of every individual ability:
 *   - list-tools       : compact catalog of the enabled tools
 *   - get-tool-schema  : full input schema for named tools
 *   - call-tool        : run a tool by name, enforcing the TARGET's own gate
 * They route to the existing abilities via wp_get_ability(); call-tool never
 * bypasses a target's permission_callback.
 *
 * @package KarMCP
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KarMCP_Dispatcher_Abilities {

	const NAMES = array(
		'karmcp/list-tools',
		'karmcp/get-tool-schema',
		'karmcp/call-tool',
	);

	/**
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return self::NAMES;
	}

	/**
	 * The enabled ability-name set (after the karmcp_ability_names filter).
	 * Seam for testing.
	 *
	 * @return string[]
	 */
	protected function active_names(): array {
		$names = array();
		if ( class_exists( 'KarMCP_Plugin' ) && method_exists( 'KarMCP_Plugin', 'instance' ) ) {
			$names = KarMCP_Plugin::instance()->get_active_ability_names();
		}
		// Fold in the same WordPress core context abilities the server surfaces
		// directly in full mode, so they stay listable + callable via call-tool
		// in compact mode (where the top-level surface is just the 3 meta-tools).
		if ( function_exists( 'wp_get_ability' ) ) {
			foreach ( array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' ) as $core ) {
				if ( wp_get_ability( $core ) && ! in_array( $core, $names, true ) ) {
					$names[] = $core;
				}
			}
		}
		return $names;
	}

	/**
	 * Resolve a tool name to a WP_Ability (or null). Seam for testing.
	 *
	 * @param string $name
	 * @return object|null
	 */
	protected function resolve_ability( string $name ) {
		return function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
	}

	/**
	 * @param object $ability
	 * @return bool
	 */
	private function is_destructive( $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
		return ! empty( $meta['annotations']['destructive'] );
	}

	/**
	 * list-tools: compact catalog of enabled tools (+ discovery context).
	 *
	 * @param array $input { search?: string, category?: string }
	 * @return array
	 */
	public function execute_list_tools( $input ) {
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$tools    = array();

		foreach ( $this->active_names() as $name ) {
			$ability = $this->resolve_ability( $name );
			if ( ! $ability ) {
				continue;
			}
			$desc = (string) $ability->get_description();
			$cat  = (string) $ability->get_category();
			if ( '' !== $category && $cat !== $category ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $name . ' ' . $desc, $search ) ) {
				continue;
			}
			$tools[] = array(
				'name'        => $name,
				'description' => $desc,
				'category'    => $cat,
				'destructive' => $this->is_destructive( $ability ),
			);
		}

		return array(
			'tools'   => $tools,
			'context' => class_exists( 'KarMCP_Site_Context' ) ? KarMCP_Site_Context::environment_summary() : '',
		);
	}

	/**
	 * get-tool-schema: input schema for named tools (batch).
	 *
	 * @param array $input { names: string[] }
	 * @return array
	 */
	public function execute_get_tool_schema( $input ) {
		$names       = array_values( (array) ( $input['names'] ?? array() ) );
		$enabled     = array_flip( $this->active_names() );
		$schemas     = array();
		$unavailable = array();

		foreach ( $names as $name ) {
			$name    = (string) $name;
			$ability = isset( $enabled[ $name ] ) ? $this->resolve_ability( $name ) : null;
			if ( ! $ability ) {
				$unavailable[] = $name;
				continue;
			}
			$schemas[ $name ] = array(
				'description' => (string) $ability->get_description(),
				'inputSchema' => (array) $ability->get_input_schema(),
			);
		}

		return array( 'schemas' => $schemas, 'unavailable' => $unavailable );
	}

	/**
	 * call-tool: run a tool by name. Enforces the TARGET ability's own
	 * check_permissions() — the real security gate.
	 *
	 * @param array $input { name: string, arguments?: object }
	 * @return mixed|\WP_Error
	 */
	public function execute_call_tool( $input ) {
		$name = (string) ( $input['name'] ?? '' );
		$args = (array) ( $input['arguments'] ?? array() );

		if ( '' === $name || ! in_array( $name, $this->active_names(), true ) ) {
			return new \WP_Error( 'tool_unavailable', __( 'Unknown or disabled tool.', 'karmcp' ) );
		}
		$ability = $this->resolve_ability( $name );
		if ( ! $ability ) {
			return new \WP_Error( 'tool_unavailable', __( 'Tool is not registered.', 'karmcp' ) );
		}

		$perm = $ability->check_permissions( $args );
		if ( is_wp_error( $perm ) || ! $perm ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to run this tool.', 'karmcp' ) );
		}

		if ( method_exists( $ability, 'validate_input' ) ) {
			$valid = $ability->validate_input( $args );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		return $ability->execute( $args );
	}

	/**
	 * Register the three dispatcher abilities. Always registered (so the registry
	 * resolves them); only surfaced on the server when dispatcher mode is on.
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/list-tools',
			array(
				'label'               => __( 'List Tools', 'karmcp' ),
				'description'         => __( 'Compact catalog of the available KarMCP tools (name, description, category). Filter with search or category. Step 1 of compact tool mode: list-tools -> get-tool-schema -> call-tool. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_tools' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => __( 'Filter by substring of the tool name or description.', 'karmcp' ) ),
						'category' => array( 'type' => 'string', 'description' => __( 'Filter by tool category.', 'karmcp' ) ),
					),
				),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);

		karmcp_register_ability(
			'karmcp/get-tool-schema',
			array(
				'label'               => __( 'Get Tool Schema', 'karmcp' ),
				'description'         => __( 'Return the full input schema for one or more tools by name. Step 2 of compact tool mode. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_tool_schema' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'names' ),
					'properties' => array(
						'names' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Tool names to fetch schemas for (e.g. ["karmcp/list-widgets"]).', 'karmcp' ),
						),
					),
				),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);

		karmcp_register_ability(
			'karmcp/call-tool',
			array(
				'label'               => __( 'Call Tool', 'karmcp' ),
				'description'         => __( 'Run a tool by name with its arguments. Step 3 of compact tool mode. Enforces the target tool\'s own permissions; check the destructive flag from list-tools before calling.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_call_tool' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'      => array( 'type' => 'string', 'description' => __( 'The tool name to run (from list-tools).', 'karmcp' ) ),
						'arguments' => array( 'type' => 'object', 'description' => __( 'The arguments object for the tool (shape from get-tool-schema).', 'karmcp' ) ),
					),
				),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Permission for the dispatcher tools themselves — metadata/routing only; the
	 * real gate is the target tool's own permission at call time.
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}
}
