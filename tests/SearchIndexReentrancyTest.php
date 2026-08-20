<?php
/**
 * The save_post re-entrancy guard on the search indexer.
 *
 * Indexing a page reads the document, and on a document Elementor has not
 * converted yet that read makes Elementor convert and save the post — firing
 * save_post from inside the indexer. Without a guard the handler re-enters
 * itself and recurses until PHP exhausts memory (upstream #119: 156,054 stack
 * frames, 512 MB, a 22 MB error log from two requests).
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-schema-state.php';
require_once __DIR__ . '/../includes/class-search-index.php';

class SearchIndexReentrancyTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
		$this->set_reindexing( false );
	}

	protected function tearDown(): void {
		$this->set_reindexing( false );
	}

	/**
	 * Read the private guard.
	 */
	private function reindexing(): bool {
		$prop = new ReflectionProperty( 'KarMCP_Search_Index', 'reindexing' );
		return (bool) $prop->getValue();
	}

	/**
	 * Write the private guard, standing in for "we are already inside indexing".
	 *
	 * @param bool $value Guard value.
	 */
	private function set_reindexing( bool $value ): void {
		$prop = new ReflectionProperty( 'KarMCP_Search_Index', 'reindexing' );
		$prop->setValue( null, $value );
	}

	/**
	 * Mark the index table installed so on_save_post() gets past its schema check.
	 */
	private function install_schema(): void {
		$GLOBALS['karmcp_test']['options'][ KarMCP_Schema_State::OPTION ] = array(
			KarMCP_Schema_State::KEY_SEARCH => KarMCP_Search_Index::DB_VERSION,
		);
	}

	/**
	 * The nested call is the one that used to recurse. It must return before the
	 * try/finally that owns the guard — if it ran the body, the finally would
	 * release the guard and the outer call would then index on a half-saved post.
	 */
	public function test_a_nested_call_returns_without_running_the_body(): void {
		$this->install_schema();
		$this->set_reindexing( true );

		KarMCP_Search_Index::on_save_post( 41, (object) array( 'post_type' => 'page' ) );

		$this->assertTrue( $this->reindexing(), 'The nested call ran the body and released the outer call guard.' );
	}

	/**
	 * A normal save leaves the guard down, so the next save still indexes.
	 */
	public function test_the_guard_is_released_after_an_ordinary_save(): void {
		$this->install_schema();

		KarMCP_Search_Index::on_save_post( 41, (object) array( 'post_type' => 'attachment' ) );

		$this->assertFalse( $this->reindexing() );
	}

	/**
	 * The early returns above the try/finally must not leave the guard raised.
	 */
	public function test_an_early_return_leaves_the_guard_down(): void {
		// Schema not installed: on_save_post() bails before the guard is raised.
		KarMCP_Search_Index::on_save_post( 41, (object) array( 'post_type' => 'page' ) );

		$this->assertFalse( $this->reindexing() );
	}

	/**
	 * A revision save is skipped, and skipping must not strand the guard either.
	 */
	public function test_a_revision_leaves_the_guard_down(): void {
		$this->install_schema();
		$GLOBALS['karmcp_test']['revisions'] = array( 41 );

		KarMCP_Search_Index::on_save_post( 41, (object) array( 'post_type' => 'page' ) );

		$this->assertFalse( $this->reindexing() );
	}
}
