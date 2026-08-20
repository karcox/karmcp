<?php
/**
 * OAuth discovery metadata — the two documents MCP clients fetch to bootstrap
 * the flow: Protected Resource Metadata (RFC 9728) and Authorization Server
 * Metadata (RFC 8414). Both are served at the site root under `/.well-known/`.
 *
 * @package KarMCP
 * @since   3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds + serves the OAuth discovery documents.
 *
 * @since 3.4.1
 */
class KarMCP_OAuth_Metadata {

	const PATH_PROTECTED_RESOURCE = '/.well-known/oauth-protected-resource';
	const PATH_AUTH_SERVER        = '/.well-known/oauth-authorization-server';

	/**
	 * Wire the root-level well-known request interception.
	 */
	public static function init(): void {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * The issuer identifier (the site's home URL, no trailing slash).
	 *
	 * @return string
	 */
	public static function issuer(): string {
		// The reachable public base (rest_url-derived / admin-overridable), NOT
		// home_url() — a host that pins the Site Address to a not-yet-live domain
		// would otherwise advertise an unreachable issuer and break OAuth
		// discovery. Matches resource()/base_url(), which already use rest_url().
		if ( class_exists( 'KarMCP_Site_Context' ) ) {
			return KarMCP_Site_Context::public_base_url();
		}
		return rtrim( (string) home_url(), '/' );
	}

	/**
	 * The protected resource identifier — the MCP server endpoint clients call.
	 *
	 * @return string
	 */
	public static function resource(): string {
		// Route through the reachable public base so an admin Server URL override
		// governs the whole OAuth flow (resource + token + issuer), not just the
		// issuer — otherwise a host that differs from rest_url() would produce an
		// inconsistent discovery document.
		if ( class_exists( 'KarMCP_Site_Context' ) ) {
			return KarMCP_Site_Context::rest_endpoint( 'mcp/karmcp-server' );
		}
		return rest_url( 'mcp/karmcp-server' );
	}

	/**
	 * Protected Resource Metadata document (RFC 9728).
	 *
	 * @return array
	 */
	public static function protected_resource_document(): array {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( KarMCP_OAuth_Server::SCOPE ),
			// Published in the discovery document every MCP client reads, so it
			// has to resolve — filterable for anyone hosting their own docs.
			'resource_documentation'   => apply_filters( 'karmcp_resource_documentation', 'https://github.com/karcox/karmcp#readme' ),
		);
	}

	/**
	 * Authorization Server Metadata document (RFC 8414).
	 *
	 * @return array
	 */
	public static function authorization_server_document(): array {
		$base = KarMCP_OAuth_Server::base_url();
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => KarMCP_OAuth_Authorize::endpoint_url(),
			'token_endpoint'                        => $base . '/token',
			'registration_endpoint'                 => $base . '/register',
			'revocation_endpoint'                   => $base . '/revoke',
			'scopes_supported'                      => array( KarMCP_OAuth_Server::SCOPE ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
		);
	}

	/**
	 * Serve a well-known document when the request path matches, then exit.
	 * No-op for any other request.
	 *
	 * @param WP $wp Current WordPress environment (unused).
	 */
	public static function maybe_serve( $wp = null ): void {
		if ( ! KarMCP_OAuth_Server::is_enabled() ) {
			return;
		}
		$path = self::request_path();

		// Match both the root well-known path and the resource-scoped variant
		// clients build by appending the resource path, e.g.
		// /.well-known/oauth-protected-resource/wp-json/mcp/karmcp-server
		// (RFC 9728 §3.1). Exact-match-only 404s the request real MCP clients
		// actually make, which silently breaks OAuth discovery.
		if ( self::path_matches( $path, self::PATH_PROTECTED_RESOURCE ) ) {
			self::emit( self::protected_resource_document() );
		}
		if ( self::path_matches( $path, self::PATH_AUTH_SERVER ) ) {
			self::emit( self::authorization_server_document() );
		}
	}

	/**
	 * Whether a request path is the given well-known path or a resource-scoped
	 * variant of it (the well-known path followed by a "/…" resource path).
	 *
	 * @param string $path     Request path.
	 * @param string $wellknown Base well-known path.
	 * @return bool
	 */
	public static function path_matches( string $path, string $wellknown ): bool {
		return $path === $wellknown || 0 === strpos( $path, $wellknown . '/' );
	}

	/**
	 * The current request path (no query string, no trailing slash except root).
	 *
	 * @return string
	 */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		// A site installed under a subdirectory receives the request as
		// /gpt-build/.well-known/oauth-protected-resource, but the paths we match
		// against are site-root-relative. Without stripping the prefix the
		// discovery document we advertise in WWW-Authenticate 404s, and OAuth
		// discovery dead-ends before any client can get a token — the same class
		// of bug the authorize endpoint already handles. No-op at the root.
		$path = self::strip_home_path( $path );

		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		return $path;
	}

	/**
	 * Remove the WordPress home-URL path prefix — the subdirectory a site is
	 * installed under — from a request path. `/blog/.well-known/x` becomes
	 * `/.well-known/x`; a root install returns the path unchanged.
	 *
	 * @since 1.28.0
	 * @param string      $path Request path.
	 * @param string|null $home Home path to strip; read from home_url() when null.
	 * @return string
	 */
	public static function strip_home_path( string $path, ?string $home = null ): string {
		if ( null === $home ) {
			$home = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_PATH ) : '';
		}
		$home = untrailingslashit( $home );
		if ( '' === $home || '/' === $home ) {
			return $path;
		}
		if ( $path === $home || 0 === strpos( $path, $home . '/' ) ) {
			$stripped = substr( $path, strlen( $home ) );
			return '' === $stripped ? '/' : $stripped;
		}
		return $path;
	}

	/**
	 * Emit a JSON document with permissive CORS (public discovery) and exit.
	 *
	 * @param array $doc Document.
	 */
	private static function emit( array $doc ): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Cache-Control: public, max-age=3600' );
		}
		echo wp_json_encode( $doc );
		exit;
	}
}
