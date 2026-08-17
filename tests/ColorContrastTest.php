<?php
/**
 * KarMCP_Color_Contrast — the WCAG maths and the colours it can read.
 *
 * The formula is specified, so the reference ratios below are not opinions:
 * black on white is exactly 21, and mid grey on white is 4.54, which is the
 * value that decides whether a very common design choice passes AA.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/audits/class-color-contrast.php';

final class ColorContrastTest extends TestCase {

	// -------------------------------------------------------------- ratio

	public function test_black_on_white_is_the_maximum_ratio(): void {
		$black = KarMCP_Color_Contrast::parse( '#000000' );
		$white = KarMCP_Color_Contrast::parse( '#ffffff' );

		$this->assertSame( 21.0, KarMCP_Color_Contrast::ratio( $black, $white ) );
	}

	public function test_a_colour_against_itself_is_one(): void {
		$grey = KarMCP_Color_Contrast::parse( '#777777' );

		$this->assertSame( 1.0, KarMCP_Color_Contrast::ratio( $grey, $grey ) );
	}

	public function test_the_ratio_does_not_depend_on_argument_order(): void {
		$a = KarMCP_Color_Contrast::parse( '#1a1a1a' );
		$b = KarMCP_Color_Contrast::parse( '#cccccc' );

		$this->assertSame(
			KarMCP_Color_Contrast::ratio( $a, $b ),
			KarMCP_Color_Contrast::ratio( $b, $a )
		);
	}

	/**
	 * #767676 on white is the canonical "just passes AA" grey — the darkest
	 * grey a designer can use for body text and still meet 4.5.
	 */
	public function test_the_reference_grey_just_passes_aa(): void {
		$grey  = KarMCP_Color_Contrast::parse( '#767676' );
		$white = KarMCP_Color_Contrast::parse( '#ffffff' );

		$ratio = KarMCP_Color_Contrast::ratio( $grey, $white );

		$this->assertGreaterThanOrEqual( KarMCP_Color_Contrast::AA_NORMAL, $ratio );
		$this->assertLessThan( 4.7, $ratio );
	}

	public function test_luminance_endpoints(): void {
		$this->assertSame( 0.0, round( KarMCP_Color_Contrast::luminance( array( 'r' => 0, 'g' => 0, 'b' => 0 ) ), 4 ) );
		$this->assertSame( 1.0, round( KarMCP_Color_Contrast::luminance( array( 'r' => 255, 'g' => 255, 'b' => 255 ) ), 4 ) );
	}

	// -------------------------------------------------------------- parse

	public function test_parses_long_and_short_hex(): void {
		$long  = KarMCP_Color_Contrast::parse( '#aabbcc' );
		$short = KarMCP_Color_Contrast::parse( '#abc' );

		$this->assertSame( $long, $short, '#abc must expand to #aabbcc' );
		$this->assertSame( 170, $long['r'] );
		$this->assertSame( 1.0, $long['a'] );
	}

	public function test_parses_hex_with_alpha(): void {
		$color = KarMCP_Color_Contrast::parse( '#ffffff80' );

		$this->assertSame( 255, $color['r'] );
		$this->assertLessThan( 1.0, $color['a'] );
	}

	public function test_parses_rgb_and_rgba_in_both_syntaxes(): void {
		$comma  = KarMCP_Color_Contrast::parse( 'rgb(18, 52, 86)' );
		$modern = KarMCP_Color_Contrast::parse( 'rgb(18 52 86)' );

		$this->assertSame( $comma, $modern );
		$this->assertSame( 18, $comma['r'] );

		$alpha = KarMCP_Color_Contrast::parse( 'rgba(0, 0, 0, 0.5)' );
		$this->assertSame( 0.5, $alpha['a'] );

		$slash = KarMCP_Color_Contrast::parse( 'rgb(0 0 0 / 0.5)' );
		$this->assertSame( 0.5, $slash['a'] );
	}

	public function test_parses_named_colours(): void {
		$this->assertSame( 255, KarMCP_Color_Contrast::parse( 'white' )['r'] );
		$this->assertSame( 0, KarMCP_Color_Contrast::parse( 'black' )['r'] );
		$this->assertSame( KarMCP_Color_Contrast::parse( 'grey' ), KarMCP_Color_Contrast::parse( 'gray' ) );
	}

	public function test_case_and_whitespace_are_ignored(): void {
		$this->assertSame(
			KarMCP_Color_Contrast::parse( '#AABBCC' ),
			KarMCP_Color_Contrast::parse( '  #aabbcc ' )
		);
	}

	/**
	 * Anything we cannot read must come back as null so the caller reports
	 * inconclusive. Returning a default colour here would be inventing the
	 * answer.
	 */
	public function test_unreadable_colours_return_null(): void {
		$this->assertNull( KarMCP_Color_Contrast::parse( 'transparent' ) );
		$this->assertNull( KarMCP_Color_Contrast::parse( 'currentColor' ) );
		$this->assertNull( KarMCP_Color_Contrast::parse( 'inherit' ) );
		$this->assertNull( KarMCP_Color_Contrast::parse( 'var(--mi-color)' ) );
		$this->assertNull( KarMCP_Color_Contrast::parse( 'hsl(200, 50%, 40%)' ) );
		$this->assertNull( KarMCP_Color_Contrast::parse( '#12345' ) );
		$this->assertNull( KarMCP_Color_Contrast::parse( '' ) );
	}

	// ------------------------------------------------------- large text

	public function test_large_text_thresholds(): void {
		$this->assertTrue( KarMCP_Color_Contrast::is_large( 24.0 ) );
		$this->assertFalse( KarMCP_Color_Contrast::is_large( 23.0 ) );

		// Bold text qualifies earlier, at 14pt.
		$this->assertTrue( KarMCP_Color_Contrast::is_large( 19.0, true ) );
		$this->assertFalse( KarMCP_Color_Contrast::is_large( 18.0, true ) );
	}

	public function test_required_ratio_by_size_and_level(): void {
		$this->assertSame( 4.5, KarMCP_Color_Contrast::required( false ) );
		$this->assertSame( 3.0, KarMCP_Color_Contrast::required( true ) );
		$this->assertSame( 7.0, KarMCP_Color_Contrast::required( false, 'AAA' ) );
		$this->assertSame( 4.5, KarMCP_Color_Contrast::required( true, 'AAA' ) );
	}

	// --------------------------------------------------------- evaluate

	public function test_evaluates_a_clear_pass_and_a_clear_fail(): void {
		$pass = KarMCP_Color_Contrast::evaluate( '#000000', '#ffffff' );
		$this->assertSame( 'pass', $pass['status'] );
		$this->assertSame( 21.0, $pass['ratio'] );

		$fail = KarMCP_Color_Contrast::evaluate( '#cccccc', '#ffffff' );
		$this->assertSame( 'fail', $fail['status'] );
	}

	/**
	 * The same pair can pass as a heading and fail as body text.
	 */
	public function test_size_changes_the_verdict(): void {
		$body    = KarMCP_Color_Contrast::evaluate( '#949494', '#ffffff', 16.0 );
		$heading = KarMCP_Color_Contrast::evaluate( '#949494', '#ffffff', 32.0 );

		$this->assertSame( 'fail', $body['status'] );
		$this->assertSame( 'pass', $heading['status'] );
	}

	/**
	 * The rule the roadmap set before a line was written: when the answer would
	 * depend on something not visible here, say so. A false pass closes the
	 * question and is worse than no check.
	 */
	public function test_an_unknown_colour_is_inconclusive_never_a_pass(): void {
		$no_background = KarMCP_Color_Contrast::evaluate( '#000000', null );

		$this->assertSame( 'inconclusive', $no_background['status'] );
		$this->assertSame( 'background_unknown', $no_background['reason'] );
		$this->assertArrayNotHasKey( 'ratio', $no_background );
	}

	public function test_a_variable_or_inherited_colour_is_inconclusive(): void {
		$this->assertSame( 'inconclusive', KarMCP_Color_Contrast::evaluate( 'var(--texto)', '#ffffff' )['status'] );
		$this->assertSame( 'inconclusive', KarMCP_Color_Contrast::evaluate( '#000000', 'transparent' )['status'] );
	}

	/**
	 * A translucent colour composites against whatever is painted underneath,
	 * which is exactly what cannot be seen from the markup.
	 */
	public function test_translucency_is_inconclusive(): void {
		$result = KarMCP_Color_Contrast::evaluate( 'rgba(0,0,0,0.6)', '#ffffff' );

		$this->assertSame( 'inconclusive', $result['status'] );
		$this->assertSame( 'translucent', $result['reason'] );
	}

	// ------------------------------------------------ size not knowable

	/**
	 * Font sizes normally come from a stylesheet, so "unknown" is the common
	 * case rather than the exception. Two thirds of the range still have a
	 * rigorous answer, and only the band between the two thresholds actually
	 * depends on the size.
	 */
	public function test_unknown_size_still_passes_what_passes_at_any_size(): void {
		$result = KarMCP_Color_Contrast::evaluate( '#000000', '#ffffff', null );

		$this->assertSame( 'pass', $result['status'] );
		$this->assertSame( 4.5, $result['required'] );
	}

	public function test_unknown_size_still_fails_what_fails_at_any_size(): void {
		$result = KarMCP_Color_Contrast::evaluate( '#dddddd', '#ffffff', null );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertSame( 3.0, $result['required'] );
	}

	public function test_unknown_size_is_inconclusive_only_between_the_thresholds(): void {
		// #949494 on white lands around 3.4:1 — passes as a heading, fails as
		// body text, so without the size there is genuinely no answer.
		$result = KarMCP_Color_Contrast::evaluate( '#949494', '#ffffff', null );

		$this->assertSame( 'inconclusive', $result['status'] );
		$this->assertSame( 'size_unknown', $result['reason'] );
		$this->assertGreaterThanOrEqual( 3.0, $result['ratio'] );
		$this->assertLessThan( 4.5, $result['ratio'] );
	}

	public function test_inconclusive_still_reports_what_was_required(): void {
		$result = KarMCP_Color_Contrast::evaluate( '#000000', null, 32.0 );

		$this->assertTrue( $result['large'] );
		$this->assertSame( 3.0, $result['required'] );
	}
}
