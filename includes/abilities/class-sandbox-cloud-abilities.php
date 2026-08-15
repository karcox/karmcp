<?php
/**
 * Sandbox Cloud abilities — export/import any sandbox artifact (block/widget/
 * snippet) as a portable bundle over the cloud contract.
 *
 * This is the single MCP surface a future KarMCP Cloud client reuses: it does
 * not know or care which concrete store backs a given artifact kind, only
 * that the resolved store implements KarMCP_Sandbox_Artifact. Two tools:
 *
 *   - `export-sandbox-artifact` — { kind, id } -> { bundle }
 *   - `import-sandbox-artifact` — { bundle }   -> { id }
 *
 * Free tree. Unlike the Widget Builder / Widget Generator abilities, this
 * class does NOT self-gate on Pro — the tools always register. The `block`
 * kind is Pro-only via the resolver: `KarMCP_Block_Store` is absent on
 * free sites, so `resolve_artifact('block')` returns null there and a block
 * export/import cleanly fails with a `pro_required` WP_Error instead of a
 * fatal. Each resolved artifact's own store still enforces its own
 * permission gate inside apply_bundle()/to_bundle() (e.g. the block store
 * checks Pro + manage_options) — this class only adds the manage_options
 * floor common to both tools.
 *
 * @package KarMCP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the sandbox cloud export/import abilities.
 *
 * @since 3.7.0
 */
class KarMCP_Sandbox_Cloud_Abilities {

	/**
	 * Registered ability names.
	 *
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Ability names (always registered — free tree, no Pro gate).
	 *
	 * @since 3.7.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/export-sandbox-artifact',
			'karmcp/import-sandbox-artifact',
		);
	}

	/**
	 * Registers both abilities.
	 *
	 * @since 3.7.0
	 */
	public function register(): void {
		$this->register_export_sandbox_artifact();
		$this->register_import_sandbox_artifact();
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Both tools require manage_options. The imported/exported artifact's own
	 * store still enforces its own gate inside apply_bundle()/to_bundle().
	 *
	 * @since 3.7.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Artifact resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolves an artifact kind to its backing KarMCP_Sandbox_Artifact
	 * store/adapter.
	 *
	 * @since 3.7.0
	 *
	 * @param string $kind One of 'block', 'widget', 'snippet'.
	 * @return KarMCP_Sandbox_Artifact|null Null when the kind is unknown,
	 *                                          or when its backing class is
	 *                                          unavailable (block store is
	 *                                          Pro-only).
	 */
	public function resolve_artifact( string $kind ): ?KarMCP_Sandbox_Artifact {
		switch ( $kind ) {
			case 'block':
				return class_exists( 'KarMCP_Block_Store' ) ? KarMCP_Block_Store::instance() : null;
			case 'widget':
				return new KarMCP_Widget_Bundle_Adapter();
			case 'snippet':
				return new KarMCP_Snippet_Bundle_Adapter();
			case 'extension':
				return class_exists( 'KarMCP_Extension_Store' ) ? KarMCP_Extension_Store::instance() : null;
			default:
				return null;
		}
	}

	// -------------------------------------------------------------------------
	// export-sandbox-artifact
	// -------------------------------------------------------------------------

	/**
	 * Registers `karmcp/export-sandbox-artifact`.
	 *
	 * @since 3.7.0
	 */
	private function register_export_sandbox_artifact(): void {
		$this->ability_names[] = 'karmcp/export-sandbox-artifact';
		karmcp_register_ability(
			'karmcp/export-sandbox-artifact',
			array(
				'label'               => __( 'Export Sandbox Artifact', 'karmcp' ),
				'description'         => __( 'Exports a sandbox artifact (custom block, custom widget, or PHP snippet) as a portable, checksum-verified bundle suitable for sharing or cloud sync.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_export' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'kind' => array(
							'type'        => 'string',
							'enum'        => array( 'block', 'widget', 'snippet' ),
							'description' => __( 'The kind of sandbox artifact to export.', 'karmcp' ),
						),
						'id'   => array(
							'type'        => 'integer',
							'description' => __( 'The artifact\'s local post ID.', 'karmcp' ),
						),
					),
					'required'   => array( 'kind', 'id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'bundle' => array( 'type' => 'object' ),
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
	 * Executes `export-sandbox-artifact`.
	 *
	 * @since 3.7.0
	 *
	 * @param array $input { kind, id }.
	 * @return array|WP_Error
	 */
	public function execute_export( $input ) {
		$kind     = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : '';
		$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$artifact = $this->resolve_artifact( $kind );

		if ( null === $artifact ) {
			return $this->unresolved_kind_error( $kind );
		}

		$bundle = $artifact->to_bundle( $id );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		return array( 'bundle' => $bundle );
	}

	// -------------------------------------------------------------------------
	// import-sandbox-artifact
	// -------------------------------------------------------------------------

	/**
	 * Registers `karmcp/import-sandbox-artifact`.
	 *
	 * @since 3.7.0
	 */
	private function register_import_sandbox_artifact(): void {
		$this->ability_names[] = 'karmcp/import-sandbox-artifact';
		karmcp_register_ability(
			'karmcp/import-sandbox-artifact',
			array(
				'label'               => __( 'Import Sandbox Artifact', 'karmcp' ),
				'description'         => __( 'Imports a portable sandbox-artifact bundle (custom block, custom widget, or PHP snippet) as a new local draft. The bundle is validated (schema version, checksum) before anything is written.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_import' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'bundle' => array(
							'type'        => 'object',
							'description' => __( 'A bundle produced by export-sandbox-artifact.', 'karmcp' ),
						),
					),
					'required'   => array( 'bundle' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes `import-sandbox-artifact`.
	 *
	 * @since 3.7.0
	 *
	 * @param array $input { bundle }.
	 * @return array|WP_Error
	 */
	public function execute_import( $input ) {
		$bundle = isset( $input['bundle'] ) && is_array( $input['bundle'] ) ? $input['bundle'] : array();

		$valid = KarMCP_Sandbox_Bundle::validate( $bundle );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$kind     = (string) $bundle['kind'];
		$artifact = $this->resolve_artifact( $kind );

		if ( null === $artifact ) {
			return $this->unresolved_kind_error( $kind );
		}

		$new_id = $artifact->apply_bundle( $bundle );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		return array( 'id' => (int) $new_id );
	}

	// -------------------------------------------------------------------------
	// Shared
	// -------------------------------------------------------------------------

	/**
	 * Builds the WP_Error returned when resolve_artifact() comes back null —
	 * either an unsupported kind, or a Pro-only kind (block) on a site
	 * without the Pro overlay.
	 *
	 * @since 3.7.0
	 *
	 * @param string $kind The requested kind.
	 * @return WP_Error
	 */
	private function unresolved_kind_error( string $kind ): WP_Error {
		if ( in_array( $kind, KarMCP_Sandbox_Bundle::KINDS, true ) ) {
			return new WP_Error(
				'pro_required',
				sprintf(
					/* translators: %s: artifact kind (e.g. "block") */
					__( 'The "%s" artifact kind requires KarMCP Pro.', 'karmcp' ),
					$kind
				)
			);
		}

		return new WP_Error(
			'unsupported_kind',
			sprintf(
				/* translators: %s: artifact kind */
				__( 'Unsupported sandbox artifact kind "%s".', 'karmcp' ),
				$kind
			)
		);
	}
}
