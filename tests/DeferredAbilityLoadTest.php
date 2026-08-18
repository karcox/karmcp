<?php
/**
 * The invariants the deferred tool-class load rests on.
 *
 * The ~76 files under includes/abilities/ no longer load on every request; they
 * load when something first asks for a tool. Two things have to stay true for
 * that to be safe, and neither fails visibly in the suite — the first shows up
 * as a fatal on a live site, the second as a fatal only on the code path that
 * happens to run first. So they are pinned here.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class DeferredAbilityLoadTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	/**
	 * Classes outside includes/abilities/ that legitimately name a tool class,
	 * each with the reason it is safe. Anything else appearing here means a
	 * runtime file grew a dependency on a class that may not be loaded yet.
	 *
	 * @var array<string,string[]>
	 */
	private const ALLOWED = array(
		// Built lazily in registrar(), which loads the tool classes first.
		'KarMCP_Ability_Registrar'     => array( 'class-plugin.php' ),
		// Read in register_mcp_server(), which returns early until
		// register_abilities() has run and loaded the tool classes.
		'KarMCP_Dispatcher_Abilities'  => array( 'class-plugin.php' ),
		// admin/ is loaded only in is_admin(), where load_admin() loads the tool
		// classes up front.
		'KarMCP_Sandbox_Cloud_Abilities' => array( 'class-admin.php' ),
		'KarMCP_WPCLI_Abilities'       => array( 'class-admin.php' ),
		// Named in a doc comment only.
		'KarMCP_Seo_Audit_Abilities'   => array( 'class-page-snapshot.php' ),
		'KarMCP_SlimSEO_Integration'   => array( 'class-seo-meta.php' ),
	);

	/**
	 * Every class and trait declared under includes/abilities/.
	 *
	 * @return string[]
	 */
	private function ability_classes(): array {
		$names = array();
		$dir   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::ROOT . '/includes/abilities' ) );
		foreach ( $dir as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$src = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( '/^\s*(?:abstract\s+|final\s+)?(?:class|trait)\s+(\w+)/m', $src, $m ) ) {
				foreach ( $m[1] as $name ) {
					$names[] = $name;
				}
			}
		}
		sort( $names );
		return array_unique( $names );
	}

	/**
	 * A require for a file that isn't there is a fatal on a live site and
	 * nothing at all here — the suite never runs the bootstrap. A typo in the
	 * deferred list would ship.
	 */
	public function test_every_deferred_require_points_at_a_file_that_exists(): void {
		$src = (string) file_get_contents( self::ROOT . '/includes/class-bootstrap.php' );
		$this->assertSame( 1, preg_match_all( '/function load_ability_classes/', $src ), 'load_ability_classes() exists exactly once' );

		preg_match_all( "#require_once KARMCP_DIR \. '(includes/abilities/[^']+)';#", $src, $m );
		$this->assertGreaterThan( 60, count( $m[1] ), 'the deferred list still holds the tool classes' );

		foreach ( $m[1] as $rel ) {
			$this->assertFileExists( self::ROOT . '/' . $rel );
		}
	}

	/**
	 * Nothing under includes/abilities/ may be required from load_classes() — a
	 * single one left behind quietly restores the per-request cost the split
	 * removed, and nothing would fail.
	 */
	public function test_the_runtime_load_requires_no_tool_classes(): void {
		$src = (string) file_get_contents( self::ROOT . '/includes/class-bootstrap.php' );
		$from = strpos( $src, 'private static function load_classes' );
		$to   = strpos( $src, 'public static function load_ability_classes' );
		$this->assertIsInt( $from );
		$this->assertIsInt( $to );
		$this->assertGreaterThan( $from, $to, 'load_classes() comes first' );

		$runtime = substr( $src, $from, $to - $from );
		$this->assertSame(
			0,
			preg_match_all( "#require_once KARMCP_DIR \. 'includes/abilities/#", $runtime ),
			'load_classes() must not require any tool class'
		);
	}

	/**
	 * The load order inside the deferred block is load-bearing: the dispatch
	 * trait before the integrations that use it, each abstract base before its
	 * subclasses. PHP resolves a parent at declaration time, so getting this
	 * wrong is an immediate fatal.
	 */
	public function test_bases_are_required_before_what_extends_them(): void {
		$src = (string) file_get_contents( self::ROOT . '/includes/class-bootstrap.php' );
		preg_match_all( "#require_once KARMCP_DIR \. 'includes/abilities/([^']+)';#", $src, $m );
		$order = array_flip( array_map( 'basename', $m[1] ) );

		$pairs = array(
			array( 'trait-operation-dispatcher.php', 'class-active-theme-integration.php' ),
			array( 'class-theme-integration.php', 'class-astra-integration.php' ),
			array( 'class-theme-integration.php', 'class-kadence-integration.php' ),
			array( 'class-form-integration.php', 'class-cf7-integration.php' ),
			array( 'class-seo-integration.php', 'class-slimseo-integration.php' ),
			array( 'class-translation-integration.php', 'class-polylang-integration.php' ),
			array( 'class-translation-integration.php', 'class-wpml-integration.php' ),
		);
		foreach ( $pairs as $pair ) {
			list( $base, $child ) = $pair;
			$this->assertArrayHasKey( $base, $order, "$base is in the deferred list" );
			$this->assertArrayHasKey( $child, $order, "$child is in the deferred list" );
			$this->assertLessThan( $order[ $child ], $order[ $base ], "$base loads before $child" );
		}
	}

	/**
	 * The load-bearing one: a runtime file that names a tool class may run
	 * before those classes are loaded. Every such reference needs a reason, and
	 * ALLOWED is where the reason is written down.
	 */
	public function test_no_runtime_file_depends_on_an_unloaded_tool_class(): void {
		$classes = $this->ability_classes();
		$this->assertNotEmpty( $classes );

		$dir   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::ROOT . '/includes' ) );
		$found = array();
		foreach ( $dir as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$path = str_replace( '\\', '/', $file->getPathname() );
			if ( false !== strpos( $path, '/includes/abilities/' ) ) {
				continue;
			}
			$src  = (string) file_get_contents( $file->getPathname() );
			$name = basename( $path );
			foreach ( $classes as $class ) {
				if ( preg_match( '/\b' . preg_quote( $class, '/' ) . '\b/', $src ) ) {
					$found[ $class ][] = $name;
				}
			}
		}

		foreach ( $found as $class => $files ) {
			$this->assertArrayHasKey(
				$class,
				self::ALLOWED,
				"$class is a tool class referenced from " . implode( ', ', array_unique( $files ) )
					. ' — those files run before the tool classes load. Either drop the reference,'
					. ' call KarMCP_Bootstrap::load_ability_classes() first, or add it to ALLOWED with the reason.'
			);
			foreach ( array_unique( $files ) as $f ) {
				$this->assertContains( $f, self::ALLOWED[ $class ], "$class referenced from an unexpected file ($f)" );
			}
		}
	}
}
