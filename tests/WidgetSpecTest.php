<?php
/**
 * The widget spec contract.
 *
 * The spec is what an AI agent writes and what the plugin compiles, so its
 * validation is the boundary between "the agent described a widget" and "the
 * agent supplied code". These pin the rules that make that boundary real:
 * templates are data (no PHP, no script, no inline handlers), control names are
 * a closed vocabulary, and a template can only reference controls that exist.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';
require_once __DIR__ . '/../includes/sandbox/class-widget-spec.php';

class WidgetSpecTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function spec( array $overrides = array() ): array {
		return array_merge(
			array(
				'spec_version' => 1,
				'meta'         => array( 'title' => 'Testimonial' ),
				'controls'     => array(
					array( 'name' => 'quote', 'type' => 'text', 'label' => 'Quote' ),
				),
				'template'     => '<blockquote>{{quote}}</blockquote>',
			),
			$overrides
		);
	}

	private function assertRejected( array $spec, string $code ): void {
		$result = KarMCP_Widget_Spec::validate( $spec );
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the spec to be rejected.' );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_a_minimal_spec_is_valid(): void {
		$this->assertTrue( KarMCP_Widget_Spec::validate( $this->spec() ) );
	}

	public function test_spec_version_defaults_to_current_when_absent(): void {
		$spec = $this->spec();
		unset( $spec['spec_version'] );
		$this->assertTrue( KarMCP_Widget_Spec::validate( $spec ) );
	}

	public function test_future_spec_version_is_rejected(): void {
		$this->assertRejected(
			$this->spec( array( 'spec_version' => KarMCP_Widget_Spec::SPEC_VERSION + 1 ) ),
			'spec_version'
		);
	}

	public function test_title_is_required(): void {
		$this->assertRejected( $this->spec( array( 'meta' => array( 'title' => '  ' ) ) ), 'spec_title' );
	}

	public function test_template_is_required(): void {
		$this->assertRejected( $this->spec( array( 'template' => '' ) ), 'spec_template' );
	}

	// ----- The markup rules: a template is data, not behaviour -----

	public function test_template_with_php_tag_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<p><?php echo 1; ?></p>' ) ), 'template_php' );
	}

	public function test_template_with_short_php_tag_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<p><?= 1 ?></p>' ) ), 'template_php' );
	}

	public function test_template_with_script_tag_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<script>alert(1)</script>' ) ), 'template_script' );
	}

	public function test_template_with_inline_event_handler_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<div onclick="x()">y</div>' ) ), 'template_event_handler' );
	}

	public function test_template_with_javascript_url_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<a href="javascript:x()">y</a>' ) ), 'template_js_url' );
	}

	public function test_template_referencing_an_undeclared_control_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<p>{{missing}}</p>' ) ), 'spec_template_unknown' );
	}

	// ----- Controls -----

	public function test_unknown_control_type_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array( array( 'name' => 'quote', 'type' => 'sql' ) ),
				)
			),
			'spec_control_type'
		);
	}

	public function test_invalid_control_name_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array( array( 'name' => 'Quote-1', 'type' => 'text' ) ),
					'template' => '<p>x</p>',
				)
			),
			'spec_control_name'
		);
	}

	public function test_reserved_control_name_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array( array( 'name' => 'settings', 'type' => 'text' ) ),
					'template' => '<p>x</p>',
				)
			),
			'spec_control_reserved'
		);
	}

	public function test_duplicate_control_name_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array(
						array( 'name' => 'quote', 'type' => 'text' ),
						array( 'name' => 'quote', 'type' => 'textarea' ),
					),
				)
			),
			'spec_control_duplicate'
		);
	}

	public function test_select_without_options_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array( array( 'name' => 'size', 'type' => 'select' ) ),
					'template' => '<p>{{size}}</p>',
				)
			),
			'spec_select_options'
		);
	}

	public function test_select_option_key_must_be_a_slug(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array(
						array(
							'name'    => 'size',
							'type'    => 'select',
							'options' => array( '"><script>' => 'Broken' ),
						),
					),
					'template' => '<p>{{size}}</p>',
				)
			),
			'spec_select_option'
		);
	}

	public function test_select_with_valid_options_is_accepted(): void {
		$this->assertTrue(
			KarMCP_Widget_Spec::validate(
				$this->spec(
					array(
						'controls' => array(
							array(
								'name'    => 'size',
								'type'    => 'select',
								'options' => array( 'sm' => 'Small', 'lg' => 'Large' ),
							),
						),
						'template' => '<p class="is-{{size|attr}}">x</p>',
					)
				)
			)
		);
	}

	public function test_too_many_controls_is_rejected(): void {
		$controls = array();
		for ( $i = 0; $i <= KarMCP_Widget_Spec::MAX_CONTROLS; $i++ ) {
			$controls[] = array( 'name' => 'c' . $i, 'type' => 'text' );
		}
		$this->assertRejected(
			$this->spec( array( 'controls' => $controls, 'template' => '<p>x</p>' ) ),
			'spec_controls_many'
		);
	}

	public function test_unknown_section_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'controls' => array( array( 'name' => 'quote', 'type' => 'text', 'section' => 'advanced' ) ),
				)
			),
			'spec_control_section'
		);
	}

	// ----- Assets -----

	public function test_styles_with_php_tag_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'styles' => '.a{} <?php ?>' ) ), 'spec_asset_php' );
	}

	public function test_oversized_script_is_rejected(): void {
		$this->assertRejected(
			$this->spec( array( 'scripts' => str_repeat( 'a', KarMCP_Widget_Spec::MAX_ASSET + 1 ) ) ),
			'spec_asset_long'
		);
	}

	// ----- The vocabulary is self-describing -----

	public function test_describe_lists_every_control_type_with_its_syntax(): void {
		$described = KarMCP_Widget_Spec::describe();

		$this->assertSame( KarMCP_Widget_Spec::SPEC_VERSION, $described['spec_version'] );
		$this->assertCount( count( KarMCP_Widget_Spec::control_types() ), $described['control_types'] );
		$this->assertArrayHasKey( 'conditional', $described['template_syntax'] );

		// No "raw" escape hatch may ever exist: not as a modifier the compiler
		// accepts, and not as syntax the agent could copy out of the docs.
		$this->assertNotContains( 'raw', KarMCP_Sandbox_Template::MODIFIERS );
		foreach ( $described['template_syntax'] as $key => $syntax ) {
			if ( 'notes' === $key ) {
				continue;
			}
			$this->assertStringNotContainsString( '|raw', (string) $syntax );
		}
	}
}
