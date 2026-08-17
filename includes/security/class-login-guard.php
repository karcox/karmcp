<?php
/**
 * Login Guard: the wiring.
 *
 * Gathers the facts, hands them to KarMCP_Login_Guard_Policy, and acts on the
 * verdict. Every decision lives in the policy so it can be tested; everything
 * here is plumbing.
 *
 * Beyond throttling it closes the two doors that make brute force cheap:
 * anonymous user enumeration, which is how an attacker learns the usernames
 * worth trying, and XML-RPC's `system.multicall`, which lets one request carry
 * hundreds of password guesses.
 *
 * @package KarMCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login Guard runtime.
 *
 * @since 1.4.0
 */
class KarMCP_Login_Guard {

	const OPTION_SETTINGS = 'karmcp_login_guard_settings';

	/**
	 * Boots the guard. Called by the module only when it is active.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function init(): void {
		KarMCP_Login_Guard_Store::init();

		// Priority 30: after WordPress has decided whether the credentials are
		// good, so a locked-out subject is refused whatever they typed and a
		// correct password does not quietly reset the count.
		add_filter( 'authenticate', array( __CLASS__, 'block_when_locked' ), 30, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_failure' ), 10, 1 );
		add_action( 'wp_login', array( __CLASS__, 'on_success' ), 10, 2 );

		$settings = self::settings();

		if ( ! empty( $settings['block_enumeration'] ) ) {
			add_filter( 'rest_endpoints', array( __CLASS__, 'restrict_user_endpoints' ) );
			add_action( 'template_redirect', array( __CLASS__, 'block_author_probe' ) );
		}
		if ( ! empty( $settings['harden_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'drop_multicall' ) );
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
	}

	/**
	 * Effective settings, clamped.
	 *
	 * @since 1.4.0
	 * @return array
	 */
	public static function settings(): array {
		$raw = get_option( self::OPTION_SETTINGS, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$config = KarMCP_Login_Guard_Policy::normalize_config( $raw );

		$config['block_enumeration'] = ! isset( $raw['block_enumeration'] ) || (bool) $raw['block_enumeration'];
		$config['harden_xmlrpc']     = ! isset( $raw['harden_xmlrpc'] ) || (bool) $raw['harden_xmlrpc'];
		$config['forwarded_header']  = (string) ( $raw['forwarded_header'] ?? '' );
		$config['trusted_proxies']   = array_values( array_filter( (array) ( $raw['trusted_proxies'] ?? array() ) ) );

		return $config;
	}

	/**
	 * The address to throttle on.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- resolve_ip() validates every candidate with FILTER_VALIDATE_IP and returns '' otherwise.
		return KarMCP_Login_Guard_Policy::resolve_ip( (array) $_SERVER, self::settings() );
	}

	/**
	 * Whether this request is the MCP server or its OAuth endpoints.
	 *
	 * Those must never be counted: OAuth already rate-limits `/register` on its
	 * own, and a client that retries a token exchange would otherwise lock out
	 * the very agent the plugin exists to serve.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_mcp_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a substring, never output or stored.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return false;
		}
		foreach ( array( '/mcp/karmcp-server', '/karmcp/v1/oauth', '/.well-known/oauth' ) as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Refuses authentication while the address or the username is locked.
	 *
	 * @since 1.4.0
	 *
	 * @param null|WP_User|WP_Error $user     Result so far.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return null|WP_User|WP_Error
	 */
	public static function block_when_locked( $user, $username, $password ) {
		unset( $password );

		if ( self::is_mcp_request() ) {
			return $user;
		}

		$now      = time();
		$username = is_string( $username ) ? $username : '';

		foreach ( self::subjects( $username ) as $subject ) {
			$until = KarMCP_Login_Guard_Store::active_lock_until( $subject['type'], $subject['value'], $now );
			if ( $until > $now ) {
				return new WP_Error(
					'karmcp_login_locked',
					sprintf(
						/* translators: %s: human-readable duration, e.g. "15 minutes". */
						__( '<strong>Too many failed attempts.</strong> Sign-in is blocked for another %s. This is KarMCP\'s Login Guard, not your password.', 'karmcp' ),
						human_time_diff( $now, $until )
					),
					array( 'retry_after' => $until - $now )
				);
			}
		}

		return $user;
	}

	/**
	 * Records a failure and locks the subject when the threshold is crossed.
	 *
	 * @since 1.4.0
	 *
	 * @param string $username Attempted username.
	 * @return void
	 */
	public static function on_failure( $username ): void {
		if ( self::is_mcp_request() ) {
			return;
		}

		$now      = time();
		$config   = self::settings();
		$username = is_string( $username ) ? sanitize_user( $username, true ) : '';
		$ip       = self::client_ip();

		KarMCP_Login_Guard_Store::record_failure( $ip, $username, $now );

		foreach ( self::subjects( $username ) as $subject ) {
			$verdict = KarMCP_Login_Guard_Policy::evaluate(
				KarMCP_Login_Guard_Store::failures_since( $subject['type'], $subject['value'], $now - (int) $config['window'] ),
				KarMCP_Login_Guard_Store::prior_lockouts( $subject['type'], $subject['value'], $now - KarMCP_Login_Guard_Store::RETENTION ),
				KarMCP_Login_Guard_Store::active_lock_until( $subject['type'], $subject['value'], $now ),
				$now,
				$config
			);

			if ( $verdict['locked'] && 'threshold_reached' === $verdict['reason'] ) {
				KarMCP_Login_Guard_Store::record_lock( $subject['type'], $subject['value'], $username, $now, $verdict['until'] );
			}
		}
	}

	/**
	 * Wipes the failure slate on a successful sign-in — the failures only, not
	 * the lock history, so alternating a success with near-threshold failures
	 * cannot farm free attempts forever.
	 *
	 * @since 1.4.0
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       The user.
	 * @return void
	 */
	public static function on_success( $user_login, $user = null ): void {
		unset( $user );
		$ip = self::client_ip();
		if ( '' !== $ip ) {
			KarMCP_Login_Guard_Store::clear_failures( 'ip', $ip );
		}
		if ( is_string( $user_login ) && '' !== $user_login ) {
			KarMCP_Login_Guard_Store::clear_failures( 'user', $user_login );
		}
	}

	/**
	 * The two subjects a single attempt counts against.
	 *
	 * @since 1.4.0
	 *
	 * @param string $username Attempted username.
	 * @return array<int,array{type:string,value:string}>
	 */
	private static function subjects( string $username ): array {
		$out = array();
		$ip  = self::client_ip();
		if ( '' !== $ip ) {
			$out[] = array(
				'type'  => 'ip',
				'value' => $ip,
			);
		}
		if ( '' !== $username ) {
			$out[] = array(
				'type'  => 'user',
				'value' => $username,
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Reconnaissance
	// ---------------------------------------------------------------------

	/**
	 * Requires authentication on the REST user routes.
	 *
	 * `/wp-json/wp/v2/users` hands an anonymous caller every username on the
	 * site, which is the half of a brute-force attack that happens before the
	 * first password is tried. The routes stay available to signed-in callers
	 * that can list users, because blocks, themes and the editor rely on them.
	 *
	 * @since 1.4.0
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array
	 */
	public static function restrict_user_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( empty( $endpoints[ $route ] ) ) {
				continue;
			}
			foreach ( $endpoints[ $route ] as $i => $handler ) {
				$existing = $handler['permission_callback'] ?? null;

				$endpoints[ $route ][ $i ]['permission_callback'] = static function ( $request ) use ( $existing ) {
					if ( ! is_user_logged_in() ) {
						return new WP_Error(
							'karmcp_enumeration_blocked',
							__( 'Listing users requires authentication.', 'karmcp' ),
							array( 'status' => 401 )
						);
					}
					return is_callable( $existing ) ? $existing( $request ) : true;
				};
			}
		}
		return $endpoints;
	}

	/**
	 * Blocks `?author=N`, which redirects to the author archive and leaks the
	 * username in the resulting slug.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function block_author_probe(): void {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only probe check on a public request.
		if ( isset( $_GET['author'] ) && is_numeric( sanitize_text_field( wp_unslash( $_GET['author'] ) ) ) ) {
			wp_die(
				esc_html__( 'Not available.', 'karmcp' ),
				esc_html__( 'Forbidden', 'karmcp' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Drops `system.multicall`, which batches hundreds of password guesses into
	 * a single XML-RPC request and is what makes XML-RPC worth attacking.
	 *
	 * @since 1.4.0
	 *
	 * @param array $methods XML-RPC methods.
	 * @return array
	 */
	public static function drop_multicall( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}
		unset( $methods['system.multicall'], $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}
}
