<?php
/**
 * A WebP that came out bigger than its source is discarded.
 *
 * WebP being smaller is the premise of generating it, not a guarantee. On
 * photographic JPEGs already saved at a sensible quality the re-encode
 * regularly grows, and when it does the sibling costs twice: a second file on
 * disk, and a bigger download for every visitor, because the rewriter prefers
 * the sibling whenever it exists.
 *
 * Measured on content.karcos.com on 2026-08-18: ten photographs and every one
 * of their generated sub-sizes came out larger as WebP, from +15% to +43%. The
 * numbers in these tests are four of those real pairs.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/modules/image-optimization/class-webp-generator.php';

class WebpNotSmallerTest extends TestCase {

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/karmcp-webp-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
	}

	/**
	 * Writes a source and its sibling at the given sizes, then runs the check.
	 *
	 * @param int $source_bytes Size of the original.
	 * @param int $webp_bytes   Size of the generated sibling.
	 * @return array{0:mixed,1:string} The return value, and the sibling path.
	 */
	private function check( int $source_bytes, int $webp_bytes ): array {
		$file    = $this->dir . '/photo.jpeg';
		$sibling = KarMCP_Webp_Generator::sibling_path( $file );

		file_put_contents( $file, str_repeat( 'a', $source_bytes ) );
		file_put_contents( $sibling, str_repeat( 'b', $webp_bytes ) );

		$method = new ReflectionMethod( KarMCP_Webp_Generator::class, 'discard_if_larger' );

		return array( $method->invoke( new KarMCP_Webp_Generator( 82 ), $file, $sibling ), $sibling );
	}

	/**
	 * pexels-photo-3943909: 142,242 bytes of JPEG became 203,414 of WebP.
	 */
	public function test_a_larger_webp_is_deleted_and_reported(): void {
		list( $result, $sibling ) = $this->check( 142242, 203414 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webp_not_smaller', $result->get_error_code() );
		$this->assertFileDoesNotExist( $sibling, 'The useless sibling must not be left on disk.' );
	}

	/**
	 * The error carries both sizes, so a caller can report the trade rather
	 * than just the fact that something was dropped.
	 */
	public function test_the_error_carries_both_sizes(): void {
		list( $result ) = $this->check( 142242, 203414 );

		$data = $result->get_error_data();
		$this->assertSame( 142242, $data['source_bytes'] );
		$this->assertSame( 203414, $data['webp_bytes'] );
	}

	/**
	 * The point of the feature still works: a WebP that saves is kept.
	 */
	public function test_a_smaller_webp_is_kept(): void {
		list( $result, $sibling ) = $this->check( 100000, 62000 );

		$this->assertSame( $sibling, $result );
		$this->assertFileExists( $sibling );
	}

	/**
	 * Equal sizes save nothing and still cost a file, so they go too.
	 */
	public function test_an_identical_size_is_not_worth_a_second_file(): void {
		list( $result, $sibling ) = $this->check( 50000, 50000 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFileDoesNotExist( $sibling );
	}

	/**
	 * If a size cannot be read, keep what was generated: deleting on a failed
	 * stat would throw away good conversions on any host with odd permissions.
	 */
	public function test_an_unreadable_size_keeps_the_sibling(): void {
		$file    = $this->dir . '/gone.jpeg';
		$sibling = KarMCP_Webp_Generator::sibling_path( $file );
		file_put_contents( $sibling, str_repeat( 'b', 10 ) );

		$method = new ReflectionMethod( KarMCP_Webp_Generator::class, 'discard_if_larger' );
		$result = $method->invoke( new KarMCP_Webp_Generator( 82 ), $file, $sibling );

		$this->assertSame( $sibling, $result );
		$this->assertFileExists( $sibling );
	}
}
