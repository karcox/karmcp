<?php
/**
 * KarMCP_Filename_Guard: the one executable-extension list, and the two ways
 * it is consumed.
 *
 * The properties worth pinning: only INNER segments are judged (a final .php
 * belongs to wp_check_filetype's better refusal, a leading php. is a base
 * name); 'pl' is deliberately NOT on the list (Poland ccTLD false positives —
 * "onet.pl.jpg" is a screenshot); and the malware audit's regex is built from
 * the same PHP_FAMILY constant, so the front door and the scanner can no
 * longer drift apart (they had: the audit's hardcoded regex lacked php8 and
 * phar while upload-media refused them).
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-filename-guard.php';
require_once __DIR__ . '/../includes/security/class-security-malware-audit.php';

class FilenameGuardTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	// -----------------------------------------------------------------
	// executable_extension_in()
	// -----------------------------------------------------------------

	public function test_only_inner_segments_are_judged(): void {
		$this->assertSame( '', KarMCP_Filename_Guard::executable_extension_in( 'payload.php' ) );
		$this->assertSame( '', KarMCP_Filename_Guard::executable_extension_in( 'php.jpg' ) );
		$this->assertSame( 'php', KarMCP_Filename_Guard::executable_extension_in( 'photo.php.jpg' ) );
		$this->assertSame( 'phtml', KarMCP_Filename_Guard::executable_extension_in( 'a.b.phtml.c.jpg' ) );
	}

	public function test_matching_is_case_insensitive(): void {
		$this->assertSame( 'php', KarMCP_Filename_Guard::executable_extension_in( 'photo.PHP.jpg' ) );
		$this->assertSame( 'phar', KarMCP_Filename_Guard::executable_extension_in( 'shell.PhAr.gif' ) );
	}

	public function test_harmless_extra_dots_pass(): void {
		foreach ( array( 'mi.foto.bonita.jpg', 'photo.large.jpg', 'a.b.c.d.png' ) as $name ) {
			$this->assertSame( '', KarMCP_Filename_Guard::executable_extension_in( $name ), $name );
		}
	}

	/**
	 * 'pl' was on the list in 1.33.0 and refused real filenames: as a
	 * two-letter inner segment it collides with the Poland ccTLD and ordinary
	 * abbreviations. It was dropped on purpose — this test is what keeps it
	 * from creeping back in the next sync against someone else's list.
	 */
	public function test_pl_is_not_treated_as_executable(): void {
		$this->assertSame( '', KarMCP_Filename_Guard::executable_extension_in( 'krakow.pl.jpg' ) );
		$this->assertSame( '', KarMCP_Filename_Guard::executable_extension_in( 'onet.pl.foto.jpg' ) );
	}

	public function test_the_whole_php_family_is_refused_inline(): void {
		foreach ( KarMCP_Filename_Guard::PHP_FAMILY as $ext ) {
			$this->assertSame(
				$ext,
				KarMCP_Filename_Guard::executable_extension_in( 'photo.' . $ext . '.jpg' ),
				$ext
			);
		}
	}

	// -----------------------------------------------------------------
	// The malware audit consumes the same list
	// -----------------------------------------------------------------

	private function audit(): KarMCP_Security_Malware_Audit {
		return ( new ReflectionClass( KarMCP_Security_Malware_Audit::class ) )
			->newInstanceWithoutConstructor();
	}

	/**
	 * The drift this architecture ends: before 1.33.1 the audit's hardcoded
	 * regex lacked php8 and phar, so a shell.php8 under uploads was refused at
	 * upload time but invisible to the scanner built to catch exactly it.
	 */
	public function test_the_scanner_flags_every_php_family_extension_under_uploads(): void {
		$audit = $this->audit();
		foreach ( KarMCP_Filename_Guard::PHP_FAMILY as $ext ) {
			$this->assertTrue(
				$audit->is_misplaced_php( 'wp-content/uploads/2026/shell.' . $ext ),
				$ext
			);
		}
	}

	public function test_the_scanner_still_ignores_non_php_files_and_non_uploads(): void {
		$audit = $this->audit();
		$this->assertFalse( $audit->is_misplaced_php( 'wp-content/uploads/2026/photo.jpg' ) );
		// .jsp is refused as an INNER extension at intake, but it is not the
		// PHP family — this server cannot execute it, so the scanner does not
		// report it as misplaced PHP.
		$this->assertFalse( $audit->is_misplaced_php( 'wp-content/uploads/2026/page.jsp' ) );
		$this->assertFalse( $audit->is_misplaced_php( 'wp-includes/functions.php' ) );
	}
}
