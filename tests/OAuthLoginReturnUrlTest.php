<?php
/**
 * The URL the authorize endpoint sends a logged-out user back to after login.
 *
 * A client that is not already logged into WordPress is bounced to wp-login.php
 * with the whole authorize request as `redirect_to`. That request carries the
 * client's `redirect_uri`, percent-encoded — and the URL was being rebuilt with
 * `sanitize_text_field()`, which strips every %XX octet. So
 * `redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback` came back as
 * `http127.0.0.133418callback`: not the URI the client registered, so authorize
 * refused it and sign-in dead-ended.
 *
 * Nothing about this was visible from the server side. Discovery resolved, the
 * client registered, the 401 challenge was correct, and the flow still never
 * produced a token — and only for users who were not already logged in, which
 * is why it survived every manual test done from a browser with a live session.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/oauth/class-oauth-authorize.php';

class OAuthLoginReturnUrlTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private const URI = '/karmcp-oauth/authorize?response_type=code&client_id=karmcp_abc123&redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&scope=mcp&state=xyz';

	/**
	 * The whole point: the callback the client registered has to survive the
	 * round trip through the login screen unchanged.
	 */
	public function test_the_percent_encoded_redirect_uri_survives(): void {
		$url = KarMCP_OAuth_Authorize::build_return_url( 'https', 'example.com', self::URI );

		$this->assertStringContainsString(
			'redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback',
			$url,
			'The client callback lost its percent-encoding on the way to wp-login.php.'
		);
		$this->assertStringNotContainsString( 'http127.0.0.133418callback', $url );
	}

	/**
	 * The exact corruption that shipped, named so it cannot come back quietly.
	 */
	public function test_the_old_sanitizer_would_still_destroy_it(): void {
		$mangled = sanitize_text_field( self::URI );

		$this->assertStringContainsString(
			'redirect_uri=http127.0.0.133418callback',
			$mangled,
			'sanitize_text_field() no longer strips percent-encoded octets — if core changed, this test is the record of why the authorize endpoint avoids it.'
		);
	}

	public function test_the_scheme_and_host_are_prefixed(): void {
		$url = KarMCP_OAuth_Authorize::build_return_url( 'https', 'example.com', self::URI );

		$this->assertStringStartsWith( 'https://example.com/karmcp-oauth/authorize?', $url );
	}

	/**
	 * The parameters that were never encoded were never the problem, and they
	 * still have to arrive intact.
	 */
	public function test_the_unencoded_parameters_are_untouched(): void {
		$url = KarMCP_OAuth_Authorize::build_return_url( 'https', 'example.com', self::URI );

		$this->assertStringContainsString( 'response_type=code', $url );
		$this->assertStringContainsString( 'client_id=karmcp_abc123', $url );
		$this->assertStringContainsString( 'code_challenge_method=S256', $url );
		$this->assertStringContainsString( 'state=xyz', $url );
	}

	/**
	 * A subdirectory install carries its prefix in REQUEST_URI; the return URL
	 * is that request, not a rebuilt guess at it.
	 */
	public function test_a_subdirectory_request_is_returned_as_received(): void {
		$url = KarMCP_OAuth_Authorize::build_return_url( 'https', 'example.com', '/blog' . self::URI );

		$this->assertStringStartsWith( 'https://example.com/blog/karmcp-oauth/authorize?', $url );
		$this->assertStringContainsString( 'redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback', $url );
	}
}
