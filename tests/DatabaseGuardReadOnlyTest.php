<?php
/**
 * The read-only SQL gate for the `query` tool.
 *
 * The denylist has to separate two things that share a spelling: REPLACE the
 * write statement and REPLACE() the string function. Blocking the keyword
 * outright refused ordinary analysis queries, and the failure is invisible from
 * the outside — the agent just gets "unsafe keyword" for a SELECT.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-database-guard.php';

class DatabaseGuardReadOnlyTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** @param string $sql */
	private function allowed( string $sql ): bool {
		return true === KarMCP_Database_Guard::is_read_only_sql( $sql );
	}

	// -----------------------------------------------------------------
	// REPLACE: function vs statement
	// -----------------------------------------------------------------

	public function test_replace_function_is_allowed(): void {
		$this->assertTrue(
			$this->allowed( "SELECT REPLACE(post_title, 'foo', 'bar') FROM wp_posts" )
		);
	}

	public function test_replace_function_is_allowed_lowercase(): void {
		$this->assertTrue(
			$this->allowed( "select replace(post_name, '-', ' ') as t from wp_posts limit 10" )
		);
	}

	public function test_nested_replace_calls_are_allowed(): void {
		$this->assertTrue(
			$this->allowed( "SELECT REPLACE(REPLACE(guid, 'http://', ''), 'https://', '') FROM wp_posts" )
		);
	}

	public function test_replace_statement_is_still_blocked(): void {
		$this->assertFalse( $this->allowed( "REPLACE INTO wp_options VALUES (1, 'a', 'b', 'no')" ) );
	}

	/**
	 * `INTO` is optional in MySQL's REPLACE syntax, so the form without it must
	 * not slip past a check that only looks for "REPLACE INTO".
	 */
	public function test_replace_statement_without_into_is_blocked(): void {
		$this->assertFalse( $this->allowed( "REPLACE wp_options VALUES (1, 'a', 'b', 'no')" ) );
	}

	public function test_replace_with_a_space_before_the_table_is_blocked(): void {
		$this->assertFalse( $this->allowed( 'SELECT 1; REPLACE   wp_options VALUES (1)' ) );
	}

	// -----------------------------------------------------------------
	// The rest of the gate still holds
	// -----------------------------------------------------------------

	public function test_plain_select_is_allowed(): void {
		$this->assertTrue( $this->allowed( 'SELECT ID, post_title FROM wp_posts LIMIT 5' ) );
	}

	public function test_update_is_blocked(): void {
		$this->assertFalse( $this->allowed( "UPDATE wp_posts SET post_title = 'x'" ) );
	}

	public function test_delete_is_blocked(): void {
		$this->assertFalse( $this->allowed( 'DELETE FROM wp_posts' ) );
	}

	public function test_a_write_hidden_after_a_semicolon_is_blocked(): void {
		$this->assertFalse( $this->allowed( 'SELECT 1; DROP TABLE wp_posts' ) );
	}

	public function test_executable_comments_are_blocked(): void {
		$this->assertFalse( $this->allowed( '/*!50000 SELECT */ 1' ) );
	}

	public function test_file_access_is_blocked(): void {
		$this->assertFalse( $this->allowed( "SELECT LOAD_FILE('/etc/passwd')" ) );
	}

	/**
	 * The word appearing inside a string literal is not a keyword. normalize_sql()
	 * empties literals before the denylist runs, so this must survive.
	 */
	public function test_the_word_replace_inside_a_literal_is_allowed(): void {
		$this->assertTrue(
			$this->allowed( "SELECT ID FROM wp_posts WHERE post_title = 'replace me'" )
		);
	}
}
