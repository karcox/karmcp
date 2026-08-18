<?php
/**
 * Telling "this page is empty" apart from "I cannot read this page".
 *
 * They arrive looking identical — both end up as an empty element list — and
 * conflating them is what turned a failed `batch-update` into a wipe: the read
 * came back empty, no element id matched, and the save wrote that emptiness
 * over a page holding 37 KB of content. The distinction is now a predicate
 * because both the read path and the write path ask the question, and they must
 * never answer it differently.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';

class UnreadableElementorDataTest extends \PHPUnit\Framework\TestCase {

	/** A page with nothing on it is readable. It just has nothing. */
	public function test_an_empty_value_is_not_unreadable(): void {
		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( '' ) );
		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( '[]' ) );
	}

	public function test_valid_json_is_not_unreadable(): void {
		$this->assertFalse(
			KarMCP_Data::is_unreadable_elementor_data( '[{"id":"abc1234","elType":"container"}]' )
		);
	}

	/**
	 * The shape the real corruption took: Elementor JSON run through
	 * stripslashes, so every `\"` became a bare `"` and the structure fell
	 * apart. Measured on post 12087 of a live site — 37,529 bytes, zero
	 * backslashes left, and it is those bytes this predicate has to catch.
	 */
	public function test_json_that_lost_its_backslashes_is_unreadable(): void {
		$valid  = '[{"id":"abc1234","settings":{"html":"<a href=\\"https:\/\/x.test\/a\\">Ir</a>","title":"Módulo \u0031"}}]';
		$broken = stripslashes( $valid );

		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( $valid ) );
		$this->assertTrue( KarMCP_Data::is_unreadable_elementor_data( $broken ) );
	}

	/**
	 * The limit of this check, stated so nobody mistakes it for a safety net.
	 *
	 * Unslashing only breaks the JSON when the escapes it eats were load-bearing
	 * — a quote inside a value. Data whose escapes are all `\\/` and `\\uXXXX`
	 * survives as valid JSON and arrives quietly wrong: a URL with its slashes
	 * intact but its `\\u00e1` now reading as the literal text `u00e1`. Nothing
	 * downstream can detect that, which is why the real fix is upstream, in
	 * KarMCP_Post_Duplicator, and this predicate is only the second line.
	 */
	public function test_data_whose_escapes_were_not_load_bearing_still_decodes(): void {
		$valid  = '[{"u":"https:\/\/x.test\/a"}]';
		$broken = stripslashes( $valid );

		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( $broken ) );
		$this->assertNotSame( $valid, $broken, 'The value did change; it just stayed parseable.' );
	}

	public function test_arbitrary_rubbish_is_unreadable(): void {
		$this->assertTrue( KarMCP_Data::is_unreadable_elementor_data( 'not json at all' ) );
		$this->assertTrue( KarMCP_Data::is_unreadable_elementor_data( '{"unclosed": ' ) );
	}

	/**
	 * A bare JSON scalar decodes without error and is not an element list, so
	 * it counts as unreadable too — nothing downstream can walk it.
	 */
	public function test_a_json_scalar_is_unreadable(): void {
		$this->assertTrue( KarMCP_Data::is_unreadable_elementor_data( '"just a string"' ) );
		$this->assertTrue( KarMCP_Data::is_unreadable_elementor_data( '42' ) );
	}

	/** Non-strings never reach the decoder. */
	public function test_non_string_values_are_not_unreadable(): void {
		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( null ) );
		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( array() ) );
		$this->assertFalse( KarMCP_Data::is_unreadable_elementor_data( false ) );
	}
}
