<?php
/**
 * Public unit tests for KarMCP_Data::strip_media() — the tree walk behind
 * apply-template's `strip_media` flag.
 *
 * Copying a block from a house template used to drag its imagery along with no
 * way to shed it: an element's own `background-image` outranks any stylesheet
 * rule, so a white-label course came out wearing the house's photos until each
 * element was cleaned by hand. Pure array work, so it is pinned here.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-id-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-element-factory.php';
require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';

class StripMediaTest extends \PHPUnit\Framework\TestCase {

	private KarMCP_Data $data;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->data = new KarMCP_Data();
	}

	private function strip( array $elements ): array {
		$removed = 0;
		return array( $this->data->strip_media( $elements, $removed ), $removed );
	}

	public function test_removes_the_reported_keys_and_keeps_the_layout() {
		$tree = array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'settings' => array(
					'background_background'         => 'classic',
					'background_image'              => array( 'url' => 'https://x/hero.jpg', 'id' => 12 ),
					'background_overlay_background' => 'classic',
					'background_overlay_color'      => '#000000',
					'background_overlay_opacity'    => array( 'size' => 0.7, 'unit' => 'px' ),
					'padding'                       => array( 'top' => '64' ),
					'css_classes'                   => 'fet-hero',
				),
				'elements' => array(),
			),
		);

		list( $out, $removed ) = $this->strip( $tree );
		$settings              = $out[0]['settings'];

		$this->assertArrayNotHasKey( 'background_image', $settings );
		$this->assertArrayNotHasKey( 'background_overlay_background', $settings );
		$this->assertArrayNotHasKey( 'background_overlay_color', $settings );
		$this->assertArrayNotHasKey( 'background_overlay_opacity', $settings );
		$this->assertSame( 4, $removed );

		// Layout and identity survive: this strips pictures, not design.
		$this->assertSame( array( 'top' => '64' ), $settings['padding'] );
		$this->assertSame( 'fet-hero', $settings['css_classes'] );
		$this->assertSame( 'classic', $settings['background_background'] );
	}

	public function test_reaches_nested_children() {
		$tree = array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'bbb2222',
						'elType'   => 'container',
						'settings' => array(),
						'elements' => array(
							array(
								'id'         => 'ccc3333',
								'elType'     => 'widget',
								'widgetType' => 'image',
								'settings'   => array(
									'image'                       => array( 'url' => 'https://x/stock.jpg', 'id' => 44 ),
									'image_css_filter_css_filter' => 'custom',
									'align'                       => 'center',
								),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);

		list( $out, $removed ) = $this->strip( $tree );
		$widget                = $out[0]['elements'][0]['elements'][0]['settings'];

		$this->assertArrayNotHasKey( 'image', $widget );
		$this->assertArrayNotHasKey( 'image_css_filter_css_filter', $widget );
		$this->assertSame( 'center', $widget['align'] );
		$this->assertSame( 2, $removed );
	}

	public function test_removes_responsive_overrides_too() {
		// A tablet-only background would otherwise come back at one breakpoint.
		$tree = array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'settings' => array(
					'background_image'        => array( 'url' => 'a.jpg' ),
					'background_image_tablet' => array( 'url' => 'b.jpg' ),
					'background_image_mobile' => array( 'url' => 'c.jpg' ),
				),
				'elements' => array(),
			),
		);

		list( $out, $removed ) = $this->strip( $tree );

		$this->assertSame( array(), $out[0]['settings'] );
		$this->assertSame( 3, $removed );
	}

	public function test_removes_the_global_binding_for_a_stripped_key() {
		// A global bound to the overlay colour keeps painting on its own.
		$tree = array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'settings' => array(
					'background_overlay_color' => '#000000',
					'__globals__'              => array(
						'background_overlay_color' => 'globals/colors?id=0e4689f',
						'title_color'              => 'globals/colors?id=primary',
					),
				),
				'elements' => array(),
			),
		);

		list( $out, $removed ) = $this->strip( $tree );

		$this->assertSame(
			array( 'title_color' => 'globals/colors?id=primary' ),
			$out[0]['settings']['__globals__']
		);
		$this->assertSame( 2, $removed );
	}

	public function test_tree_with_no_media_is_untouched() {
		$tree = array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'settings' => array( 'background_background' => 'gradient', 'background_color' => '#0025FF' ),
				'elements' => array(),
			),
		);

		list( $out, $removed ) = $this->strip( $tree );

		$this->assertSame( $tree, $out );
		$this->assertSame( 0, $removed );
	}
}
