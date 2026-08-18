<?php
/**
 * The bundled brand kits have to satisfy what KarMCP_System_Kit_Writer demands.
 *
 * Applying a kit is all-or-nothing by design: replace_system_colors() aborts
 * the whole palette if any one of the four system slots is missing or carries
 * an unparseable hex, rather than leaving the site half-restyled. That makes a
 * malformed kit in the JSON a runtime failure with no way to catch it short of
 * clicking all ten, so the data gets checked here instead.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class BrandKitBundleTest extends TestCase {

	private const SLOTS = array( 'primary', 'secondary', 'text', 'accent' );

	/**
	 * @return array<int,array{0:string,1:array}> [ kit slug, kit ] per kit.
	 */
	private function kits(): array {
		$path = dirname( __DIR__ ) . '/assets/brand-kits/free-brand-kits.json';
		$this->assertFileExists( $path );
		$data = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $data, 'free-brand-kits.json is not valid JSON.' );

		$out = array();
		foreach ( (array) ( $data['categories'] ?? array() ) as $cat ) {
			foreach ( (array) ( $cat['kits'] ?? array() ) as $kit ) {
				$out[] = array( (string) ( $kit['slug'] ?? '?' ), $kit );
			}
		}
		return $out;
	}

	public function test_the_bundle_actually_ships_kits(): void {
		$this->assertNotEmpty( $this->kits(), 'The Brand Kits tab renders from this file; empty means an empty tab.' );
	}

	public function test_every_kit_has_a_complete_and_valid_palette(): void {
		foreach ( $this->kits() as list( $slug, $kit ) ) {
			$this->assertArrayHasKey( 'colors', $kit, "Kit '$slug' has no colors; apply_kit() rejects it." );
			foreach ( self::SLOTS as $slot ) {
				$this->assertArrayHasKey( $slot, $kit['colors'], "Kit '$slug' is missing the '$slot' color slot." );
				$hex = (string) ( $kit['colors'][ $slot ]['color'] ?? '' );
				$this->assertMatchesRegularExpression(
					'/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i',
					$hex,
					"Kit '$slug' slot '$slot' has hex '$hex', which sanitize_hex_color() rejects — the apply aborts."
				);
			}
		}
	}

	public function test_every_kit_has_all_four_typography_slots(): void {
		foreach ( $this->kits() as list( $slug, $kit ) ) {
			$this->assertArrayHasKey( 'typography', $kit, "Kit '$slug' has no typography; apply_kit() rejects it." );
			foreach ( self::SLOTS as $slot ) {
				$this->assertArrayHasKey( $slot, $kit['typography'], "Kit '$slug' is missing the '$slot' typography slot." );
			}
		}
	}

	/**
	 * apply_theme_style() reads these two to set the site's heading and body
	 * fonts — the step that visibly re-skins the page.
	 */
	public function test_heading_and_body_font_families_are_present(): void {
		foreach ( $this->kits() as list( $slug, $kit ) ) {
			foreach ( array( 'primary', 'text' ) as $slot ) {
				$family = (string) ( $kit['typography'][ $slot ]['font_family'] ?? '' );
				$this->assertNotSame( '', $family, "Kit '$slug' has no font_family on '$slot'; the theme style would apply an empty font." );
			}
		}
	}

	/**
	 * find_kit() resolves by slug, so a duplicate makes one kit unreachable.
	 */
	public function test_kit_slugs_are_unique(): void {
		$slugs = array_column( $this->kits(), 0 );
		$this->assertSame( array_unique( $slugs ), $slugs, 'Duplicate kit slug — find_kit() would always return the first.' );
	}
}
