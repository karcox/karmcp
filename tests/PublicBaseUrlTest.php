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

	/*
	 * The shapes get_rest_url() really emits (wp-includes/rest-api.php). The
	 * test above feeds `https://plain.test/?rest_route=`, a URL WordPress never
	 * produces: on a site without a permalink structure core appends `index.php`
	 * before the query, to avoid an nginx redirect that drops the request
	 * method. The suite passed on the gentle shape while the real one put
	 * `index.php` into the OAuth issuer and the bundle's WP_URL.
	 */

	/**
	 * @dataProvider real_rest_url_shapes
	 */
	public function test_base_from_every_real_rest_url_shape( string $rest, string $expected ) {
		$this->assertSame( $expected, KarMCP_Site_Context::base_from_rest_url( $rest, 'https://fallback.test' ) );
	}

	public static function real_rest_url_shapes(): array {
		return array(
			'pretty'                   => array( 'https://host.test/wp-json/', 'https://host.test' ),
			'pretty in a subdirectory' => array( 'https://host.test/blog/wp-json/', 'https://host.test/blog' ),
			'PATHINFO'                 => array( 'https://host.test/index.php/wp-json/', 'https://host.test' ),
			'PATHINFO in a subdir'     => array( 'https://host.test/blog/index.php/wp-json/', 'https://host.test/blog' ),
			'plain'                    => array( 'https://host.test/index.php?rest_route=/', 'https://host.test' ),
			'plain in a subdirectory'  => array( 'https://host.test/blog/index.php?rest_route=/', 'https://host.test/blog' ),
			'plain with a port'        => array( 'http://host.test:8080/index.php?rest_route=/', 'http://host.test:8080' ),
		);
	}

	public function test_a_directory_merely_named_like_index_php_is_not_stripped() {
		// Only a trailing `index.php` segment is WordPress's; a path that happens
		// to contain the string elsewhere is the site's own.
		$this->assertSame(
			'https://host.test/index.php-archive',
			KarMCP_Site_Context::base_from_rest_url( 'https://host.test/index.php-archive/wp-json/', 'https://fallback.test' )
		);
	}

	public function test_an_empty_rest_url_falls_back_to_home() {
		$this->assertSame( 'https://fallback.test', KarMCP_Site_Context::base_from_rest_url( '', 'https://fallback.test/' ) );
	}
}
