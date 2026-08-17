<?php
/**
 * Public unit tests for the three silent write bugs found while building two
 * full courses through MCP: settings that save with `success: true`, read back
 * correctly, and render wrong.
 *
 *  - The CSS-class key is spelled `_css_classes` on widgets and `css_classes`
 *    on every other classic element type. Sending the wrong one stores the
 *    class where Elementor never looks for it.
 *  - `null` has to mean "delete this key", or a setting can only ever be
 *    overwritten, never removed.
 *  - The background activator must be decided on the element's merged settings.
 *    Deciding it on the incoming payload downgrades an existing gradient to a
 *    flat colour on an update that never mentioned the background.
 *
 * All three are pure array work, so they are pinned here rather than left to
 * manual front-end checks.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-id-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-element-factory.php';
require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';

class ElementSettingsWriteTest extends \PHPUnit\Framework\TestCase {

	private KarMCP_Data $data;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->data = new KarMCP_Data();
	}

	/**
	 * Builds a one-element tree.
	 *
	 * @param string $el_type  Element type.
	 * @param array  $settings Starting settings.
	 * @param array  $extra    Extra top-level element keys (e.g. widgetType).
	 * @return array
	 */
	private function tree( string $el_type, array $settings = array(), array $extra = array() ): array {
		return array(
			array_merge(
				array(
					'id'       => 'abc1234',
					'elType'   => $el_type,
					'settings' => $settings,
					'elements' => array(),
				),
				$extra
			),
		);
	}

	private function update( array $data, array $settings ): array {
		$this->assertTrue( $this->data->update_element_settings( $data, 'abc1234', $settings ) );
		return $data[0]['settings'];
	}

	// -- CSS classes -------------------------------------------------------

	public function test_container_gets_the_unprefixed_key() {
		$settings = $this->update( $this->tree( 'container' ), array( '_css_classes' => 'fet-hero' ) );

		$this->assertSame( 'fet-hero', $settings['css_classes'] );
		$this->assertArrayNotHasKey( '_css_classes', $settings );
	}

	public function test_widget_gets_the_prefixed_key() {
		$settings = $this->update(
			$this->tree( 'widget', array(), array( 'widgetType' => 'heading' ) ),
			array( 'css_classes' => 'fet-titulo' )
		);

		$this->assertSame( 'fet-titulo', $settings['_css_classes'] );
		$this->assertArrayNotHasKey( 'css_classes', $settings );
	}

	public function test_correct_key_is_left_alone() {
		$settings = $this->update( $this->tree( 'container' ), array( 'css_classes' => 'ya-bien' ) );

		$this->assertSame( 'ya-bien', $settings['css_classes'] );
		$this->assertArrayNotHasKey( '_css_classes', $settings );
	}

	public function test_atomic_classes_prop_is_not_touched() {
		$data     = $this->tree( 'e-flexbox', array( 'classes' => array( '$$type' => 'classes', 'value' => array( 'g-1' ) ) ) );
		$settings = $this->update( $data, array( '_css_classes' => 'no-tocar' ) );

		// The typed prop survives, and nothing was rewritten into a classic key.
		$this->assertSame( array( 'g-1' ), $settings['classes']['value'] );
		$this->assertSame( 'no-tocar', $settings['_css_classes'] );
		$this->assertArrayNotHasKey( 'css_classes', $settings );
	}

	// -- null deletes ------------------------------------------------------

	public function test_null_removes_the_key() {
		$data     = $this->tree( 'container', array( 'background_image' => array( 'url' => 'x.jpg', 'id' => 9 ), 'padding' => 10 ) );
		$settings = $this->update( $data, array( 'background_image' => null ) );

		$this->assertArrayNotHasKey( 'background_image', $settings );
		$this->assertSame( 10, $settings['padding'] );
	}

	public function test_null_never_persists_as_a_value() {
		$settings = $this->update( $this->tree( 'container' ), array( 'image_css_filter_css_filter' => null ) );

		$this->assertArrayNotHasKey( 'image_css_filter_css_filter', $settings );
	}

	public function test_null_deletes_the_aliased_key_that_was_actually_stored() {
		// `justify_content` is stored as `flex_justify_content`, so the deletion
		// has to travel through the same alias map as a write.
		$data     = $this->tree( 'container', array( 'flex_justify_content' => 'center' ) );
		$settings = $this->update( $data, array( 'justify_content' => null ) );

		$this->assertArrayNotHasKey( 'flex_justify_content', $settings );
		$this->assertArrayNotHasKey( 'justify_content', $settings );
	}

	public function test_null_deletes_the_class_key_that_was_actually_stored() {
		$data     = $this->tree( 'container', array( 'css_classes' => 'fet-hero' ) );
		$settings = $this->update( $data, array( '_css_classes' => null ) );

		$this->assertArrayNotHasKey( 'css_classes', $settings );
		$this->assertArrayNotHasKey( '_css_classes', $settings );
	}

	// -- background activator ---------------------------------------------

	public function test_existing_gradient_survives_a_colour_only_update() {
		$data = $this->tree(
			'container',
			array(
				'background_background'   => 'gradient',
				'background_color'        => '#0025FF',
				'background_color_b'      => '#2E86FF',
				'background_gradient_type' => 'linear',
			)
		);

		$settings = $this->update( $data, array( 'background_color' => '#0030AA' ) );

		$this->assertSame( 'gradient', $settings['background_background'] );
		$this->assertSame( '#0030AA', $settings['background_color'] );
		$this->assertSame( '#2E86FF', $settings['background_color_b'] );
	}

	public function test_activator_is_still_injected_when_there_is_none() {
		$settings = $this->update( $this->tree( 'container' ), array( 'background_color' => '#FFFFFF' ) );

		$this->assertSame( 'classic', $settings['background_background'] );
	}

	public function test_activator_is_not_injected_onto_a_deleted_background() {
		$data = $this->tree(
			'container',
			array( 'background_background' => 'classic', 'background_color' => '#FFFFFF' )
		);

		$settings = $this->update( $data, array( 'background_color' => null, 'background_background' => null ) );

		$this->assertArrayNotHasKey( 'background_background', $settings );
		$this->assertArrayNotHasKey( 'background_color', $settings );
	}

	public function test_widget_background_still_normalizes_on_update() {
		$settings = $this->update(
			$this->tree( 'widget', array(), array( 'widgetType' => 'heading' ) ),
			array( 'background' => array( 'background_color' => '#101010' ) )
		);

		$this->assertSame( '#101010', $settings['background_color'] );
		$this->assertSame( 'classic', $settings['background_background'] );
	}

	// -- built-with-elementor flag ----------------------------------------

	public function test_mark_built_with_elementor_sets_the_flag() {
		KarMCP_Data::mark_built_with_elementor( 4242 );

		$this->assertSame(
			array( 'builder' ),
			$GLOBALS['karmcp_test']['post_meta'][4242]['_elementor_edit_mode']
		);
	}
}
