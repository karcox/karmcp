<?php
/**
 * The SSRF gate used by the sideload tools.
 *
 * `wp_http_validate_url()` is the check WordPress applies when
 * `reject_unsafe_urls` is set, and it permits the link-local 169.254.0.0/16
 * range — which is where the cloud-metadata endpoint 169.254.169.254 lives —
 * and does not consider IPv6 at all. So the stub below deliberately says "core
 * is happy with this": what these tests pin is the layer KarMCP adds on top,
 * which is the only thing standing between a redirect and an instance's
 * credentials.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		return $url;
	}
}

require_once __DIR__ . '/../includes/class-url-guard.php';

class UrlGuardTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	// -----------------------------------------------------------------
	// ip_is_blocked()
	// -----------------------------------------------------------------

	/** @return array<string,array{0:string}> */
	public static function blocked_addresses(): array {
		return array(
			'cloud metadata'     => array( '169.254.169.254' ),
			'link-local'         => array( '169.254.0.1' ),
			'loopback'           => array( '127.0.0.1' ),
			'rfc1918 10'         => array( '10.0.0.5' ),
			'rfc1918 172'        => array( '172.16.3.4' ),
			'rfc1918 192'        => array( '192.168.1.1' ),
			'unspecified'        => array( '0.0.0.0' ),
			'cgnat'              => array( '100.64.0.1' ),
			'ipv6 loopback'      => array( '::1' ),
			'ipv6 unique local'  => array( 'fd00::1' ),
			'ipv6 link local'    => array( 'fe80::1' ),
			'ipv4-mapped v6'     => array( '::ffff:127.0.0.1' ),
			'ipv4-mapped meta'   => array( '::ffff:169.254.169.254' ),
			'not an address'     => array( 'not-an-ip' ),
		);
	}

	/** @dataProvider blocked_addresses */
	public function test_internal_addresses_are_blocked( string $ip ): void {
		$this->assertTrue( KarMCP_Url_Guard::ip_is_blocked( $ip ), $ip );
	}

	/** @return array<string,array{0:string}> */
	public static function public_addresses(): array {
		return array(
			'documentation range' => array( '198.51.100.10' ),
			'public v4'           => array( '8.8.8.8' ),
			'public v6'           => array( '2606:4700:4700::1111' ),
		);
	}

	/** @dataProvider public_addresses */
	public function test_public_addresses_are_allowed( string $ip ): void {
		$this->assertFalse( KarMCP_Url_Guard::ip_is_blocked( $ip ), $ip );
	}

	// -----------------------------------------------------------------
	// is_safe_remote_url() — the gate safe_download() applies to every hop
	// -----------------------------------------------------------------

	public function test_the_metadata_endpoint_is_refused(): void {
		$this->assertFalse(
			KarMCP_Url_Guard::is_safe_remote_url( 'http://169.254.169.254/latest/meta-data/iam/security-credentials/' )
		);
	}

	public function test_loopback_is_refused(): void {
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( 'http://127.0.0.1/wp-admin/' ) );
	}

	public function test_a_bracketed_ipv6_loopback_is_refused(): void {
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( 'http://[::1]/x.png' ) );
	}

	/**
	 * An IPv4-mapped IPv6 literal is the same address wearing a different hat;
	 * a check that only understood dotted quads would wave it through.
	 */
	public function test_an_ipv4_mapped_metadata_address_is_refused(): void {
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( 'http://[::ffff:169.254.169.254]/latest/' ) );
	}

	public function test_a_public_literal_is_allowed(): void {
		$this->assertTrue( KarMCP_Url_Guard::is_safe_remote_url( 'https://198.51.100.10/image.png' ) );
	}

	public function test_a_non_http_scheme_is_refused(): void {
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( 'file:///etc/passwd' ) );
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( 'gopher://198.51.100.10/' ) );
	}

	public function test_a_url_without_a_host_is_refused(): void {
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( '/relative/path.png' ) );
		$this->assertFalse( KarMCP_Url_Guard::is_safe_remote_url( '' ) );
	}
}
