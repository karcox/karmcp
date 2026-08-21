<?php
/**
 * The route gate that keeps somebody else's REST request from building our
 * MCP server.
 *
 * `rest_api_init` fires on every REST request, so before this gate existed a
 * call to `/wp-json/wp/v2/posts` loaded the 76 tool classes and registered
 * ~200 abilities with their schemas. The gate has to be exactly right in both
 * directions: too narrow and a real client silently gets a server with no
 * tools, too wide and the cost comes straight back.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-mcp-host-guard.php';
require_once __DIR__ . '/../includes/class-plugin.php';

class McpServerGateTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset_hooks();
		$GLOBALS['wp'] = (object) array( 'query_vars' => array() );
	}

	protected function tearDown(): void {
		karmcp_test_reset_hooks();
		unset( $GLOBALS['wp'] );
	}

	/**
	 * Puts a REST route in place the way WordPress does before `rest_api_init`.
	 *
	 * @param string $route Route, or null to leave the query var absent.
	 */
	private function serving( ?string $route ): void {
		$GLOBALS['wp'] = (object) array(
			'query_vars' => ( null === $route ) ? array() : array( 'rest_route' => $route ),
		);
	}

	public function test_our_own_route_builds_the_server(): void {
		$this->serving( '/mcp/karmcp-server' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	public function test_a_sub_route_of_ours_builds_the_server(): void {
		$this->serving( '/mcp/karmcp-server/messages' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * `?rest_route=mcp/karmcp-server` reaches us without the leading slash.
	 * Missing this would turn a working client into a toolless one.
	 */
	public function test_a_route_without_its_leading_slash_still_matches(): void {
		$this->serving( 'mcp/karmcp-server' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * The whole point: the editor's own REST traffic stops paying for tools.
	 */
	public function test_someone_elses_route_does_not_build_the_server(): void {
		foreach ( array( '/wp/v2/posts', '/wp/v2/media', '/oembed/1.0/embed', '/contact-form-7/v1/x' ) as $route ) {
			$this->serving( $route );
			$this->assertFalse( KarMCP_Plugin::request_needs_mcp_server(), "$route must not build the server" );
		}
	}

	/**
	 * Our OAuth routes are registered by KarMCP_OAuth_Server, not by the MCP
	 * server, so they do not need it either — and a client hits them before it
	 * ever reaches the endpoint.
	 */
	public function test_our_oauth_routes_do_not_build_the_server(): void {
		$this->serving( '/karmcp/oauth/token' );
		$this->assertFalse( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * The gate tests the namespace, not our server. The adapter mounts its own
	 * default server beside ours, and matching only `/mcp/karmcp-server` would
	 * turn that one into a 404 for anybody using it.
	 */
	public function test_the_adapters_own_default_server_still_builds(): void {
		$this->serving( '/mcp/mcp-adapter-default-server' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * The one that actually saves the work. The adapter builds its default
	 * server on `mcp_adapter_init` at priority 10 — before our own hook — and
	 * building it calls `wp_get_abilities()`, which forces the lazy Abilities
	 * API and with it our whole tool registration. Declining our own server
	 * without declining that one saves nothing at all.
	 */
	public function test_the_adapter_default_server_is_declined_on_a_foreign_route(): void {
		$this->serving( '/wp/v2/posts' );
		$this->assertFalse( KarMCP_Plugin::filter_default_server( true ) );

		$this->serving( '/mcp/karmcp-server' );
		$this->assertTrue( KarMCP_Plugin::filter_default_server( true ) );
	}

	/**
	 * And a site that already switched the default server off keeps it off.
	 */
	public function test_declining_the_default_server_composes_with_an_existing_no(): void {
		$this->serving( '/mcp/karmcp-server' );
		$this->assertFalse( KarMCP_Plugin::filter_default_server( false ) );
	}

	/**
	 * Both callbacks above are only worth anything if they are wired, and the
	 * suite never boots the plugin, so nothing else here would notice them being
	 * dropped. The filter in particular has to be registered by `init()`, which
	 * runs at `plugins_loaded`, because the adapter reads it at `rest_api_init:15`.
	 */
	public function test_the_gate_is_actually_wired_up(): void {
		$src  = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
		$init = strpos( $src, 'private function init(): void' );
		$this->assertIsInt( $init, 'init() is where the hooks are registered' );

		$body = substr( $src, $init, strpos( $src, "\n\t}", $init ) - $init );
		$this->assertStringContainsString(
			"add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ) )",
			$body,
			'the adapter default server must be gated from init(), before rest_api_init:15'
		);

		$server = strpos( $src, 'public function register_mcp_server' );
		$this->assertIsInt( $server );
		$this->assertStringContainsString(
			'if ( ! self::request_needs_mcp_server() ) {',
			substr( $src, $server, 1200 ),
			'register_mcp_server() must consult the gate too'
		);
	}

	/**
	 * The `/wp-json/` index lists our route, and some clients discover it there.
	 * This is the case that made plain route filtering look unattractive; it is
	 * one rare request, so it pays full price and discovery keeps working.
	 */
	public function test_the_discovery_index_builds_the_server(): void {
		$this->serving( '/' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * `rest_get_server()` called outside a served REST request — an internal
	 * rest_do_request(), a plugin preloading in wp-admin. We cannot tell what it
	 * wants, so behaviour is unchanged from before the gate.
	 */
	public function test_an_absent_or_empty_route_builds_the_server(): void {
		$this->serving( null );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );

		$this->serving( '' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * The escape hatch, for a client that reaches the endpoint by a route this
	 * does not recognise.
	 */
	public function test_the_filter_can_force_the_server_on(): void {
		$this->serving( '/wp/v2/posts' );
		$this->assertFalse( KarMCP_Plugin::request_needs_mcp_server() );

		add_filter( 'karmcp_needs_mcp_server', '__return_true' );
		$this->assertTrue( KarMCP_Plugin::request_needs_mcp_server() );
	}

	/**
	 * And off, for a site that never wants the endpoint built implicitly.
	 */
	public function test_the_filter_can_force_the_server_off(): void {
		$this->serving( '/mcp/karmcp-server' );
		add_filter( 'karmcp_needs_mcp_server', '__return_false' );
		$this->assertFalse( KarMCP_Plugin::request_needs_mcp_server() );
	}
}
