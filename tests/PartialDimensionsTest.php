<?php
/**
 * A dimension with one side left blank does not apply that side and skip the
 * others — it applies nothing at all.
 *
 * Elementor renders a dimension control through a selector template
 * (`padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} …`). In
 * `Base::add_control_rules()` a placeholder that resolves to an empty string
 * throws, and the catch around the selector loop returns — abandoning every
 * rule of that control. An absent side behaves identically, because
 * `Control_Base_Multiple::get_value()` fills what is missing from the control
 * default and a dimension default is empty on all four sides.
 *
 * So `padding: {top: 20}` saves, reads back exactly as sent, reports success,
 * and produces no padding anywhere. Nothing short of opening the browser says
 * so, which is what the advisory is for.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/validators/class-settings-validator.php';

class PartialDimensionsTest extends \PHPUnit\Framework\TestCase {

	/**
	 * The reported keys, so a test reads as the question it is asking.
	 *
	 * @param array $settings The settings payload.
	 * @return string[]
	 */
	private function keys( array $settings ): array {
		return array_column( KarMCP_Settings_Validator::partial_dimensions( $settings ), 'key' );
	}

	// ---------------------------------------------------------------------
	// The defect itself
	// ---------------------------------------------------------------------

	public function test_a_dimension_with_blank_sides_is_reported_with_the_sides_that_are_blank() {
		$report = KarMCP_Settings_Validator::partial_dimensions(
			array(
				'padding' => array(
					'unit'     => 'px',
					'top'      => '20',
					'right'    => '',
					'bottom'   => '',
					'left'     => '',
					'isLinked' => false,
				),
			)
		);

		$this->assertCount( 1, $report );
		$this->assertSame( 'padding', $report[0]['key'] );
		$this->assertSame( array( 'right', 'bottom', 'left' ), $report[0]['blank'] );
	}

	public function test_sides_that_are_absent_count_as_blank() {
		$report = KarMCP_Settings_Validator::partial_dimensions(
			array(
				'margin' => array(
					'unit' => 'px',
					'top'  => '40',
				),
			)
		);

		$this->assertSame( array( 'right', 'bottom', 'left' ), $report[0]['blank'] );
	}

	public function test_responsive_variants_are_covered_too() {
		$this->assertSame(
			array( 'padding_tablet' ),
			$this->keys(
				array(
					'padding_tablet' => array(
						'unit'   => 'px',
						'top'    => '10',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
					),
				)
			)
		);
	}

	public function test_every_dimension_key_in_one_payload_is_reported() {
		$this->assertSame(
			array( 'padding', 'border_radius' ),
			$this->keys(
				array(
					'padding'       => array(
						'unit' => 'px',
						'top'  => '20',
					),
					'margin'        => array(
						'unit'   => 'px',
						'top'    => '0',
						'right'  => '0',
						'bottom' => '0',
						'left'   => '0',
					),
					'border_radius' => array(
						'unit' => 'px',
						'top'  => '8',
					),
				)
			)
		);
	}

	public function test_a_dimension_inside_a_repeater_row_is_reported_with_its_path() {
		$report = KarMCP_Settings_Validator::partial_dimensions(
			array(
				'icon_list' => array(
					array( 'text' => 'One' ),
					array(
						'text'         => 'Two',
						'item_padding' => array(
							'unit' => 'px',
							'top'  => '12',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $report );
		$this->assertSame( 'icon_list[1].item_padding', $report[0]['key'] );
		$this->assertSame( array( 'right', 'bottom', 'left' ), $report[0]['blank'] );
	}

	// ---------------------------------------------------------------------
	// What must not be reported
	// ---------------------------------------------------------------------

	public function test_a_complete_dimension_is_not_reported() {
		$this->assertSame(
			array(),
			$this->keys(
				array(
					'padding' => array(
						'unit'     => 'px',
						'top'      => '20',
						'right'    => '10',
						'bottom'   => '20',
						'left'     => '10',
						'isLinked' => false,
					),
				)
			)
		);
	}

	public function test_zero_is_a_value_not_a_blank() {
		$this->assertSame(
			array(),
			$this->keys(
				array(
					'padding' => array(
						'unit'     => 'px',
						'top'      => '20',
						'right'    => '0',
						'bottom'   => '0',
						'left'     => '0',
						'isLinked' => false,
					),
				)
			)
		);
	}

	public function test_a_numeric_zero_is_a_value_too() {
		$this->assertSame(
			array(),
			$this->keys(
				array(
					'padding' => array(
						'unit'   => 'px',
						'top'    => 20,
						'right'  => 0,
						'bottom' => 0,
						'left'   => 0,
					),
				)
			)
		);
	}

	public function test_an_entirely_blank_dimension_is_not_reported() {
		// Nothing was asked for, so nothing was lost. This is also how a
		// dimension is cleared.
		$this->assertSame(
			array(),
			$this->keys(
				array(
					'padding' => array(
						'unit'   => 'px',
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
					),
				)
			)
		);
	}

	public function test_a_value_that_only_looks_like_a_dimension_is_left_alone() {
		$this->assertSame(
			array(),
			$this->keys(
				array(
					// A box-shadow: no side keys at all.
					'box_shadow' => array(
						'horizontal' => 0,
						'vertical'   => 4,
						'blur'       => 10,
						'spread'     => 0,
						'color'      => '#000',
					),
					// A gaps control: its own vocabulary, and `column`/`row`
					// put it outside the dimension shape.
					'grid_gaps'  => array(
						'unit'     => 'px',
						'column'   => '20',
						'row'      => '',
						'isLinked' => false,
					),
					// A slider.
					'width'      => array(
						'unit' => '%',
						'size' => 100,
					),
				)
			)
		);
	}

	public function test_a_v4_atomic_dimension_is_left_alone() {
		// Atomic dimensions are typed props keyed by logical side, and each
		// side is its own CSS property there, so a partial one is normal.
		$this->assertSame(
			array(),
			$this->keys(
				array(
					'padding' => array(
						'$$type' => 'dimensions',
						'value'  => array(
							'block-start' => array(
								'$$type' => 'size',
								'value'  => array(
									'unit' => 'px',
									'size' => 20,
								),
							),
						),
					),
				)
			)
		);
	}

	public function test_scalars_and_lists_are_ignored() {
		$this->assertSame(
			array(),
			$this->keys(
				array(
					'align'      => 'center',
					'html_tag'   => 'section',
					'some_count' => 4,
					'a_list'     => array( 'one', 'two' ),
					'nothing'    => array(),
				)
			)
		);
	}
}
