<?php
/**
 * The version is written in three places and all three have to say the same.
 *
 * The plugin header is what WordPress shows on the Plugins screen, the
 * KARMCP_VERSION constant is what the code and the asset cache-busting use, and
 * readme.txt's Stable tag is what the release zip is named after. Keeping them
 * in step is step one of every release in CLAUDE.md, and it was the one step
 * with nothing behind it — three hand edits in two files, done at the end of a
 * change, which is exactly when attention is lowest.
 *
 * A mismatch does not break anything loudly. The plugin runs, the tests pass,
 * and the zip ships; the symptom is a site reporting a version it is not
 * running, or an asset that keeps serving from cache after an update. Cheap to
 * check, invisible otherwise.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class VersionTripleTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	public function test_header_constant_and_stable_tag_agree(): void {
		$plugin = (string) file_get_contents( self::ROOT . '/karmcp.php' );
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );

		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $plugin, $h ), 'No "Version:" line in the karmcp.php plugin header.' );
		$this->assertSame( 1, preg_match( "/define\(\s*'KARMCP_VERSION'\s*,\s*'([^']+)'\s*\)/", $plugin, $c ), 'No KARMCP_VERSION define in karmcp.php.' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $s ), 'No "Stable tag:" line in readme.txt.' );

		$header = $h[1];
		$const  = $c[1];
		$stable = $s[1];

		$this->assertSame( $header, $const, "karmcp.php header says $header but KARMCP_VERSION is $const." );
		$this->assertSame( $header, $stable, "karmcp.php header says $header but readme.txt Stable tag is $stable." );
	}

	/**
	 * The changelog has to have an entry for whatever the header claims to be,
	 * or the release ships with no record of what changed in it.
	 */
	public function test_the_current_version_has_a_changelog_entry(): void {
		$plugin = (string) file_get_contents( self::ROOT . '/karmcp.php' );
		preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $plugin, $h );
		$version = $h[1] ?? '';

		$changelog = (string) file_get_contents( self::ROOT . '/CHANGELOG.md' );

		$this->assertStringContainsString(
			'## [' . $version . ']',
			$changelog,
			"karmcp.php declares $version but CHANGELOG.md has no `## [$version]` section."
		);
	}
}
