<?php
/**
 * The stepped scan must produce the same report as the unstepped one.
 *
 * That is the only property worth pinning here, and it is easy to lose: the
 * moment the two paths assemble their results differently, the progress bar
 * starts producing a subtly different report from the daily cron, and nobody
 * notices until the numbers disagree.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security/class-security-finding.php';
require_once __DIR__ . '/../includes/security/class-security-malware-audit.php';
require_once __DIR__ . '/../includes/security/class-security-software-audit.php';
require_once __DIR__ . '/../includes/security/class-security-scanner.php';

class SecurityStepRunnerTest extends TestCase {

	private function malware_stub(): KarMCP_Security_Malware_Audit {
		return new class() extends KarMCP_Security_Malware_Audit {
			public function run( bool $deep = false, int $max_files = 0, int $max_seconds = 0 ): array {
				return array(
					'findings' => array(
						KarMCP_Security_Finding::make( 'm1', 'malware', 'One', 'warning', 'a.php', 'msg', 'rec' ),
					),
					'stats'    => array(
						'files_scanned'      => 7,
						'files_skipped_size' => 1,
						'truncated'          => false,
						'truncated_reason'   => null,
					),
				);
			}
		};
	}

	public function test_a_single_check_returns_its_findings_and_its_meta(): void {
		$scanner = new KarMCP_Security_Scanner( $this->malware_stub() );
		$step    = $scanner->run_check( 'malware' );

		$this->assertCount( 1, $step['findings'] );
		$this->assertSame( 7, $step['meta']['files_scanned'] );
	}

	/**
	 * The property that matters: stepping through the checks one at a time has
	 * to add up to exactly what running them together produces. If these ever
	 * diverge, the progress bar and the nightly cron are reporting different
	 * things about the same site.
	 */
	public function test_stepping_the_checks_adds_up_to_the_same_report(): void {
		$scanner = new KarMCP_Security_Scanner( $this->malware_stub() );

		$whole = $scanner->scan( array( 'checks' => array( 'malware', 'hardening' ) ) );

		$stepped = array();
		foreach ( array( 'malware', 'hardening' ) as $check ) {
			$step    = $scanner->run_check( $check );
			$stepped = array_merge( $stepped, $step['findings'] );
		}
		$summary = $scanner->summarize( $stepped );

		$this->assertSame( $whole['summary']['score'], $summary['score'] );
		$this->assertSame( $whole['summary']['grade'], $summary['grade'] );
		$this->assertSame( $whole['summary']['counts'], $summary['counts'] );
	}

	/** A check that throws still costs only itself when run as a single step. */
	public function test_a_throwing_check_is_contained_within_its_own_step(): void {
		$software = new class() extends KarMCP_Security_Software_Audit {
			public function run(): array {
				throw new \Error( 'boom' );
			}
		};

		$scanner = new KarMCP_Security_Scanner( null, null, null, $software );
		$step    = $scanner->run_check( 'software' );

		$this->assertCount( 1, $step['findings'] );
		$this->assertSame( 'software_audit_failed', $step['findings'][0]['id'] );
		$this->assertSame( 'warning', $step['findings'][0]['status'] );
	}

	public function test_an_unknown_check_yields_nothing_rather_than_erroring(): void {
		$scanner = new KarMCP_Security_Scanner();
		$step    = $scanner->run_check( 'not-a-check' );

		$this->assertSame( array(), $step['findings'] );
		$this->assertSame( array(), $step['meta'] );
	}
}
