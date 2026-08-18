<?php
/**
 * Every AJAX action the admin JS posts has to have a handler registered.
 *
 * The plugin was renamed from its upstream `karmcp_tools_*` action prefix to
 * `karmcp_*`, and three call sites were missed. Nothing failed loudly: WP
 * answers an unregistered action with `0`, the fetch resolves, and the button
 * just never works. Applying a brand kit, restoring one, and generating an
 * application password were all dead this way, on and off, for releases.
 *
 * A mismatch is invisible in review and invisible at runtime, which is exactly
 * the kind of thing a test should be holding.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class AjaxActionContractTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	/**
	 * Every action name the admin JS posts to admin-ajax.
	 *
	 * @return array<string,string> action => the JS file it came from.
	 */
	private function js_actions(): array {
		$found = array();
		foreach ( glob( self::ROOT . '/assets/js/*.js' ) as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match_all( '/append\(\s*[\'"]action[\'"]\s*,\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/i', $src, $m ) ) {
				foreach ( $m[1] as $action ) {
					$found[ $action ] = basename( $file );
				}
			}
		}
		return $found;
	}

	/**
	 * Every action registered with add_action( 'wp_ajax_...' ) in PHP.
	 *
	 * @return string[]
	 */
	private function php_actions(): array {
		$found = array();
		$dir   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::ROOT . '/includes' ) );
		foreach ( $dir as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$src = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( '/add_action\(\s*[\'"]wp_ajax_(?:nopriv_)?([a-z0-9_]+)[\'"]/i', $src, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
		}
		return array_unique( $found );
	}

	public function test_every_js_ajax_action_has_a_php_handler(): void {
		$js  = $this->js_actions();
		$php = $this->php_actions();

		$this->assertNotEmpty( $js, 'Found no AJAX actions in the admin JS — the scanner regex probably broke.' );
		$this->assertNotEmpty( $php, 'Found no wp_ajax_ registrations — the scanner regex probably broke.' );

		foreach ( $js as $action => $file ) {
			$this->assertContains(
				$action,
				$php,
				"assets/js/$file posts action '$action', which no add_action( 'wp_ajax_$action' ) registers."
					. ' WordPress answers an unregistered action with 0, so the feature fails silently.'
			);
		}
	}

	/**
	 * The two brand-kit actions specifically — they are the ones that were
	 * broken, and the ones this release restores.
	 */
	public function test_brand_kit_actions_are_registered(): void {
		$php = $this->php_actions();
		$this->assertContains( 'karmcp_apply_brand_kit', $php );
		$this->assertContains( 'karmcp_restore_brand_kit', $php );
	}

	/**
	 * The upstream prefix is gone; a new one creeping back in means another
	 * half-finished rename.
	 */
	public function test_no_upstream_action_prefix_survives(): void {
		foreach ( $this->js_actions() as $action => $file ) {
			$this->assertStringStartsNotWith(
				'karmcp_tools_',
				$action,
				"assets/js/$file still posts the upstream action name '$action'."
			);
		}
	}
}
