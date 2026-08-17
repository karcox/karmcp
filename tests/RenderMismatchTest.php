<?php
/**
 * A `full` render that fetched the wrong page says so.
 *
 * The loopback request carries no session, so a site behind an access wall
 * answers it with the login page at 200 OK — and every collector then analyses
 * that. A published, perfectly visible course came back as
 * `document.title: "Login"`, canonical `/login/`, and warnings `no_h1` and
 * `empty_container` that read as defects of the course. Real-looking findings
 * about the wrong document are worse than a failure.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-content-extractor.php';

class RenderMismatchTest extends TestCase {

	/**
	 * Runs the private flag, which is where the decision lives; extract() itself
	 * needs a live loopback.
	 *
	 * @param array $digest  Digest so far.
	 * @param int   $post_id Requested post.
	 * @return array The digest after flagging.
	 */
	private function flag( array $digest, int $post_id ): array {
		// setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.
		$method = new ReflectionMethod( KarMCP_Content_Extractor::class, 'flag_wrong_page' );
		$method->invokeArgs( null, array( &$digest, $post_id ) );
		return $digest;
	}

	/**
	 * @param string $canonical Canonical URL found in the rendered HTML.
	 * @return array
	 */
	private function digest( string $canonical ): array {
		return array(
			'document' => array( 'canonical' => $canonical, 'title' => 'Login' ),
			'render'   => array( 'scope' => 'full', 'status_code' => 200 ),
			'warnings' => array(
				array( 'code' => 'no_h1', 'severity' => 'warning', 'message' => 'no h1' ),
				array( 'code' => 'empty_container', 'severity' => 'warning', 'message' => 'empty' ),
			),
		);
	}

	public function test_the_login_page_is_reported_instead_of_analysed(): void {
		$after = $this->flag( $this->digest( 'http://example.test/login/' ), 11103 );

		$this->assertFalse( $after['render']['matches_post'] );
		$this->assertSame( 'render_mismatch', $after['warnings'][0]['code'] );
		$this->assertSame( 'error', $after['warnings'][0]['severity'] );
	}

	/**
	 * The findings about the served page must not survive: they describe a
	 * document nobody asked about, and they are what made this look like a
	 * problem with the course.
	 */
	public function test_the_warnings_about_the_wrong_page_are_dropped(): void {
		$after = $this->flag( $this->digest( 'http://example.test/login/' ), 11103 );

		$codes = array_column( $after['warnings'], 'code' );
		$this->assertNotContains( 'no_h1', $codes );
		$this->assertNotContains( 'empty_container', $codes );
	}

	public function test_the_right_page_is_left_alone(): void {
		$after = $this->flag( $this->digest( 'http://example.test/?p=11103' ), 11103 );

		$this->assertArrayNotHasKey( 'matches_post', $after['render'] );
		$this->assertCount( 2, $after['warnings'] );
	}

	/**
	 * Trailing slashes, case and host form differ between what a theme prints
	 * and what get_permalink() returns; none of those is a different page, and
	 * treating them as one would fire the alarm on every correct render.
	 */
	public function test_a_trailing_slash_or_capital_is_not_a_different_page(): void {
		$after = $this->flag( $this->digest( 'http://example.test/?p=11103&' ), 11103 );

		$this->assertArrayNotHasKey( 'matches_post', $after['render'] );
	}

	/**
	 * No canonical, no verdict. Plenty of themes omit it, and a missing tag is
	 * not evidence of anything.
	 */
	public function test_no_canonical_means_no_claim(): void {
		$digest = $this->digest( '' );
		$after  = $this->flag( $digest, 11103 );

		$this->assertArrayNotHasKey( 'matches_post', $after['render'] );
		$this->assertCount( 2, $after['warnings'] );
	}
}
