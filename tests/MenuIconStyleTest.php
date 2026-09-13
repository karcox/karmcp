<?php
/**
 * The sidebar mark is painted white in CSS, except where white would vanish.
 *
 * WordPress's svg-painter.js recolours a data-URI menu icon by rewriting its
 * `fill` and nothing else, and the KarMCP mark is stroked — so the painter left
 * the K black and turned only its two nodes grey, which on the dark sidebar is
 * a near-invisible letter beside two dots. The fix is a CSS filter that turns
 * every painted pixel white.
 *
 * The part worth a test is the exception. The Light scheme's sidebar is pale
 * grey, a white mark there disappears completely, and the rule that prevents it
 * is one conditional that looks like it could be simplified away. Nothing else
 * would notice: the suite does not render the admin, and the people it breaks
 * for are the ones who chose a light sidebar.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/admin/class-admin.php';

if ( ! function_exists( 'get_user_option' ) ) {
	/**
	 * Test double: the admin colour scheme the current user picked.
	 *
	 * @param string $option Option name.
	 * @return mixed
	 */
	function get_user_option( $option ) {
		return 'admin_color' === $option ? ( $GLOBALS['karmcp_test_admin_color'] ?? 'fresh' ) : false;
	}
}

class MenuIconStyleTest extends TestCase {

	/**
	 * The style block the admin prints for a given colour scheme.
	 *
	 * @param string $scheme Admin colour scheme slug.
	 * @return string
	 */
	private function style_for( string $scheme ): string {
		$GLOBALS['karmcp_test_admin_color'] = $scheme;

		$admin = ( new ReflectionClass( 'KarMCP_Admin' ) )->newInstanceWithoutConstructor();

		ob_start();
		$admin->print_menu_icon_style();
		return (string) ob_get_clean();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['karmcp_test_admin_color'] );
	}

	/**
	 * @dataProvider dark_schemes
	 */
	public function test_the_mark_is_painted_white_on_a_dark_sidebar( string $scheme ): void {
		$this->assertStringContainsString(
			'#toplevel_page_karmcp .wp-menu-image{filter:brightness(0) invert(1);}',
			$this->style_for( $scheme )
		);
	}

	public static function dark_schemes(): array {
		// Every core scheme whose sidebar is dark enough for a white mark.
		return array(
			'fresh'     => array( 'fresh' ),
			'modern'    => array( 'modern' ),
			'blue'      => array( 'blue' ),
			'coffee'    => array( 'coffee' ),
			'ectoplasm' => array( 'ectoplasm' ),
			'midnight'  => array( 'midnight' ),
			'ocean'     => array( 'ocean' ),
			'sunrise'   => array( 'sunrise' ),
		);
	}

	public function test_the_mark_is_not_forced_white_on_the_light_scheme(): void {
		$this->assertStringNotContainsString(
			'filter:brightness(0) invert(1)',
			$this->style_for( 'light' ),
			'The Light sidebar is pale grey; a white mark on it is invisible.'
		);
	}

	public function test_the_duplicate_submenu_rows_stay_hidden_either_way(): void {
		// The same style block hides the sidebar rows the panel's own rail
		// already lists. Editing the icon rule must not take that with it.
		foreach ( array( 'fresh', 'light' ) as $scheme ) {
			$this->assertStringContainsString(
				'.wp-submenu a[href*="page=karmcp-"]{display:none !important;}',
				$this->style_for( $scheme ),
				"Submenu rule missing under the $scheme scheme."
			);
		}
	}
}
