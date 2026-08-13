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
