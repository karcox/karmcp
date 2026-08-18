<?php
/**
 * Giving an uploaded SVG an intrinsic size when it has none.
 *
 * A viewBox-only SVG is valid, scalable markup — and inside an Elementor image
 * widget it measures 0x0: naturalWidth is 0, `max-width: 100%` resolves against
 * a parent sized by its content, and the element disappears without an error
 * anywhere. The case that found it was a client logo wired as the link back to
 * the index, so what went missing was a navigation control.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/class-svg-icon-abilities.php';

class SvgIntrinsicSizeTest extends TestCase {

	private function dimension( string $svg ): array {
		return KarMCP_Svg_Icon_Abilities::ensure_intrinsic_size( $svg );
	}

	public function test_a_viewbox_only_svg_gets_dimensioned(): void {
		$result = $this->dimension( '<svg viewBox="0 0 720 400" xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' );

		$this->assertTrue( $result['injected'] );
		$this->assertSame( 720.0, $result['width'] );
		$this->assertSame( 400.0, $result['height'] );
		$this->assertStringContainsString( 'width="720"', $result['svg'] );
		$this->assertStringContainsString( 'height="400"', $result['svg'] );
		$this->assertStringContainsString( 'viewBox="0 0 720 400"', $result['svg'], 'The viewBox must survive.' );
		$this->assertStringContainsString( '<path d="M0 0"/>', $result['svg'], 'The body must be untouched.' );
	}

	/**
	 * An SVG that already declares its size is somebody's decision, and not
	 * ours to overwrite.
	 */
	public function test_an_already_dimensioned_svg_is_left_alone(): void {
		$svg    = '<svg width="64" height="32" viewBox="0 0 720 400"><path d="M0 0"/></svg>';
		$result = $this->dimension( $svg );

		$this->assertFalse( $result['injected'] );
		$this->assertSame( $svg, $result['svg'] );
		$this->assertSame( 64.0, $result['width'] );
		$this->assertSame( 32.0, $result['height'] );
	}

	/** Half-dimensioned: only the missing half is written. */
	public function test_only_the_missing_dimension_is_added(): void {
		$result = $this->dimension( '<svg width="100" viewBox="0 0 720 400"></svg>' );

		$this->assertTrue( $result['injected'] );
		$this->assertStringContainsString( 'width="100"', $result['svg'], 'The declared width must survive.' );
		$this->assertStringContainsString( 'height="400"', $result['svg'] );
		$this->assertSame( 1, substr_count( $result['svg'], 'width=' ), 'No duplicate width attribute.' );
	}

	/** No viewBox, nothing to derive from, so nothing is invented. */
	public function test_an_svg_with_no_viewbox_is_left_alone(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>';
		$result = $this->dimension( $svg );

		$this->assertFalse( $result['injected'] );
		$this->assertSame( $svg, $result['svg'] );
		$this->assertSame( 0.0, $result['width'] );
	}

	/**
	 * A percentage width is a real decision too, and reading it as pixels would
	 * be a lie. The file is left alone and the size reported as unknown.
	 */
	public function test_a_percentage_size_is_not_reported_as_pixels(): void {
		$result = $this->dimension( '<svg width="100%" height="100%" viewBox="0 0 720 400"></svg>' );

		$this->assertFalse( $result['injected'] );
		$this->assertSame( 0.0, $result['width'] );
		$this->assertSame( 0.0, $result['height'] );
	}

	public function test_a_fractional_viewbox_does_not_grow_a_trailing_zero(): void {
		$result = $this->dimension( '<svg viewBox="0 0 24.5 12.25"></svg>' );

		$this->assertStringContainsString( 'width="24.5"', $result['svg'] );
		$this->assertStringContainsString( 'height="12.25"', $result['svg'] );
	}

	/** A viewBox with commas and a non-zero origin is still a viewBox. */
	public function test_a_comma_separated_viewbox_with_an_offset_origin(): void {
		$result = $this->dimension( "<svg viewBox='-10,-5, 300 , 150'></svg>" );

		$this->assertTrue( $result['injected'] );
		$this->assertSame( 300.0, $result['width'] );
		$this->assertSame( 150.0, $result['height'] );
	}

	/** A degenerate viewBox gives no usable size, so nothing is written. */
	public function test_a_zero_sized_viewbox_is_refused(): void {
		$result = $this->dimension( '<svg viewBox="0 0 0 0"></svg>' );

		$this->assertFalse( $result['injected'] );
	}

	public function test_markup_that_is_not_an_svg_is_returned_untouched(): void {
		$result = $this->dimension( '<p>no soy un SVG</p>' );

		$this->assertFalse( $result['injected'] );
		$this->assertSame( '<p>no soy un SVG</p>', $result['svg'] );
	}
}
