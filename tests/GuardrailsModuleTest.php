<?php
/**
 * Guardrails module — the wiring between the policy and the write veto.
 *
 * The policy itself is covered by GuardrailsPolicyTest. What is pinned here is
 * the plumbing that decides *which* facts the policy gets to judge, because
 * that is where a guardrail silently stops guarding — or starts blocking reads.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/modules/class-module.php';
require_once __DIR__ . '/../includes/modules/guardrails/class-guardrails-policy.php';
require_once __DIR__ . '/../includes/modules/guardrails/class-guardrails-module.php';

class GuardrailsModuleTest extends TestCase {

	/** @var KarMCP_Guardrails_Module */
	private $module;

	protected function setUp(): void {
		karmcp_test_reset();
		karmcp_test_reset_hooks();
		$this->module = new KarMCP_Guardrails_Module();
		$this->at( '12:00', 3 );
	}

	/** Pin the clock the module reads. */
	private function at( string $time, int $day ): void {
		$GLOBALS['karmcp_test']['now'] = array( 'H:i' => $time, 'N' => (string) $day );
	}

	/** Set one of the module's options. */
	private function option( string $short, string $value ): void {
		$GLOBALS['karmcp_test']['options'][ KarMCP_Guardrails_Module::PREFIX . $short ] = $value;
	}

	/** Register an ability in the fixture with the given annotations. */
	private function ability( string $name, array $annotations ): void {
		$GLOBALS['karmcp_test']['abilities'][ $name ] = array( 'meta' => array( 'annotations' => $annotations ) );
	}

	/** Put a post in the fixture so get_post() resolves it. */
	private function post( int $id, string $type ): void {
		$GLOBALS['karmcp_test']['posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => $type ) );
	}

	// ---- the dispatcher envelope -------------------------------------------

	/**
	 * `karmcp/call-tool` is not annotated read-only, so the veto fires for the
	 * envelope before it fires for the target. Judging the envelope would refuse
	 * *reads*: an agent running list-posts through call-tool in compact mode
	 * would be blocked by a write rule. The envelope must pass through, and the
	 * target — which runs via the same wrapped callback — is what gets judged.
	 */
	public function test_call_tool_envelope_is_never_judged(): void {
		$this->option( 'read_only', '1' );

		$this->assertNull(
			$this->module->veto_write( null, 'karmcp/call-tool', array( 'name' => 'karmcp/list-posts', 'arguments' => array() ) ),
			'The dispatcher envelope must pass through, or compact mode blocks reads.'
		);

		// The target itself is still judged, so nothing is actually let through.
		$this->assertInstanceOf(
			WP_Error::class,
			$this->module->veto_write( null, 'karmcp/update-post', array( 'post_id' => 7 ) )
		);
	}

	// ---- an earlier listener's decision stands ------------------------------

	public function test_an_existing_veto_is_not_overwritten(): void {
		$existing = new WP_Error( 'someone_else', 'Blocked upstream.' );
		$this->assertSame( $existing, $this->module->veto_write( $existing, 'karmcp/update-post', array() ) );
	}

	// ---- which ids count as posts -------------------------------------------

	public function test_protected_post_is_matched_through_post_id(): void {
		$this->option( 'protected_posts', '340' );
		$result = $this->module->veto_write( null, 'karmcp/update-post', array( 'post_id' => 340 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'karmcp_policy_protected_post', $result->get_error_code() );
	}

	/**
	 * `id` means a snippet, redirect or template row in plenty of tools. Treating
	 * it as a post id would block redirect 340 because post 340 is protected —
	 * a bug that reads like a policy decision, which is the worst kind.
	 */
	public function test_a_bare_id_that_is_not_a_post_is_ignored(): void {
		$this->option( 'protected_posts', '340' );
		$this->assertNull( $this->module->veto_write( null, 'karmcp/update-redirect', array( 'id' => 340 ) ) );
	}

	public function test_a_bare_id_that_is_a_post_counts(): void {
		$this->option( 'protected_posts', '340' );
		$this->post( 340, 'page' );
		$this->assertInstanceOf( WP_Error::class, $this->module->veto_write( null, 'karmcp/update-post', array( 'id' => 340 ) ) );
	}

	// ---- protected types ----------------------------------------------------

	public function test_protected_type_is_resolved_from_the_post_id(): void {
		$this->option( 'protected_types', 'shop_order' );
		$this->post( 88, 'shop_order' );

		$result = $this->module->veto_write( null, 'karmcp/update-post', array( 'post_id' => 88 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'karmcp_policy_protected_type', $result->get_error_code() );
	}

	/** A create call has no id yet, so the type has to come from the input. */
	public function test_protected_type_is_caught_on_creation(): void {
		$this->option( 'protected_types', 'shop_order' );
		$this->assertInstanceOf(
			WP_Error::class,
			$this->module->veto_write( null, 'karmcp/create-post', array( 'post_type' => 'shop_order', 'title' => 'x' ) )
		);
	}

	// ---- the destructive flag comes from the ability's own annotation --------

	public function test_destructive_flag_is_read_from_the_registered_ability(): void {
		$this->option( 'block_destructive', '1' );
		$this->ability( 'karmcp/delete-theme', array( 'readonly' => false, 'destructive' => true ) );
		$this->ability( 'karmcp/update-post', array( 'readonly' => false, 'destructive' => false ) );

		$this->assertInstanceOf( WP_Error::class, $this->module->veto_write( null, 'karmcp/delete-theme', array() ) );
		$this->assertNull( $this->module->veto_write( null, 'karmcp/update-post', array() ) );
	}

	// ---- the freeze window reads the site clock -----------------------------

	public function test_freeze_window_uses_the_site_clock(): void {
		$this->option( 'freeze', '1' );
		$this->option( 'freeze_from', '09:00' );
		$this->option( 'freeze_to', '19:00' );
		$this->option( 'freeze_days', '1,2,3,4,5' );

		$this->at( '12:00', 3 );
		$this->assertInstanceOf( WP_Error::class, $this->module->veto_write( null, 'karmcp/update-post', array() ) );

		$this->at( '20:00', 3 );
		$this->assertNull( $this->module->veto_write( null, 'karmcp/update-post', array() ) );

		$this->at( '12:00', 6 );
		$this->assertNull( $this->module->veto_write( null, 'karmcp/update-post', array() ) );
	}

	// ---- the discovery seam -------------------------------------------------

	/** With no policy set, the seam must come back exactly as it went in. */
	public function test_discovery_memory_is_untouched_when_nothing_is_configured(): void {
		$this->assertSame( '', $this->module->discovery_memory( '' ) );
		$this->assertSame( '## Existing', $this->module->discovery_memory( '## Existing' ) );
	}

	public function test_discovery_memory_appends_without_clobbering(): void {
		$this->option( 'read_only', '1' );

		$fresh = $this->module->discovery_memory( '' );
		$this->assertStringContainsString( '## Site policy', $fresh );

		$appended = $this->module->discovery_memory( '## Existing' );
		$this->assertStringStartsWith( '## Existing', $appended );
		$this->assertStringContainsString( '## Site policy', $appended );
	}

	// ---- the module registers on both seams ---------------------------------

	public function test_register_wires_both_seams(): void {
		$this->module->register();
		$hooks = $GLOBALS['karmcp_test_hooks'];

		$this->assertArrayHasKey( 'karmcp_before_write', $hooks, 'Enforcement seam not wired.' );
		$this->assertArrayHasKey( 'karmcp_discovery_memory', $hooks, 'Prevention seam not wired.' );
	}
}
