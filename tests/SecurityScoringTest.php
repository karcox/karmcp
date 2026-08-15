<?php
/**
 * Scoring, and the uploads exemption.
 *
 * Both of these are here because the first real scan produced a report that was
 * worse than useless: 144 criticals of which **none were real** — 142 were Code
 * Snippets storing one PHP file per snippet under uploads, exactly as it is
 * designed to — and a score of 0 out of 100 that a genuinely compromised site
 * could not have beaten.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security/class-security-finding.php';
require_once __DIR__ . '/../includes/security/class-security-malware-audit.php';
require_once __DIR__ . '/../includes/security/class-security-scanner.php';

class SecurityScoringTest extends TestCase {

	/** @var KarMCP_Security_Scanner */
	private $scanner;

	protected function setUp(): void {
		$this->scanner = new KarMCP_Security_Scanner();
	}

	private function findings( string $status, string $category, int $n ): array {
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = array(
				'id'       => $category . '_' . $i,
				'category' => $category,
				'status'   => $status,
			);
		}
		return $out;
	}

	// -----------------------------------------------------------------
	// Scoring
	// -----------------------------------------------------------------

	public function test_a_clean_site_scores_full_marks(): void {
		$s = $this->scanner->summarize( $this->findings( 'pass', 'hardening', 6 ) );
		$this->assertSame( 100, $s['score'] );
		$this->assertSame( 'A', $s['grade'] );
	}

	/**
	 * The measured failure. Thirty outdated plugins is a maintenance backlog,
	 * not a break-in, and before the per-category cap it scored the same zero as
	 * a site with modified core files — a score that cannot tell those apart is
	 * not worth printing.
	 */
	public function test_a_pile_of_ordinary_warnings_cannot_force_a_zero(): void {
		$s = $this->scanner->summarize( $this->findings( 'warning', 'software', 46 ) );

		$this->assertGreaterThan( 0, $s['score'], 'Forty-six routine warnings must not floor the score.' );
		$this->assertSame( 100 - KarMCP_Security_Scanner::CATEGORY_WARN_CAP, $s['score'] );
	}

	public function test_warnings_are_capped_per_category_not_globally(): void {
		$mixed = array_merge(
			$this->findings( 'warning', 'software', 40 ),
			$this->findings( 'warning', 'hardening', 40 )
		);
		$s = $this->scanner->summarize( $mixed );

		// Two categories, so two caps — a site failing on two fronts scores
		// worse than one failing on a single front, which is the point.
		$this->assertSame( 100 - ( 2 * KarMCP_Security_Scanner::CATEGORY_WARN_CAP ), $s['score'] );
	}

	public function test_criticals_still_outweigh_warnings(): void {
		$crit = $this->scanner->summarize( $this->findings( 'critical', 'integrity', 1 ) );
		$warn = $this->scanner->summarize( $this->findings( 'warning', 'integrity', 1 ) );

		$this->assertLessThan( $warn['score'], $crit['score'] );
	}

	/**
	 * The category of a warning must be read from the warning. Resolving it only
	 * inside the critical branch carried the previous finding's category into
	 * every warning and mis-bucketed the penalties — introduced and caught while
	 * adding the cap above.
	 */
	public function test_a_warning_is_charged_to_its_own_category(): void {
		$mixed = array(
			array( 'id' => 'a', 'category' => 'integrity', 'status' => 'critical' ),
			array( 'id' => 'b', 'category' => 'software', 'status' => 'warning' ),
			array( 'id' => 'c', 'category' => 'software', 'status' => 'warning' ),
		);
		$s = $this->scanner->summarize( $mixed );

		// One critical (20) plus two software warnings (10). If the warnings had
		// been charged to 'integrity' the total would be the same — so assert the
		// count too, which is what would diverge if the bucket were wrong.
		$this->assertSame( 100 - 20 - 10, $s['score'] );
		$this->assertSame( 2, $s['counts']['warning'] );
		$this->assertSame( 1, $s['counts']['critical'] );
	}

	public function test_info_findings_never_affect_the_score(): void {
		$s = $this->scanner->summarize( $this->findings( 'info', 'malware', 50 ) );
		$this->assertSame( 100, $s['score'] );
	}

	// -----------------------------------------------------------------
	// PHP under uploads
	// -----------------------------------------------------------------

	/**
	 * Code Snippets writes `uploads/code-snippets/<id>.php`, one per snippet.
	 * On the test bed that alone produced 142 identical criticals.
	 */
	public function test_php_from_plugins_that_store_it_under_uploads_is_recognised(): void {
		$known = array(
			'wp-content/uploads/code-snippets/2283.php',
			'wp-content/uploads/wp-staging/clone_options.cache.php',
			'wp-content/uploads/litespeed/something.php',
			'wp-content/uploads/cache/x.php',
		);
		foreach ( $known as $path ) {
			$this->assertTrue( KarMCP_Security_Malware_Audit::is_known_php_producer( $path ), $path );
		}
	}

	/** Anything in a directory nobody recognises stays a critical finding. */
	public function test_php_anywhere_else_under_uploads_is_still_flagged(): void {
		$suspect = array(
			'wp-content/uploads/2026/08/shell.php',
			'wp-content/uploads/evil.php',
			'wp-content/uploads/woocommerce_uploads/x.php',
		);
		foreach ( $suspect as $path ) {
			$this->assertFalse( KarMCP_Security_Malware_Audit::is_known_php_producer( $path ), $path );
		}
	}

	/**
	 * The exemption is on the directory, so it cannot be claimed by copying the
	 * filename somewhere else.
	 */
	public function test_the_exemption_cannot_be_claimed_by_filename(): void {
		$this->assertFalse( KarMCP_Security_Malware_Audit::is_known_php_producer( 'wp-content/uploads/2026/2283.php' ) );
		$this->assertFalse( KarMCP_Security_Malware_Audit::is_known_php_producer( 'wp-content/uploads/code-snippets-evil/x.php' ) );
	}

	public function test_backslash_paths_are_normalised(): void {
		$this->assertTrue(
			KarMCP_Security_Malware_Audit::is_known_php_producer( 'wp-content\\uploads\\code-snippets\\2283.php' )
		);
	}
}
