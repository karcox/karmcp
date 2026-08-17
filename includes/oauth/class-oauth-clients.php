<?php
/**
 * Dynamic Client Registration (RFC 7591) — MCP clients self-register and get a
 * public `client_id` (no secret; they use PKCE). Open registration, as the spec
 * expects, but the redirect URIs are validated (https, or http loopback only).
 *
 * @package KarMCP
 * @since   3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `/register` endpoint + registration validation.
 *
 * @since 3.4.1
 */
class KarMCP_OAuth_Clients {

	/** Registrations one address may make inside a window. */
	const RATE_LIMIT = 10;

	/** Length of the rate-limit window, in seconds. */
	const RATE_WINDOW = 3600;

	/**
	 * Register the REST route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			KarMCP_OAuth_Server::REST_NAMESPACE,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a Dynamic Client Registration request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_register( $request ) {
		// Open registration is what the spec asks for, but "open" is not
		// "unlimited": the endpoint is unauthenticated and every call writes a
		// row, so without a ceiling one script fills the clients table. The
		// window is per address and generous enough that a person setting up
		// several MCP clients never meets it.
		if ( self::rate_limited() ) {
			return new WP_REST_Response(
				array(
					'error'             => 'too_many_requests',
					'error_description' => 'Too many client registrations from this address. Try again later.',
				),
				429
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$result = self::validate_registration( is_array( $body ) ? $body : array() );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'             => 'invalid_client_metadata',
					'error_description' => $result->get_error_message(),
				),
				400
			);
		}

		$client = KarMCP_OAuth_Store::create_client(
			$result['client_name'],
			$result['redirect_uris'],
			get_current_user_id()
		);

		return new WP_REST_Response(
			array(
				'client_id'                  => $client['client_id'],
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $client['redirect_uris'],
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'client_id_issued_at'        => time(),
			),
			201
		);
	}

	/**
	 * Whether this caller has already used up its registrations for the window.
	 *
	 * Counts into a fixed time bucket rather than a sliding transient: a plain
	 * `set_transient()` per hit pushes the expiry forward every time, so a
	 * steady stream of requests would keep a counter alive forever and never
	 * let a legitimate client through once it tripped.
	 *
	 * @since 1.2.0
	 * @return bool True when the request should be refused.
	 */
	public static function rate_limited(): bool {
		$key   = self::rate_limit_key( self::client_ip(), time() );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		// Two windows of TTL so the bucket outlives its own period and the count
		// cannot be reset by a request landing exactly on the boundary.
		set_transient( $key, $count + 1, self::RATE_WINDOW * 2 );
		return false;
	}

	/**
	 * The transient key for one address in one time bucket. Pure.
	 *
	 * The address is hashed: it is personal data, and it has no business sitting
	 * in the options table in the clear.
	 *
	 * @since 1.2.0
	 * @param string $ip  Caller address.
	 * @param int    $now Current UNIX time.
	 * @return string
	 */
	public static function rate_limit_key( string $ip, int $now ): string {
		return 'karmcp_oauth_reg_' . md5( $ip ) . '_' . (int) floor( $now / self::RATE_WINDOW );
	}

	/**
	 * The caller's address. REMOTE_ADDR only — a forwarded-for header is
	 * attacker-controlled, and trusting it would hand out a fresh bucket per
	 * request, which is worse than no limit at all.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? 'unknown' : $ip;
	}

	/**
	 * Validate + normalize a registration body.
	 *
	 * @param array $body Request body.
	 * @return array{client_name:string,redirect_uris:string[]}|WP_Error
	 */
	public static function validate_registration( array $body ) {
		$uris = $body['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uris is required and must be a non-empty array.' );
		}

		$clean = array();
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! self::is_allowed_redirect_uri( $uri ) ) {
				return new WP_Error( 'invalid_redirect_uri', 'Each redirect_uri must be an absolute https URL (or an http loopback address).' );
			}
			$clean[] = $uri;
		}

		$name = ( isset( $body['client_name'] ) && is_string( $body['client_name'] ) && '' !== trim( $body['client_name'] ) )
			? trim( $body['client_name'] )
			: 'MCP Client';

		return array(
			'client_name'   => $name,
			'redirect_uris' => array_values( array_unique( $clean ) ),
		);
	}

	/**
	 * Whether a redirect URI is allowed: absolute https, or http on a loopback
	 * host. No fragment component (RFC 6749 §3.1.2).
	 *
	 * @param string $uri Candidate URI.
	 * @return bool
	 */
	public static function is_allowed_redirect_uri( string $uri ): bool {
		$p = parse_url( $uri );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $p['scheme'] );
		// Must be a syntactically valid URI scheme (RFC 3986).
		if ( ! preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme ) ) {
			return false;
		}
		// Plaintext http is allowed only for loopback (RFC 8252 §7.3).
		if ( 'http' === $scheme ) {
			$host = isset( $p['host'] ) ? strtolower( trim( $p['host'], '[]' ) ) : '';
			return in_array( $host, array( '127.0.0.1', '::1', 'localhost' ), true );
		}
		// https and private-use / custom app schemes (RFC 8252 §7.1) are allowed —
		// native MCP clients (Claude Desktop, VS Code, Cursor, …) register these.
		return true;
	}
}
