<?php
/**
 * The Widget Builder MCP surface.
 *
 * Covers what is checkable without a database: that every tool registers under
 * the karmcp category (an ability without it is dropped silently), that the
 * destructive one is both badged and gated behind confirm, and that the dry-run
 * validator really runs the compiler rather than a lookalike.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';
require_once __DIR__ . '/../includes/sandbox/class-widget-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-widget-generator.php';
require_once __DIR__ . '/../includes/abilities/class-widget-builder-abilities.php';

class WidgetBuilderAbilitiesTest extends TestCase {

	private KarMCP_Widget_Builder_Abilities $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->abilities = new KarMCP_Widget_Builder_Abilities();
	}

	private function spec(): array {
		return array(
			'meta'     => array( 'title' => 'Testimonial' ),
			'controls' => array( array( 'name' => 'quote', 'type' => 'text' ) ),
			'template' => '<blockquote>{{quote}}</blockquote>',
		);
	}

	public function test_every_tool_registers_with_the_karmcp_category(): void {
		$this->abilities->register();

		foreach ( $this->abilities->get_ability_names() as $name ) {
			$this->assertArrayHasKey( $name, $GLOBALS['karmcp_test']['abilities'], "$name did not register" );
			$this->assertSame( 'karmcp', $GLOBALS['karmcp_test']['abilities'][ $name ]['category'] ?? null, "$name is missing its category" );
		}
	}

	public function test_the_catalog_and_the_registration_agree(): void {
		// Drift here means the Tools tab offers a toggle for a tool that does not
		// exist, or hides one that does.
		$this->assertSame(
			array(
				'karmcp/list-control-types',
				'karmcp/validate-widget-spec',
				'karmcp/create-custom-widget',
				'karmcp/update-custom-widget',
				'karmcp/get-custom-widget',
				'karmcp/list-custom-widgets',
				'karmcp/set-widget-status',
				'karmcp/delete-custom-widget',
			),
			$this->abilities->get_ability_names()
		);
	}

	public function test_read_tools_are_annotated_read_only(): void {
		$this->abilities->register();

		foreach ( array( 'karmcp/list-control-types', 'karmcp/validate-widget-spec', 'karmcp/get-custom-widget', 'karmcp/list-custom-widgets' ) as $name ) {
			$annotations = $GLOBALS['karmcp_test']['abilities'][ $name ]['meta']['annotations'] ?? array();
			$this->assertTrue( $annotations['readonly'] ?? false, "$name should be read-only" );
		}
	}

	public function test_delete_is_badged_destructive_and_requires_confirm(): void {
		$this->abilities->register();

		$registered = $GLOBALS['karmcp_test']['abilities']['karmcp/delete-custom-widget'];
		$this->assertTrue( $registered['meta']['annotations']['destructive'] ?? false );
		$this->assertContains( 'confirm', $registered['input_schema']['required'] );

		$result = $this->abilities->execute_delete( array( 'widget_id' => 7 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'confirm_required', $result->get_error_code() );
	}

	public function test_delete_without_an_id_is_refused(): void {
		$result = $this->abilities->execute_delete( array( 'confirm' => true ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}

	public function test_validate_returns_the_compiled_source_for_a_good_spec(): void {
		$result = $this->abilities->execute_validate( array( 'spec' => $this->spec() ) );

		$this->assertTrue( $result['valid'] );
		$this->assertStringContainsString( 'extends \\Elementor\\Widget_Base', $result['php'] );
		$this->assertStringContainsString( 'esc_html', $result['php'] );
	}

	public function test_validate_reports_the_compilers_own_error(): void {
		$spec             = $this->spec();
		$spec['template'] = '<p>{{quote}}<script>alert(1)</script></p>';

		$result = $this->abilities->execute_validate( array( 'spec' => $spec ) );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'template_script', $result['code'] );
		$this->assertArrayNotHasKey( 'php', $result );
	}

	public function test_control_types_are_self_describing(): void {
		$described = $this->abilities->execute_list_control_types( array() );

		$this->assertSame( KarMCP_Widget_Spec::SPEC_VERSION, $described['spec_version'] );
		$this->assertNotEmpty( $described['control_types'] );
	}

	public function test_set_status_rejects_an_unknown_status(): void {
		$result = $this->abilities->execute_set_status( array( 'widget_id' => 3, 'status' => 'live' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	public function test_update_without_an_id_is_refused(): void {
		$result = $this->abilities->execute_update( array( 'spec' => $this->spec() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}

	/** A spec that arrives without a version is stamped, so storage records it. */
	public function test_a_spec_without_a_version_is_stamped_before_compiling(): void {
		$reflection = new ReflectionMethod( KarMCP_Widget_Builder_Abilities::class, 'normalize_spec' );

		$normalized = $reflection->invoke( $this->abilities, array( 'spec' => $this->spec() ) );

		$this->assertSame( KarMCP_Widget_Spec::SPEC_VERSION, $normalized['spec_version'] );
	}
}
