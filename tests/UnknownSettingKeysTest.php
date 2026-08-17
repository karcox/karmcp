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

	/**
	 * The regression from the 18-08 verification pass, on the real control set.
	 *
	 * 1.20.0 shipped this check and it caught none of the two names that
	 * motivated it: matching accepted anything sharing a prefix with a known
	 * control, and on this widget `button_` prefixes half the schema. A pure
	 * invention, `button_pepito_inventado`, went through as valid.
	 *
	 * The names below are the button widget's actual controls, read from
	 * Elementor 4.2.x via get-widget-schema full:true on 2026-08-18.
	 *
	 * @return KarMCP_Settings_Validator
	 */
	private function button_validator(): KarMCP_Settings_Validator {
		return $this->validator(
			array(
				'text', 'link', 'size', 'button_type', 'align', 'align_tablet', 'align_mobile',
				'selected_icon', 'icon_align', 'icon_indent', 'button_css_id',
				'button_text_color', 'background_background', 'background_color',
				'button_box_shadow_box_shadow_type', 'button_box_shadow_box_shadow',
				'hover_color', 'button_background_hover_background', 'button_background_hover_color',
				'button_hover_border_color', 'button_hover_transition_duration', 'hover_animation',
				'border_border', 'border_width', 'border_color', 'border_radius', 'text_padding',
				'typography_typography', 'typography_font_family', 'typography_font_size',
			)
		);
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function buttonKeys(): array {
		return array(
			// The two that started all of this. Both are real controls — on the
			// KIT, not on this widget — and both begin with `button_`.
			'the kit padding'          => array( 'button_padding', true ),
			'the kit background'       => array( 'button_background_color', true ),
			// A pure invention wearing the same prefix.
			'an invention'             => array( 'button_pepito_inventado', true ),
			// Another widget's control.
			"heading's title colour"   => array( 'title_color', true ),
			// Real controls of this widget: silence, or the warning gets ignored.
			'the widget padding'       => array( 'text_padding', false ),
			'the hover background'     => array( 'button_background_hover_color', false ),
			'a group sub-field'        => array( 'typography_font_family', false ),
			'a responsive variant'     => array( 'text_padding_mobile', false ),
		);
	}

	/**
	 * @dataProvider buttonKeys
	 *
	 * @param string $key      Setting key written to a button widget.
	 * @param bool   $reported Whether it must be reported as unknown.
	 */
	public function test_button_keys_are_judged_against_the_widgets_own_controls( string $key, bool $reported ): void {
		$unknown = $this->button_validator()->unknown_keys( 'button', array( $key => 'x' ) );

		$this->assertSame(
			$reported ? array( $key ) : array(),
			$unknown,
			$reported ? $key . ' must be reported.' : $key . ' is a real control and must stay silent.'
		);
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
	 * Responsive variants stay tolerated by shape: Elementor registers only a
	 * few of them explicitly, so `text_padding_mobile` will not be in the list
	 * even though `text_padding` is.
	 */
	public function test_responsive_variants_are_not_reported(): void {
		$validator = $this->validator( array( 'text_padding', 'typography_font_size' ) );

		$this->assertSame(
			array(),
			$validator->unknown_keys( 'button', array( 'text_padding_mobile' => array() ) )
		);
	}

	/**
	 * Group sub-fields are judged like anything else — by being in the list.
	 *
	 * This test previously asserted the opposite: that `typography_font_weight`
	 * passed on the strength of sharing a prefix with `typography_font_size`.
	 * That leniency is exactly what let `button_padding` through, and it is no
	 * longer needed: get_full_controls() flips Elementor's style-control toggle
	 * while reading, so the real schema carries every expanded group field —
	 * the live button schema lists font_weight, font_style, line_height and the
	 * rest alongside font_size.
	 *
	 * The trade is deliberate: on an install where the controls somehow come
	 * back partial, a real sub-field would be reported as unknown. That is a
	 * false warning on a call that still succeeds, against the alternative of
	 * missing every wrong name that wears a familiar prefix.
	 */
	public function test_a_group_sub_field_is_reported_when_it_is_not_in_the_control_list(): void {
		$validator = $this->validator( array( 'typography_font_size', 'typography_font_weight' ) );

		$this->assertSame(
			array(),
			$validator->unknown_keys( 'button', array( 'typography_font_weight' => '700' ) )
		);

		$this->assertSame(
			array( 'typography_invented_field' ),
			$validator->unknown_keys( 'button', array( 'typography_invented_field' => 'x' ) )
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
