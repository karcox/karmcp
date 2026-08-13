<?php
/**
 * AI-safe transaction MCP abilities: the change ledger + rollback.
 *
 * @package KarMCP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers list-changes / get-change / rollback-change.
 *
 * @since 3.3.0
 */
class KarMCP_Transaction_Abilities {

	/**
	 * Ability names.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/list-changes',
			'karmcp/get-change',
			'karmcp/rollback-change',
		);
	}

	/**
	 * Capability check (the ledger spans admin-grade fs/db targets).
	 *
	 * @return bool
	 */
	public function check_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register the three abilities.
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/list-changes',
			array(
				'label'               => __( 'List Changes', 'karmcp' ),
				'description'         => __( 'Lists recent AI-made changes (Elementor edits, filesystem writes, database writes) recorded in the change ledger, newest first, each with a summary, whether it is reversible, and whether it has already been rolled back. Filter by domain (elementor/filesystem/database), rolled_back, or reversible. Use get-change for full detail and rollback-change to undo one. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'domain'      => array( 'type' => 'string', 'enum' => array( 'elementor', 'filesystem', 'database' ), 'description' => __( 'Filter by domain.', 'karmcp' ) ),
						'rolled_back' => array( 'type' => 'boolean', 'description' => __( 'Only entries with this rolled-back state.', 'karmcp' ) ),
						'reversible'  => array( 'type' => 'boolean', 'description' => __( 'Only entries that are (or are not) reversible.', 'karmcp' ) ),
						'limit'       => array( 'type' => 'integer', 'description' => __( 'Max entries (default 50).', 'karmcp' ) ),
					),
				),
			)
		);

		karmcp_register_ability(
			'karmcp/get-change',
			array(
				'label'               => __( 'Get Change', 'karmcp' ),
				'description'         => __( 'Returns the full detail of one change-ledger entry by id, including its rollback reference (the before-image / backup pointer). Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'string', 'description' => __( 'Change id.', 'karmcp' ) ),
					),
					'required'   => array( 'id' ),
				),
			)
		);

		karmcp_register_ability(
			'karmcp/rollback-change',
			array(
				'label'               => __( 'Roll Back Change', 'karmcp' ),
				'description'         => __( 'Undoes one recorded change by id, restores a page\'s prior Elementor data, restores/removes a file from its backup, or inverses a database write from its before-image. Refuses with a "conflict" error if the target changed since the change was recorded (pass force:true to override and overwrite the newer state). Marks the entry rolled back (no double-rollback) and records a compensating entry. Only reverts changes KarMCP itself recorded.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_rollback' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'string', 'description' => __( 'Change id to roll back.', 'karmcp' ) ),
						'force' => array( 'type' => 'boolean', 'description' => __( 'Roll back even if the target changed since (overwrites the newer state). Default: false.', 'karmcp' ) ),
					),
					'required'   => array( 'id' ),
				),
			)
		);
	}

	/**
	 * list-changes.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_list( $input ) {
		$entries = array_reverse( KarMCP_Change_Log::all() ); // newest first.
		$domain  = isset( $input['domain'] ) ? (string) $input['domain'] : '';
		$limit   = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;

		$out = array();
		foreach ( $entries as $e ) {
			if ( '' !== $domain && ( $e['domain'] ?? '' ) !== $domain ) {
				continue;
			}
			$reversible = ! empty( $e['rollback'] ) && empty( $e['rolled_back'] );
			if ( isset( $input['rolled_back'] ) && (bool) $input['rolled_back'] !== ! empty( $e['rolled_back'] ) ) {
				continue;
			}
			if ( isset( $input['reversible'] ) && (bool) $input['reversible'] !== $reversible ) {
				continue;
			}
			$out[] = array(
				'id'          => $e['id'] ?? '',
				'ts'          => $e['ts'] ?? 0,
				'user_login'  => $e['user_login'] ?? '',
				'domain'      => $e['domain'] ?? '',
				'action'      => $e['action'] ?? '',
				'target'      => $e['target'] ?? '',
				'summary'     => $e['summary'] ?? '',
				'rolled_back' => ! empty( $e['rolled_back'] ),
				'reversible'  => $reversible,
				'rollback'    => self::light_rollback( $e['rollback'] ?? null ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array(
			'changes' => $out,
			'total'   => count( KarMCP_Change_Log::all() ),
		);
	}

	/**
	 * get-change.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_get( $input ) {
		$id    = isset( $input['id'] ) ? (string) $input['id'] : '';
		$entry = KarMCP_Change_Log::get( $id );
		if ( null === $entry ) {
			return new WP_Error( 'not_found', __( 'Change not found.', 'karmcp' ) );
		}
		return $entry;
	}

	/**
	 * rollback-change.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_rollback( $input ) {
		$id    = isset( $input['id'] ) ? (string) $input['id'] : '';
		$force = ! empty( $input['force'] );
		return KarMCP_Change_Log::rollback( $id, $force );
	}

	/**
	 * Strip heavy payloads (before_rows/before) from a rollback ref for list output.
	 *
	 * @param array|null $rollback Rollback ref.
	 * @return array|null
	 */
	private static function light_rollback( $rollback ) {
		if ( ! is_array( $rollback ) ) {
			return null;
		}
		unset( $rollback['before_rows'], $rollback['before'], $rollback['inserted_key'] );
		return $rollback;
	}
}
