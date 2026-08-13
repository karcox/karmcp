<?php
/**
 * Public unit tests for KarMCP_Site_Context::public_base_url() /
 * detected_base_url() / mcp_endpoint() — the canonical reachable-URL resolver
 * that fixes staging hosts where home_url() (pinned) differs from rest_url()
 * (reachable).
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-site-context.php';

class PublicBaseUrlTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** Normal site: rest_url host == home_url host → detected == home_url base. */
	public function test_detected_matches_home_on_a_normal_site() {
		// Harness home_url() = http://example.test; default rest base derives from it.
		$this->assertSame( 'http://example.test', KarMCP_Site_Context::detected_base_url() );
	}

	/** Staging split: rest_url answers on the reachable host, home_url is pinned. */
	public function test_detected_uses_rest_host_not_home_on_staging() {
		$GLOBALS['karmcp_test']['rest_url_base'] = 'https://royalblue-stork-540633.hostingersite.com/wp-json';
		// home_url() (pinned) is still example.test, but detected follows rest_url.
		$this->assertSame( 'https://royalblue-stork-540633.hostingersite.com', KarMCP_Site_Context::detected_base_url() );
	}

	/** No override → public_base_url() == detected. */
	public function test_public_base_url_defaults_to_detected() {
		$GLOBALS['karmcp_test']['rest_url_base'] = 'https://reach.test/wp-json';
		$this->assertSame( 'https://reach.test', KarMCP_Site_Context::public_base_url() );
	}

	/** Admin override wins over detection, trailing slash trimmed. */
	public function test_override_wins() {
		$GLOBALS['karmcp_test']['rest_url_base']                          = 'https://reach.test/wp-json';
		$GLOBALS['karmcp_test']['options']['karmcp_public_base_url'] = 'https://override.test/';
		$this->assertSame( 'https://override.test', KarMCP_Site_Context::public_base_url() );
	}

	/** mcp_endpoint(): no override → rest_url() endpoint. */
	public function test_mcp_endpoint_no_override_uses_rest_url() {
		$GLOBALS['karmcp_test']['rest_url_base'] = 'https://reach.test/wp-json';
		$this->assertSame( 'https://reach.test/wp-json/mcp/karmcp-server', KarMCP_Site_Context::mcp_endpoint() );
	}

	/** mcp_endpoint(): override → override + pretty REST path. */
	public function test_mcp_endpoint_override() {
		$GLOBALS['karmcp_test']['options']['karmcp_public_base_url'] = 'https://override.test';
		$this->assertSame( 'https://override.test/wp-json/mcp/karmcp-server', KarMCP_Site_Context::mcp_endpoint() );
	}

	/** rest_endpoint(): override is authoritative for ANY path (oauth token/register). */
	public function test_rest_endpoint_override_is_authoritative() {
		$GLOBALS['karmcp_test']['options']['karmcp_public_base_url'] = 'https://override.test';
		$this->assertSame( 'https://override.test/wp-json/karmcp/oauth/v1', KarMCP_Site_Context::rest_endpoint( 'karmcp/oauth/v1' ) );
		$this->assertSame( 'https://override.test/wp-json/mcp/karmcp-server', KarMCP_Site_Context::rest_endpoint( 'mcp/karmcp-server' ) );
	}

	/** rest_endpoint(): no override → rest_url() for the path. */
	public function test_rest_endpoint_no_override_uses_rest_url() {
		$GLOBALS['karmcp_test']['rest_url_base'] = 'https://reach.test/wp-json';
		$this->assertSame( 'https://reach.test/wp-json/karmcp/oauth/v1', KarMCP_Site_Context::rest_endpoint( 'karmcp/oauth/v1' ) );
	}

	/** Plain-permalink rest_url (?rest_route=) → detected keeps scheme+host. */
	public function test_detected_plain_permalinks() {
		$GLOBALS['karmcp_test']['rest_url_base'] = 'https://plain.test/?rest_route=';
		// rest_url('') → 'https://plain.test/?rest_route=/'; no /wp-json/ to strip,
		// so it falls back to scheme+host from wp_parse_url.
		$this->assertSame( 'https://plain.test', KarMCP_Site_Context::detected_base_url() );
	}
}
