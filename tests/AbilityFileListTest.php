<?php
/**
 * Every ability file has to be in the list that loads them.
 *
 * `KarMCP_Bootstrap::load_ability_classes()` stays an explicit list on purpose:
 * since 1.30.0 the autoloader can resolve any of these classes on demand, so
 * the list is not there to make loading *possible* — it is there to load them
 * all at once the moment a tool is asked for, which is what keeps the cost of
 * MCP registration a measured number instead of a guess.
 *
 * DeferredAbilityLoadTest already checks that every path in the list points at
 * a file that exists. It does not check the other direction, and that is the
 * direction a new file goes missing in: add includes/abilities/class-foo.php,
 * forget the require line, and the suite stays green. The class still resolves
 * through the autoloader whenever something names it, so nothing fails — the
 * group simply never registers its tools during the eager load, and the tools
 * are absent from the server for reasons no error explains.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/lib-ability-scan.php';

class AbilityFileListTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	/**
	 * Paths, relative to includes/abilities/, that the loader requires.
	 *
	 * @return string[]
	 */
	private function listed(): array {
		$src = (string) file_get_contents( self::ROOT . '/includes/class-bootstrap.php' );
		if ( ! preg_match( '/function load_ability_classes\(\): void \{(.*?)\n\t\}/s', $src, $m ) ) {
			$this->fail( 'load_ability_classes() changed shape; this gate reads its require list.' );
		}
		preg_match_all( '#includes/abilities/([a-z0-9/-]+\.php)#', $m[1], $hits );
		return array_values( array_unique( $hits[1] ) );
	}

	/**
	 * Paths, relative to includes/abilities/, that exist on disk.
	 *
	 * @return string[]
	 */
	private function on_disk(): array {
		$out  = array();
		$root = self::ROOT . '/includes/abilities/';
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$path  = str_replace( '\\', '/', $file->getPathname() );
			$out[] = substr( $path, strpos( $path, 'includes/abilities/' ) + strlen( 'includes/abilities/' ) );
		}
		sort( $out );
		return $out;
	}

	public function test_every_ability_file_is_loaded_eagerly(): void {
		$disk   = $this->on_disk();
		$listed = $this->listed();

		$this->assertNotEmpty( $disk, 'No ability files found — the scanner broke, not the tree.' );
		$this->assertNotEmpty( $listed, 'No require lines found in load_ability_classes().' );

		$unlisted = array_values( array_diff( $disk, $listed ) );

		$this->assertSame(
			array(),
			$unlisted,
			"These files live in includes/abilities/ but KarMCP_Bootstrap::load_ability_classes() does not require them:\n  "
				. implode( "\n  ", $unlisted )
				. "\nThe autoloader will still resolve the class when something names it, so nothing errors —"
				. ' the group just never registers during the eager load. Add the require line.'
		);
	}

	/**
	 * The mirror of DeferredAbilityLoadTest's check, kept here so both
	 * directions fail in the same place with the same wording.
	 */
	public function test_the_list_names_no_file_that_is_gone(): void {
		$missing = array_values( array_diff( $this->listed(), $this->on_disk() ) );

		$this->assertSame(
			array(),
			$missing,
			"load_ability_classes() requires files that are not on disk:\n  " . implode( "\n  ", $missing )
		);
	}
}
