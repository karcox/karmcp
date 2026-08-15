<?php
/**
 * The cleanup plan.
 *
 * The tasks themselves talk to a live database and the stub harness cannot
 * reach them, so what is pinned here is the contract the screen depends on:
 * every task declares what it breaks and whether it can be undone, and nothing
 * with a count of zero is offered as worth running.
 *
 * That contract is the safety. "Clean my database" is a thing people ask for
 * without knowing what is in it, and four of these six deletions are permanent.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/performance/class-db-cleaner.php';

class DbCleanerPlanTest extends TestCase {

	public function test_every_task_declares_what_it_breaks_and_whether_it_undoes(): void {
		foreach ( KarMCP_DB_Cleaner::tasks() as $id => $task ) {
			$this->assertNotSame( '', $task['label'], $id );
			$this->assertNotSame( '', $task['does'], $id );
			$this->assertNotSame( '', $task['breaks'], $id );
			$this->assertArrayHasKey( 'reversible', $task, $id );
			$this->assertIsBool( $task['reversible'], $id );
		}
	}

	/**
	 * The permanent ones have to stay marked permanent. If one of these ever
	 * flips to reversible the screen stops warning about it, and the warning is
	 * the only thing standing between a click and an emptied trash.
	 */
	public function test_the_destructive_tasks_are_marked_as_permanent(): void {
		$tasks = KarMCP_DB_Cleaner::tasks();
		foreach ( array( 'revisions', 'orphan_meta', 'spam_comments', 'trashed_posts' ) as $id ) {
			$this->assertFalse( $tasks[ $id ]['reversible'], $id . ' must be marked permanent.' );
		}
	}

	public function test_the_harmless_ones_are_not_marked_permanent(): void {
		$tasks = KarMCP_DB_Cleaner::tasks();
		$this->assertTrue( $tasks['transients']['reversible'] );
		$this->assertTrue( $tasks['optimize']['reversible'] );
	}

	public function test_a_task_with_nothing_to_do_is_not_worth_running(): void {
		$plan = KarMCP_DB_Cleaner::plan( array( 'revisions' => 0, 'transients' => 12 ) );
		$by   = array();
		foreach ( $plan as $t ) {
			$by[ $t['id'] ] = $t;
		}

		$this->assertFalse( $by['revisions']['worth_running'] );
		$this->assertTrue( $by['transients']['worth_running'] );
		$this->assertSame( 12, $by['transients']['count'] );
	}

	public function test_a_missing_count_reads_as_zero_rather_than_erroring(): void {
		$plan = KarMCP_DB_Cleaner::plan( array() );
		$this->assertCount( count( KarMCP_DB_Cleaner::tasks() ), $plan );
		foreach ( $plan as $t ) {
			$this->assertSame( 0, $t['count'] );
			$this->assertFalse( $t['worth_running'] );
		}
	}

	/** An unknown task id must be ignored, never dispatched. */
	public function test_run_ignores_task_ids_it_does_not_know(): void {
		$this->assertSame( array(), KarMCP_DB_Cleaner::run( array( 'drop_everything', '../../etc' ) ) );
	}

	public function test_deletions_are_batched(): void {
		// Unbounded, one click on a site with a hundred thousand revisions is a
		// request the host kills halfway through.
		$this->assertGreaterThan( 0, KarMCP_DB_Cleaner::BATCH );
		$this->assertLessThanOrEqual( 1000, KarMCP_DB_Cleaner::BATCH );
	}

	public function test_some_revisions_are_always_kept(): void {
		$this->assertGreaterThan( 0, KarMCP_DB_Cleaner::KEEP_REVISIONS );
	}
}
