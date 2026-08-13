<?php
/**
 * Host-mismatch guard (Bug report Issue 2).
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-mcp-host-guard.php';

class McpHostGuardTest extends TestCase {

	public function test_exact_match(): void {
		$this->assertTrue(
			KarMCP_MCP_Host_Guard::host_matches( 'devis.debord-toiture.com', 'devis.debord-toiture.com' )
		);
	}

	public function test_www_and_case_and_port_tolerant(): void {
		$this->assertTrue(
			KarMCP_MCP_Host_Guard::host_matches( 'www.Example.com:443', 'example.com' )
		);
	}

	public function test_different_domain_is_mismatch(): void {
		$this->assertFalse(
			KarMCP_MCP_Host_Guard::host_matches( 'paleturquoise-sardine-346722.hostingersite.com', 'devis.debord-toiture.com' )
		);
	}

	public function test_empty_request_host_is_not_a_mismatch(): void {
		// A missing Host header must never brick the endpoint.
		$this->assertTrue( KarMCP_MCP_Host_Guard::host_matches( '', 'example.com' ) );
	}

	public function test_is_mcp_route(): void {
		$this->assertTrue( KarMCP_MCP_Host_Guard::is_mcp_route( '/mcp/karmcp-server' ) );
		$this->assertTrue( KarMCP_MCP_Host_Guard::is_mcp_route( '/mcp/karmcp-server/messages' ) );
		$this->assertFalse( KarMCP_MCP_Host_Guard::is_mcp_route( '/wp/v2/posts' ) );
		$this->assertFalse( KarMCP_MCP_Host_Guard::is_mcp_route( '/mcp/other-server' ) );
	}
}
