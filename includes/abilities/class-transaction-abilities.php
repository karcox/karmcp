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
	 *
	 * All three carry MCP annotations, and the two readers need theirs for a
	 * reason that is not obvious: `KarMCP_Schema_Compat::wrap_execute_callback()`
	 * derives `$readonly` from `meta.annotations.readonly` and runs the
	 * `karmcp_before_write` veto whenever it is falsy. A missing annotation is
	 * falsy, so this file registered two pure reads that Guardrails treated as
	 * writes: read-only mode and the freeze window both refused `list-changes`
	 * and `get-change`, and the compact dispatcher left them out of its
	 * read-only pass-through. The failure ran in the safe direction, which is
	 * why it survived — nothing broke, a ledger the operator was entitled to
	 * read simply came back refused.
	 *
	 * `KarMCP_Change_Log::all()` and `::get()` are `get_option()` and a loop
	 * over it. That is worth stating, because CLAUDE.md records the opposite
	 * case in `paused()`, where a reader wrote and the annotation became a lie.
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/list-changes',
			array(
				'label'               => __( 'List Changes', 'karmcp' ),
				'description'         => __( 'Lists recent AI-made changes recorded in the change ledger — Elementor edits and page settings, created and edited posts, uploaded and deleted media, global colours and fonts, site settings, users, ACF fields, redirects, file and database writes — newest first, each with a summary, whether it is reversible, and whether it has already been rolled back. Filter by domain, rolled_back, or reversible. Use get-change for full detail and rollback-change to undo one. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'domain'      => array( 'type' => 'string', 'enum' => array( 'elementor', 'content', 'gutenberg', 'media', 'globals', 'settings', 'users', 'acf', 'seo', 'redirect', 'filesystem', 'database', 'wpcli' ), 'description' => __( 'Filter by domain.', 'karmcp' ) ),
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
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
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
				'description'         => __( 'Undoes one recorded change by id: restores prior Elementor data, page settings, post fields, options or meta; deletes a post, page or attachment that was created (with its files); restores a deleted one under its original id; restores or removes a file from its backup; or inverses a database write from its before-image. Options, meta and post meta are read back after the restore, and a value that did not take is reported as a failure instead of being marked undone; a deletion is checked to have happened, and a restore to have landed under its original id. Elementor data, ACF fields, user profile fields, files and database rows are trusted once the write itself reports no error. Refuses with a "conflict" error if the target changed since the change was recorded (pass force:true to override and overwrite the newer state) — for a creation that means the post was edited after it was made, and undoing it would delete that work; a creation recorded before 1.42.0 has no such check and needs force too. Refuses even with force to restore under an id another item now uses, or an attachment whose saved file copy is gone. Marks the entry rolled back (no double-rollback) and records a compensating entry. Only reverts changes KarMCP itself recorded.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_rollback' ),
				'permission_callback' => array( $this, 'check_manage' ),
				// Not annotated destructive, which is a deliberate reading rather
				// than an omission: Guardrails is the only consumer of that flag,
				// and its "block destructive tools" mode would then block the one
				// tool that undoes damage. This reverts only changes KarMCP itself
				// recorded, refuses on conflict unless forced, and writes a
				// compensating entry rather than erasing the original. The admin
				// catalog says the same by giving it no badge.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
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
