<?php
/**
 * The Block Builder MCP surface.
 *
 * Mirrors the Widget Builder tests: registration under the karmcp category, the
 * destructive tool gated behind confirm, and a dry-run validator that runs the
 * real compiler. The compiled output itself is covered by BlockGeneratorTest.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';
require_once __DIR__ . '/../includes/sandbox/class-block-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-block-generator.php';
require_once __DIR__ . '/../includes/abilities/class-block-builder-abilities.php';

class BlockBuilderAbilitiesTest extends TestCase {

	private KarMCP_Block_Builder_Abilities $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->abilities = new KarMCP_Block_Builder_Abilities();
	}

	private function spec(): array {
		return array(
			'meta'       => array( 'title' => 'Callout' ),
			'attributes' => array( array( 'name' => 'heading', 'type' => 'text' ) ),
			'template'   => '<h2>{{heading}}</h2>',
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
				'karmcp/list-block-control-types',
				'karmcp/validate-block-spec',
				'karmcp/create-custom-block',
				'karmcp/update-custom-block',
				'karmcp/get-custom-block',
				'karmcp/list-custom-blocks',
				'karmcp/set-block-status',
				'karmcp/delete-custom-block',
			),
			$this->abilities->get_ability_names()
		);
	}

	public function test_delete_is_badged_destructive_and_requires_confirm(): void {
		$this->abilities->register();

		$registered = $GLOBALS['karmcp_test']['abilities']['karmcp/delete-custom-block'];
		$this->assertTrue( $registered['meta']['annotations']['destructive'] ?? false );

		$result = $this->abilities->execute_delete( array( 'block_id' => 4 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'confirm_required', $result->get_error_code() );
	}

	public function test_validate_returns_both_generated_files(): void {
		$result = $this->abilities->execute_validate( array( 'spec' => $this->spec() ) );

		$this->assertTrue( $result['valid'] );
		$this->assertStringContainsString( '"apiVersion": 3', $result['block_json'] );
		$this->assertStringContainsString( 'esc_html', $result['render_php'] );
	}

	public function test_validate_reports_the_compilers_own_error(): void {
		$spec             = $this->spec();
		$spec['template'] = '<h2>{{heading}}</h2><script>x()</script>';

		$result = $this->abilities->execute_validate( array( 'spec' => $spec ) );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'template_script', $result['code'] );
	}

	public function test_attribute_types_are_self_describing(): void {
		$described = $this->abilities->execute_list_types( array() );

		$this->assertSame( KarMCP_Block_Spec::SPEC_VERSION, $described['spec_version'] );
		$this->assertNotEmpty( $described['attribute_types'] );
	}

	public function test_set_status_rejects_an_unknown_status(): void {
		$result = $this->abilities->execute_set_status( array( 'block_id' => 2, 'status' => 'published' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	public function test_update_without_an_id_is_refused(): void {
		$result = $this->abilities->execute_update( array( 'spec' => $this->spec() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}
}
