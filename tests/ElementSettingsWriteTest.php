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

	// -- globals shadowing a literal write ---------------------------------

	/**
	 * Builds a container whose background colour is bound to a kit global.
	 *
	 * @return array
	 */
	private function bound_container(): array {
		return $this->tree(
			'container',
			array(
				'background_background' => 'classic',
				'background_color'      => '#000000',
				'__globals__'           => array( 'background_color' => 'globals/colors?id=primary' ),
			)
		);
	}

	private function update_reporting( array $data, array $settings, bool $clear = false ): array {
		$report = array();
		$this->assertTrue(
			$this->data->update_element_settings( $data, 'abc1234', $settings, $report, array( 'globals' => $clear ) )
		);

		return array( $data[0]['settings'], $report['globals'] ?? array() );
	}

	/**
	 * Same, for the responsive axis.
	 *
	 * @param array $data     One-element tree.
	 * @param array $settings Settings to merge.
	 * @param bool  $clear    Whether to drop the breakpoint overrides.
	 * @return array [ settings, responsive report ]
	 */
	private function update_responsive( array $data, array $settings, bool $clear = false ): array {
		$report = array();
		$this->assertTrue(
			$this->data->update_element_settings( $data, 'abc1234', $settings, $report, array( 'responsive' => $clear ) )
		);

		return array( $data[0]['settings'], $report['responsive'] ?? array() );
	}

	public function test_a_literal_over_a_binding_is_reported() {
		list( $settings, $shadowed ) = $this->update_reporting(
			$this->bound_container(),
			array( 'background_color' => '#0025FF' )
		);

		$this->assertSame( array( 'background_color' => 'globals/colors?id=primary' ), $shadowed );

		// Reported, not repaired: the binding is still there and still wins.
		$this->assertSame( 'globals/colors?id=primary', $settings['__globals__']['background_color'] );
		$this->assertSame( '#0025FF', $settings['background_color'] );
	}

	public function test_clear_globals_drops_the_binding_so_the_literal_applies() {
		list( $settings, $shadowed ) = $this->update_reporting(
			$this->bound_container(),
			array( 'background_color' => '#0025FF' ),
			true
		);

		$this->assertSame( array( 'background_color' => 'globals/colors?id=primary' ), $shadowed );
		$this->assertArrayNotHasKey( 'background_color', $settings['__globals__'] );
		$this->assertSame( '#0025FF', $settings['background_color'] );
	}

	public function test_a_caller_clearing_the_binding_itself_is_not_reported() {
		list( $settings, $shadowed ) = $this->update_reporting(
			$this->bound_container(),
			array(
				'background_color' => '#0025FF',
				'__globals__'      => array( 'background_color' => '' ),
			)
		);

		$this->assertSame( array(), $shadowed );
		$this->assertSame( '', $settings['__globals__']['background_color'] );
	}

	public function test_an_unbound_key_is_not_reported() {
		list( , $shadowed ) = $this->update_reporting(
			$this->bound_container(),
			array( 'padding' => array( 'size' => 20 ) )
		);

		$this->assertSame( array(), $shadowed );
	}

	/**
	 * `__globals__` is keyed by the name Elementor stores, so the check has to
	 * run on the rewritten key. A container's class key arrives as
	 * `_css_classes` and is stored as `css_classes`.
	 */
	public function test_the_binding_is_matched_on_the_rewritten_key() {
		$data = $this->tree(
			'container',
			array(
				'css_classes' => 'de-la-casa',
				'__globals__' => array( 'css_classes' => 'globals/whatever?id=x' ),
			)
		);

		list( , $shadowed ) = $this->update_reporting( $data, array( '_css_classes' => 'del-cliente' ) );

		$this->assertSame( array( 'css_classes' => 'globals/whatever?id=x' ), $shadowed );
	}

	public function test_deleting_a_key_is_not_a_shadowed_write() {
		list( , $shadowed ) = $this->update_reporting(
			$this->bound_container(),
			array( 'background_color' => null )
		);

		$this->assertSame( array(), $shadowed );
	}

	public function test_an_empty_binding_does_not_shadow_anything() {
		$data = $this->tree(
			'container',
			array(
				'background_color' => '#000000',
				'__globals__'      => array( 'background_color' => '' ),
			)
		);

		list( , $shadowed ) = $this->update_reporting( $data, array( 'background_color' => '#0025FF' ) );

		$this->assertSame( array(), $shadowed );
	}

	public function test_a_nested_element_reports_through_the_recursion() {
		$data = array(
			array(
				'id'       => 'parent1',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => $this->bound_container(),
			),
		);

		$report = array();
		$this->assertTrue(
			$this->data->update_element_settings( $data, 'abc1234', array( 'background_color' => '#0025FF' ), $report )
		);

		$this->assertSame( array( 'background_color' => 'globals/colors?id=primary' ), $report['globals'] );
	}

	// -- breakpoints shadowing a desktop write -----------------------------

	/**
	 * A container with a mobile padding override, as a block copied from a
	 * design that had been made responsive arrives.
	 *
	 * @param array $extra Extra settings to merge in.
	 * @return array
	 */
	private function responsive_container( array $extra = array() ): array {
		return $this->tree(
			'container',
			array_merge(
				array(
					'padding'        => array( 'unit' => 'px', 'top' => '80', 'bottom' => '80' ),
					'padding_mobile' => array( 'unit' => 'px', 'top' => '20', 'bottom' => '20' ),
				),
				$extra
			)
		);
	}

	public function test_a_desktop_write_is_reported_when_a_breakpoint_overrides_it() {
		list( $settings, $shadowed ) = $this->update_responsive(
			$this->responsive_container(),
			array( 'padding' => array( 'unit' => 'px', 'top' => '120', 'bottom' => '120' ) )
		);

		$this->assertSame( array( 'padding' => array( 'padding_mobile' ) ), $shadowed );

		// Reported, not repaired: the phone keeps the layout someone chose.
		$this->assertSame( '20', $settings['padding_mobile']['top'] );
		$this->assertSame( '120', $settings['padding']['top'] );
	}

	public function test_clear_responsive_drops_the_override() {
		list( $settings, $shadowed ) = $this->update_responsive(
			$this->responsive_container(),
			array( 'padding' => array( 'unit' => 'px', 'top' => '120', 'bottom' => '120' ) ),
			true
		);

		$this->assertSame( array( 'padding' => array( 'padding_mobile' ) ), $shadowed );
		$this->assertArrayNotHasKey( 'padding_mobile', $settings );
		$this->assertSame( '120', $settings['padding']['top'] );
	}

	public function test_setting_the_breakpoint_in_the_same_call_is_not_reported() {
		list( , $shadowed ) = $this->update_responsive(
			$this->responsive_container(),
			array(
				'padding'        => array( 'unit' => 'px', 'top' => '120' ),
				'padding_mobile' => array( 'unit' => 'px', 'top' => '40' ),
			)
		);

		$this->assertSame( array(), $shadowed );
	}

	public function test_a_key_with_no_overrides_is_not_reported() {
		list( , $shadowed ) = $this->update_responsive(
			$this->responsive_container(),
			array( 'min_height' => array( 'unit' => 'px', 'size' => 400 ) )
		);

		$this->assertSame( array(), $shadowed );
	}

	/**
	 * A control that was touched and cleared keeps its unit and nothing else.
	 * It paints nothing, so it overrides nothing — reporting it would be the
	 * false positive that teaches people to ignore the warning.
	 */
	public function test_an_emptied_override_does_not_shadow() {
		$data = $this->tree(
			'container',
			array(
				'padding'        => array( 'unit' => 'px', 'top' => '80' ),
				'padding_mobile' => array( 'unit' => 'px', 'size' => '', 'sizes' => array() ),
			)
		);

		list( , $shadowed ) = $this->update_responsive( $data, array( 'padding' => array( 'unit' => 'px', 'top' => '120' ) ) );

		$this->assertSame( array(), $shadowed );
	}

	public function test_a_zero_override_does_shadow() {
		$data = $this->tree(
			'container',
			array(
				'padding'        => array( 'unit' => 'px', 'top' => '80' ),
				'padding_mobile' => array( 'unit' => 'px', 'top' => '0' ),
			)
		);

		list( , $shadowed ) = $this->update_responsive( $data, array( 'padding' => array( 'unit' => 'px', 'top' => '120' ) ) );

		$this->assertSame( array( 'padding' => array( 'padding_mobile' ) ), $shadowed );
	}

	public function test_every_breakpoint_that_declares_a_value_is_listed() {
		$data = $this->tree(
			'container',
			array(
				'padding'            => array( 'unit' => 'px', 'top' => '80' ),
				'padding_widescreen' => array( 'unit' => 'px', 'top' => '100' ),
				'padding_tablet'     => array( 'unit' => 'px', 'top' => '40' ),
				'padding_mobile'     => array( 'unit' => 'px', 'top' => '20' ),
			)
		);

		list( , $shadowed ) = $this->update_responsive( $data, array( 'padding' => array( 'unit' => 'px', 'top' => '120' ) ) );

		$this->assertSame(
			array( 'padding_widescreen', 'padding_tablet', 'padding_mobile' ),
			$shadowed['padding']
		);
	}

	/**
	 * Writing a breakpoint value is only shadowed by NARROWER ones: a tablet
	 * value is beaten on a phone, never by the desktop value above it.
	 */
	public function test_a_breakpoint_write_is_shadowed_only_by_narrower_ones() {
		$data = $this->tree(
			'container',
			array(
				'padding'        => array( 'unit' => 'px', 'top' => '80' ),
				'padding_tablet' => array( 'unit' => 'px', 'top' => '40' ),
				'padding_mobile' => array( 'unit' => 'px', 'top' => '20' ),
			)
		);

		list( , $shadowed ) = $this->update_responsive( $data, array( 'padding_tablet' => array( 'unit' => 'px', 'top' => '50' ) ) );

		$this->assertSame( array( 'padding_tablet' => array( 'padding_mobile' ) ), $shadowed );
	}

	public function test_the_narrowest_breakpoint_is_never_shadowed() {
		$data = $this->tree(
			'container',
			array(
				'padding'        => array( 'unit' => 'px', 'top' => '80' ),
				'padding_mobile' => array( 'unit' => 'px', 'top' => '20' ),
			)
		);

		list( , $shadowed ) = $this->update_responsive( $data, array( 'padding_mobile' => array( 'unit' => 'px', 'top' => '30' ) ) );

		$this->assertSame( array(), $shadowed );
	}

	/**
	 * `_tablet_extra` must be cut at the longer suffix, not at `_tablet`, or the
	 * base key comes out as `padding_` and nothing matches.
	 */
	public function test_the_longer_suffix_wins_when_splitting_the_key() {
		$data = $this->tree(
			'container',
			array(
				'padding_tablet_extra' => array( 'unit' => 'px', 'top' => '60' ),
				'padding_mobile'       => array( 'unit' => 'px', 'top' => '20' ),
			)
		);

		list( , $shadowed ) = $this->update_responsive(
			$data,
			array( 'padding_tablet_extra' => array( 'unit' => 'px', 'top' => '70' ) )
		);

		$this->assertSame( array( 'padding_tablet_extra' => array( 'padding_mobile' ) ), $shadowed );
	}

	public function test_deleting_a_key_is_not_a_shadowed_responsive_write() {
		list( , $shadowed ) = $this->update_responsive( $this->responsive_container(), array( 'padding' => null ) );

		$this->assertSame( array(), $shadowed );
	}

	public function test_both_axes_report_side_by_side() {
		$data = $this->tree(
			'container',
			array(
				'background_color' => '#000000',
				'padding'          => array( 'unit' => 'px', 'top' => '80' ),
				'padding_mobile'   => array( 'unit' => 'px', 'top' => '20' ),
				'__globals__'      => array( 'background_color' => 'globals/colors?id=primary' ),
			)
		);

		$report = array();
		$this->assertTrue(
			$this->data->update_element_settings(
				$data,
				'abc1234',
				array(
					'background_color' => '#0025FF',
					'padding'          => array( 'unit' => 'px', 'top' => '120' ),
				),
				$report
			)
		);

		$this->assertSame( array( 'background_color' => 'globals/colors?id=primary' ), $report['globals'] );
		$this->assertSame( array( 'padding' => array( 'padding_mobile' ) ), $report['responsive'] );
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
