<?php
/**
 * Gutenberg block MCP abilities.
 *
 * Ten tools to discover WordPress blocks/patterns and build/edit a post's block
 * tree incrementally with raw block markup + index-path addressing. Pure WP core
 * (no Elementor); operates on any post's post_content via KarMCP_Block_Tree.
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
class KarMCP_Gutenberg_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Register all ten abilities.
	 *
	 * @since 3.1.0
	 */
	public function register(): void {
		$this->register_list_blocks();
		$this->register_get_block_schema();
		$this->register_get_post_blocks();
		$this->register_list_patterns();
		$this->register_add_block();
		$this->register_update_block();
		$this->register_remove_block();
		$this->register_move_block();
		$this->register_duplicate_block();
		$this->register_insert_pattern();
	}

	// ---- permissions -------------------------------------------------------

	/**
	 * @param array|null $input
	 * @return bool
	 */
	public function check_read_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param array|null $input
	 * @return bool
	 */
	public function check_write_permission( $input = null ): bool {
		$post_id = absint( $input['post_id'] ?? 0 );
		return $post_id && current_user_can( 'edit_post', $post_id );
	}

	// ---- shared position schema fragment -----------------------------------

	/** @return array */
	private function position_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'Where to place the block(s). mode: append (top end) | prepend (top start) | before | after | inside. before/after/inside need "path".', 'karmcp' ),
			'properties'  => array(
				'mode' => array( 'type' => 'string', 'enum' => array( 'append', 'prepend', 'before', 'after', 'inside' ) ),
				'path' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
		);
	}

	// ---- list-blocks -------------------------------------------------------

	private function register_list_blocks(): void {
		$this->ability_names[] = 'karmcp/list-blocks';
		karmcp_register_ability(
			'karmcp/list-blocks',
			array(
				'label'               => __( 'List Blocks', 'karmcp' ),
				'description'         => __( 'Lists registered Gutenberg block types (name, title, category). Filter by category or search. Step 1 of build a block page: list-blocks -> get-block-schema -> add-block.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_blocks' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category' => array( 'type' => 'string', 'description' => __( 'Only blocks in this category (e.g. text, media, design).', 'karmcp' ) ),
						'search'   => array( 'type' => 'string', 'description' => __( 'Substring match on block name or title.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'blocks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_blocks( $input ): array {
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$search   = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$rows     = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$cat = isset( $type->category ) ? (string) $type->category : '';
			if ( '' !== $category && $cat !== $category ) {
				continue;
			}
			$title = isset( $type->title ) && '' !== (string) $type->title ? (string) $type->title : (string) $name;
			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$rows[] = array( 'name' => (string) $name, 'title' => $title, 'category' => $cat );
		}
		return array( 'blocks' => $rows );
	}

	// ---- get-block-schema --------------------------------------------------

	private function register_get_block_schema(): void {
		$this->ability_names[] = 'karmcp/get-block-schema';
		karmcp_register_ability(
			'karmcp/get-block-schema',
			array(
				'label'               => __( 'Get Block Schema', 'karmcp' ),
				'description'         => __( 'Returns a block type\'s attributes, supports, and a minimal markup example. Pass "name" for one or "names" for a batch. Step 2 before add-block.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_block_schema' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string', 'description' => __( 'Block type name, e.g. core/heading.', 'karmcp' ) ),
						'names' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Batch of block type names.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'blocks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_get_block_schema( $input ): array {
		$names = array();
		if ( ! empty( $input['names'] ) && is_array( $input['names'] ) ) {
			$names = array_map( 'strval', $input['names'] );
		} elseif ( ! empty( $input['name'] ) ) {
			$names = array( (string) $input['name'] );
		}
		$registry = WP_Block_Type_Registry::get_instance();
		$rows     = array();
		foreach ( $names as $name ) {
			$type = $registry->get_registered( $name );
			if ( ! $type ) {
				$rows[] = array( 'name' => $name, 'error' => __( 'Block type not registered.', 'karmcp' ) );
				continue;
			}
			$short  = ( 0 === strpos( $name, 'core/' ) ) ? substr( $name, 5 ) : $name;
			$rows[] = array(
				'name'       => (string) $name,
				'title'      => isset( $type->title ) ? (string) $type->title : (string) $name,
				'category'   => isset( $type->category ) ? (string) $type->category : '',
				'attributes' => is_array( $type->attributes ?? null ) ? $type->attributes : array(),
				'supports'   => is_array( $type->supports ?? null ) ? $type->supports : array(),
				'example'    => sprintf( '<!-- wp:%1$s -->…<!-- /wp:%1$s -->', $short ),
			);
		}
		return array( 'blocks' => $rows );
	}

	// ---- get-post-blocks ---------------------------------------------------

	private function register_get_post_blocks(): void {
		$this->ability_names[] = 'karmcp/get-post-blocks';
		karmcp_register_ability(
			'karmcp/get-post-blocks',
			array(
				'label'               => __( 'Get Post Blocks', 'karmcp' ),
				'description'         => __( 'Returns a post\'s parsed block tree with an index PATH per block (e.g. [2,1]). Call this immediately before update-block/remove-block/move-block to get current paths.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_post_blocks' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'depth'   => array( 'type' => 'integer', 'description' => __( 'Max nesting depth to return. Omit for full tree.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'blocks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_get_post_blocks( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'karmcp' ) );
		}
		$depth  = isset( $input['depth'] ) ? max( 1, (int) $input['depth'] ) : null;
		$tree   = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		return array( 'blocks' => KarMCP_Block_Tree::summarize( $tree, $depth ) );
	}

	// ---- list-patterns -----------------------------------------------------

	private function register_list_patterns(): void {
		$this->ability_names[] = 'karmcp/list-patterns';
		karmcp_register_ability(
			'karmcp/list-patterns',
			array(
				'label'               => __( 'List Patterns', 'karmcp' ),
				'description'         => __( 'Lists registered block patterns (prebuilt block compositions), name, title, categories, description. Use insert-pattern to drop one into a post.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_patterns' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => __( 'Substring match on pattern name or title.', 'karmcp' ) ),
						'category' => array( 'type' => 'string', 'description' => __( 'Only patterns in this category.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'patterns' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_patterns( $input ): array {
		$search   = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$rows     = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			$name  = (string) ( $p['name'] ?? '' );
			$title = (string) ( $p['title'] ?? $name );
			$cats  = array_map( 'strval', (array) ( $p['categories'] ?? array() ) );
			if ( '' !== $category && ! in_array( $category, $cats, true ) ) {
				continue;
			}
			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$rows[] = array(
				'name'        => $name,
				'title'       => $title,
				'categories'  => $cats,
				'description' => (string) ( $p['description'] ?? '' ),
			);
		}
		return array( 'patterns' => $rows );
	}

	// ---- write helpers -----------------------------------------------------

	/**
	 * Load a post for a write op, or return an [error] array.
	 *
	 * @param array $input
	 * @return array{0: object|null, 1: array|null} [post, errorResult]
	 */
	private function load_for_write( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return array( null, array( 'error' => __( 'Post not found.', 'karmcp' ) ) );
		}
		return array( $post, null );
	}

	/**
	 * Persist a mutated tree back to the post.
	 *
	 * @param object $post
	 * @param array  $tree
	 * @return void
	 */
	private function save_tree( $post, array $tree ): void {
		$before_content = (string) $post->post_content;
		// wp_update_post() runs wp_unslash() on the data, and block serialization
		// emits backslash escapes (&, \", \\ …) in attributes — so the markup
		// MUST be slashed here or those escapes get stripped and the block corrupts.
		wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_content' => wp_slash( KarMCP_Block_Tree::to_markup( $tree ) ),
			),
			true
		);
		// Record the block edit so it can be rolled back (post_content only).
		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			$post_id = (int) $post->ID;
			KarMCP_Change_Recorder::record_post_fields(
				$post_id,
				array( 'fields' => array( 'post_content' => $before_content ), 'meta' => array(), 'terms' => array() ),
				sprintf( 'Edited blocks on post #%d', $post_id ),
				trim( (string) get_the_title( $post_id ) . ' (#' . $post_id . ')' ),
				'gutenberg',
				'block-write'
			);
		}
	}

	/**
	 * Normalize the position input to a clean { mode, path } array.
	 *
	 * @param array $input
	 * @return array
	 */
	private function position_from_input( array $input ): array {
		$pos  = isset( $input['position'] ) && is_array( $input['position'] ) ? $input['position'] : array();
		$mode = isset( $pos['mode'] ) ? (string) $pos['mode'] : 'append';
		$path = isset( $pos['path'] ) && is_array( $pos['path'] ) ? array_map( 'intval', $pos['path'] ) : array();
		return array( 'mode' => $mode, 'path' => $path );
	}

	// ---- add-block ---------------------------------------------------------

	private function register_add_block(): void {
		$this->ability_names[] = 'karmcp/add-block';
		karmcp_register_ability(
			'karmcp/add-block',
			array(
				'label'               => __( 'Add Block', 'karmcp' ),
				'description'         => __( 'Inserts raw Gutenberg block markup into a post at a position. markup may contain one or more blocks. position.mode = append|prepend|before|after|inside (before/after/inside need position.path from get-post-blocks).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_add_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'markup'   => array( 'type' => 'string', 'description' => __( 'Gutenberg block markup, e.g. <!-- wp:heading --><h2>Hi</h2><!-- /wp:heading -->.', 'karmcp' ) ),
						'position' => $this->position_schema(),
					),
					'required'   => array( 'post_id', 'markup' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'added' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_add_block( $input ): array {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$new = KarMCP_Block_Tree::strip_separators( parse_blocks( (string) ( $input['markup'] ?? '' ) ) );
		if ( ! $new ) {
			return array( 'error' => __( 'markup did not parse to any block.', 'karmcp' ) );
		}
		$position = $this->position_from_input( $input );
		$tree     = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		if ( in_array( $position['mode'], array( 'before', 'after', 'inside' ), true ) && null === KarMCP_Block_Tree::at( $tree, $position['path'] ) ) {
			return array( 'error' => __( 'position.path does not resolve to a block. Call get-post-blocks first.', 'karmcp' ) );
		}
		$tree = KarMCP_Block_Tree::insert( $tree, $new, $position );
		$this->save_tree( $post, $tree );
		return array( 'post_id' => (int) $post->ID, 'added' => count( $new ), 'path' => $position['path'] );
	}

	// ---- update-block ------------------------------------------------------

	private function register_update_block(): void {
		$this->ability_names[] = 'karmcp/update-block';
		karmcp_register_ability(
			'karmcp/update-block',
			array(
				'label'               => __( 'Update Block', 'karmcp' ),
				'description'         => __( 'Replaces the block at an index path with new block markup. Get the path from get-post-blocks first.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to replace, e.g. [2,1].', 'karmcp' ) ),
						'markup'  => array( 'type' => 'string', 'description' => __( 'Replacement block markup.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id', 'path', 'markup' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_update_block( $input ): array {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $path || null === KarMCP_Block_Tree::at( $tree, $path ) ) {
			return array( 'error' => __( 'path does not resolve to a block. Call get-post-blocks first.', 'karmcp' ) );
		}
		$new = KarMCP_Block_Tree::strip_separators( parse_blocks( (string) ( $input['markup'] ?? '' ) ) );
		if ( ! $new ) {
			return array( 'error' => __( 'markup did not parse to any block.', 'karmcp' ) );
		}
		$tree = KarMCP_Block_Tree::replace( $tree, $path, $new );
		$this->save_tree( $post, $tree );
		return array( 'post_id' => (int) $post->ID, 'path' => $path );
	}

	// ---- remove-block ------------------------------------------------------

	private function register_remove_block(): void {
		$this->ability_names[] = 'karmcp/remove-block';
		karmcp_register_ability(
			'karmcp/remove-block',
			array(
				'label'               => __( 'Remove Block', 'karmcp' ),
				'description'         => __( 'Deletes the block at an index path (and its inner blocks). Destructive. Get the path from get-post-blocks first.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_remove_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to remove.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'removed' => array( 'type' => 'boolean' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_remove_block( $input ): array {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $path || null === KarMCP_Block_Tree::at( $tree, $path ) ) {
			return array( 'error' => __( 'path does not resolve to a block. Call get-post-blocks first.', 'karmcp' ) );
		}
		$tree = KarMCP_Block_Tree::remove( $tree, $path );
		$this->save_tree( $post, $tree );
		return array( 'post_id' => (int) $post->ID, 'removed' => true );
	}

	// ---- move-block --------------------------------------------------------

	private function register_move_block(): void {
		$this->ability_names[] = 'karmcp/move-block';
		karmcp_register_ability(
			'karmcp/move-block',
			array(
				'label'               => __( 'Move Block', 'karmcp' ),
				'description'         => __( 'Moves the block at "path" to a target position ({mode,path}). Get paths from get-post-blocks first.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_move_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'path'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to move.', 'karmcp' ) ),
						'position' => $this->position_schema(),
					),
					'required'   => array( 'post_id', 'path', 'position' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_move_block( $input ): array {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$from = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $from || null === KarMCP_Block_Tree::at( $tree, $from ) ) {
			return array( 'error' => __( 'path does not resolve to a block. Call get-post-blocks first.', 'karmcp' ) );
		}
		$position = $this->position_from_input( $input );
		if ( in_array( $position['mode'], array( 'before', 'after', 'inside' ), true ) && null === KarMCP_Block_Tree::at( $tree, $position['path'] ) ) {
			return array( 'error' => __( 'target position.path does not resolve to a block.', 'karmcp' ) );
		}
		$tree = KarMCP_Block_Tree::move( $tree, $from, $position );
		$this->save_tree( $post, $tree );
		return array( 'post_id' => (int) $post->ID, 'path' => $position['path'] );
	}

	// ---- duplicate-block ---------------------------------------------------

	private function register_duplicate_block(): void {
		$this->ability_names[] = 'karmcp/duplicate-block';
		karmcp_register_ability(
			'karmcp/duplicate-block',
			array(
				'label'               => __( 'Duplicate Block', 'karmcp' ),
				'description'         => __( 'Clones the block at an index path and inserts the copy immediately after it. Get the path from get-post-blocks first.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_duplicate_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to duplicate.', 'karmcp' ) ),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_duplicate_block( $input ): array {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $path || null === KarMCP_Block_Tree::at( $tree, $path ) ) {
			return array( 'error' => __( 'path does not resolve to a block. Call get-post-blocks first.', 'karmcp' ) );
		}
		$tree = KarMCP_Block_Tree::duplicate( $tree, $path );
		$this->save_tree( $post, $tree );
		$copy_path                            = $path;
		$copy_path[ count( $copy_path ) - 1 ] = (int) end( $path ) + 1;
		return array( 'post_id' => (int) $post->ID, 'path' => $copy_path );
	}

	// ---- insert-pattern ----------------------------------------------------

	private function register_insert_pattern(): void {
		$this->ability_names[] = 'karmcp/insert-pattern';
		karmcp_register_ability(
			'karmcp/insert-pattern',
			array(
				'label'               => __( 'Insert Pattern', 'karmcp' ),
				'description'         => __( 'Inserts a registered block pattern (by name from list-patterns) into a post at a position.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_insert_pattern' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'karmcp' ) ),
						'pattern_name' => array( 'type' => 'string', 'description' => __( 'Pattern name from list-patterns, e.g. core/two-columns.', 'karmcp' ) ),
						'position'     => $this->position_schema(),
					),
					'required'   => array( 'post_id', 'pattern_name' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'added' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_insert_pattern( $input ): array {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$name    = (string) ( $input['pattern_name'] ?? '' );
		$pattern = $name ? WP_Block_Patterns_Registry::get_instance()->get_registered( $name ) : null;
		if ( ! $pattern || empty( $pattern['content'] ) ) {
			return array( 'error' => __( 'Pattern not registered. Use list-patterns for valid names.', 'karmcp' ) );
		}
		$new = KarMCP_Block_Tree::strip_separators( parse_blocks( (string) $pattern['content'] ) );
		if ( ! $new ) {
			return array( 'error' => __( 'Pattern produced no blocks.', 'karmcp' ) );
		}
		$position = $this->position_from_input( $input );
		$tree     = KarMCP_Block_Tree::from_markup( (string) $post->post_content );
		if ( in_array( $position['mode'], array( 'before', 'after', 'inside' ), true ) && null === KarMCP_Block_Tree::at( $tree, $position['path'] ) ) {
			return array( 'error' => __( 'position.path does not resolve to a block. Call get-post-blocks first.', 'karmcp' ) );
		}
		$tree = KarMCP_Block_Tree::insert( $tree, $new, $position );
		$this->save_tree( $post, $tree );
		return array( 'post_id' => (int) $post->ID, 'added' => count( $new ), 'path' => $position['path'] );
	}
}
