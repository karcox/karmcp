<?php
/**
 * The element extension compiler, tested by running what it compiles.
 *
 * An extension writes into a namespace it does not own — Elementor's props
 * schema — and puts attributes on elements built by someone else. So the tests
 * that matter here are about restraint: that it only touches its targets, that
 * it can only write data-/aria- attributes, that a class never comes from a
 * control's value, and that a hostile value cannot break out of the attribute
 * it lives in.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/elementor-atomic.php';
require_once __DIR__ . '/../includes/sandbox/class-extension-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-extension-generator.php';

class ExtensionGeneratorTest extends TestCase {

	private static int $seq = 0;

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** The particles spec, the one this feature was built for. */
	private function spec( array $overrides = array() ): array {
		return array_merge(
			array(
				'spec_version' => 1,
				'meta'         => array( 'title' => 'Partículas' ),
				'targets'      => array( 'e-div-block', 'e-flexbox' ),
				'section'      => array( 'label' => 'Efectos KarMCP' ),
				'props'        => array(
					array(
						'name'    => 'karmcp_particles',
						'type'    => 'select',
						'label'   => 'Partículas',
						'default' => 'none',
						'options' => array( 'none' => 'Ninguna', 'snow' => 'Nieve', 'stars' => 'Estrellas' ),
					),
					array( 'name' => 'karmcp_density', 'type' => 'number', 'label' => 'Densidad', 'default' => 40 ),
				),
				'output'       => array(
					array(
						'when'       => array( 'prop' => 'karmcp_particles', 'not' => 'none' ),
						'class'      => 'karmcp-fx-particles',
						'attributes' => array(
							'data-karmcp-particles' => '{{karmcp_particles}}',
							'data-karmcp-density'   => '{{karmcp_density}}',
						),
					),
				),
				'styles'       => array(
					'critical' => '.karmcp-fx-particles{position:relative}',
					'deferred' => '.karmcp-fx-particles canvas{position:absolute}',
				),
			),
			$overrides
		);
	}

	/** Compiles and loads the extension, returning an instance. */
	private function build( array $spec, array $opts = array() ) {
		$class = 'KarMCP_Extension_Test_' . ( ++self::$seq );
		$php   = KarMCP_Extension_Generator::generate( $spec, $class, $opts );

		$this->assertIsString( $php, is_wp_error( $php ) ? $php->get_error_message() : 'expected source' );

		eval( preg_replace( '/^<\?php/', '', $php, 1 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- running the compiler's output IS the test.

		$this->assertTrue( class_exists( $class ), 'The generated class did not load.' );

		return new $class();
	}

	private function div_block( array $settings ): \KarMCP\Tests\Div_Block_Double {
		return new \KarMCP\Tests\Div_Block_Double( $settings );
	}

	/**
	 * Renders while swallowing the critical CSS the extension prints. The tests
	 * that care about that output capture it themselves.
	 */
	private function render( $extension, $element ): void {
		ob_start();
		$extension->render( $element );
		ob_end_clean();
	}

	// -------------------------------------------------------------------------
	// props()
	// -------------------------------------------------------------------------

	public function test_props_are_declared_on_the_schema(): void {
		$schema = $this->build( $this->spec() )->props( array( 'existing' => 'kept' ) );

		$this->assertArrayHasKey( 'existing', $schema, 'The filter must not drop what was already there.' );
		$this->assertArrayHasKey( 'karmcp_particles', $schema );
		$this->assertArrayHasKey( 'karmcp_density', $schema );
	}

	public function test_select_prop_carries_its_enum_and_default(): void {
		$schema = $this->build( $this->spec() )->props( array() );

		$this->assertSame( array( 'none', 'snow', 'stars' ), $schema['karmcp_particles']->calls['enum'] );
		$this->assertSame( 'none', $schema['karmcp_particles']->calls['default'] );
		$this->assertSame( 40.0, $schema['karmcp_density']->calls['default'] );
	}

	public function test_a_non_array_schema_is_returned_untouched(): void {
		$this->assertNull( $this->build( $this->spec() )->props( null ) );
	}

	// -------------------------------------------------------------------------
	// controls()
	// -------------------------------------------------------------------------

	public function test_the_section_is_added_to_a_targeted_element(): void {
		$controls = $this->build( $this->spec() )->controls( array(), $this->div_block( array() ) );

		$this->assertCount( 1, $controls );
		$this->assertSame( 'Efectos KarMCP', $controls[0]->label );
		$this->assertCount( 2, $controls[0]->get_items() );
		$this->assertSame( 'karmcp_particles', $controls[0]->get_items()[0]->bound );
	}

	public function test_an_element_outside_the_targets_gets_nothing(): void {
		$heading  = new \KarMCP\Tests\Heading_Double( array() );
		$controls = $this->build( $this->spec() )->controls( array(), $heading );

		$this->assertSame( array(), $controls );
	}

	public function test_wildcard_target_applies_everywhere(): void {
		$heading  = new \KarMCP\Tests\Heading_Double( array() );
		$controls = $this->build( $this->spec( array( 'targets' => array( '*' ) ) ) )->controls( array(), $heading );

		$this->assertCount( 1, $controls );
	}

	/** Elementor wants a list of pairs, not the map the spec is written with. */
	public function test_select_options_are_translated_to_elementors_shape(): void {
		$controls = $this->build( $this->spec() )->controls( array(), $this->div_block( array() ) );

		$this->assertSame(
			array(
				array( 'value' => 'none', 'label' => 'Ninguna' ),
				array( 'value' => 'snow', 'label' => 'Nieve' ),
				array( 'value' => 'stars', 'label' => 'Estrellas' ),
			),
			$controls[0]->get_items()[0]->options
		);
	}

	// -------------------------------------------------------------------------
	// render()
	// -------------------------------------------------------------------------

	public function test_the_rule_applies_when_the_condition_holds(): void {
		$element = $this->div_block( array( 'karmcp_particles' => 'snow', 'karmcp_density' => 40 ) );

		$this->render( $this->build( $this->spec() ), $element );

		// Atomic elements render their opening tag from settings, not from the
		// wrapper attributes, so the props are what actually reach the HTML.
		$this->assertSame( array( 'karmcp-fx-particles' ), $element->classes() );
		$this->assertSame( 'snow', $element->attributes()['data-karmcp-particles'] );
		$this->assertSame( '40', $element->attributes()['data-karmcp-density'] );

		// And the wrapper is written too, for atomic elements with no template.
		$this->assertSame( 'karmcp-fx-particles', $element->attribute( 'class' ) );
	}

	public function test_nothing_is_applied_when_the_condition_fails(): void {
		$element = $this->div_block( array( 'karmcp_particles' => 'none' ) );

		$this->render( $this->build( $this->spec() ), $element );

		$this->assertSame( array(), $element->wrapper );
		$this->assertSame( array(), $element->props );
	}

	public function test_an_element_outside_the_targets_is_never_touched(): void {
		$heading = new \KarMCP\Tests\Heading_Double( array( 'karmcp_particles' => 'snow' ) );

		$this->render( $this->build( $this->spec() ), $heading );

		$this->assertSame( array(), $heading->wrapper );
		$this->assertSame( array(), $heading->props );
	}

	public function test_missing_settings_do_not_fatal(): void {
		$element = $this->div_block( array() );

		$this->render( $this->build( $this->spec() ), $element );

		$this->assertSame( array(), $element->wrapper );
	}

	/**
	 * The value is not escaped by the generated code on purpose — Elementor
	 * escapes wrapper attributes when it prints them. What the generator
	 * guarantees is the *shape*: a number stays a number.
	 */
	public function test_a_hostile_number_is_cast_and_cannot_carry_markup(): void {
		$element = $this->div_block(
			array( 'karmcp_particles' => 'snow', 'karmcp_density' => '"><img src=x onerror=alert(1)>' )
		);

		$this->render( $this->build( $this->spec() ), $element );

		$this->assertSame( '0', $element->attributes()['data-karmcp-density'] );
	}

	public function test_a_size_prop_is_rebuilt_from_a_number_and_a_known_unit(): void {
		$spec = $this->spec(
			array(
				'props'  => array( array( 'name' => 'karmcp_blur', 'type' => 'size', 'label' => 'Blur' ) ),
				'output' => array(
					array( 'attributes' => array( 'data-karmcp-blur' => '{{karmcp_blur}}' ) ),
				),
			)
		);

		$element = $this->div_block( array( 'karmcp_blur' => array( 'size' => 12, 'unit' => 'px' ) ) );
		$this->render( $this->build( $spec ), $element );
		$this->assertSame( '12px', $element->attributes()['data-karmcp-blur'] );

		// An unknown unit falls back rather than reaching the attribute.
		$hostile = $this->div_block( array( 'karmcp_blur' => array( 'size' => '9;}evil', 'unit' => '"><script>' ) ) );
		$this->render( $this->build( $spec ), $hostile );
		$this->assertSame( '9px', $hostile->attributes()['data-karmcp-blur'] );
	}

	public function test_literal_text_around_a_placeholder_is_preserved(): void {
		$spec = $this->spec(
			array(
				'output' => array(
					array( 'attributes' => array( 'data-karmcp-preset' => 'fx-{{karmcp_particles}}-v2' ) ),
				),
			)
		);

		$element = $this->div_block( array( 'karmcp_particles' => 'snow' ) );
		$this->render( $this->build( $spec ), $element );

		$this->assertSame( 'fx-snow-v2', $element->attributes()['data-karmcp-preset'] );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function test_assets_are_enqueued_only_when_the_effect_applies(): void {
		$GLOBALS['karmcp_test']['registered_styles'][]  = 'karmcp-extension-9-style';
		$GLOBALS['karmcp_test']['registered_scripts'][] = 'karmcp-extension-9-script';

		$extension = $this->build(
			$this->spec(),
			array( 'style_handle' => 'karmcp-extension-9-style', 'script_handle' => 'karmcp-extension-9-script' )
		);

		ob_start();
		$extension->render( $this->div_block( array( 'karmcp_particles' => 'none' ) ) );
		ob_end_clean();

		$this->assertSame( array(), $GLOBALS['karmcp_test']['enqueued_styles'] );

		ob_start();
		$extension->render( $this->div_block( array( 'karmcp_particles' => 'snow' ) ) );
		ob_end_clean();

		$this->assertSame( array( 'karmcp-extension-9-style' ), $GLOBALS['karmcp_test']['enqueued_styles'] );
		$this->assertSame( array( 'karmcp-extension-9-script' ), $GLOBALS['karmcp_test']['enqueued_scripts'] );
	}

	public function test_critical_css_is_printed_once_per_request(): void {
		$extension = $this->build( $this->spec() );

		ob_start();
		$extension->render( $this->div_block( array( 'karmcp_particles' => 'snow' ) ) );
		$extension->render( $this->div_block( array( 'karmcp_particles' => 'stars' ) ) );
		$printed = ob_get_clean();

		$this->assertSame( 1, substr_count( $printed, '<style>' ) );
		$this->assertStringContainsString( '.karmcp-fx-particles{position:relative}', $printed );
	}

	// -------------------------------------------------------------------------
	// Refusals
	// -------------------------------------------------------------------------

	public function test_an_invalid_spec_is_refused_before_any_code_is_produced(): void {
		$result = KarMCP_Extension_Generator::generate(
			$this->spec( array( 'props' => array( array( 'name' => 'particles', 'type' => 'select', 'options' => array( 'a' => 'A' ) ) ) ) ),
			'KarMCP_Extension_Bad'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'spec_prop_name', $result->get_error_code() );
	}

	public function test_an_invalid_class_name_is_refused(): void {
		$result = KarMCP_Extension_Generator::generate( $this->spec(), '2 Bad Name' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_class_name', $result->get_error_code() );
	}
}
