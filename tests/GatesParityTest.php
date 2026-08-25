<?php
/**
 * bin/check.ps1 and the workflows have to run the same gates.
 *
 * CLAUDE.md states the parity rule outright — "si bin/check.ps1 gana una
 * puerta, el workflow que le toque la gana también" — and .github/workflows/
 * lint.yml repeats it in a comment. Both are prose, and prose is what failed
 * last time: lint.yml shipped with `continue-on-error`, the plan to remove it
 * was written down, the triage finished two days later, and nobody went back to
 * the file. The check was green and could not fail, for eight days, while
 * CLAUDE.md still said there was no CI.
 *
 * A gate that only runs locally makes CI's green hollow; a gate that only runs
 * in CI is one a developer never sees before pushing. This holds the rule.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class GatesParityTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	/**
	 * The gates, as the fragment that identifies each one on both sides.
	 *
	 * Matching on the command rather than on a name keeps this honest: a step
	 * renamed in a workflow still counts, a step deleted does not.
	 *
	 * @var array<string,string> label => fragment that must appear on both sides.
	 */
	private const GATES = array(
		'classmap freshness' => 'generate-classmap.php --check',
		'POT freshness'      => 'make-pot.php --check',
		'PHPStan'            => 'phpstan',
		'PHPCS'              => 'phpcs',
	);

	/** @return string The concatenated workflow sources. */
	private function workflows(): string {
		$out = '';
		foreach ( glob( self::ROOT . '/.github/workflows/*.yml' ) as $file ) {
			$out .= file_get_contents( $file ) . "\n";
		}
		return str_replace( '\\', '/', $out );
	}

	/** @return string bin/check.ps1, slashes normalised. */
	private function check_script(): string {
		return str_replace( '\\', '/', (string) file_get_contents( self::ROOT . '/bin/check.ps1' ) );
	}

	public function test_every_local_gate_also_runs_in_ci(): void {
		$local = $this->check_script();
		$ci    = $this->workflows();

		$this->assertNotEmpty( $ci, 'No workflow files found.' );

		foreach ( self::GATES as $label => $fragment ) {
			$this->assertStringContainsString( $fragment, $local, "bin/check.ps1 no longer runs the $label gate; update GATES or restore it." );
			$this->assertStringContainsString(
				$fragment,
				$ci,
				"bin/check.ps1 runs the $label gate but no workflow does. A gate that only runs locally"
					. " makes CI's green hollow — add it to .github/workflows/."
			);
		}
	}

	public function test_the_suite_runs_on_both_sides(): void {
		$this->assertMatchesRegularExpression( '/phpunit/i', $this->check_script(), 'bin/check.ps1 no longer runs PHPUnit.' );
		$this->assertMatchesRegularExpression( '/phpunit/i', $this->workflows(), 'No workflow runs PHPUnit.' );
	}

	/**
	 * The specific way the last hollow green happened: a step that reports
	 * success whatever it finds.
	 */
	public function test_no_workflow_step_is_allowed_to_fail_silently(): void {
		foreach ( glob( self::ROOT . '/.github/workflows/*.yml' ) as $file ) {
			$src  = (string) file_get_contents( $file );
			$name = basename( $file );

			// Strip comments: lint.yml explains this rule in one, and the
			// explanation must not read as a violation of itself.
			$code = preg_replace( '/^\s*#.*$/m', '', $src );

			$this->assertSame(
				0,
				preg_match( '/continue-on-error\s*:\s*true/', (string) $code ),
				"$name has a step with continue-on-error: true. That step cannot fail the check, so the green it"
					. ' produces means nothing. This is exactly how lint.yml was hollow for eight days.'
			);
		}
		$this->assertTrue( true );
	}
}
