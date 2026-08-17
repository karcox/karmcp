<?php
/**
 * Writes report the setting keys they did not recognise.
 *
 * The failure mode this closes: a write accepts any key, stores it in
 * `_elementor_data`, and answers `success`. Reading the element back shows the
 * key sitting there. So a wrong control name looks applied everywhere except on
 * the page, and the page is the last place anyone looks.
 *
 * Two happened in one session. `button_background_color` instead of
 * `background_color` left a white button on a white background and the module
 * was written off as broken. `button_padding` — which is the kit's control, not
 * the widget's — left four buttons on factory padding.
 *
 * Advisory, never a rejection: dynamic tags, third-party addons and Elementor's
 * own incomplete headless control list all yield names we cannot account for.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/schemas/class-schema-generator.php';
require_once __DIR__ . '/../includes/validators/class-settings-validator.php';

class UnknownSettingKeysTest extends TestCase {

	/**
	 * @param string[] $controls Control names the widget is said to have.
	 * @return KarMCP_Settings_Validator
	 */
	private function validator( array $controls ): KarMCP_Settings_Validator {
		$properties = array();
		foreach ( $controls as $control ) {
			$properties[ $control ] = array( 'type' => 'string' );
		}

		$generator = $this->getMockBuilder( KarMCP_Schema_Generator::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'generate' ) )
			->getMock();
		$generator->method( 'generate' )->willReturn( array( 'properties' => $properties ) );

		return new KarMCP_Settings_Validator( $generator );
	}

	public function test_a_control_the_widget_has_is_not_reported(): void {
		$validator = $this->validator( array( 'text_padding', 'background_color' ) );

		$this->assertSame(
			array(),
			$validator->unknown_keys( 'button', array( 'text_padding' => array( 'top' => '18' ) ) )
		);
	}

	public function test_the_kits_padding_is_reported_on_a_widget(): void {
		$validator = $this->validator( array( 'text_padding', 'background_color' ) );

		$this->assertSame(
			array( 'button_padding' ),
			$validator->unknown_keys( 'button', array( 'button_padding' => array( 'top' => '18' ) ) )
		);
	}

	/**
	 * Edit distance alone never finds this: button_padding → text_padding is
	 * seven edits apart. The shared trailing segment is what carries it.
	 */
	public function test_the_suggestion_finds_the_control_it_was_confused_with(): void {
		$validator = $this->validator( array( 'text_padding', 'background_color', 'hover_color' ) );

		$this->assertContains(
			'text_padding',
			$validator->suggest_controls( 'button', 'button_padding' )
		);
	}

	/**
	 * The other real case, which is a containment match rather than a suffix one.
	 */
	public function test_the_suggestion_finds_a_control_the_name_wraps(): void {
		$validator = $this->validator( array( 'background_color', 'text_padding' ) );

		$this->assertContains(
			'background_color',
			$validator->suggest_controls( 'button', 'button_background_color' )
		);
	}

	/**
	 * Responsive variants and group-control sub-keys are real, and flagging them
	 * would train people to ignore the warning — which is worse than no warning.
	 */
	public function test_responsive_and_group_variants_are_not_reported(): void {
		$validator = $this->validator( array( 'text_padding', 'typography_font_size' ) );

		$this->assertSame(
			array(),
			$validator->unknown_keys(
				'button',
				array(
					'text_padding_mobile'    => array(),
					'typography_font_weight' => '700',
				)
			)
		);
	}

	/**
	 * When the controls cannot be introspected at all, claim nothing. Reporting
	 * every key as unknown would be noise on exactly the sites where Elementor
	 * is least reachable.
	 */
	public function test_nothing_is_claimed_when_the_schema_is_unavailable(): void {
		$generator = $this->getMockBuilder( KarMCP_Schema_Generator::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'generate' ) )
			->getMock();
		$generator->method( 'generate' )->willReturn( new WP_Error( 'no_widget', 'nope' ) );

		$validator = new KarMCP_Settings_Validator( $generator );

		$this->assertSame( array(), $validator->unknown_keys( 'button', array( 'whatever' => 1 ) ) );
		$this->assertSame( array(), $validator->suggest_controls( 'button', 'whatever' ) );
	}

	/**
	 * validate() stays advisory: it must keep returning true whatever it finds,
	 * or an unrecognised-but-valid key starts aborting inserts again.
	 */
	public function test_validate_still_never_rejects(): void {
		$validator = $this->validator( array( 'text_padding' ) );

		$this->assertTrue( $validator->validate( 'button', array( 'nonsense_key' => 'x' ) ) );
	}
}
