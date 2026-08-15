<?php
/**
 * Login Guard: the decision half, deliberately pure.
 *
 * Nothing in this class reads an option, a superglobal or the clock. It is
 * handed the failures, the history and the current time, and it answers whether
 * the subject is locked and until when — which is why the whole of it is
 * testable without WordPress, the same arrangement as KarMCP_Guardrails_Policy.
 *
 * Two decisions live here and both matter more than they look:
 *
 * 1. **Counting per IP and per username, separately.** Only by IP and a
 *    distributed attack against one account walks straight through; only by
 *    username and one address can sweep the whole user list unhindered.
 *
 * 2. **Resolving the client address.** Behind a reverse proxy `REMOTE_ADDR` is
 *    the *proxy*, so every visitor shares one address and the fifth failure by
 *    anybody locks out the world. The forwarded header fixes that and is
 *    forgeable by anyone unless it arrives from a proxy you trust, which turns
 *    the lockout into theatre. So: `REMOTE_ADDR` by default, the header only
 *    when the administrator has declared the proxy.
 *
 * @package KarMCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// DAY_IN_SECONDS is a WordPress constant and this file is also loaded by the
// test harness, which has no WordPress. Defined up front so the class stays
// pure and usable either way.
if ( ! defined( 'KARMCP_DAY_IN_SECONDS' ) ) {
	define( 'KARMCP_DAY_IN_SECONDS', 86400 );
}

/**
 * Pure login-throttling policy.
 *
 * @since 1.4.0
 */
class KarMCP_Login_Guard_Policy {

	/**
	 * Shipping defaults. Deliberately forgiving: a guard that locks out the
	 * owner is uninstalled the same afternoon, and then the site has nothing.
	 *
	 * @since 1.4.0
	 * @var array<string,int>
	 */
	const DEFAULTS = array(
		'threshold'    => 5,     // Failures inside the window before the first lock.
		'window'       => 900,   // 15 min: how far back failures are counted.
		'base_lockout' => 900,   // 15 min for the first lock.
		'max_lockout'  => 86400, // Hard ceiling of 24 h. Never permanent.
	);

	/** Ceiling on the doubling exponent, so the shift can't overflow. */
	const MAX_ESCALATION_STEPS = 20;

	/**
	 * Clamps a raw settings array into something the evaluator can trust.
	 *
	 * An administrator typo (threshold 0, window -1) must not turn into
	 * "everyone is locked out forever", so every value is bounded here rather
	 * than trusted at the point of use.
	 *
	 * @since 1.4.0
	 *
	 * @param array $raw Raw settings.
	 * @return array<string,int> Normalized config.
	 */
	public static function normalize_config( array $raw ): array {
		$c = array();

		$c['threshold']    = max( 1, min( 100, (int) ( $raw['threshold'] ?? self::DEFAULTS['threshold'] ) ) );
		$c['window']       = max( 60, min( KARMCP_DAY_IN_SECONDS, (int) ( $raw['window'] ?? self::DEFAULTS['window'] ) ) );
		$c['base_lockout'] = max( 60, min( KARMCP_DAY_IN_SECONDS, (int) ( $raw['base_lockout'] ?? self::DEFAULTS['base_lockout'] ) ) );
		$c['max_lockout']  = max( $c['base_lockout'], min( 7 * KARMCP_DAY_IN_SECONDS, (int) ( $raw['max_lockout'] ?? self::DEFAULTS['max_lockout'] ) ) );

		return $c;
	}

	/**
	 * How long the next lock lasts, given how many the subject has already
	 * collected. Doubles each time and stops at the ceiling.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $prior_lockouts Locks already served by this subject.
	 * @param array $config         Normalized config.
	 * @return int Seconds.
	 */
	public static function lockout_duration( int $prior_lockouts, array $config ): int {
		$steps    = max( 0, min( self::MAX_ESCALATION_STEPS, $prior_lockouts ) );
		$duration = (int) $config['base_lockout'] * ( 1 << $steps );

		return (int) min( $duration, $config['max_lockout'] );
	}

