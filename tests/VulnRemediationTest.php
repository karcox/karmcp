<?php
/**
 * What an update would actually achieve.
 *
 * The button this backs is the one place in the security section where a wrong
 * answer costs someone real time: telling them an update clears a vulnerability
 * when it does not sends them away believing they are done.
 *
 * Grouped by plugin because fifteen JetEngine CVEs are one update, not fifteen
 * buttons — and the count has to be honest, because on the test bed several
 * plugins have an available update that covers some of their vulnerabilities
 * and not all.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/modules/vulnerabilities/class-vuln-matcher.php';
require_once __DIR__ . '/../includes/modules/vulnerabilities/class-vuln-remediation.php';

class VulnRemediationTest extends TestCase {

	private function match( string $slug, string $file, string $installed, string $to, float $score, bool $patched = true ): array {
		return array(
			'slug'              => $slug,
			'software_type'     => 'plugin',
			'plugin_file'       => $file,
			'installed'         => $installed,
			'cvss_score'        => $score,
			'patched'           => $patched,
			'patched_versions'  => wp_json_encode( array( $to ) ),
			'affected_versions' => wp_json_encode(
				array(
					'r' => array(
						'from_version'   => '*',
						'from_inclusive' => true,
						'to_version'     => $to,
						'to_inclusive'   => false,
					),
				)
			),
		);
	}

	public function test_several_vulnerabilities_in_one_plugin_become_one_entry(): void {
		$plan = KarMCP_Vuln_Remediation::plan(
			array(
				$this->match( 'jet-engine', 'jet-engine/jet-engine.php', '3.8.3', '3.8.10', 8.1 ),
				$this->match( 'jet-engine', 'jet-engine/jet-engine.php', '3.8.3', '3.8.9.1', 7.5 ),
				$this->match( 'jet-engine', 'jet-engine/jet-engine.php', '3.8.3', '3.8.6.2', 7.5 ),
			),
			array( 'jet-engine/jet-engine.php' => '3.8.14' )
		);

		$this->assertCount( 1, $plan );
		$entry = reset( $plan );
		$this->assertSame( 3, $entry['total'] );
		$this->assertSame( 3, $entry['fixed'] );
		$this->assertSame( 8.1, $entry['worst'] );
	}

	/**
	 * The honest count. An available update that does not reach every patched
	 * version clears some and leaves the rest, and saying "clears 3 of 3" there
	 * would send somebody away believing they were done.
	 */
	public function test_an_update_that_covers_only_some_is_counted_as_only_some(): void {
		$plan = KarMCP_Vuln_Remediation::plan(
			array(
				$this->match( 'x', 'x/x.php', '1.0', '1.5', 9.0 ),
				$this->match( 'x', 'x/x.php', '1.0', '2.0', 7.0 ),
			),
			array( 'x/x.php' => '1.6' )
		);

		$entry = reset( $plan );
		$this->assertSame( 2, $entry['total'] );
		$this->assertSame( 1, $entry['fixed'] );
		$this->assertSame( 1, $entry['remaining'] );
	}

	public function test_with_no_update_on_offer_nothing_is_claimed_as_fixed(): void {
		$plan = KarMCP_Vuln_Remediation::plan(
			array( $this->match( 'jet-engine', 'jet-engine/jet-engine.php', '3.8.3', '3.8.10', 8.1 ) ),
			array()
		);

		$entry = reset( $plan );
		$this->assertSame( '', $entry['available'] );
		$this->assertSame( 0, $entry['fixed'] );
		$this->assertSame( 1, $entry['remaining'] );
	}

	public function test_an_unpatched_vulnerability_is_never_counted_as_fixed(): void {
		$plan = KarMCP_Vuln_Remediation::plan(
			array( $this->match( 'y', 'y/y.php', '1.0', '9.9', 8.0, false ) ),
			array( 'y/y.php' => '99.0' )
		);

		$entry = reset( $plan );
		$this->assertSame( 1, $entry['unpatched'] );
		$this->assertSame( 0, $entry['fixed'] );
	}

	/** Worst first: the 9.8 unauthenticated RCE is what somebody should click. */
	public function test_the_worst_plugin_comes_first(): void {
		$plan = KarMCP_Vuln_Remediation::plan(
			array(
				$this->match( 'low', 'low/low.php', '1.0', '2.0', 4.3 ),
				$this->match( 'high', 'high/high.php', '1.0', '2.0', 9.8 ),
				$this->match( 'mid', 'mid/mid.php', '1.0', '2.0', 6.5 ),
			),
			array()
		);

		$this->assertSame( array( 'high/high.php', 'mid/mid.php', 'low/low.php' ), array_keys( $plan ) );
	}

	public function test_themes_are_not_offered_as_plugin_updates(): void {
		$theme = $this->match( 'sometheme', '', '1.0', '2.0', 7.0 );
		$theme['software_type'] = 'theme';

		$this->assertSame( array(), KarMCP_Vuln_Remediation::plan( array( $theme ), array() ) );
	}
}
