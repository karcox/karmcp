<?php
/**
 * Settings already at their control default are dropped, and nothing else is.
 *
 * A template copied from a real page carries every control the original ever
 * touched. Third-party widgets make that expensive: Unlimited Elements ships
 * its background sliders pre-filled with six Unsplash photographs, and the
 * navigation block on one site is 5 elements and 28,499 characters, almost all
 * of it sample rows nobody sees — riding into every module of every course, and
 * into the SCORM export.
 *
 * The safety here is structural rather than careful: a widget resolves an
 * absent setting to that same default, so removing a value identical to it
 * cannot change what renders. Every test below is about the boundary of that
 * claim.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-default-stripper.php';

class DefaultStripperTest extends TestCase {

	/**
	 * @param array $controls Control id => definition.
	 * @return callable
	 */
	private function controls( array $controls ): callable {
		return static fn( string $el, string $widget ): array => 'widget' === $el ? $controls : array();
	}

	/**
	 * @param array    $settings Element settings.
	 * @param callable $controls Control resolver.
	 * @return array{0:array,1:int} Settings after, and how many were removed.
	 */
	private function strip( array $settings, callable $controls ): array {
		$tree = array(
			array(
				'id'         => 'a1',
				'elType'     => 'widget',
				'widgetType' => 'ucaddon_x',
				'settings'   => $settings,
			),
		);

		$removed = 0;
		$out     = KarMCP_Default_Stripper::strip( $tree, $controls, $removed );

		return array( $out[0]['settings'], $removed );
	}

	/**
	 * The case this exists for: a repeater left exactly as the addon ships it.
	 */
	public function test_a_repeater_identical_to_its_default_is_dropped(): void {
		$rows = array(
			array( 'image' => array( 'url' => 'https://images.unsplash.com/1' ) ),
			array( 'image' => array( 'url' => 'https://images.unsplash.com/2' ) ),
		);

		list( $after, $removed ) = $this->strip(
			array( 'random_background_widget_uc_items' => $rows, 'title' => 'Real content' ),
			$this->controls(
				array(
					'random_background_widget_uc_items' => array( 'type' => 'repeater', 'default' => $rows ),
					'title'                             => array( 'type' => 'text', 'default' => '' ),
				)
			)
		);

		$this->assertArrayNotHasKey( 'random_background_widget_uc_items', $after );
		$this->assertSame( 'Real content', $after['title'], 'A value someone chose must survive.' );
		$this->assertSame( 1, $removed );
	}

	/**
	 * One changed row means somebody used it. The whole repeater stays.
	 */
	public function test_a_repeater_with_one_row_changed_is_kept(): void {
		$default = array( array( 'text' => 'Sample' ), array( 'text' => 'Sample 2' ) );
		$stored  = array( array( 'text' => 'Sample' ), array( 'text' => 'Ours' ) );

		list( $after ) = $this->strip(
			array( 'uc_items' => $stored ),
			$this->controls( array( 'uc_items' => array( 'type' => 'repeater', 'default' => $default ) ) )
		);

		$this->assertSame( $stored, $after['uc_items'] );
	}

	/**
	 * Elementor round-trips numbers through form fields, so a stored "0" and a
	 * default 0 are the same value. Treating them as different would leave the
	 * weight in place for no reason.
	 */
	public function test_a_number_stored_as_a_string_still_matches_its_default(): void {
		list( $after ) = $this->strip(
			array( 'gap' => '0' ),
			$this->controls( array( 'gap' => array( 'type' => 'slider', 'default' => 0 ) ) )
		);

		$this->assertArrayNotHasKey( 'gap', $after );
	}

	/**
	 * But an empty string is not a zero, and null is not false. Collapsing those
	 * would delete a deliberate value: unset and zero mean different things to
	 * Elementor.
	 */
	public function test_empty_null_and_false_are_not_interchangeable(): void {
		list( $after, $removed ) = $this->strip(
			array( 'a' => '', 'b' => null, 'c' => false ),
			$this->controls(
				array(
					'a' => array( 'type' => 'text', 'default' => 0 ),
					'b' => array( 'type' => 'text', 'default' => '' ),
					'c' => array( 'type' => 'switcher', 'default' => '' ),
				)
			)
		);

		$this->assertSame( array( 'a' => '', 'b' => null, 'c' => false ), $after );
		$this->assertSame( 0, $removed );
	}

	/**
	 * The bindings that make globals work are not controls and have no default.
	 * Dropping them would unbind a colour from the kit.
	 */
	public function test_globals_and_dynamic_bindings_are_never_touched(): void {
		$settings = array(
			'__globals__' => array( 'title_color' => 'globals/colors?id=primary' ),
			'__dynamic__' => array( 'title' => '[tag]' ),
		);

		list( $after, $removed ) = $this->strip( $settings, $this->controls( array() ) );

		$this->assertSame( $settings, $after );
		$this->assertSame( 0, $removed );
	}

	/**
	 * No control list means no knowledge of the default, and guessing there
	 * would delete content. Containers land here, and so does any widget the
	 * site does not have installed.
	 */
	public function test_an_element_with_no_known_controls_is_left_alone(): void {
		$tree = array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array( 'background_color' => '#fff' ),
				'elements' => array(),
			),
		);

		$removed = 0;
		$out     = KarMCP_Default_Stripper::strip(
			$tree,
			$this->controls( array( 'background_color' => array( 'default' => '#fff' ) ) ),
			$removed
		);

		$this->assertSame( '#fff', $out[0]['settings']['background_color'] );
		$this->assertSame( 0, $removed );
	}

	/**
	 * A control with no declared default cannot be compared against one.
	 */
	public function test_a_control_without_a_default_is_left_alone(): void {
		list( $after, $removed ) = $this->strip(
			array( 'title' => '' ),
			$this->controls( array( 'title' => array( 'type' => 'text' ) ) )
		);

		$this->assertArrayHasKey( 'title', $after );
		$this->assertSame( 0, $removed );
	}

	/**
	 * And it reaches all the way down: the weight is in nested widgets.
	 */
	public function test_it_walks_nested_elements(): void {
		$tree = array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'ucaddon_x',
						'settings'   => array( 'sample' => 'factory' ),
					),
				),
			),
		);

		$removed = 0;
		$out     = KarMCP_Default_Stripper::strip(
			$tree,
			$this->controls( array( 'sample' => array( 'default' => 'factory' ) ) ),
			$removed
		);

		$this->assertSame( array(), $out[0]['elements'][0]['settings'] );
		$this->assertSame( 1, $removed );
	}
}
