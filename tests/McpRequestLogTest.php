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
