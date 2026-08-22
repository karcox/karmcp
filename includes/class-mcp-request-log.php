<?php
/**
 * MCP request log — a small, capped, option-backed ring buffer of recent MCP
 * requests (tool, status, duration) so a site owner can match connector
 * failures to server-side outcomes.
 *
 * Bug report Issue 5: the client only ever sees two generic error strings with
 * no status code or step name. This records each MCP request's result and, when
 * WP_DEBUG is on, the underlying error, surfaced on the "MCP Log" admin tab.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KarMCP_MCP_Request_Log {

	const OPTION    = 'karmcp_mcp_request_log';
	const MAX_COUNT = 100;

	/**
	 * The JSON-RPC methods the MCP server can actually act on.
	 *
	 * Mirrors the handler map in the adapter's `RequestRouter::route_request()`,
	 * which builds it as a local variable and so cannot be read at runtime.
	 * `McpRequestLogTest` parses that file and fails if the two drift, which is
	 * the only reason a hand-copied list is acceptable here.
	 *
	 * @since 1.30.2
	 */
	const ROUTABLE = array(
		'initialize',
		'ping',
		'tools/list',
		'tools/list/all',
		'tools/call',
		'resources/list',
		'resources/read',
		'prompts/list',
		'prompts/get',
	);

	/**
	 * Whether a request body earns a slot in the log.
	 *
	 * Clients probe. Claude's connector sends `server/discover` — a method that
	 * is in no MCP specification, in no adapter handler, and in nothing we
	 * ship — before the handshake and again before individual calls. The server
	 * rejects each one with a 400 (the session check runs before routing, so an
	 * unknown method is reported as a missing `Mcp-Session-Id` header rather
	 * than as method-not-found), and every rejection used to take a row.
	 *
	 * That costs twice. The log keeps the last 100 entries, so half the visible
	 * history was probes; and each row is a read plus a rewrite of an option
	 * holding up to 100 serialized records, so the noise doubled that churn too.
	 *
	 * Skips only what it can positively identify as unroutable. A batch, a body
	 * with no scalar method, anything unparseable: logged, exactly as before.
	 * Notifications are real protocol traffic and stay.
	 *
	 * @since 1.30.2
	 *
	 * @param mixed $body The decoded JSON-RPC request body.
	 * @return bool
	 */
	public static function should_record( $body ): bool {
		if ( ! is_array( $body ) || ! isset( $body['method'] ) || ! is_string( $body['method'] ) ) {
			return true;
		}

		$method = $body['method'];
		if ( 0 === strpos( $method, 'notifications/' ) ) {
			return true;
		}

		return in_array( $method, self::ROUTABLE, true );
	}

	/** Test seam: when non-null, overrides the WP_DEBUG check. */
	public static $debug_override = null;

	/** Whether to keep the underlying error message (only under WP_DEBUG). */
	public static function debug_enabled(): bool {
		if ( null !== self::$debug_override ) {
			return (bool) self::$debug_override;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Append a request record (newest last). Keys: tool, status, ms, req_id, error.
	 * The error is dropped unless WP_DEBUG is on.
	 *
	 * @param array $entry Partial record.
	 */
	public static function record( array $entry ): void {
		$row = array(
			'ts'     => time(),
			'tool'   => (string) ( $entry['tool'] ?? '' ),
			'status' => (string) ( $entry['status'] ?? '' ),
			'ms'     => (int) ( $entry['ms'] ?? 0 ),
			'req_id' => (string) ( $entry['req_id'] ?? '' ),
		);
		if ( self::debug_enabled() && ! empty( $entry['error'] ) ) {
			$row['error'] = (string) $entry['error'];
		}

		$log   = self::all();
		$log[] = $row;
		if ( count( $log ) > self::MAX_COUNT ) {
			$log = array_slice( $log, -self::MAX_COUNT );
		}
		update_option( self::OPTION, $log, false );
	}

	/** All records, oldest first. */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Clear the log. */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