	/**
	 * Decides whether a subject is locked right now.
	 *
	 * @since 1.4.0
	 *
	 * @param int[] $failure_times  Unix timestamps of recent failures.
	 * @param int   $prior_lockouts How many locks this subject has already served.
	 * @param int   $active_until   Unix timestamp an existing lock runs to; 0 if none.
	 * @param int   $now            Current Unix timestamp.
	 * @param array $config         Normalized config.
	 * @return array{locked:bool,until:int,retry_after:int,recent_failures:int,reason:string}
	 */
	public static function evaluate( array $failure_times, int $prior_lockouts, int $active_until, int $now, array $config ): array {
		// An existing lock wins outright: it is not re-derived from the failure
		// count, because the failures that caused it age out of the window long
		// before a long lock expires — recomputing would release early.
		if ( $active_until > $now ) {
			return array(
				'locked'          => true,
				'until'           => $active_until,
				'retry_after'     => $active_until - $now,
				'recent_failures' => 0,
				'reason'          => 'locked',
			);
		}

		$cutoff = $now - (int) $config['window'];
		$recent = 0;
		foreach ( $failure_times as $t ) {
			if ( (int) $t > $cutoff && (int) $t <= $now ) {
				++$recent;
			}
		}

		if ( $recent >= (int) $config['threshold'] ) {
			$until = $now + self::lockout_duration( $prior_lockouts, $config );
			return array(
				'locked'          => true,
				'until'           => $until,
				'retry_after'     => $until - $now,
				'recent_failures' => $recent,
				'reason'          => 'threshold_reached',
			);
		}

		return array(
			'locked'          => false,
			'until'           => 0,
			'retry_after'     => 0,
			'recent_failures' => $recent,
			'reason'          => 'ok',
		);
	}

	/**
	 * How many attempts are left before the next lock. Only for the message
	 * shown to a human; never for a decision.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $recent_failures Failures inside the window.
	 * @param array $config          Normalized config.
	 * @return int
	 */
	public static function attempts_left( int $recent_failures, array $config ): int {
		return (int) max( 0, (int) $config['threshold'] - $recent_failures );
	}

	// ---------------------------------------------------------------------
	// Client address resolution
	// ---------------------------------------------------------------------

	/**
	 * The client address to throttle on.
	 *
	 * Reads the forwarded header **only** when `REMOTE_ADDR` is one of the
	 * proxies the administrator declared. That condition is the whole point: the
	 * header is set by whoever is talking to you, so trusting it unconditionally
	 * lets an attacker mint a fresh identity per request and never get locked;
	 * ignoring it behind Cloudflare collapses every visitor onto one address and
	 * locks out the world on the fifth failure by anybody.
	 *
	 * @since 1.4.0
	 *
	 * @param array $server  A `$_SERVER`-shaped array.
	 * @param array $config  {
	 *     @type string[] $trusted_proxies  Proxy addresses whose header is believed.
	 *     @type string   $forwarded_header Header name, e.g. `HTTP_CF_CONNECTING_IP`.
	 * }
	 * @return string The address, or '' when none can be determined.
	 */
	public static function resolve_ip( array $server, array $config ): string {
		$remote = self::clean_ip( (string) ( $server['REMOTE_ADDR'] ?? '' ) );

		$trusted = array_filter( array_map( array( __CLASS__, 'clean_ip' ), (array) ( $config['trusted_proxies'] ?? array() ) ) );
		$header  = (string) ( $config['forwarded_header'] ?? '' );

		if ( '' === $header || empty( $trusted ) || '' === $remote ) {
			return $remote;
		}
		if ( ! in_array( $remote, $trusted, true ) ) {
			// The hop talking to us is not a declared proxy, so whatever header it
			// sent is its own claim about itself. Ignore it.
			return $remote;
		}

		$forwarded = (string) ( $server[ $header ] ?? '' );
		if ( '' === $forwarded ) {
			return $remote;
		}

		// X-Forwarded-For is a chain, "client, proxy1, proxy2". The leftmost entry
		// is the originating client — and also the only one the client controls,
		// which is why this branch is reachable only from a trusted hop.
		foreach ( explode( ',', $forwarded ) as $candidate ) {
			$ip = self::clean_ip( $candidate );
			if ( '' !== $ip && ! in_array( $ip, $trusted, true ) ) {
				return $ip;
			}
		}

		return $remote;
	}

	/**
	 * Trims and validates a single address, dropping a `:port` suffix and the
	 * brackets around an IPv6 literal.
	 *
	 * @since 1.4.0
	 *
	 * @param string $raw Candidate address.
	 * @return string Valid address, or ''.
	 */
	public static function clean_ip( string $raw ): string {
		$ip = trim( $raw );
		if ( '' === $ip ) {
			return '';
		}
		if ( '[' === $ip[0] ) {
			$end = strpos( $ip, ']' );
			if ( false !== $end ) {
				$ip = substr( $ip, 1, $end - 1 );
			}
		} elseif ( substr_count( $ip, ':' ) === 1 && false !== strpos( $ip, '.' ) ) {
			// "1.2.3.4:5678" — IPv4 with a port. A bare IPv6 has several colons.
			$ip = substr( $ip, 0, strpos( $ip, ':' ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
