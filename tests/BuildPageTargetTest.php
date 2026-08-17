<?php
/**
 * Public unit tests for build-page's existing-post target (`post_id` + `mode`).
 *
 * Without it, filling a CPT that build-page cannot create — a jet-popup used as
 * a course item — took three calls and left a scratch page behind whenever
 * something failed halfway. The gates matter more than the happy path here:
 * `mode` is deliberately not defaulted, because guessing "append" duplicates a
 * layout and guessing "replace" destroys one, and `replace` needs `confirm`
 * like every other write in this plugin that throws content away.
 *
 * Every case below returns before any Elementor dependency is touched, which is
 * also the point: a bad target costs nothing.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-id-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-element-factory.php';
require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';
require_once dirname( __DIR__ ) . '/includes/class-atomic-props.php';
require_once dirname( __DIR__ ) . '/includes/abilities/class-composite-abilities.php';

class BuildPageTargetTest extends \PHPUnit\Framework\TestCase {

	private KarMCP_Composite_Abilities $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->abilities = new KarMCP_Composite_Abilities( new KarMCP_Data(), new KarMCP_Element_Factory() );

		$post            = new \stdClass();
		$post->ID        = 555;
		$post->post_type = 'jet-popup';
		$GLOBALS['karmcp_test']['posts'][555] = $post;
	}

	/**
	 * A minimal valid structure, so only the target gating is under test.
	 *
	 * @param array $extra Input keys to merge in.
	 * @return array
	 */
	private function input( array $extra ): array {
		return array_merge(
			array( 'structure' => array( array( 'type' => 'container' ) ) ),
			$extra
		);
	}

	public function test_unknown_target_is_refused() {
		$result = $this->abilities->execute_build_page( $this->input( array( 'post_id' => 9999, 'mode' => 'append' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	public function test_target_the_user_cannot_edit_is_refused() {
		$GLOBALS['karmcp_test']['post_caps'][555] = false;

		$result = $this->abilities->execute_build_page( $this->input( array( 'post_id' => 555, 'mode' => 'append' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'cannot_edit', $result->get_error_code() );
	}

	public function test_mode_is_required_with_a_target() {
		$result = $this->abilities->execute_build_page( $this->input( array( 'post_id' => 555 ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_mode', $result->get_error_code() );
	}

	public function test_unknown_mode_is_refused() {
		$result = $this->abilities->execute_build_page( $this->input( array( 'post_id' => 555, 'mode' => 'merge' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_mode', $result->get_error_code() );
	}

	public function test_replace_without_confirm_is_refused() {
		$result = $this->abilities->execute_build_page( $this->input( array( 'post_id' => 555, 'mode' => 'replace' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'confirm_required', $result->get_error_code() );
	}

	public function test_title_is_only_required_when_creating() {
		// No post_id and no title: still the old error.
		$result = $this->abilities->execute_build_page( $this->input( array() ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_title', $result->get_error_code() );

		// With a target, the title comes from the post, so its absence is fine —
		// the call gets past the title gate and fails on the missing mode instead.
		$result = $this->abilities->execute_build_page( $this->input( array( 'post_id' => 555 ) ) );

		$this->assertSame( 'missing_mode', $result->get_error_code() );
	}

	public function test_permission_callback_gates_on_the_target_not_on_page_caps() {
		// Someone who may edit this post but holds no page-creation caps.
		$GLOBALS['karmcp_test']['caps']            = array();
		$GLOBALS['karmcp_test']['post_caps'][555]  = true;

		$this->assertTrue( $this->abilities->check_create_permission( array( 'post_id' => 555 ) ) );
		$this->assertFalse( $this->abilities->check_create_permission( array( 'post_id' => 9999 ) ) );
		$this->assertFalse( $this->abilities->check_create_permission( array() ) );
	}
}
