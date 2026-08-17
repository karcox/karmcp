<?php
/**
 * Block-tree position validation.
 *
 * The mutators in KarMCP_Block_Tree are total: handed a path that doesn't
 * resolve they return the tree untouched. That is correct for a pure transform
 * and dangerous for a tool, because the save that follows succeeds and the tool
 * reports having inserted a block it never inserted. These pin the two
 * validators every caller runs first — including the two cases move() declines
 * on its own, which no path-resolves check would ever catch.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-block-tree.php';

class BlockTreePositionTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/**
	 * A tree of two top-level blocks; the first holds two children.
	 *
	 * @return array
	 */
	private function tree(): array {
		return array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerContent' => array() ),
					array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerContent' => array() ),
				),
				'innerContent' => array( '<div>', null, null, '</div>' ),
			),
			array( 'blockName' => 'core/separator', 'attrs' => array(), 'innerBlocks' => array(), 'innerContent' => array() ),
		);
	}

	// ---- position_error ----------------------------------------------------

	public function test_append_and_prepend_need_no_path(): void {
		$this->assertNull( KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => 'append' ) ) );
		$this->assertNull( KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => 'prepend' ) ) );
	}

	public function test_missing_mode_defaults_to_append(): void {
		$this->assertNull( KarMCP_Block_Tree::position_error( $this->tree(), array() ) );
	}

	public function test_resolvable_paths_pass(): void {
		foreach ( array( 'before', 'after', 'inside' ) as $mode ) {
			$this->assertNull(
				KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => $mode, 'path' => array( 0 ) ) ),
				"mode $mode at [0]"
			);
			$this->assertNull(
				KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => $mode, 'path' => array( 0, 1 ) ) ),
				"mode $mode at [0,1]"
			);
		}
	}

	public function test_out_of_range_path_is_rejected(): void {
		$err = KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => 'after', 'path' => array( 9 ) ) );
		$this->assertIsString( $err );
		$this->assertStringContainsString( '9', $err );
	}

	public function test_path_into_a_childless_block_is_rejected(): void {
		// [1] is the separator: it has no children, so [1,0] resolves to nothing.
		$this->assertIsString( KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => 'inside', 'path' => array( 1, 0 ) ) ) );
	}

	public function test_relative_mode_without_a_path_is_rejected(): void {
		foreach ( array( 'before', 'after', 'inside' ) as $mode ) {
			$this->assertIsString(
				KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => $mode ) ),
				"mode $mode with no path"
			);
		}
	}

	/**
	 * An unknown mode falls through insert()'s branches to the before/after
	 * splice with an empty path, which returns the tree untouched. Left
	 * unchecked, a typo in "inside" is a silent no-op that still reports success.
	 */
	public function test_unknown_mode_is_rejected(): void {
		$err = KarMCP_Block_Tree::position_error( $this->tree(), array( 'mode' => 'inseide', 'path' => array( 0 ) ) );
		$this->assertIsString( $err );
		$this->assertStringContainsString( 'inseide', $err );
	}

	// ---- move_error --------------------------------------------------------

	public function test_valid_move_passes(): void {
		$this->assertNull(
			KarMCP_Block_Tree::move_error( $this->tree(), array( 0, 1 ), array( 'mode' => 'after', 'path' => array( 1 ) ) )
		);
	}

	public function test_move_from_an_unresolvable_path_is_rejected(): void {
		$this->assertIsString( KarMCP_Block_Tree::move_error( $this->tree(), array( 7 ), array( 'mode' => 'append' ) ) );
		$this->assertIsString( KarMCP_Block_Tree::move_error( $this->tree(), array(), array( 'mode' => 'append' ) ) );
	}

	public function test_move_to_an_unresolvable_target_is_rejected(): void {
		$this->assertIsString(
			KarMCP_Block_Tree::move_error( $this->tree(), array( 0 ), array( 'mode' => 'after', 'path' => array( 4 ) ) )
		);
	}

	/**
	 * move() returns the tree untouched for a block moved onto its own position.
	 * Both paths resolve, so only this check catches it.
	 */
	public function test_move_onto_its_own_position_is_rejected(): void {
		$this->assertIsString(
			KarMCP_Block_Tree::move_error( $this->tree(), array( 0, 1 ), array( 'mode' => 'after', 'path' => array( 0, 1 ) ) )
		);
		$this->assertIsString(
			KarMCP_Block_Tree::move_error( $this->tree(), array( 0, 1 ), array( 'mode' => 'before', 'path' => array( 0, 1 ) ) )
		);
	}

	/**
	 * A target inside the moved node's own subtree would remove the node and then
	 * fail to re-insert it. move() declines; both paths resolve, so again only
	 * this check reports it.
	 */
	public function test_move_into_its_own_subtree_is_rejected(): void {
		$this->assertIsString(
			KarMCP_Block_Tree::move_error( $this->tree(), array( 0 ), array( 'mode' => 'inside', 'path' => array( 0, 1 ) ) )
		);
	}

	/**
	 * The guarded cases are exactly the ones where move() is a no-op — pinned
	 * here so the validator can never drift into rejecting a move that works.
	 */
	public function test_every_rejected_move_is_one_move_would_not_perform(): void {
		$cases = array(
			array( array( 0, 1 ), array( 'mode' => 'after', 'path' => array( 0, 1 ) ) ),
			array( array( 0 ), array( 'mode' => 'inside', 'path' => array( 0, 1 ) ) ),
		);
		foreach ( $cases as $i => $case ) {
			list( $from, $position ) = $case;
			$this->assertIsString( KarMCP_Block_Tree::move_error( $this->tree(), $from, $position ), "case $i rejected" );
			$this->assertSame(
				$this->tree(),
				KarMCP_Block_Tree::move( $this->tree(), $from, $position ),
				"case $i is a no-op in move()"
			);
		}
	}
}
