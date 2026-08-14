<?php
/**
 * The two unauthenticated edges of the OAuth server.
 *
 * `/register` is open by design (RFC 7591 — an MCP client self-registers before
 * anyone has logged in), which makes it the one endpoint that writes a row for
 * a caller who has proved nothing. And the scope the client asks for used to be
 * stored and echoed back as the scope it was GRANTED, which is a lie the moment
 * anything downstream reads that column.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['karmcp_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ): bool {
		$GLOBALS['karmcp_test_transients'][ $key ]     = $value;
		$GLOBALS['karmcp_test_transient_ttl'][ $key ] = $ttl;
		return true;
	}
}

require_once __DIR__ . '/../includes/oauth/class-oauth-server.php';
require_once __DIR__ . '/../includes/oauth/class-oauth-clients.php';
require_once __DIR__ . '/../includes/oauth/class-oauth-authorize.php';

class OAuthRegistrationTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
		$GLOBALS['karmcp_test_transients']    = array();
		$GLOBALS['karmcp_test_transient_ttl'] = array();
		$_SERVER['REMOTE_ADDR']               = '198.51.100.7';
	}

	// -----------------------------------------------------------------
	// Rate limiting
	// -----------------------------------------------------------------

	public function test_registrations_up_to_the_limit_are_allowed(): void {
		for ( $i = 0; $i < KarMCP_OAuth_Clients::RATE_LIMIT; $i++ ) {
			$this->assertFalse( KarMCP_OAuth_Clients::rate_limited(), 'call ' . $i );
		}
	}

	public function test_the_next_registration_is_refused(): void {
		for ( $i = 0; $i < KarMCP_OAuth_Clients::RATE_LIMIT; $i++ ) {
			KarMCP_OAuth_Clients::rate_limited();
		}
		$this->assertTrue( KarMCP_OAuth_Clients::rate_limited() );
	}

	public function test_a_different_address_has_its_own_budget(): void {
		for ( $i = 0; $i < KarMCP_OAuth_Clients::RATE_LIMIT; $i++ ) {
			KarMCP_OAuth_Clients::rate_limited();
		}
		$this->assertTrue( KarMCP_OAuth_Clients::rate_limited() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertFalse( KarMCP_OAuth_Clients::rate_limited() );
	}

	/** The address is personal data and has no business sitting in the clear. */
	public function test_the_key_does_not_contain_the_raw_address(): void {
		$key = KarMCP_OAuth_Clients::rate_limit_key( '198.51.100.7', 1000000 );
		$this->assertStringNotContainsString( '198.51.100.7', $key );
		$this->assertStringStartsWith( 'karmcp_oauth_reg_', $key );
	}

	/**
	 * Fixed buckets, not a sliding transient: a `set_transient()` per hit pushes
	 * the expiry forward every time, so a steady stream of requests would keep
	 * one counter alive forever and lock the address out permanently.
	 */
	public function test_the_bucket_rolls_over_with_the_window(): void {
		$window = KarMCP_OAuth_Clients::RATE_WINDOW;

		// Two moments inside one window share a counter...
		$this->assertSame(
			KarMCP_OAuth_Clients::rate_limit_key( '198.51.100.7', 0 ),
			KarMCP_OAuth_Clients::rate_limit_key( '198.51.100.7', $window - 1 )
		);

		// ...and the next window starts a fresh one.
		$this->assertNotSame(
			KarMCP_OAuth_Clients::rate_limit_key( '198.51.100.7', $window - 1 ),
			KarMCP_OAuth_Clients::rate_limit_key( '198.51.100.7', $window )
		);
	}

	// -----------------------------------------------------------------
	// Scope
	// -----------------------------------------------------------------

	public function test_the_supported_scope_is_granted(): void {
		$this->assertSame( 'mcp', KarMCP_OAuth_Authorize::normalize_scope( 'mcp' ) );
	}

	public function test_an_unsupported_scope_is_dropped(): void {
		$this->assertSame( 'mcp', KarMCP_OAuth_Authorize::normalize_scope( 'mcp admin:everything' ) );
	}

	public function test_a_wholly_unsupported_request_gets_the_default(): void {
		$this->assertSame( 'mcp', KarMCP_OAuth_Authorize::normalize_scope( 'openid profile' ) );
	}

	public function test_an_empty_request_gets_the_default(): void {
		$this->assertSame( 'mcp', KarMCP_OAuth_Authorize::normalize_scope( '' ) );
		$this->assertSame( 'mcp', KarMCP_OAuth_Authorize::normalize_scope( '   ' ) );
	}

	public function test_duplicates_collapse(): void {
		$this->assertSame( 'mcp', KarMCP_OAuth_Authorize::normalize_scope( 'mcp mcp  mcp' ) );
	}

	public function test_validated_params_carry_the_normalized_scope(): void {
		$params = array(
			'response_type'         => 'code',
			'client_id'             => 'abc',
			'redirect_uri'          => 'https://example.test/cb',
			'code_challenge'        => 'x',
			'code_challenge_method' => 'S256',
			'scope'                 => 'mcp wp:admin',
		);

		$valid = KarMCP_OAuth_Authorize::validate_params( $params, array( 'client_id' => 'abc' ) );

		$this->assertIsArray( $valid );
		$this->assertSame( 'mcp', $valid['scope'] );
	}
}
