<?php
/**
 * The authorize endpoint's request matching.
 *
 * The endpoint URL is advertised from the public base, which carries the
 * subdirectory on a WordPress installed under one. Matching the request against
 * the bare constant means the URL we published is never served — sign-in
 * dead-ends on the site's 404 page, and nothing in the plugin reports an error.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/oauth/class-oauth-authorize.php';

class OAuthAuthorizePathTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	public function test_root_install_matches_the_bare_path(): void {
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/karmcp-oauth/authorize', '' ) );
	}

	public function test_subdirectory_install_matches_the_prefixed_path(): void {
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/blog/karmcp-oauth/authorize', '/blog' ) );
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/a/b/karmcp-oauth/authorize', '/a/b' ) );
	}

	/**
	 * Still accepted when a proxy strips the prefix before WordPress sees it.
	 */
	public function test_bare_path_is_accepted_on_a_subdirectory_install(): void {
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/karmcp-oauth/authorize', '/blog' ) );
	}

	public function test_a_trailing_slash_still_matches(): void {
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/karmcp-oauth/authorize/', '' ) );
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/blog/karmcp-oauth/authorize/', '/blog' ) );
	}

	public function test_a_trailing_slash_on_the_prefix_still_matches(): void {
		$this->assertTrue( KarMCP_OAuth_Authorize::path_matches( '/blog/karmcp-oauth/authorize', '/blog/' ) );
	}

	public function test_other_requests_are_left_alone(): void {
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/', '' ) );
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/about', '' ) );
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/karmcp-oauth', '' ) );
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/karmcp-oauth/token', '' ) );
	}

	/**
	 * The prefix is the whole prefix: a different subdirectory is a different
	 * site, not this endpoint.
	 */
	public function test_a_foreign_prefix_does_not_match(): void {
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/other/karmcp-oauth/authorize', '/blog' ) );
	}

	public function test_the_path_must_end_at_the_endpoint(): void {
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/karmcp-oauth/authorize/extra', '' ) );
		$this->assertFalse( KarMCP_OAuth_Authorize::path_matches( '/blog/karmcp-oauth/authorize/extra', '/blog' ) );
	}
}
