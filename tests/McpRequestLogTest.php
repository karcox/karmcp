<?php
/**
 * MCP request log (Bug report Issue 5).
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-mcp-request-log.php';

class McpRequestLogTest extends TestCase {

	protected function setUp(): void {
		KarMCP_MCP_Request_Log::clear();
		KarMCP_MCP_Request_Log::$debug_override = null;
	}

	protected function tearDown(): void {
		KarMCP_MCP_Request_Log::$debug_override = null;
	}

	/**
	 * The list of routable methods is copied by hand from the adapter, because
	 * `RequestRouter::route_request()` builds its handler map as a local
	 * variable that nothing can read at runtime. A hand-copy is only acceptable
	 * if it cannot rot silently, so this parses the adapter and compares. If the
	 * adapter gains a method, this fails and tells you to add it.
	 */
	public function test_the_routable_list_matches_the_adapters_handler_map(): void {
		$router = __DIR__ . '/../vendor/wordpress/mcp-adapter/includes/Transport/Infrastructure/RequestRouter.php';
		$this->assertFileExists( $router, 'the bundled adapter moved; update this path' );

		$src = (string) file_get_contents( $router );
		$at  = strpos( $src, '$handlers = array(' );
		$this->assertIsInt( $at, 'the handler map is no longer built as $handlers = array(' );

		// Paren-matched, not up to the first ');': the initialize entry is a closure
		// whose own call ends in ');' and would cut the block after one key.
		$open  = strpos( $src, '(', $at );
		$depth = 0;
		$end   = $open;
		for ( $i = $open, $len = strlen( $src ); $i < $len; $i++ ) {
			if ( '(' === $src[ $i ] ) {
				++$depth;
			} elseif ( ')' === $src[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}
		$block = substr( $src, $open, $end - $open );
		preg_match_all( "/'([a-zA-Z][a-zA-Z0-9\/]*)'\s*=>/", $block, $m );

		$adapter = $m[1];
		$ours    = KarMCP_MCP_Request_Log::ROUTABLE;
		sort( $adapter );
		sort( $ours );

		$this->assertSame(
			$adapter,
			$ours,
			'KarMCP_MCP_Request_Log::ROUTABLE has drifted from the adapter handler map'
		);
	}

	/**
	 * The case this exists for: a probe for a method that is in no MCP spec and
	 * no adapter handler. It was taking a row per call, and there are only 100.
	 */
	public function test_a_probe_for_an_unimplemented_method_is_not_recorded(): void {
		$this->assertFalse( KarMCP_MCP_Request_Log::should_record( array( 'method' => 'server/discover' ) ) );
	}

	public function test_real_protocol_traffic_is_recorded(): void {
		foreach ( KarMCP_MCP_Request_Log::ROUTABLE as $method ) {
			$this->assertTrue(
				KarMCP_MCP_Request_Log::should_record( array( 'method' => $method ) ),
				"$method must still be logged"
			);
		}
		$this->assertTrue( KarMCP_MCP_Request_Log::should_record( array( 'method' => 'notifications/initialized' ) ) );
		$this->assertTrue( KarMCP_MCP_Request_Log::should_record( array( 'method' => 'notifications/cancelled' ) ) );
	}

	/**
	 * Skip only what can be positively identified as unroutable. A batch has no
	 * top-level method and was logged before; it still is.
	 */
	public function test_anything_it_cannot_classify_is_still_recorded(): void {
		$this->assertTrue( KarMCP_MCP_Request_Log::should_record( array( array( 'method' => 'tools/call' ) ) ) );
		$this->assertTrue( KarMCP_MCP_Request_Log::should_record( array() ) );
		$this->assertTrue( KarMCP_MCP_Request_Log::should_record( null ) );
		$this->assertTrue( KarMCP_MCP_Request_Log::should_record( array( 'method' => array( 'not', 'scalar' ) ) ) );
	}

	public function test_record_appends_and_reads_back(): void {
		KarMCP_MCP_Request_Log::record( array( 'tool' => 'list-plugins', 'status' => '200', 'ms' => 42, 'req_id' => 'req_1' ) );
		$log = KarMCP_MCP_Request_Log::all();
		$this->assertCount( 1, $log );
		$this->assertSame( 'list-plugins', $log[0]['tool'] );
		$this->assertSame( 42, $log[0]['ms'] );
	}

	public function test_cap_drops_oldest(): void {
		for ( $i = 0; $i < KarMCP_MCP_Request_Log::MAX_COUNT + 5; $i++ ) {
			KarMCP_MCP_Request_Log::record( array( 'tool' => 't' . $i, 'status' => 'ok' ) );
		}
		$log = KarMCP_MCP_Request_Log::all();
		$this->assertCount( KarMCP_MCP_Request_Log::MAX_COUNT, $log );
		$this->assertSame( 't5', $log[0]['tool'], 'oldest 5 dropped' );
	}

	public function test_error_kept_only_under_debug(): void {
		KarMCP_MCP_Request_Log::$debug_override = false;
		KarMCP_MCP_Request_Log::record( array( 'tool' => 'x', 'status' => 'error', 'error' => 'boom' ) );
		$this->assertArrayNotHasKey( 'error', KarMCP_MCP_Request_Log::all()[0] );

		KarMCP_MCP_Request_Log::clear();
		KarMCP_MCP_Request_Log::$debug_override = true;
		KarMCP_MCP_Request_Log::record( array( 'tool' => 'x', 'status' => 'error', 'error' => 'boom' ) );
		$this->assertSame( 'boom', KarMCP_MCP_Request_Log::all()[0]['error'] );
	}
}
