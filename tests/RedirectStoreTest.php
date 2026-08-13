<?php
/**
 * Public unit tests for KarMCP_Redirect_Store — the DB-free surface:
 * path normalization, loop guarding, target resolution, and create()'s
 * pre-DB validation branches. Persistence (insert/get/all/hit/rollback) is
 * covered by the live wp-cli smoke against a real database.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/redirects/class-redirect-store.php';

class RedirectStoreTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** @var string */
	private $store = 'KarMCP_Redirect_Store';

	public function test_normalize_path_strips_scheme_host_query_and_trailing_slash() {
		$n = $this->store;
		$this->assertSame( '/old-page', $n::normalize_path( 'https://example.com/old-page/?utm=1' ) );
		$this->assertSame( '/old-page', $n::normalize_path( '/Old-Page/' ) ); // lowercased.
		$this->assertSame( '/a/b', $n::normalize_path( '/a//b//' ) );         // collapse dup slashes.
		$this->assertSame( '/', $n::normalize_path( '/' ) );                  // root keeps its slash.
		$this->assertSame( '/x', $n::normalize_path( 'x' ) );                 // ensure leading slash.
		$this->assertSame( '/', $n::normalize_path( '' ) );                   // empty → root.
	}

	public function test_would_loop_flags_self_target() {
		$n = $this->store;
		$this->assertTrue( $n::would_loop( '/a', '/a' ) );
		$this->assertTrue( $n::would_loop( '/a', 'https://example.com/a/' ) );
		$this->assertFalse( $n::would_loop( '/a', '/b' ) );
	}

	public function test_resolve_target_prefers_permalink_for_post_target() {
		$n = $this->store;
		// Stored URL target → returned verbatim.
		$this->assertSame( '/dest', $n::resolve_target( array( 'target' => '/dest', 'target_post_id' => 0 ) ) );
		// Post target → the permalink (bootstrap get_permalink stub).
		$this->assertSame( 'http://example.test/?p=42', $n::resolve_target( array( 'target' => '', 'target_post_id' => 42 ) ) );
	}

	public function test_create_rejects_empty_source() {
		$n   = $this->store;
		$res = $n::create( array( 'target' => '/x' ) );
		$this->assertTrue( is_wp_error( $res ) );
		$this->assertSame( 'invalid_source', $res->get_error_code() );
	}

	public function test_create_rejects_too_long_source() {
		$n   = $this->store;
		$res = $n::create( array( 'source' => '/' . str_repeat( 'a', 200 ), 'target' => '/x' ) );
		$this->assertTrue( is_wp_error( $res ) );
		$this->assertSame( 'source_too_long', $res->get_error_code() );
	}

	public function test_create_rejects_ambiguous_target() {
		$n   = $this->store;
		$res = $n::create( array( 'source' => '/a', 'target' => '/x', 'target_post_id' => 5 ) );
		$this->assertTrue( is_wp_error( $res ) );
		$this->assertSame( 'ambiguous_target', $res->get_error_code() );
	}

	public function test_create_rejects_missing_target() {
		$n   = $this->store;
		$res = $n::create( array( 'source' => '/a' ) );
		$this->assertTrue( is_wp_error( $res ) );
		$this->assertSame( 'missing_target', $res->get_error_code() );
	}

	public function test_create_rejects_self_loop() {
		$n   = $this->store;
		$res = $n::create( array( 'source' => '/a', 'target' => 'https://example.com/a/' ) );
		$this->assertTrue( is_wp_error( $res ) );
		$this->assertSame( 'redirect_loop', $res->get_error_code() );
	}
}
