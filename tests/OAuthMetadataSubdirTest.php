<?php
/**
 * Well-known discovery on a WordPress installed under a subdirectory.
 *
 * The metadata documents (RFC 9728 / RFC 8414) are advertised at the site's own
 * URL, which carries the subdirectory, but the paths we match the request
 * against are site-root-relative. Matched without stripping that prefix, the
 * document we told the client to fetch 404s, and OAuth discovery dead-ends
 * before any client can ask for a token. Nothing in the plugin reports it: the
 * site simply answers 404 and the connector gives up.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/oauth/class-oauth-metadata.php';

class OAuthMetadataSubdirTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['karmcp_test']['home_url'], $_SERVER['REQUEST_URI'] );
	}

	public function test_a_root_install_leaves_the_path_alone(): void {
		$this->assertSame(
			'/.well-known/oauth-protected-resource',
			KarMCP_OAuth_Metadata::strip_home_path( '/.well-known/oauth-protected-resource', '' )
		);
		$this->assertSame(
			'/.well-known/oauth-protected-resource',
			KarMCP_OAuth_Metadata::strip_home_path( '/.well-known/oauth-protected-resource', '/' )
		);
	}

	public function test_a_subdirectory_prefix_is_stripped(): void {
		$this->assertSame(
			'/.well-known/oauth-protected-resource',
			KarMCP_OAuth_Metadata::strip_home_path( '/gpt-build/.well-known/oauth-protected-resource', '/gpt-build' )
		);
		$this->assertSame(
			'/.well-known/oauth-authorization-server',
			KarMCP_OAuth_Metadata::strip_home_path( '/a/b/.well-known/oauth-authorization-server', '/a/b' )
		);
	}

	public function test_a_trailing_slash_on_the_prefix_still_strips(): void {
		$this->assertSame(
			'/.well-known/oauth-protected-resource',
			KarMCP_OAuth_Metadata::strip_home_path( '/gpt-build/.well-known/oauth-protected-resource', '/gpt-build/' )
		);
	}

	/**
	 * A proxy that strips the prefix before WordPress sees it must still work.
	 */
	public function test_an_already_bare_path_is_unchanged(): void {
		$this->assertSame(
			'/.well-known/oauth-protected-resource',
			KarMCP_OAuth_Metadata::strip_home_path( '/.well-known/oauth-protected-resource', '/gpt-build' )
		);
	}

	/**
	 * Only a real path segment counts: a directory whose name merely starts with
	 * the prefix is a different place.
	 */
	public function test_a_lookalike_prefix_is_not_stripped(): void {
		$this->assertSame(
			'/gpt-building/.well-known/oauth-protected-resource',
			KarMCP_OAuth_Metadata::strip_home_path( '/gpt-building/.well-known/oauth-protected-resource', '/gpt-build' )
		);
	}

	public function test_the_home_path_on_its_own_becomes_the_root(): void {
		$this->assertSame( '/', KarMCP_OAuth_Metadata::strip_home_path( '/gpt-build', '/gpt-build' ) );
	}

	/**
	 * End to end through request_path(): the real entry point reads home_url()
	 * itself, so a subdirectory install must reach the matcher as a bare path.
	 */
	public function test_the_request_path_of_a_subdirectory_install_matches(): void {
		$GLOBALS['karmcp_test']['home_url'] = 'http://example.test/gpt-build';
		$_SERVER['REQUEST_URI']             = '/gpt-build/.well-known/oauth-protected-resource';

		$method = new ReflectionMethod( 'KarMCP_OAuth_Metadata', 'request_path' );
		$path   = (string) $method->invoke( null );

		$this->assertSame( '/.well-known/oauth-protected-resource', $path );
		$this->assertTrue(
			KarMCP_OAuth_Metadata::path_matches( $path, KarMCP_OAuth_Metadata::PATH_PROTECTED_RESOURCE )
		);
	}

	/**
	 * The resource-scoped variant clients actually build, on a subdirectory install.
	 */
	public function test_the_resource_scoped_variant_matches_on_a_subdirectory_install(): void {
		$GLOBALS['karmcp_test']['home_url'] = 'http://example.test/gpt-build';
		$_SERVER['REQUEST_URI']             = '/gpt-build/.well-known/oauth-protected-resource/wp-json/mcp/karmcp-server';

		$method = new ReflectionMethod( 'KarMCP_OAuth_Metadata', 'request_path' );
		$path   = (string) $method->invoke( null );

		$this->assertTrue(
			KarMCP_OAuth_Metadata::path_matches( $path, KarMCP_OAuth_Metadata::PATH_PROTECTED_RESOURCE )
		);
	}

	/**
	 * A root install is unaffected, prefix logic or not.
	 */
	public function test_a_root_install_still_matches(): void {
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';

		$method = new ReflectionMethod( 'KarMCP_OAuth_Metadata', 'request_path' );
		$path   = (string) $method->invoke( null );

		$this->assertSame( '/.well-known/oauth-authorization-server', $path );
		$this->assertTrue(
			KarMCP_OAuth_Metadata::path_matches( $path, KarMCP_OAuth_Metadata::PATH_AUTH_SERVER )
		);
	}
}
