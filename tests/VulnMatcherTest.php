<?php
/**
 * Version-range matching and the feed splitter.
 *
 * Both are pure and both are load-bearing in a way that is easy to
 * underestimate: a mistake in either does not raise an error, it reports a
 * clean site. Being silently wrong in the reassuring direction is the specific
 * failure this whole module exists to prevent, so these are the tests that
 * matter most in it.
 *
 * The range cases are taken from real Wordfence records for plugins installed
 * on the test bed, not invented.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/modules/vulnerabilities/class-vuln-matcher.php';
require_once __DIR__ . '/../includes/modules/vulnerabilities/class-vuln-stream-parser.php';

class VulnMatcherTest extends TestCase {

	private function range( $from, $to, bool $fi = true, bool $ti = true ): array {
		return array(
			'from_version'   => $from,
			'from_inclusive' => $fi,
			'to_version'     => $to,
			'to_inclusive'   => $ti,
		);
	}

	// -----------------------------------------------------------------
	// compare()
	// -----------------------------------------------------------------

	public function test_ordinary_versions_compare(): void {
		$this->assertSame( -1, KarMCP_Vuln_Matcher::compare( '1.2.3', '1.2.4' ) );
		$this->assertSame( 1, KarMCP_Vuln_Matcher::compare( '1.3.0', '1.2.9' ) );
		$this->assertSame( 0, KarMCP_Vuln_Matcher::compare( '1.2.3', '1.2.3' ) );
	}

	/** WordPress plugins ship these routinely; semver-only comparers drop the fourth part. */
	public function test_four_component_versions_compare(): void {
		$this->assertSame( -1, KarMCP_Vuln_Matcher::compare( '3.5.6.1', '3.5.6.2' ) );
		$this->assertSame( 1, KarMCP_Vuln_Matcher::compare( '3.8.10.2', '3.8.10.1' ) );
		// 3.8.10 is greater than 3.8.9, not less, whatever string sorting says.
		$this->assertSame( 1, KarMCP_Vuln_Matcher::compare( '3.8.10', '3.8.9' ) );
	}

	public function test_a_missing_part_counts_as_zero(): void {
		$this->assertSame( 0, KarMCP_Vuln_Matcher::compare( '1.2', '1.2.0' ) );
		$this->assertSame( 0, KarMCP_Vuln_Matcher::compare( '4', '4.0.0' ) );
	}

	public function test_a_prerelease_sorts_below_its_release(): void {
		$this->assertSame( -1, KarMCP_Vuln_Matcher::compare( '1.0.0-beta2', '1.0.0' ) );
		$this->assertSame( -1, KarMCP_Vuln_Matcher::compare( '2.0.0-rc1', '2.0.0' ) );
	}

	public function test_a_leading_v_is_ignored(): void {
		$this->assertSame( 0, KarMCP_Vuln_Matcher::compare( 'v1.2.3', '1.2.3' ) );
	}

	// -----------------------------------------------------------------
	// in_range()
	// -----------------------------------------------------------------

	public function test_a_version_inside_a_closed_range_is_affected(): void {
		$r = $this->range( '1.0.0', '1.2.3' );
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '1.1.0', $r ) );
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '1.0.0', $r ) );
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '1.2.3', $r ) );
	}

	public function test_a_version_outside_a_closed_range_is_not(): void {
		$r = $this->range( '1.0.0', '1.2.3' );
		$this->assertFalse( KarMCP_Vuln_Matcher::in_range( '0.9.9', $r ) );
		$this->assertFalse( KarMCP_Vuln_Matcher::in_range( '1.2.4', $r ) );
	}

	/**
	 * The off-by-one that matters. `to_inclusive: false` on 3.8.9.1 means that
	 * exact version is already patched — reporting it as vulnerable sends
	 * somebody to update software that is fine.
	 */
	public function test_exclusive_ends_are_respected(): void {
		$this->assertFalse( KarMCP_Vuln_Matcher::in_range( '3.8.9.1', $this->range( '1.0', '3.8.9.1', true, false ) ) );
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '3.8.9.0', $this->range( '1.0', '3.8.9.1', true, false ) ) );
		$this->assertFalse( KarMCP_Vuln_Matcher::in_range( '1.0', $this->range( '1.0', '2.0', false, true ) ) );
	}

	/**
	 * "everything up to X" arrives as from_version "*". Treating it as a literal
	 * makes every comparison fail and the site is reported clean — wrong in the
	 * reassuring direction, which is the worst way to be wrong here.
	 */
	public function test_a_star_bound_means_unbounded(): void {
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '0.0.1', $this->range( '*', '3.0.0' ) ) );
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '99.0', $this->range( '3.0.0', '*' ) ) );
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '5.5', $this->range( '*', '*' ) ) );
		$this->assertFalse( KarMCP_Vuln_Matcher::in_range( '3.0.1', $this->range( '*', '3.0.0' ) ) );
	}

	public function test_an_empty_bound_behaves_like_unbounded(): void {
		$this->assertTrue( KarMCP_Vuln_Matcher::in_range( '0.1', $this->range( '', '3.0.0' ) ) );
	}

	public function test_any_of_several_ranges_is_enough(): void {
		$ranges = array(
			'a' => $this->range( '1.0', '1.5' ),
			'b' => $this->range( '2.0', '2.5' ),
		);
		$this->assertTrue( KarMCP_Vuln_Matcher::is_affected( '2.1', $ranges ) );
		$this->assertFalse( KarMCP_Vuln_Matcher::is_affected( '1.7', $ranges ) );
	}

	public function test_an_empty_installed_version_matches_nothing(): void {
		$this->assertFalse( KarMCP_Vuln_Matcher::is_affected( '', array( $this->range( '*', '*' ) ) ) );
	}

	// -----------------------------------------------------------------
	// fixes() — what earns the Package Guard exception
	// -----------------------------------------------------------------

	public function test_an_update_that_leaves_the_range_fixes_it(): void {
		$software = array(
			'patched'           => true,
			'patched_versions'  => array( '1.2.4' ),
			'affected_versions' => array( $this->range( '*', '1.2.3' ) ),
		);
		$this->assertTrue( KarMCP_Vuln_Matcher::fixes( '1.2.4', $software ) );
		$this->assertTrue( KarMCP_Vuln_Matcher::fixes( '2.0.0', $software ) );
	}

	public function test_an_update_still_inside_the_range_fixes_nothing(): void {
		$software = array(
			'patched'           => true,
			'patched_versions'  => array( '1.2.4' ),
			'affected_versions' => array( $this->range( '*', '1.2.3' ) ),
		);
		$this->assertFalse( KarMCP_Vuln_Matcher::fixes( '1.2.2', $software ) );
	}

	/** No patch exists, so no update can be claimed to fix it. */
	public function test_an_unpatched_vulnerability_is_never_fixed_by_updating(): void {
		$software = array(
			'patched'           => false,
			'affected_versions' => array( $this->range( '*', '*' ) ),
		);
		$this->assertFalse( KarMCP_Vuln_Matcher::fixes( '99.0', $software ) );
	}

	// -----------------------------------------------------------------
	// The stream splitter
	// -----------------------------------------------------------------

	public function test_it_splits_a_root_object_into_records(): void {
		$json = '{"a":{"id":"a"},"b":{"id":"b"}}';
		$out  = KarMCP_Vuln_Stream_Parser::split( $json );

		$this->assertSame( array( 'a', 'b' ), array_keys( $out['records'] ) );
		$this->assertSame( 'a', json_decode( $out['records']['a'], true )['id'] );
	}

	public function test_it_handles_nested_objects(): void {
		$json = '{"a":{"software":[{"slug":"x","affected_versions":{"1-2":{"from_version":"1"}}}]}}';
		$out  = KarMCP_Vuln_Stream_Parser::split( $json );

		$decoded = json_decode( $out['records']['a'], true );
		$this->assertSame( 'x', $decoded['software'][0]['slug'] );
	}

	/**
	 * The trap the hand-written scanner exists for: a brace inside a string is
	 * not a delimiter. A naive counter breaks on the first description that
	 * contains one, and breaks silently.
	 */
	public function test_braces_inside_strings_do_not_confuse_it(): void {
		$json = '{"a":{"description":"use {this} and }that{"},"b":{"id":"b"}}';
		$out  = KarMCP_Vuln_Stream_Parser::split( $json );

		$this->assertSame( array( 'a', 'b' ), array_keys( $out['records'] ) );
		$this->assertSame( 'use {this} and }that{', json_decode( $out['records']['a'], true )['description'] );
	}

	/** An escaped quote does not end the string, so the braces after it still hide. */
	public function test_escaped_quotes_do_not_confuse_it(): void {
		$json = '{"a":{"t":"a \" quote and a { brace"},"b":{"id":"b"}}';
		$out  = KarMCP_Vuln_Stream_Parser::split( $json );

		$this->assertSame( array( 'a', 'b' ), array_keys( $out['records'] ) );
	}

	/**
	 * The reason it returns a remainder at all: on a real feed it is fed chunks,
	 * and a record cut in half must wait for the rest rather than be emitted
	 * truncated.
	 */
	public function test_a_truncated_record_is_held_back_and_completes_on_the_next_chunk(): void {
		$whole = '{"a":{"id":"a"},"b":{"id":"b"}}';
		$cut   = 20; // Lands inside record "b".

		$first = KarMCP_Vuln_Stream_Parser::split( substr( $whole, 0, $cut ) );
		$this->assertSame( array( 'a' ), array_keys( $first['records'] ) );

		$second = KarMCP_Vuln_Stream_Parser::split( $first['remainder'] . substr( $whole, $cut ), true );
		$this->assertSame( array( 'b' ), array_keys( $second['records'] ) );
	}

	public function test_whitespace_and_newlines_between_records_are_tolerated(): void {
		$json = "{\n  \"a\" : {\"id\":\"a\"} ,\n  \"b\" : {\"id\":\"b\"}\n}";
		$out  = KarMCP_Vuln_Stream_Parser::split( $json );

		$this->assertSame( array( 'a', 'b' ), array_keys( $out['records'] ) );
	}

	public function test_an_empty_document_yields_nothing(): void {
		$out = KarMCP_Vuln_Stream_Parser::split( '{}' );
		$this->assertSame( array(), $out['records'] );
	}
}
