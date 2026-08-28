<?php
/**
 * How the token endpoint reads the body it is posted.
 *
 * `WP_REST_Request::get_body_params()` fills only for form-encoded (and
 * multipart) bodies; a JSON body lands in `get_json_params()`. The endpoint read
 * just the first, so a client posting the exchange as JSON — Antigravity does —
 * arrived with an empty `grant_type` and got `unsupported_grant_type` back.
 *
 * The damage was that it looked like an authentication problem and was not. The
 * browser flow completed, the user pasted a perfectly valid code, and the client
 * reported "Unauthorized" with no token ever issued. Application Passwords went
 * on working, which pointed the search at OAuth as a whole rather than at the
 * one endpoint that could not read its own request.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/oauth/class-oauth-token.php';

/**
 * The two accessors WP_REST_Request exposes for a body, and nothing else.
 */
class KarMCP_Fake_Token_Request {

	/** @var array */
	private $form;

	/** @var array */
	private $json;

	public function __construct( array $form = array(), array $json = array() ) {
		$this->form = $form;
		$this->json = $json;
	}

	public function get_body_params() {
		return $this->form;
	}

	public function get_json_params() {
		return $this->json;
	}
}

class OAuthTokenBodyEncodingTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/**
	 * The encoding the spec mandates. This always worked.
	 */
	public function test_a_form_encoded_body_is_read(): void {
		$r = new KarMCP_Fake_Token_Request( array( 'grant_type' => 'authorization_code', 'code' => 'abc' ) );

		$p = KarMCP_OAuth_Token::request_params( $r );

		$this->assertSame( 'authorization_code', $p['grant_type'] );
		$this->assertSame( 'abc', $p['code'] );
	}

	/**
	 * The regression: a JSON body used to come back empty, so `grant_type` was
	 * '' and the exchange was refused as `unsupported_grant_type`.
	 */
	public function test_a_json_body_is_read(): void {
		$r = new KarMCP_Fake_Token_Request( array(), array( 'grant_type' => 'authorization_code', 'code' => 'abc' ) );

		$p = KarMCP_OAuth_Token::request_params( $r );

		$this->assertSame( 'authorization_code', $p['grant_type'], 'A JSON token request still reads as having no grant_type.' );
		$this->assertSame( 'abc', $p['code'] );
	}

	public function test_a_json_refresh_body_is_read(): void {
		$r = new KarMCP_Fake_Token_Request( array(), array( 'grant_type' => 'refresh_token', 'refresh_token' => 'r1' ) );

		$p = KarMCP_OAuth_Token::request_params( $r );

		$this->assertSame( 'refresh_token', $p['grant_type'] );
		$this->assertSame( 'r1', $p['refresh_token'] );
	}

	/**
	 * Revocation posts a body too, and had the same single-encoding read.
	 */
	public function test_a_json_revocation_body_is_read(): void {
		$r = new KarMCP_Fake_Token_Request( array(), array( 'token' => 't1' ) );

		$this->assertSame( 't1', KarMCP_OAuth_Token::request_params( $r )['token'] );
	}

	/**
	 * Both populated is not a shape any client sends, but the precedence is
	 * decided rather than incidental: form is the encoding the spec mandates.
	 */
	public function test_the_form_body_wins_a_collision(): void {
		$r = new KarMCP_Fake_Token_Request(
			array( 'grant_type' => 'authorization_code' ),
			array( 'grant_type' => 'refresh_token' )
		);

		$this->assertSame( 'authorization_code', KarMCP_OAuth_Token::request_params( $r )['grant_type'] );
	}

	/**
	 * Keys present in only one source survive the merge.
	 */
	public function test_keys_from_both_sources_are_kept(): void {
		$r = new KarMCP_Fake_Token_Request( array( 'code' => 'c' ), array( 'code_verifier' => 'v' ) );

		$p = KarMCP_OAuth_Token::request_params( $r );

		$this->assertSame( 'c', $p['code'] );
		$this->assertSame( 'v', $p['code_verifier'] );
	}

	/**
	 * An empty or malformed body must not fatal — it has to fall through to the
	 * endpoint's own error, which is what tells the client what was wrong.
	 */
	public function test_an_empty_body_yields_no_parameters(): void {
		$this->assertSame( array(), KarMCP_OAuth_Token::request_params( new KarMCP_Fake_Token_Request() ) );
	}
}
