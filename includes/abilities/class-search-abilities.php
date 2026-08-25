<?php
/**
 * Content search MCP abilities: search-content + reindex-search.
 *
 * @package KarMCP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the content-search tools.
 *
 * @since 3.3.0
 */
class KarMCP_Search_Abilities {

	/**
	 * Ability names.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/search-content', 'karmcp/reindex-search' );
	}

	/**
	 * Read permission.
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Register the abilities.
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/search-content',
			array(
				'label'               => __( 'Search Content', 'karmcp' ),
				'description'         => __( 'Searches an indexed corpus of the site\'s own pages, saved templates, widgets, and global styles by natural-language query, returning the best matches ranked by relevance, so you can REUSE an existing page/template/widget instead of building from scratch. Returns object_type + object_id + title + score + snippet; then read/clone the winner with the relevant tool (get-page-structure, apply-template, add-*-widget, etc.). Filter by types. Needs the index to exist: if it has never been built this returns an "index_not_built" error — run reindex-search once and search again. Call reindex-search too if results look stale. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_search' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				// Read-only, and execute_search() had to stop calling
				// maybe_install() before that could be true — see the note there.
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
						'query' => array( 'type' => 'string', 'description' => __( 'Natural-language query, e.g. "pricing table" or "team testimonials".', 'karmcp' ) ),
						'types' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string', 'enum' => array( 'page', 'template', 'widget', 'global_color', 'global_font', 'global_class' ) ),
							'description' => __( 'Restrict to these object types. Default: all.', 'karmcp' ),
						),
						'limit' => array( 'type' => 'integer', 'description' => __( 'Max results (default 20).', 'karmcp' ) ),
					),
					'required'   => array( 'query' ),
				),
			)
		);

		karmcp_register_ability(
			'karmcp/reindex-search',
			array(
				'label'               => __( 'Reindex Search', 'karmcp' ),
				'description'         => __( 'Rebuilds the content-search index from the current site (pages, templates, widgets, global styles). The index also updates incrementally when a page/template is saved, so this is only needed for a full refresh or a first-time build. Returns the number of items indexed per group. Optionally restrict to certain types.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_reindex' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				// Writes: it installs the table if needed and rebuilds its rows.
				// This is the tool that owns the DDL, which is why the search
				// path can refuse instead of installing.
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
						'types' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string', 'enum' => array( 'page', 'template', 'widget', 'global_color', 'global_font', 'global_class' ) ),
							'description' => __( 'Restrict the rebuild to these types. Default: all.', 'karmcp' ),
						),
					),
				),
			)
		);
	}

	/**
	 * search-content.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_search( $input ) {
		$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'query_required', __( 'A query is required.', 'karmcp' ) );
		}
		// Deliberately NOT maybe_install(): that runs dbDelta the first time it
		// is called, which made this tool a reader that writes — the exact thing
		// CLAUDE.md refuses in `paused()`, and the reason its readonly
		// annotation could not honestly be set. reindex-search owns the install.
		//
		// Refusing beats returning an empty list. The table would be empty on a
		// first call anyway, so the old behaviour answered "no matches" to a
		// question it had not been able to ask — indistinguishable from a real
		// no-match, and the agent has no way to tell it needs to build an index.
		// The save_post hook takes the same reading of "not installed" and skips.
		if ( KarMCP_Schema_State::installed( KarMCP_Schema_State::KEY_SEARCH ) < KarMCP_Search_Index::DB_VERSION ) {
			return new WP_Error(
				'index_not_built',
				__( 'The content search index has not been built yet. Run reindex-search once, then search again.', 'karmcp' )
			);
		}

		$types   = ( isset( $input['types'] ) && is_array( $input['types'] ) ) ? array_map( 'strval', $input['types'] ) : array();
		$limit   = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 20;
		$results = KarMCP_Search_Index::search( $query, $types, $limit );
		return array(
			'query'   => $query,
			'results' => $results,
			'count'   => count( $results ),
		);
	}

	/**
	 * reindex-search.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_reindex( $input ) {
		KarMCP_Search_Index::maybe_install();
		$types   = ( isset( $input['types'] ) && is_array( $input['types'] ) ) ? array_map( 'strval', $input['types'] ) : array();
		$indexed = KarMCP_Search_Index::rebuild( $types );
		return array(
			'indexed' => $indexed,
			'total'   => array_sum( $indexed ),
		);
	}
}
