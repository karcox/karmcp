<?php
/**
 * The GitHub release updater: what it offers WordPress, and everything it
 * refuses to offer.
 *
 * The value this returns is a URL WordPress downloads and unpacks over the
 * running plugin, so each refusal here is a security property, not tidiness:
 * a release body arrives over the network, and the only reasons to act on it
 * are that it came from GitHub, points back at GitHub, and names a version
 * newer than the one installed.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'KARMCP_BASENAME' ) ) {
	define( 'KARMCP_BASENAME', 'karmcp/karmcp.php' );
}
if ( ! defined( 'KARMCP_VERSION' ) ) {
	define( 'KARMCP_VERSION', '1.43.0' );
}

require_once __DIR__ . '/../includes/class-updater.php';

class UpdaterTest extends TestCase {

	private const FILE = 'karmcp/karmcp.php';

	protected function setUp(): void {
		karmcp_test_reset();
		karmcp_test_reset_hooks();
	}

	private function release( array $overrides = array() ): array {
		return array_merge(
			array(
				'tag_name'   => 'v1.43.0',
				'draft'      => false,
				'prerelease' => false,
				'html_url'   => 'https://github.com/karcox/karmcp/releases/tag/v1.43.0',
				'assets'     => array(
					array(
						'name'                 => 'karmcp-1.43.0.zip',
						'browser_download_url' => 'https://github.com/karcox/karmcp/releases/download/v1.43.0/karmcp-1.43.0.zip',
					),
				),
			),
			$overrides
		);
	}

	private function response( array $overrides = array(), string $current = '1.42.1' ): ?array {
		return KarMCP_Updater::response_from_release( $this->release( $overrides ), $current, self::FILE );
	}

	// ---------------------------------------------------------------------
	// What it offers
	// ---------------------------------------------------------------------

	public function test_a_newer_release_is_offered_with_what_wordpress_needs() {
		$out = $this->response();

		$this->assertSame( '1.43.0', $out['version'] );
		$this->assertSame( self::FILE, $out['plugin'] );
		$this->assertSame( 'karmcp', $out['slug'], 'WordPress matches the update to the installed folder by slug.' );
		$this->assertSame( 'https://github.com/karcox/karmcp/releases/download/v1.43.0/karmcp-1.43.0.zip', $out['package'] );
		$this->assertSame( 'https://github.com/karcox/karmcp/releases/tag/v1.43.0', $out['url'] );
	}

	public function test_the_tag_may_or_may_not_carry_a_v() {
		$this->assertSame( '1.43.0', $this->response( array( 'tag_name' => '1.43.0' ) )['version'] );
		$this->assertSame( '1.43.0', $this->response( array( 'tag_name' => 'V1.43.0' ) )['version'] );
	}

	public function test_the_download_may_come_from_githubs_asset_host() {
		$out = $this->response( array( 'assets' => array( array(
			'name'                 => 'karmcp-1.43.0.zip',
			'browser_download_url' => 'https://objects.githubusercontent.com/github-production-release-asset/1/karmcp-1.43.0.zip',
		) ) ) );

		$this->assertIsArray( $out );
	}

	// ---------------------------------------------------------------------
	// What it refuses
	// ---------------------------------------------------------------------

	public function test_the_installed_version_is_not_an_update() {
		$this->assertNull( $this->response( array(), '1.43.0' ) );
	}

	public function test_an_older_release_is_not_an_update() {
		$this->assertNull( $this->response( array( 'tag_name' => 'v1.41.0' ) ), 'A release behind the installed build must never be offered: WordPress would install it and call it an update.' );
	}

	public function test_a_draft_or_a_prerelease_is_not_offered() {
		$this->assertNull( $this->response( array( 'draft' => true ) ) );
		$this->assertNull( $this->response( array( 'prerelease' => true ) ) );
	}

	/**
	 * @dataProvider unusable_tags
	 */
	public function test_a_tag_that_is_not_a_version_is_refused( string $tag ) {
		$this->assertNull( $this->response( array( 'tag_name' => $tag ) ), 'A tag that compares as nothing would look like an update on every site.' );
	}

	public static function unusable_tags(): array {
		return array(
			'empty'        => array( '' ),
			'a word'       => array( 'latest' ),
			'a date'       => array( 'release-2026-09-23' ),
			'one number'   => array( '2' ),
			'with spaces'  => array( '1.43.0 final' ),
		);
	}

	public function test_a_release_without_a_zip_is_not_an_update() {
		$this->assertNull( $this->response( array( 'assets' => array() ) ) );
		$this->assertNull( $this->response( array( 'assets' => array( array(
			'name'                 => 'karmcp-1.43.0.tar.gz',
			'browser_download_url' => 'https://github.com/karcox/karmcp/releases/download/v1.43.0/karmcp-1.43.0.tar.gz',
		) ) ) ) );
	}

	/**
	 * @dataProvider foreign_downloads
	 */
	public function test_a_download_from_anywhere_else_is_refused( string $url ) {
		$out = $this->response( array( 'assets' => array( array( 'name' => 'karmcp-1.43.0.zip', 'browser_download_url' => $url ) ) ) );

		$this->assertNull( $out, 'This URL is what WordPress unpacks over the live plugin.' );
	}

	public static function foreign_downloads(): array {
		return array(
			'another host'          => array( 'https://example.test/karmcp-1.43.0.zip' ),
			'plain http'            => array( 'http://github.com/karcox/karmcp/releases/download/v1.43.0/karmcp-1.43.0.zip' ),
			'a lookalike host'      => array( 'https://github.com.evil.test/karmcp-1.43.0.zip' ),
			'github as a path only' => array( 'https://evil.test/github.com/karmcp-1.43.0.zip' ),
			'nothing at all'        => array( '' ),
		);
	}

	public function test_the_first_usable_asset_wins_over_the_others() {
		$out = $this->response( array( 'assets' => array(
			array( 'name' => 'checksums.txt', 'browser_download_url' => 'https://github.com/karcox/karmcp/releases/download/v1.43.0/checksums.txt' ),
			array( 'name' => 'karmcp-1.43.0.zip', 'browser_download_url' => 'https://github.com/karcox/karmcp/releases/download/v1.43.0/karmcp-1.43.0.zip' ),
		) ) );

		$this->assertStringEndsWith( 'karmcp-1.43.0.zip', $out['package'] );
	}

	// ---------------------------------------------------------------------
	// The gate around it
	// ---------------------------------------------------------------------

	public function test_the_filter_can_switch_the_check_off() {
		$this->assertTrue( KarMCP_Updater::enabled() );

		add_filter( 'karmcp_update_check_enabled', '__return_false' );
		$this->assertFalse( KarMCP_Updater::enabled() );
	}

	public function test_another_plugins_update_is_left_alone() {
		$untouched = array( 'version' => '9.9.9', 'package' => 'https://github.com/someone/else.zip' );

		$this->assertSame( $untouched, KarMCP_Updater::check( $untouched, array( 'Version' => '1.0.0' ), 'some-other-plugin/plugin.php' ) );
	}
}
