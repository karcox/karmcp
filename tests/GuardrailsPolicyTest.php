<?php
/**
 * Guardrails policy — the site-owner rules layered over the capability checks.
 *
 * These pin the decisions an admin is trusting: that a freeze window really
 * blocks, that it stops blocking when it should, and that an unconfigured
 * policy is inert. A guardrail that quietly stops guarding is worse than none,
 * because the site owner stops watching.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/modules/guardrails/class-guardrails-policy.php';

class GuardrailsPolicyTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** A write request with sane blanks, overridden per test. */
	private function request( array $overrides = array() ): array {
		return array_merge(
			array(
				'tool'        => 'karmcp/update-post',
				'destructive' => false,
				'post_ids'    => array(),
				'post_types'  => array(),
				'time'        => '12:00',
				'day'         => 3,
			),
			$overrides
		);
	}

	private function assertBlocked( array $policy, array $request, string $expected_code ): WP_Error {
		$result = KarMCP_Guardrails_Policy::evaluate( $policy, $this->request( $request ) );
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the write to be blocked.' );
		$this->assertSame( $expected_code, $result->get_error_code() );
		return $result;
	}

	private function assertAllowed( array $policy, array $request = array() ): void {
		$this->assertNull(
			KarMCP_Guardrails_Policy::evaluate( $policy, $this->request( $request ) ),
			'Expected the write to be allowed.'
		);
	}

	// ---- an unconfigured policy must change nothing ------------------------

	/**
	 * The module ships opt-in and empty. If the defaults blocked anything, every
	 * site that switched it on to "see what it does" would break.
	 */
	public function test_default_policy_allows_everything(): void {
		$this->assertAllowed( KarMCP_Guardrails_Policy::defaults() );
		$this->assertAllowed( array(), array( 'destructive' => true, 'tool' => 'karmcp/delete-post' ) );
	}

	// ---- read-only + destructive -------------------------------------------

	public function test_read_only_blocks_every_write(): void {
		$this->assertBlocked( array( 'read_only' => true ), array(), 'karmcp_policy_read_only' );
		$this->assertBlocked( array( 'read_only' => true ), array( 'tool' => 'karmcp/add-container' ), 'karmcp_policy_read_only' );
	}

	public function test_block_destructive_only_catches_destructive_tools(): void {
		$policy = array( 'block_destructive' => true );
		$this->assertBlocked( $policy, array( 'destructive' => true, 'tool' => 'karmcp/delete-theme' ), 'karmcp_policy_destructive' );
		$this->assertAllowed( $policy, array( 'destructive' => false ) );
	}

	/** The error text is read by the agent, so it must name the tool it refused. */
	public function test_destructive_error_names_the_tool(): void {
		$error = $this->assertBlocked(
			array( 'block_destructive' => true ),
			array( 'destructive' => true, 'tool' => 'karmcp/delete-theme' ),
			'karmcp_policy_destructive'
		);
		$this->assertStringContainsString( 'karmcp/delete-theme', $error->get_error_message() );
	}

	// ---- protected content --------------------------------------------------

	public function test_protected_post_is_blocked_and_its_neighbours_are_not(): void {
		$policy = array( 'protected_posts' => array( 12, 340 ) );
		$this->assertBlocked( $policy, array( 'post_ids' => array( 340 ) ), 'karmcp_policy_protected_post' );
		$this->assertAllowed( $policy, array( 'post_ids' => array( 341 ) ) );
	}

	public function test_protected_post_type_is_blocked(): void {
		$policy = array( 'protected_types' => array( 'shop_order' ) );
		$this->assertBlocked( $policy, array( 'post_types' => array( 'shop_order' ) ), 'karmcp_policy_protected_type' );
		$this->assertAllowed( $policy, array( 'post_types' => array( 'page' ) ) );
	}

	// ---- the freeze window --------------------------------------------------

	public function test_freeze_blocks_inside_the_window_only(): void {
		$policy = array( 'freeze' => true, 'freeze_from' => '09:00', 'freeze_to' => '19:00', 'freeze_days' => array( 1, 2, 3, 4, 5 ) );
		$this->assertBlocked( $policy, array( 'time' => '09:00', 'day' => 3 ), 'karmcp_policy_freeze' );
		$this->assertBlocked( $policy, array( 'time' => '18:59', 'day' => 3 ), 'karmcp_policy_freeze' );
		$this->assertAllowed( $policy, array( 'time' => '08:59', 'day' => 3 ) );
		// The end is exclusive: 19:00 is when work stops, not the last blocked minute.
		$this->assertAllowed( $policy, array( 'time' => '19:00', 'day' => 3 ) );
	}

	public function test_freeze_respects_the_day_list(): void {
		$policy = array( 'freeze' => true, 'freeze_from' => '09:00', 'freeze_to' => '19:00', 'freeze_days' => array( 1, 2, 3, 4, 5 ) );
		$this->assertBlocked( $policy, array( 'time' => '12:00', 'day' => 5 ), 'karmcp_policy_freeze' );
		$this->assertAllowed( $policy, array( 'time' => '12:00', 'day' => 6 ) );
		$this->assertAllowed( $policy, array( 'time' => '12:00', 'day' => 7 ) );
	}

	/**
	 * Clearing every day narrows a schedule to nothing in most UIs. Here it must
	 * mean "every day": an admin who switched the freeze on and then cleared the
	 * checkboxes has not asked for the rule to stop applying.
	 */
	public function test_empty_day_list_means_every_day(): void {
		$policy = array( 'freeze' => true, 'freeze_from' => '09:00', 'freeze_to' => '19:00', 'freeze_days' => array() );
		$this->assertBlocked( $policy, array( 'time' => '12:00', 'day' => 7 ), 'karmcp_policy_freeze' );
	}

	/** A window that ends before it starts runs over midnight. */
	public function test_overnight_window_wraps(): void {
		$policy = array( 'freeze' => true, 'freeze_from' => '22:00', 'freeze_to' => '06:00', 'freeze_days' => array() );
		$this->assertBlocked( $policy, array( 'time' => '23:30' ), 'karmcp_policy_freeze' );
		$this->assertBlocked( $policy, array( 'time' => '02:00' ), 'karmcp_policy_freeze' );
		$this->assertAllowed( $policy, array( 'time' => '06:00' ) );
		$this->assertAllowed( $policy, array( 'time' => '21:59' ) );
	}

	/**
	 * Equal ends are a typo, not a request to block the whole day. Reading them
	 * as 24h would take a site offline for writes on a mis-click.
	 */
	public function test_zero_length_window_blocks_nothing(): void {
		$this->assertFalse( KarMCP_Guardrails_Policy::in_window( '09:00', '09:00', '09:00' ) );
		$this->assertAllowed( array( 'freeze' => true, 'freeze_from' => '09:00', 'freeze_to' => '09:00' ) );
	}

	public function test_unparseable_times_never_block(): void {
		$this->assertFalse( KarMCP_Guardrails_Policy::in_window( 'nonsense', '19:00', '12:00' ) );
		$this->assertFalse( KarMCP_Guardrails_Policy::in_window( '09:00', '19:00', '25:99' ) );
	}

	// ---- reading the stored options ----------------------------------------

	public function test_from_options_parses_stored_strings(): void {
		$policy = KarMCP_Guardrails_Policy::from_options(
			array(
				'read_only'       => '0',
				'freeze'          => '1',
				'freeze_from'     => '9:30',
				'freeze_days'     => '1,2,3',
				'protected_posts' => '12, 340, 12',
				'protected_types' => 'Product, shop_order',
			)
		);

		$this->assertFalse( $policy['read_only'] );
		$this->assertTrue( $policy['freeze'] );
		$this->assertSame( '09:30', $policy['freeze_from'], 'Times are normalised to HH:MM.' );
		$this->assertSame( '19:00', $policy['freeze_to'], 'A missing value falls back to the default.' );
		$this->assertSame( array( 1, 2, 3 ), $policy['freeze_days'] );
		$this->assertSame( array( 12, 340 ), $policy['protected_posts'], 'Duplicates collapse.' );
		$this->assertSame( array( 'product', 'shop_order' ), $policy['protected_types'], 'Type slugs are lowercased.' );
	}

	public function test_garbage_time_falls_back_to_the_default(): void {
		$policy = KarMCP_Guardrails_Policy::from_options( array( 'freeze_from' => '99:99' ) );
		$this->assertSame( '09:00', $policy['freeze_from'] );
	}

	// ---- the discovery-context block ---------------------------------------

	/**
	 * With nothing configured the seam must stay exactly as empty as it was, or
	 * every agent pays for a "## Site policy" heading that says nothing.
	 */
	public function test_context_block_is_empty_when_nothing_is_configured(): void {
		$this->assertSame( '', KarMCP_Guardrails_Policy::context_block( KarMCP_Guardrails_Policy::defaults() ) );
	}

	public function test_context_block_states_the_active_rules(): void {
		$block = KarMCP_Guardrails_Policy::context_block(
			array(
				'freeze'          => true,
				'freeze_from'     => '09:00',
				'freeze_to'       => '19:00',
				'freeze_days'     => array( 1, 5 ),
				'protected_posts' => array( 12 ),
			)
		);

		$this->assertStringContainsString( '## Site policy', $block );
		$this->assertStringContainsString( '09:00', $block );
		$this->assertStringContainsString( 'Mon, Fri', $block );
		$this->assertStringContainsString( '12', $block );
	}

	/** House rules alone are worth publishing, with no rule switched on. */
	public function test_context_block_carries_house_rules_on_their_own(): void {
		$block = KarMCP_Guardrails_Policy::context_block( array( 'notes' => 'New pages start as drafts.' ) );
		$this->assertStringContainsString( 'New pages start as drafts.', $block );
		$this->assertStringNotContainsString( 'Enforced server-side', $block );
	}
}
