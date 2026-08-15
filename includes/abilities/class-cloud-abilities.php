<?php
/**
 * KarMCP Cloud MCP abilities — status, backup, list, pull, config sync.
 *
 * Free tree. Registered only when the site is connected to KarMCP Cloud (the
 * Cloud module is active and a token bundle is stored). Each tool delegates to
 * KarMCP_Cloud_Sync and requires manage_options.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KarMCP_Cloud_Abilities {

	/**
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/cloud-status',
			'karmcp/cloud-backup',
			'karmcp/cloud-list',
			'karmcp/cloud-pull',
			'karmcp/cloud-config-sync',
		);
	}

	/**
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/cloud-status',
			array(
				'label'               => __( 'Cloud Status', 'karmcp' ),
				'description'         => __( 'Return the KarMCP Cloud plan, limits, and usage for the connected account.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array( 'description' => array( 'type' => 'string' ) ) ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
		karmcp_register_ability(
			'karmcp/cloud-backup',
			array(
				'label'               => __( 'Cloud Backup', 'karmcp' ),
				'description'         => __( 'Back up a local sandbox artifact (block, widget, or PHP snippet) to your KarMCP Cloud account.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_backup' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'kind' => array( 'type' => 'string', 'enum' => KarMCP_Sandbox_Bundle::KINDS ),
						'id'   => array( 'type' => 'integer' ),
					),
					'required'   => array( 'kind', 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
		karmcp_register_ability(
			'karmcp/cloud-list',
			array(
				'label'               => __( 'Cloud List', 'karmcp' ),
				'description'         => __( 'List the artifacts backed up to your KarMCP Cloud account (optionally filtered by kind).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array( 'kind' => array( 'type' => 'string' ) ) ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
		karmcp_register_ability(
			'karmcp/cloud-pull',
			array(
				'label'               => __( 'Cloud Pull', 'karmcp' ),
				'description'         => __( 'Pull a cloud artifact into this site by its UUID. It is imported as a new inactive draft.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_pull' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'artifact_uuid' => array( 'type' => 'string' ),
						'kind'          => array( 'type' => 'string' ),
					),
					'required'   => array( 'artifact_uuid' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
		karmcp_register_ability(
			'karmcp/cloud-config-sync',
			array(
				'label'               => __( 'Cloud Config Sync', 'karmcp' ),
				'description'         => __( 'Push or pull a config blob (settings, brand_kit, tool_toggles) to/from KarMCP Cloud.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_config_sync' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'type'      => array( 'type' => 'string', 'enum' => array( 'settings', 'brand_kit', 'tool_toggles' ) ),
						'direction' => array( 'type' => 'string', 'enum' => array( 'push', 'pull' ) ),
						'data'      => array( 'type' => 'object' ),
					),
					'required'   => array( 'type', 'direction' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
	}



	/**
	 * @return array|WP_Error
	 */
	public function execute_status() {
		$r = KarMCP_Cloud_Sync::status();
		return is_wp_error( $r ) ? $r : (array) $r;
	}

	/**
	 * @param array $input { kind, id }.
	 * @return array|WP_Error
	 */
	public function execute_backup( $input ) {
		$kind = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : '';
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$r    = KarMCP_Cloud_Sync::backup( $kind, $id );
		return is_wp_error( $r ) ? $r : (array) $r;
	}

	/**
	 * @param array $input { kind? }.
	 * @return array|WP_Error
	 */
	public function execute_list( $input ) {
		$kind = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : '';
		$r    = KarMCP_Cloud_Sync::list_remote( $kind );
		return is_wp_error( $r ) ? $r : (array) $r;
	}

	/**
	 * @param array $input { artifact_uuid, kind? }.
	 * @return array|WP_Error
	 */
	public function execute_pull( $input ) {
		$uuid = isset( $input['artifact_uuid'] ) ? sanitize_text_field( (string) $input['artifact_uuid'] ) : '';
		$kind = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : '';
		$r    = KarMCP_Cloud_Sync::pull( $uuid, $kind );
		return is_wp_error( $r ) ? $r : (array) $r;
	}

	/**
	 * @param array $input { type, direction, data? }.
	 * @return array|WP_Error
	 */
	public function execute_config_sync( $input ) {
		$type      = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		$direction = isset( $input['direction'] ) ? sanitize_key( (string) $input['direction'] ) : 'pull';
		if ( 'push' === $direction ) {
			$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
			$r    = KarMCP_Cloud_Sync::push_config( $type, $data );
		} else {
			$r = KarMCP_Cloud_Sync::pull_config( $type );
		}
		return is_wp_error( $r ) ? $r : (array) $r;
	}
}
