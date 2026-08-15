<?php
/**
 * The element extension MCP surface.
 *
 * Registration, annotations, the confirm gate on the destructive tool, and a
 * dry-run validator that runs the real compiler. The compiled output itself is
 * covered by ExtensionGeneratorTest.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-extension-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-extension-generator.php';
require_once __DIR__ . '/../includes/abilities/class-extension-builder-abilities.php';

class ExtensionBuilderAbilitiesTest extends TestCase {

	private KarMCP_Extension_Builder_Abilities $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->abilities = new KarMCP_Extension_Builder_Abilities();
	}

	private function spec(): array {
		return array(
			'meta'    => array( 'title' => 'Partículas' ),
			'targets' => array( 'e-div-block' ),
			'props'   => array(
				array(
					'name'    => 'karmcp_particles',
					'type'    => 'select',
					'default' => 'none',
					'options' => array( 'none' => 'Ninguna', 'snow' => 'Nieve' ),
				),
			),
			'output'  => array(
				array(
					'when'       => array( 'prop' => 'karmcp_particles', 'not' => 'none' ),
					'class'      => 'karmcp-fx-particles',
					'attributes' => array( 'data-karmcp-particles' => '{{karmcp_particles}}' ),
				),
			),
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
		$this->assertSame(
			array(
				'karmcp/list-element-targets',
				'karmcp/validate-extension-spec',
				'karmcp/create-element-extension',
				'karmcp/update-element-extension',
				'karmcp/get-element-extension',
				'karmcp/list-element-extensions',
				'karmcp/set-extension-status',
				'karmcp/delete-element-extension',
			),
			$this->abilities->get_ability_names()
		);
	}

	public function test_delete_is_badged_destructive_and_requires_confirm(): void {
		$this->abilities->register();

		$registered = $GLOBALS['karmcp_test']['abilities']['karmcp/delete-element-extension'];
		$this->assertTrue( $registered['meta']['annotations']['destructive'] ?? false );
		$this->assertContains( 'confirm', $registered['input_schema']['required'] );

		$result = $this->abilities->execute_delete( array( 'extension_id' => 5 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'confirm_required', $result->get_error_code() );
	}

	public function test_read_tools_are_annotated_read_only(): void {
		$this->abilities->register();

		foreach ( array( 'karmcp/list-element-targets', 'karmcp/validate-extension-spec', 'karmcp/get-element-extension', 'karmcp/list-element-extensions' ) as $name ) {
			$annotations = $GLOBALS['karmcp_test']['abilities'][ $name ]['meta']['annotations'] ?? array();
			$this->assertTrue( $annotations['readonly'] ?? false, "$name should be read-only" );
		}
	}

	public function test_validate_returns_the_compiled_source(): void {
		$result = $this->abilities->execute_validate( array( 'spec' => $this->spec() ) );

		$this->assertTrue( $result['valid'] );
		$this->assertStringContainsString( "add_filter( 'elementor/atomic-widgets/props-schema'", $result['php'] );
		$this->assertStringContainsString( "add_action( 'elementor/frontend/before_render'", $result['php'] );
	}

	public function test_validate_reports_the_compilers_own_error(): void {
		$spec                       = $this->spec();
		$spec['output'][0]['class'] = '"><script>';

		$result = $this->abilities->execute_validate( array( 'spec' => $spec ) );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'spec_rule_class', $result['code'] );
	}

	public function test_targets_are_listed_even_without_elementor(): void {
		// No Elementor in the test harness: the vocabulary still comes back, and
		// the site-specific target list is simply empty.
		$described = $this->abilities->execute_list_targets( array() );

		$this->assertSame( KarMCP_Extension_Spec::SPEC_VERSION, $described['spec_version'] );
		$this->assertSame( 'karmcp_', $described['prop_prefix'] );
		$this->assertSame( array(), $described['targets'] );
	}

	public function test_set_status_rejects_an_unknown_status(): void {
		$result = $this->abilities->execute_set_status( array( 'extension_id' => 3, 'status' => 'on' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	public function test_update_without_an_id_is_refused(): void {
		$result = $this->abilities->execute_update( array( 'spec' => $this->spec() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}
}
