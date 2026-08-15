<?php
/**
 * Login Guard: the decisions, without WordPress.
 *
 * Two things are worth pinning here, and the second is the one that would
 * actually hurt.
 *
 * The throttle: it has to lock after the threshold, escalate, and **always**
 * expire. A guard that can lock permanently gets uninstalled the first time it
 * catches the owner, and then the site has nothing at all.
 *
 * The address: behind a reverse proxy REMOTE_ADDR is the proxy, so every
 * visitor shares one address and the fifth failure by anybody locks out the
 * world. The forwarded header fixes that — and is forgeable by anyone unless it
 * arrives from a proxy that was declared, which would turn the lockout into
 * theatre. Both halves of that trade are tested, because getting either one
 * wrong produces a module that looks like it works.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security/class-login-guard-policy.php';

class LoginGuardPolicyTest extends TestCase {

	/** @var array<string,int> */
	private $config;

	protected function setUp(): void {
		$this->config = KarMCP_Login_Guard_Policy::normalize_config( array() );
	}

	/** N failures at $now minus 1 second each, so all fall inside the window. */
	private function failures( int $count, int $now ): array {
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $now - ( $i + 1 );
		}
		return $out;
	}

	// -----------------------------------------------------------------
	// normalize_config()
	// -----------------------------------------------------------------

	public function test_defaults_are_applied_when_nothing_is_configured(): void {
		$this->assertSame( 5, $this->config['threshold'] );
		$this->assertSame( 900, $this->config['window'] );
		$this->assertSame( 86400, $this->config['max_lockout'] );
	}

	/**
	 * A typo in the settings form must not become "everyone is locked out
	 * forever", so every value is clamped rather than trusted where it is used.
	 */
	public function test_nonsense_settings_are_clamped_not_trusted(): void {
		$c = KarMCP_Login_Guard_Policy::normalize_config(
			array(
				'threshold'    => 0,
				'window'       => -1,
				'base_lockout' => 0,
				'max_lockout'  => -99,
			)
		);

		$this->assertGreaterThanOrEqual( 1, $c['threshold'] );
		$this->assertGreaterThanOrEqual( 60, $c['window'] );
		$this->assertGreaterThanOrEqual( 60, $c['base_lockout'] );
		// The ceiling can never sit below the first lock, or escalation inverts.
		$this->assertGreaterThanOrEqual( $c['base_lockout'], $c['max_lockout'] );
	}

	public function test_an_absurd_max_lockout_is_capped_at_a_week(): void {
		$c = KarMCP_Login_Guard_Policy::normalize_config( array( 'max_lockout' => PHP_INT_MAX ) );
		$this->assertSame( 7 * 86400, $c['max_lockout'] );
	}

	// -----------------------------------------------------------------
	// lockout_duration()
	// -----------------------------------------------------------------

	public function test_the_lock_doubles_with_each_offence(): void {
		$this->assertSame( 900, KarMCP_Login_Guard_Policy::lockout_duration( 0, $this->config ) );
		$this->assertSame( 1800, KarMCP_Login_Guard_Policy::lockout_duration( 1, $this->config ) );
		$this->assertSame( 3600, KarMCP_Login_Guard_Policy::lockout_duration( 2, $this->config ) );
	}

	public function test_the_lock_never_exceeds_the_ceiling(): void {
		foreach ( array( 10, 50, 5000, PHP_INT_MAX ) as $priors ) {
			$this->assertSame(
				$this->config['max_lockout'],
				KarMCP_Login_Guard_Policy::lockout_duration( (int) $priors, $this->config ),
				'priors=' . $priors
			);
		}
	}

	// -----------------------------------------------------------------
	// evaluate()
	// -----------------------------------------------------------------

	public function test_below_the_threshold_nothing_happens(): void {
		$now = 1000000;
		$v   = KarMCP_Login_Guard_Policy::evaluate( $this->failures( 4, $now ), 0, 0, $now, $this->config );

		$this->assertFalse( $v['locked'] );
		$this->assertSame( 4, $v['recent_failures'] );
		$this->assertSame( 1, KarMCP_Login_Guard_Policy::attempts_left( $v['recent_failures'], $this->config ) );
	}

	public function test_the_threshold_locks(): void {
		$now = 1000000;
		$v   = KarMCP_Login_Guard_Policy::evaluate( $this->failures( 5, $now ), 0, 0, $now, $this->config );

		$this->assertTrue( $v['locked'] );
		$this->assertSame( 'threshold_reached', $v['reason'] );
		$this->assertSame( $now + 900, $v['until'] );
	}

	public function test_failures_outside_the_window_do_not_count(): void {
		$now = 1000000;
		$old = array_fill( 0, 10, $now - 5000 ); // Window is 900 s.

		$v = KarMCP_Login_Guard_Policy::evaluate( $old, 0, 0, $now, $this->config );
		$this->assertFalse( $v['locked'] );
		$this->assertSame( 0, $v['recent_failures'] );
	}

	/**
	 * The regression this guards: the failures that caused a long lock age out
	 * of the counting window long before the lock expires. If the verdict were
	 * re-derived from the failure count each time, a 24-hour lock would release
	 * itself after fifteen minutes.
	 */
	public function test_an_active_lock_holds_even_once_its_failures_have_aged_out(): void {
		$now   = 1000000;
		$until = $now + 40000;

		$v = KarMCP_Login_Guard_Policy::evaluate( array(), 0, $until, $now, $this->config );

		$this->assertTrue( $v['locked'] );
		$this->assertSame( 'locked', $v['reason'] );
		$this->assertSame( $until, $v['until'] );
		$this->assertSame( 40000, $v['retry_after'] );
	}

	public function test_an_expired_lock_releases(): void {
		$now = 1000000;
		$v   = KarMCP_Login_Guard_Policy::evaluate( array(), 3, $now - 1, $now, $this->config );

		$this->assertFalse( $v['locked'] );
	}

	public function test_a_repeat_offender_gets_a_longer_lock(): void {
		$now = 1000000;
		$v   = KarMCP_Login_Guard_Policy::evaluate( $this->failures( 5, $now ), 3, 0, $now, $this->config );

		$this->assertTrue( $v['locked'] );
		$this->assertSame( $now + 7200, $v['until'] ); // 900 * 2^3.
	}

	/** Timestamps in the future are somebody's clock skew, not evidence. */
	public function test_future_timestamps_are_ignored(): void {
		$now = 1000000;
		$v   = KarMCP_Login_Guard_Policy::evaluate( array_fill( 0, 10, $now + 500 ), 0, 0, $now, $this->config );

		$this->assertFalse( $v['locked'] );
	}

	// -----------------------------------------------------------------
	// resolve_ip() — the proxy trap
	// -----------------------------------------------------------------

	public function test_without_a_proxy_the_remote_address_is_used(): void {
		$ip = KarMCP_Login_Guard_Policy::resolve_ip(
			array( 'REMOTE_ADDR' => '203.0.113.7' ),
			array()
		);
		$this->assertSame( '203.0.113.7', $ip );
	}

	/**
	 * The half that stops the guard being theatre: a forwarded header from a
	 * hop nobody declared is the client's own claim about itself. Believing it
	 * lets an attacker mint a fresh identity per request and never lock.
	 */
	public function test_a_forwarded_header_from_an_undeclared_hop_is_ignored(): void {
		$ip = KarMCP_Login_Guard_Policy::resolve_ip(
			array(
				'REMOTE_ADDR'          => '203.0.113.7',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
			),
			array(
				'forwarded_header' => 'HTTP_X_FORWARDED_FOR',
				'trusted_proxies'  => array( '192.0.2.1' ),
			)
		);
		$this->assertSame( '203.0.113.7', $ip );
	}

	/**
	 * The other half: behind a declared proxy every visitor shares REMOTE_ADDR,
	 * so ignoring the header would lock out the world on the first few failures
	 * by anybody.
	 */
	public function test_a_forwarded_header_from_a_declared_proxy_is_believed(): void {
		$ip = KarMCP_Login_Guard_Policy::resolve_ip(
			array(
				'REMOTE_ADDR'            => '192.0.2.1',
				'HTTP_CF_CONNECTING_IP'  => '198.51.100.99',
			),
			array(
				'forwarded_header' => 'HTTP_CF_CONNECTING_IP',
				'trusted_proxies'  => array( '192.0.2.1' ),
			)
		);
		$this->assertSame( '198.51.100.99', $ip );
	}

	public function test_the_leftmost_non_proxy_entry_of_a_chain_wins(): void {
		$ip = KarMCP_Login_Guard_Policy::resolve_ip(
			array(
				'REMOTE_ADDR'          => '192.0.2.1',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 192.0.2.1',
			),
			array(
				'forwarded_header' => 'HTTP_X_FORWARDED_FOR',
				'trusted_proxies'  => array( '192.0.2.1' ),
			)
		);
		$this->assertSame( '198.51.100.99', $ip );
	}

	public function test_a_garbage_forwarded_header_falls_back_to_the_remote_address(): void {
		$ip = KarMCP_Login_Guard_Policy::resolve_ip(
			array(
				'REMOTE_ADDR'          => '192.0.2.1',
				'HTTP_X_FORWARDED_FOR' => 'not-an-ip, <script>',
			),
			array(
				'forwarded_header' => 'HTTP_X_FORWARDED_FOR',
				'trusted_proxies'  => array( '192.0.2.1' ),
			)
		);
		$this->assertSame( '192.0.2.1', $ip );
	}

	public function test_clean_ip_handles_ports_brackets_and_rubbish(): void {
		$this->assertSame( '203.0.113.7', KarMCP_Login_Guard_Policy::clean_ip( ' 203.0.113.7:51234 ' ) );
		$this->assertSame( '2001:db8::1', KarMCP_Login_Guard_Policy::clean_ip( '[2001:db8::1]:443' ) );
		$this->assertSame( '2001:db8::1', KarMCP_Login_Guard_Policy::clean_ip( '2001:db8::1' ) );
		$this->assertSame( '', KarMCP_Login_Guard_Policy::clean_ip( 'localhost' ) );
		$this->assertSame( '', KarMCP_Login_Guard_Policy::clean_ip( '' ) );
		$this->assertSame( '', KarMCP_Login_Guard_Policy::clean_ip( '999.999.999.999' ) );
	}
}
