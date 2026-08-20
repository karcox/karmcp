<?php
/**
 * Redirect-URI matching at the authorize step.
 *
 * A command-line client listens on the machine the user is sitting at, and it
 * can spell that machine three ways (localhost, 127.0.0.1, ::1) on a port it
 * picks fresh each run. Demanding the spelling match byte for byte refused
 * working connections — Codex reached the sign-in page and was told "Invalid
 * client or redirect URI" — while gaining nothing, because all three names
 * reach the same listener. The relaxation must stay confined to loopback:
 * anywhere else a loose comparison is an open redirect.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/oauth/class-oauth-util.php';

class OAuthLoopbackRedirectTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	public function test_the_same_uri_matches(): void {
		$this->assertTrue(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/auth/callback', 'http://localhost:1455/auth/callback' )
		);
	}

	/**
	 * RFC 8252 §7.3: a native client binds an ephemeral port it cannot know when
	 * it registers.
	 */
	public function test_the_loopback_port_may_differ(): void {
		$this->assertTrue(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/auth/callback', 'http://localhost:52341/auth/callback' )
		);
	}

	/**
	 * The case from the report: registered one spelling, authorized with another.
	 */
	public function test_the_loopback_spellings_are_one_machine(): void {
		$pairs = array(
			array( 'http://localhost:1455/auth/callback', 'http://127.0.0.1:1455/auth/callback' ),
			array( 'http://127.0.0.1:1455/auth/callback', 'http://localhost:1455/auth/callback' ),
			array( 'http://localhost:1455/auth/callback', 'http://[::1]:1455/auth/callback' ),
			array( 'http://[::1]:1455/auth/callback', 'http://127.0.0.1:9999/auth/callback' ),
		);
		foreach ( $pairs as list( $registered, $given ) ) {
			$this->assertTrue(
				KarMCP_OAuth_Util::redirect_uri_matches( $registered, $given ),
				"$registered should accept $given"
			);
		}
	}

	public function test_a_trailing_slash_is_not_a_mismatch(): void {
		$this->assertTrue(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/auth/callback', 'http://localhost:1455/auth/callback/' )
		);
		$this->assertTrue(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/', 'http://localhost:52341' )
		);
	}

	public function test_a_different_loopback_path_is_still_refused(): void {
		$this->assertFalse(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/auth/callback', 'http://localhost:1455/other' )
		);
	}

	/**
	 * The whole point of confining the relaxation: an https callback is where a
	 * loose comparison becomes an open redirect.
	 */
	public function test_https_callbacks_keep_the_exact_match(): void {
		$cases = array(
			array( 'https://app.example.com/cb', 'https://app.example.com/cb/' ),
			array( 'https://app.example.com/cb', 'https://app.example.com:8443/cb' ),
			array( 'https://app.example.com/cb', 'https://APP.example.com/cb' ),
			array( 'https://app.example.com/cb', 'https://app.example.com/cb2' ),
		);
		foreach ( $cases as list( $registered, $given ) ) {
			$this->assertFalse(
				KarMCP_OAuth_Util::redirect_uri_matches( $registered, $given ),
				"$registered must not accept $given"
			);
		}
	}

	/**
	 * A lookalike host is not loopback, whatever it is named.
	 */
	public function test_a_lookalike_host_is_refused(): void {
		$this->assertFalse(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://127.0.0.1:1455/cb', 'http://127.0.0.1.attacker.test:1455/cb' )
		);
		$this->assertFalse(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/cb', 'http://localhost.attacker.test:1455/cb' )
		);
	}

	/**
	 * Loopback over http only. Upgrading the scheme is a different destination.
	 */
	public function test_a_scheme_change_is_refused(): void {
		$this->assertFalse(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/cb', 'https://localhost:1455/cb' )
		);
	}

	/**
	 * A remote host never gets the loopback treatment.
	 */
	public function test_a_remote_host_is_refused(): void {
		$this->assertFalse(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://localhost:1455/cb', 'http://evil.test:1455/cb' )
		);
		$this->assertFalse(
			KarMCP_OAuth_Util::redirect_uri_matches( 'http://evil.test:1455/cb', 'http://evil.test:9999/cb' )
		);
	}

	public function test_is_loopback_host(): void {
		$this->assertTrue( KarMCP_OAuth_Util::is_loopback_host( '127.0.0.1' ) );
		$this->assertTrue( KarMCP_OAuth_Util::is_loopback_host( '::1' ) );
		$this->assertTrue( KarMCP_OAuth_Util::is_loopback_host( 'localhost' ) );
		$this->assertFalse( KarMCP_OAuth_Util::is_loopback_host( '127.0.0.2' ) );
		$this->assertFalse( KarMCP_OAuth_Util::is_loopback_host( '' ) );
	}
}
