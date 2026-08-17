<?php
/**
 * Bearer-token authentication for the MCP transport. Wired in as the server's
 * `transport_permission_callback`: it authenticates OAuth access tokens by
 * resolving them to the WordPress user they were issued for, and falls through
 * to WordPress's normal auth (Application Password / cookie) when no Bearer
 * token is present — so both connection methods coexist.
 *
 * Also emits the `WWW-Authenticate` challenge on unauthorized MCP responses so
 * clients can discover the OAuth flow (RFC 9728 §5.1).
 *
 * @package KarMCP
 * @since   3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bearer validation + the 401 discovery challenge.
 *
 * @since 3.4.1
 */
class KarMCP_OAuth_Bearer {

	/**
	 * Transport permission callback. Returns bool (fail-closed).
	 *
	 * @param WP_REST_Request $request The MCP request.
	 * @return bool
	 */
	public static function permission_callback( $request ): bool {
		$token = self::bearer_token( $request );

		if ( '' !== $token ) {
			// Clean at validation time: an OAuth-authenticated MCP request is the
			// natural, high-frequency point to purge expired tokens/orphans. Kept
			// throttled so it is not a DELETE per request; this keeps the tables
			// tidy on active sites within minutes, not waiting on a daily WP-Cron.
			KarMCP_OAuth_Store::gc_throttled();
			$row = KarMCP_OAuth_Store::find_token( $token, 'access' );
			if ( null !== $row ) {
				wp_set_current_user( (int) $row['user_id'] );
				return true;
			}
			return false; // Bearer present but invalid/expired → 401.
		}

		// No Bearer token: preserve the adapter's default behaviour so
		// Application-Password / cookie auth continues to work.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the MCP Adapter's own filter; we honour its name, we don't own it.
		$cap = apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', $request );
		if ( ! is_string( $cap ) || '' === $cap ) {
			$cap = 'read';
		}
		return current_user_can( $cap );
	}

	/**
	 * Extract the Bearer token from a request (or the raw Authorization header).
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return string Raw token, or '' when absent.
	 */
	public static function bearer_token( $request = null ): string {
		$header = '';
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$header = (string) $request->get_header( 'authorization' );
		}
		// Not sanitized on the way in, deliberately: parse_bearer() below runs it
		// through a regex that admits only the base64url credential alphabet,
		// which is stricter than any sanitizer — and a sanitizer that silently
		// rewrote a character would turn a valid token into a failed login with
		// nothing to show for it.
		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_bearer(), see above.
		}
		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_bearer(), see above.
		}
		return self::parse_bearer( $header );
	}

	/**
	 * Parse a Bearer token out of an Authorization header value.
	 *
	 * @param string $header Header value.
	 * @return string Token, or '' if the header is not a Bearer credential.
	 */
	public static function parse_bearer( string $header ): string {
		if ( preg_match( '/^\s*Bearer\s+([A-Za-z0-9\-._~+\/]+=*)\s*$/i', $header, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Add the `WWW-Authenticate` challenge to unauthorized responses on the MCP
	 * route, pointing clients at the protected-resource metadata.
	 *
	 * Hooked on `rest_post_dispatch`.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server (unused).
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function maybe_challenge( $response, $server, $request ) {
		if ( ! is_object( $response ) || ! is_object( $request ) ) {
			return $response;
		}
		$status = (int) $response->get_status();
		if ( 401 !== $status && 403 !== $status ) {
			return $response;
		}
		if ( false === strpos( (string) $request->get_route(), 'mcp/karmcp-server' ) ) {
			return $response;
		}
		$metadata = rtrim( (string) home_url(), '/' ) . KarMCP_OAuth_Metadata::PATH_PROTECTED_RESOURCE;
		$response->header( 'WWW-Authenticate', sprintf( 'Bearer resource_metadata="%s"', $metadata ) );
		return $response;
	}
}
