<?php
/**
 * The WebP rewriter answers "does a .webp sibling exist?" once per file per
 * request, not once per URL it is handed.
 *
 * `filter_srcset` receives the whole srcset and walks every candidate, and the
 * same attachment reaches the rewriter again through `src` and through any
 * lazy-load attribute. Without memoization a page with 20 images and 5 sizes
 * each costs ~100 `stat()` calls — on network storage that is not free.
 *
 * The memoization is proven behaviourally: the sibling is deleted from disk
 * between two identical calls, and the second call must still answer from the
 * cache. A rewriter that re-stats would return the source URL instead.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/modules/image-optimization/class-webp-rewriter.php';

class WebpRewriteMemoTest extends TestCase {

	/** @var string */
	private $dir;

	/** @var string */
	private $url = 'https://example.com/wp-content/uploads';

	protected function setUp(): void {
		karmcp_test_reset();
		$this->dir = sys_get_temp_dir() . '/karmcp-webp-memo-' . uniqid();
		mkdir( $this->dir );
		$GLOBALS['karmcp_test']['upload_dir'] = array(
			'basedir' => $this->dir,
			'baseurl' => $this->url,
		);
		// The frontend path needs the browser to advertise WebP support.
		$_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp,*/*';
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	/**
	 * Writes a source file and, optionally, its sibling.
	 *
	 * @param string $name      File name under the uploads root.
	 * @param bool   $with_webp Whether to write the .webp sibling too.
	 */
	private function seed( string $name, bool $with_webp = true ): void {
		file_put_contents( $this->dir . '/' . $name, 'source' );
		if ( $with_webp ) {
			file_put_contents( $this->dir . '/' . $name . '.webp', 'sibling' );
		}
	}

	public function test_existing_sibling_is_served() {
		$this->seed( 'photo.jpg' );
		$r = new KarMCP_Webp_Rewriter( true );

		$this->assertSame( $this->url . '/photo.jpg.webp', $r->filter_url( $this->url . '/photo.jpg' ) );
	}

	public function test_missing_sibling_leaves_the_url_alone() {
		$this->seed( 'photo.jpg', false );
		$r = new KarMCP_Webp_Rewriter( true );

		$this->assertSame( $this->url . '/photo.jpg', $r->filter_url( $this->url . '/photo.jpg' ) );
	}

	/** The memoization itself: the disk changes, the cached answer does not. */
	public function test_sibling_lookup_happens_once_per_file() {
		$this->seed( 'photo.jpg' );
		$r = new KarMCP_Webp_Rewriter( true );

		$first = $r->filter_url( $this->url . '/photo.jpg' );
		unlink( $this->dir . '/photo.jpg.webp' );
		$second = $r->filter_url( $this->url . '/photo.jpg' );

		$this->assertSame( $this->url . '/photo.jpg.webp', $first );
		$this->assertSame( $first, $second, 'The second call re-stat()ed the file instead of reusing the answer.' );
	}

	/** A negative answer is cached too — that is the common case on a page. */
	public function test_negative_answer_is_cached() {
		$this->seed( 'photo.jpg', false );
		$r = new KarMCP_Webp_Rewriter( true );

		$first = $r->filter_url( $this->url . '/photo.jpg' );
		file_put_contents( $this->dir . '/photo.jpg.webp', 'appeared' );
		$second = $r->filter_url( $this->url . '/photo.jpg' );

		$this->assertSame( $this->url . '/photo.jpg', $first );
		$this->assertSame( $first, $second );
	}

	/** Caching is per file, so a second attachment gets its own answer. */
	public function test_cache_does_not_leak_between_files() {
		$this->seed( 'has-sibling.jpg' );
		$this->seed( 'no-sibling.jpg', false );
		$r = new KarMCP_Webp_Rewriter( true );

		$this->assertSame( $this->url . '/has-sibling.jpg.webp', $r->filter_url( $this->url . '/has-sibling.jpg' ) );
		$this->assertSame( $this->url . '/no-sibling.jpg', $r->filter_url( $this->url . '/no-sibling.jpg' ) );
	}

	/** The srcset walk is the reason the cache exists; it must still be correct. */
	public function test_srcset_candidates_are_each_rewritten() {
		$this->seed( 'photo-300x200.jpg' );
		$this->seed( 'photo-600x400.jpg' );
		$this->seed( 'photo-900x600.jpg', false );
		$r = new KarMCP_Webp_Rewriter( true );

		$out = $r->filter_srcset(
			array(
				300 => array( 'url' => $this->url . '/photo-300x200.jpg' ),
				600 => array( 'url' => $this->url . '/photo-600x400.jpg' ),
				900 => array( 'url' => $this->url . '/photo-900x600.jpg' ),
			)
		);

		$this->assertSame( $this->url . '/photo-300x200.jpg.webp', $out[300]['url'] );
		$this->assertSame( $this->url . '/photo-600x400.jpg.webp', $out[600]['url'] );
		$this->assertSame( $this->url . '/photo-900x600.jpg', $out[900]['url'] );
	}

	/** A URL outside the uploads root is never touched, cached or not. */
	public function test_url_outside_uploads_is_untouched() {
		$r   = new KarMCP_Webp_Rewriter( true );
		$out = $r->filter_url( 'https://cdn.example.net/elsewhere/photo.jpg' );

		$this->assertSame( 'https://cdn.example.net/elsewhere/photo.jpg', $out );
	}
}
