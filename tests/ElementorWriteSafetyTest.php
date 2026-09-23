<?php
/**
 * Three ways an Elementor write could report success and lose something.
 *
 * - Elementor drops an element whose widget type is not registered in this
 *   context and says nothing, so editing one element could delete another.
 * - A dimension sent as a number renders on the front end and reads back as 0
 *   in the editor, where the next person saves the zero away.
 * - A system colour written as a custom colour is stored where nothing reads
 *   it, and the call answers success.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/validators/class-settings-validator.php';
require_once __DIR__ . '/../includes/class-elementor-data.php';
require_once __DIR__ . '/../includes/class-element-factory.php';
require_once __DIR__ . '/../includes/abilities/class-global-abilities.php';

class ElementorWriteSafetyTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	// ---------------------------------------------------------------------
	// Elements Elementor silently drops
	// ---------------------------------------------------------------------

	private function tree(): array {
		return array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'elements' => array(
					array( 'id' => 'bbb2222', 'elType' => 'widget', 'widgetType' => 'heading' ),
					array( 'id' => 'ccc3333', 'elType' => 'widget', 'widgetType' => 'cartflows-checkout' ),
				),
			),
		);
	}

	public function test_every_id_in_the_tree_is_accounted_for_at_any_depth() {
		$this->assertSame( array( 'aaa1111', 'bbb2222', 'ccc3333' ), KarMCP_Data::element_ids( $this->tree() ) );
	}

	public function test_an_element_that_did_not_survive_the_save_is_detectable() {
		$sent      = $this->tree();
		$persisted = $sent;
		// What Elementor does to a widget whose type is not registered here.
		unset( $persisted[0]['elements'][1] );

		$dropped = array_values( array_diff( KarMCP_Data::element_ids( $sent ), KarMCP_Data::element_ids( $persisted ) ) );

		$this->assertSame( array( 'ccc3333' ), $dropped, 'Without this comparison the save reports success and the widget is gone.' );
	}

	public function test_ids_are_not_invented_for_elements_that_have_none() {
		$this->assertSame( array(), KarMCP_Data::element_ids( array( array( 'elType' => 'container' ), 'nonsense' ) ) );
	}

	// ---------------------------------------------------------------------
	// Dimensions the editor can read back
	// ---------------------------------------------------------------------

	public function test_numeric_dimension_sides_are_stored_as_the_editor_writes_them() {
		$out = KarMCP_Settings_Validator::stringify_dimensions( array(
			'padding' => array( 'unit' => 'px', 'top' => 20, 'right' => 0, 'bottom' => 20.5, 'left' => '10', 'isLinked' => false ),
		) );

		$this->assertSame(
			array( 'unit' => 'px', 'top' => '20', 'right' => '0', 'bottom' => '20.5', 'left' => '10', 'isLinked' => false ),
			$out['padding'],
			'A number here renders on the front end and shows as 0 in the panel.'
		);
	}

	public function test_a_dimension_inside_a_repeater_row_is_fixed_too() {
		$out = KarMCP_Settings_Validator::stringify_dimensions( array(
			'icon_list' => array(
				array( 'text' => 'One', 'item_padding' => array( 'unit' => 'em', 'top' => 1, 'right' => 2, 'bottom' => 1, 'left' => 2 ) ),
			),
		) );

		$this->assertSame( '1', $out['icon_list'][0]['item_padding']['top'] );
		$this->assertSame( 'One', $out['icon_list'][0]['text'] );
	}

	public function test_values_that_are_not_dimensions_keep_their_type() {
		$settings = array(
			'width'      => array( 'unit' => 'px', 'size' => 300 ),           // A slider.
			'title'      => array( '$$type' => 'string', 'value' => 'Hi' ),   // An atomic prop.
			'menu_order' => 3,
		);

		$this->assertSame( $settings, KarMCP_Settings_Validator::stringify_dimensions( $settings ), 'Only a value whose keys are all dimension keys is a dimension.' );
	}

	// ---------------------------------------------------------------------
	// System colours are not custom colours
	// ---------------------------------------------------------------------

	/** @dataProvider system_colors */
	public function test_a_call_for_system_colours_alone_is_refused_before_anything_is_written( string $id ) {
		$refusal = KarMCP_Global_Abilities::system_color_refusal( array( array( '_id' => $id, 'color' => '#ff0000' ) ) );

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'reserved_color_id', $refusal->get_error_code() );
		$this->assertStringContainsString( 'Site Settings', $refusal->get_error_message(), 'Refusing without saying where they live just moves the problem.' );
	}

	public static function system_colors(): array {
		return array( array( 'primary' ), array( 'secondary' ), array( 'text' ), array( 'accent' ) );
	}

	public function test_a_custom_colour_alongside_them_still_goes_through() {
		$refusal = KarMCP_Global_Abilities::system_color_refusal( array(
			array( '_id' => 'primary', 'color' => '#ff0000' ),
			array( '_id' => 'brand_ink', 'color' => '#101820' ),
		) );

		$this->assertNull( $refusal, 'The custom one is written; the response says which ids were skipped.' );
	}

	public function test_ordinary_colours_are_not_refused() {
		$this->assertNull( KarMCP_Global_Abilities::system_color_refusal( array( array( '_id' => 'brand_ink', 'color' => '#101820' ) ) ) );
	}

	public function test_an_empty_side_stays_empty_rather_than_becoming_a_number() {
		$out = KarMCP_Settings_Validator::stringify_dimensions( array(
			'margin' => array( 'unit' => 'px', 'top' => 10, 'right' => '', 'bottom' => null, 'left' => '' ),
		) );

		$this->assertSame( '', $out['margin']['right'] );
		$this->assertNull( $out['margin']['bottom'] );
	}
}
