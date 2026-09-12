<?php
/**
 * Returning a bounded slice of a rendered page, and being able to ask for the
 * rest.
 *
 * A themed page routinely runs past the 200 KB cap, and the response used to
 * stop there with nothing saying so: the agent read a document whose closing
 * markup it had never seen and reasoned from the absence. Two things fix that
 * and both have to be right — the cut has to land on a character boundary, and
 * the response has to carry where it stopped.
 *
 * The cut is the half that used to be wrong in a way no one would notice: it
 * dropped one good byte whenever the boundary was already clean, which on
 * ASCII markup means the last character of every truncated response was eaten.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-content-extractor.php';

class HtmlChunkTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	// ---------------------------------------------------------------------
	// The cut
	// ---------------------------------------------------------------------

	public function test_a_string_within_the_limit_comes_back_whole() {
		$this->assertSame( 'abcdef', KarMCP_Content_Extractor::utf8_cut( 'abcdef', 10 ) );
		$this->assertSame( 'abcdef', KarMCP_Content_Extractor::utf8_cut( 'abcdef', 6 ) );
	}

	public function test_a_clean_boundary_keeps_every_byte_it_is_allowed() {
		// The version this replaced returned 'abcd' here: it walked back off a
		// partial sequence that was not there.
		$this->assertSame( 'abcde', KarMCP_Content_Extractor::utf8_cut( 'abcdefgh', 5 ) );
	}

	public function test_a_multibyte_character_is_never_cut_in_half() {
		// 'é' is two bytes; a limit of 2 lands between them.
		$cut = KarMCP_Content_Extractor::utf8_cut( 'aéb', 2 );

		$this->assertSame( 'a', $cut );
		$this->assertSame( $cut, mb_convert_encoding( $cut, 'UTF-8', 'UTF-8' ), 'The slice must be valid UTF-8.' );
	}

	public function test_a_multibyte_character_that_fits_exactly_is_kept() {
		$this->assertSame( 'aé', KarMCP_Content_Extractor::utf8_cut( 'aéb', 3 ) );
	}

	public function test_a_four_byte_character_is_all_or_nothing() {
		$emoji = "\u{1F600}"; // 4 bytes.

		$this->assertSame( 'x', KarMCP_Content_Extractor::utf8_cut( 'x' . $emoji, 3 ) );
		$this->assertSame( 'x' . $emoji, KarMCP_Content_Extractor::utf8_cut( 'x' . $emoji, 5 ) );
	}

	public function test_a_limit_of_zero_or_less_yields_nothing() {
		$this->assertSame( '', KarMCP_Content_Extractor::utf8_cut( 'abc', 0 ) );
		$this->assertSame( '', KarMCP_Content_Extractor::utf8_cut( 'abc', -5 ) );
	}

	// ---------------------------------------------------------------------
	// The continuation
	// ---------------------------------------------------------------------

	public function test_a_page_within_the_cap_reports_no_next_offset() {
		$out = KarMCP_Content_Extractor::attach_html( array(), '<html></html>', 0 );

		$this->assertSame( '<html></html>', $out['html'] );
		$this->assertNull( $out['html_chunk']['next_offset'] );
		$this->assertSame( 13, $out['html_chunk']['total_bytes'] );
	}

	public function test_a_page_over_the_cap_says_where_it_stopped() {
		$html = str_repeat( 'a', KarMCP_Content_Extractor::MAX_ECHO_BYTES + 500 );
		$out  = KarMCP_Content_Extractor::attach_html( array(), $html, 0 );

		$this->assertSame( KarMCP_Content_Extractor::MAX_ECHO_BYTES, strlen( $out['html'] ) );
		$this->assertSame( KarMCP_Content_Extractor::MAX_ECHO_BYTES, $out['html_chunk']['next_offset'] );
		$this->assertSame( strlen( $html ), $out['html_chunk']['total_bytes'] );
	}

	public function test_resuming_from_the_offset_returns_the_remainder_and_ends() {
		$html  = str_repeat( 'a', KarMCP_Content_Extractor::MAX_ECHO_BYTES + 500 );
		$first = KarMCP_Content_Extractor::attach_html( array(), $html, 0 );
		$rest  = KarMCP_Content_Extractor::attach_html( array(), $html, $first['html_chunk']['next_offset'] );

		$this->assertSame( 500, strlen( $rest['html'] ) );
		$this->assertNull( $rest['html_chunk']['next_offset'] );
		$this->assertSame( $html, $first['html'] . $rest['html'], 'The two slices have to rebuild the document exactly.' );
	}

	public function test_the_checksum_is_of_the_whole_document_not_the_slice() {
		$html  = str_repeat( 'a', KarMCP_Content_Extractor::MAX_ECHO_BYTES + 500 );
		$first = KarMCP_Content_Extractor::attach_html( array(), $html, 0 );
		$rest  = KarMCP_Content_Extractor::attach_html( array(), $html, $first['html_chunk']['next_offset'] );

		$this->assertSame(
			$first['html_chunk']['checksum'],
			$rest['html_chunk']['checksum'],
			'Both calls describe the same document, so a caller can tell it did not change under them.'
		);

		$changed = KarMCP_Content_Extractor::attach_html( array(), $html . 'x', 0 );
		$this->assertNotSame( $first['html_chunk']['checksum'], $changed['html_chunk']['checksum'] );
	}

	public function test_an_offset_past_the_end_yields_an_empty_final_slice() {
		$out = KarMCP_Content_Extractor::attach_html( array(), 'abc', 99 );

		$this->assertSame( '', $out['html'] );
		$this->assertNull( $out['html_chunk']['next_offset'] );
		$this->assertSame( 3, $out['html_chunk']['offset'], 'The offset is clamped to the document rather than echoed back.' );
	}
}
