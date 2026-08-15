<?php
/**
 * The element extension spec contract.
 *
 * This artifact is the one that writes into somebody else's house: its props
 * land in Elementor's shared schema and its output lands on elements built by
 * the core. Most of these tests are about the two rules that keep that
 * defensible — the `karmcp_` prefix and the data-/aria- restriction — plus the
 * usual vocabulary checks.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-extension-spec.php';

class ExtensionSpecTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function spec( array $overrides = array() ): array {
		return array_merge(
			array(
				'spec_version' => 1,
				'meta'         => array( 'title' => 'Partículas' ),
				'targets'      => array( 'e-div-block' ),
				'props'        => array(
					array(
						'name'    => 'karmcp_particles',
						'type'    => 'select',
						'default' => 'none',
						'options' => array( 'none' => 'Ninguna', 'snow' => 'Nieve' ),
					),
				),
				'output'       => array(
					array(
						'when'       => array( 'prop' => 'karmcp_particles', 'not' => 'none' ),
						'class'      => 'karmcp-fx-particles',
						'attributes' => array( 'data-karmcp-particles' => '{{karmcp_particles}}' ),
					),
				),
			),
			$overrides
		);
	}

	private function assertRejected( array $spec, string $code ): void {
		$result = KarMCP_Extension_Spec::validate( $spec );
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the spec to be rejected.' );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_a_minimal_spec_is_valid(): void {
		$this->assertTrue( KarMCP_Extension_Spec::validate( $this->spec() ) );
	}

	// ----- The prefix rule: the props schema is shared -----

	public function test_a_prop_without_the_karmcp_prefix_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'props'  => array( array( 'name' => 'particles', 'type' => 'text' ) ),
					'output' => array( array( 'class' => 'x' ) ),
				)
			),
			'spec_prop_name'
		);
	}

	public function test_a_prop_named_like_an_elementor_one_is_rejected(): void {
		// 'classes' and 'attributes' are core props; without the prefix rule an
		// extension could shadow them.
		foreach ( array( 'classes', 'attributes', '_cssid' ) as $name ) {
			$this->assertRejected(
				$this->spec(
					array(
						'props'  => array( array( 'name' => $name, 'type' => 'text' ) ),
						'output' => array( array( 'class' => 'x' ) ),
					)
				),
				'spec_prop_name'
			);
		}
	}

	public function test_duplicate_props_are_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'props'  => array(
						array( 'name' => 'karmcp_a', 'type' => 'text' ),
						array( 'name' => 'karmcp_a', 'type' => 'number' ),
					),
					'output' => array( array( 'class' => 'x' ) ),
				)
			),
			'spec_prop_duplicate'
		);
	}

	// ----- The attribute rule: only data-* and aria-* -----

	public function test_writing_a_scripting_attribute_is_rejected(): void {
		foreach ( array( 'onclick', 'onmouseover', 'style', 'href', 'id', 'class' ) as $attribute ) {
			$this->assertRejected(
				$this->spec(
					array(
						'output' => array( array( 'attributes' => array( $attribute => 'x' ) ) ),
					)
				),
				'spec_attribute_name'
			);
		}
	}

	public function test_data_and_aria_attributes_are_accepted(): void {
		$this->assertTrue(
			KarMCP_Extension_Spec::validate(
				$this->spec(
					array(
						'output' => array(
							array(
								'attributes' => array(
									'data-karmcp-fx' => 'on',
									'aria-hidden'    => 'true',
								),
							),
						),
					)
				)
			)
		);
	}

	public function test_a_bare_prefix_is_not_an_attribute(): void {
		$this->assertRejected(
			$this->spec( array( 'output' => array( array( 'attributes' => array( 'data-' => 'x' ) ) ) ) ),
			'spec_attribute_name'
		);
	}

	public function test_an_attribute_interpolating_an_undeclared_prop_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'output' => array( array( 'attributes' => array( 'data-x' => '{{karmcp_missing}}' ) ) ) ,
				)
			),
			'spec_attribute_prop'
		);
	}

	// ----- Classes are literals, never values -----

	public function test_a_class_with_markup_is_rejected(): void {
		$this->assertRejected(
			$this->spec( array( 'output' => array( array( 'class' => '"><script>alert(1)</script>' ) ) ) ),
			'spec_rule_class'
		);
	}

	public function test_a_rule_that_does_nothing_is_rejected(): void {
		$this->assertRejected(
			$this->spec( array( 'output' => array( array( 'when' => array( 'prop' => 'karmcp_particles' ) ) ) ) ),
			'spec_rule_empty'
		);
	}

	// ----- Targets -----

	public function test_targets_are_required(): void {
		$this->assertRejected( $this->spec( array( 'targets' => array() ) ), 'spec_targets' );
	}

	public function test_a_classic_element_name_is_rejected(): void {
		// Elementor 4 atomic types are prefixed e-; "container" or "section"
		// would silently never match.
		$this->assertRejected( $this->spec( array( 'targets' => array( 'container' ) ) ), 'spec_target_name' );
	}

	public function test_the_wildcard_target_is_accepted(): void {
		$this->assertTrue( KarMCP_Extension_Spec::validate( $this->spec( array( 'targets' => array( '*' ) ) ) ) );
	}

	// ----- Conditions -----

	public function test_a_condition_on_an_undeclared_prop_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'output' => array(
						array( 'when' => array( 'prop' => 'karmcp_nope' ), 'class' => 'x' ),
					),
				)
			),
			'spec_condition_prop'
		);
	}

	public function test_an_unknown_operator_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'output' => array(
						array(
							'when'  => array( 'prop' => 'karmcp_particles', 'matches' => '/^s/' ),
							'class' => 'x',
						),
					),
				)
			),
			'spec_condition_key'
		);
	}

	// ----- Assets -----

	public function test_oversized_critical_css_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'styles' => array( 'critical' => str_repeat( 'a', KarMCP_Extension_Spec::MAX_CRITICAL_CSS + 1 ) ),
				)
			),
			'spec_critical_long'
		);
	}

	public function test_critical_css_closing_its_own_style_tag_is_rejected(): void {
		$this->assertRejected(
			$this->spec( array( 'styles' => array( 'critical' => '.a{}</style><script>alert(1)</script>' ) ) ),
			'spec_critical_tag'
		);
	}

	public function test_php_in_an_asset_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'scripts' => 'var a = 1; <?php echo 1; ?>' ) ), 'spec_asset_php' );
	}

	public function test_plain_string_styles_are_treated_as_deferred(): void {
		$this->assertTrue(
			KarMCP_Extension_Spec::validate( $this->spec( array( 'styles' => '.a{color:red}' ) ) )
		);
	}

	// ----- Vocabulary -----

	public function test_there_is_no_color_type(): void {
		// Elementor 4.2 ships no color control under controls/types; a color
		// prop would compile to a control the editor cannot paint.
		$this->assertFalse( KarMCP_Extension_Spec::has_type( 'color' ) );

		$this->assertRejected(
			$this->spec(
				array(
					'props'  => array( array( 'name' => 'karmcp_tint', 'type' => 'color' ) ),
					'output' => array( array( 'class' => 'x' ) ),
				)
			),
			'spec_prop_type'
		);
	}

	public function test_describe_documents_the_two_house_rules(): void {
		$described = KarMCP_Extension_Spec::describe();

		$this->assertSame( 'karmcp_', $described['prop_prefix'] );
		$this->assertSame( array( 'data-', 'aria-' ), $described['output']['attributes']['allowed_prefixes'] );
		$this->assertNotEmpty( $described['prop_types'] );
	}
}
