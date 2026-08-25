<?php
/**
 * Content mirror MCP abilities: export/restore/list the git-trackable content mirror.
 *
 * @package KarMCP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers export-content / restore-content / list-content-exports.
 *
 * @since 3.3.0
 */
class KarMCP_Content_Mirror_Abilities {

	/**
	 * Ability names.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/export-content',
			'karmcp/restore-content',
			'karmcp/list-content-exports',
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Register the abilities.
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/export-content',
			array(
				'label'               => __( 'Export Content', 'karmcp' ),
				'description'         => __( 'Exports Elementor page/template content to git-trackable JSON files under uploads/karmcp-content-mirror/, so an external version-control system can diff and version your designs. Pass post_id to export one page/template, or omit it to export all. (Enable auto-export-on-save in KarMCP → Tools to keep the mirror current automatically.)', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_export' ),
				'permission_callback' => array( $this, 'check_permission' ),
				// NOT read-only, despite reading like a query: this writes JSON
				// files under uploads/karmcp-content-mirror/. The name is the
				// trap — "export" sits next to "list" in this file and both
				// sound like questions, but only one of them is.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Export just this page/template. Omit to export all.', 'karmcp' ) ),
					),
				),
			)
		);

		karmcp_register_ability(
			'karmcp/restore-content',
			array(
				'label'               => __( 'Restore Content', 'karmcp' ),
				'description'         => __( 'Restores a page/template\'s Elementor content from its mirror file (the JSON previously written by export-content), a file-based undo. Overwrites the current content with the mirrored version.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_restore' ),
				'permission_callback' => array( $this, 'check_permission' ),
				// Overwrites a page's current Elementor content with the mirrored
				// version, so it is a write. Not flagged destructive, matching the
				// admin catalog and the same reading as rollback-change: it
				// restores from an artifact this plugin wrote rather than
				// discarding data outright.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Page/template to restore from its mirror file.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
			)
		);

		karmcp_register_ability(
			'karmcp/list-content-exports',
			array(
				'label'               => __( 'List Content Exports', 'karmcp' ),
				'description'         => __( 'Lists the mirror files currently on disk (id, type, title, exported time) so you can see what is versioned and pick something to restore. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
				// Read-only for real: glob() plus file_get_contents(), and
				// KarMCP_Content_Mirror::dir() only builds the path — it does not
				// create the directory, which is the kind of helper that turns a
				// listing into a write without anyone noticing.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			)
		);
	}

	/**
	 * export-content.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_export( $input ) {
		if ( isset( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
			$path = KarMCP_Content_Mirror::export_post( (int) $input['post_id'] );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			return array( 'exported' => $path );
		}
		return array( 'exported_all' => KarMCP_Content_Mirror::export_all() );
	}

	/**
	 * restore-content.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_restore( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', __( 'A valid post_id is required.', 'karmcp' ) );
		}
		$res = KarMCP_Content_Mirror::restore_post( $post_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'restored' => $post_id );
	}

	/**
	 * list-content-exports.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_list( $input ) {
		$dir     = KarMCP_Content_Mirror::dir();
		$exports = array();
		foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$exports[] = array(
				'file'        => basename( $file ),
				'id'          => (int) ( $decoded['id'] ?? 0 ),
				'type'        => (string) ( $decoded['type'] ?? '' ),
				'title'       => (string) ( $decoded['title'] ?? '' ),
				'exported_at' => (int) ( $decoded['exported_at'] ?? 0 ),
			);
		}
		return array(
			'exports' => $exports,
			'count'   => count( $exports ),
			'dir'     => $dir,
		);
	}
}
