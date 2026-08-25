<?php
/**
 * Every ability has to declare whether it is read-only.
 *
 * `KarMCP_Schema_Compat::wrap_execute_callback()` derives `$readonly` from
 * `meta.annotations.readonly` and runs the `karmcp_before_write` veto whenever
 * it is falsy. A missing annotation is falsy, so an unannotated tool is treated
 * as a write — safe for a writer, wrong for a reader: Guardrails' read-only
 * mode and freeze window refuse it, and the compact dispatcher leaves it out of
 * its read-only pass-through.
 *
 * That failure runs in the safe direction, which is exactly why it survived.
 * Nothing breaks; a read the operator is entitled to just comes back refused,
 * and no test, linter or type checker can see it. Twenty-one abilities were in
 * that state across five files when this gate was written.
 *
 * The gate is the declaration, not its correctness. Whether `readonly => true`
 * is *true* of the execute callback is a question for review — see
 * .claude/agents/contrato-agente.md, and CLAUDE.md's note on `paused()` for
 * what it costs when the answer is no.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/lib-ability-scan.php';

class AbilityAnnotationTest extends TestCase {

	public function test_every_ability_declares_readonly(): void {
		$abilities = KarMCP_Ability_Scan::abilities();

		$this->assertNotEmpty( $abilities, 'No abilities found — the scanner broke, not the tree.' );

		$undeclared = array();
		foreach ( $abilities as $name => $meta ) {
			if ( null === $meta['readonly'] ) {
				$undeclared[] = "$name ({$meta['file']})";
			}
		}

		$this->assertSame(
			array(),
			$undeclared,
			"These abilities register without a meta.annotations.readonly declaration:\n  "
				. implode( "\n  ", $undeclared )
				. "\nThey will be treated as writes. Add the annotation — false for a writer, true for a"
				. ' reader that genuinely does not write.'
		);
	}

	/**
	 * The gate above is only as good as the scanner's ability to find an
	 * annotation that is there.
	 *
	 * It has been wrong in this direction before: the eight atomic convenience
	 * tools compose their name at run time and carry their annotations in a
	 * shared wrapper, and the scanner reported all eight as undeclared until it
	 * learned to read the wrapper. A scan that cannot see an annotation makes
	 * this file demand work that is already done, which is how a gate teaches
	 * people to route around it.
	 */
	public function test_the_scanner_can_read_annotations_from_a_shared_wrapper(): void {
		$abilities = KarMCP_Ability_Scan::abilities();

		$this->assertArrayHasKey( 'karmcp/add-atomic-heading', $abilities, 'The atomic convenience tools are gone or renamed; retarget this check.' );
		$this->assertFalse(
			$abilities['karmcp/add-atomic-heading']['readonly'],
			'add-atomic-heading is annotated in register_atomic_convenience(), not at its call site.'
				. ' Reading it as undeclared means the scanner stopped following the wrapper.'
		);
	}
}
