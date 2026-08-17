<?php
/**
 * The order of KarMCP's submenu.
 *
 * WordPress points a top-level menu at whatever sits FIRST in its submenu
 * array. Post types that declare `show_in_menu => karmcp` — Skills does — are
 * added on an earlier `admin_menu` pass than KarMCP_Admin runs on, so without
 * a reorder the Skills list becomes the destination of the KarMCP menu itself.
 *
 * That shipped in 1.19.0 and made the panel unreachable from the sidebar: the
 * same release hid the submenu rows, so the wrong first entry stopped being a
 * cosmetic detail and became the only door.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/admin/class-admin.php';

class AdminSubmenuOrderTest extends TestCase {

	/**
	 * Builds a $submenu shaped like WordPress's: each row is
	 * [ page title, capability, menu slug, ... ].
	 *
	 * @param array<int,string> $slugs Menu slugs in insertion order.
	 * @return array<int,array<int,string>>
	 */
	private function rows( array $slugs ): array {
		return array_map(
			static fn( string $slug ): array => array( ucfirst( $slug ), 'manage_options', $slug ),
			$slugs
		);
	}

	/**
	 * @param array<int,string> $slugs Menu slugs in insertion order.
	 * @return array<int,string> Slugs after the reorder.
	 */
	private function reorder( array $slugs ): array {
		global $submenu;
		$submenu = array( KarMCP_Admin::PAGE_SLUG => $this->rows( $slugs ) );

		( new KarMCP_Admin() )->order_submenu();

		return array_column( $submenu[ KarMCP_Admin::PAGE_SLUG ], 2 );
	}

	/**
	 * The regression itself: a post type got in first, and the menu followed it.
	 */
	public function test_dashboard_leads_even_when_a_post_type_was_added_first(): void {
		$order = $this->reorder(
			array( 'edit.php?post_type=karmcp_skill', KarMCP_Admin::PAGE_SLUG, 'karmcp-tools' )
		);

		$this->assertSame( KarMCP_Admin::PAGE_SLUG, $order[0] );
	}

	/**
	 * Only the Dashboard moves. Everything else keeps the order it was
	 * registered in, because that order is what the sidebar reads top to bottom.
	 */
	public function test_the_remaining_entries_keep_their_relative_order(): void {
		$order = $this->reorder(
			array( 'edit.php?post_type=karmcp_skill', KarMCP_Admin::PAGE_SLUG, 'karmcp-modules', 'karmcp-tools' )
		);

		$this->assertSame(
			array( KarMCP_Admin::PAGE_SLUG, 'edit.php?post_type=karmcp_skill', 'karmcp-modules', 'karmcp-tools' ),
			$order
		);
	}

	/**
	 * Nothing to promote is not an error, and must not empty the menu: a bail
	 * that returned early having already half-rebuilt the array would leave the
	 * plugin with no sidebar entry at all.
	 */
	public function test_a_submenu_without_a_dashboard_row_is_left_untouched(): void {
		$slugs = array( 'edit.php?post_type=karmcp_skill', 'karmcp-tools' );

		$this->assertSame( $slugs, $this->reorder( $slugs ) );
	}

	public function test_an_empty_submenu_is_survivable(): void {
		global $submenu;
		$submenu = array();

		( new KarMCP_Admin() )->order_submenu();

		$this->assertSame( array(), $submenu );
	}
}
