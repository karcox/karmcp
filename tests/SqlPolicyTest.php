<?php
/**
 * The token-based read-only SQL guard.
 *
 * The four bypasses in the first block are the reason this exists. Each one is a
 * place where the text normalizer that came before read a byte differently from
 * MySQL, so a query that looked harmless to the guard still reached the user
 * table. They are written here as the attacker would write them, and they assert
 * on the fact that matters — that the protected table is SEEN — rather than on
 * an error code, because the leak was always "the guard never noticed".
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sql/class-sql-lexer.php';
require_once __DIR__ . '/../includes/sql/class-sql-policy.php';

class SqlPolicyTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** @param string $sql */
	private function is_read( string $sql ): bool {
		return true === KarMCP_SQL_Policy::check( $sql, array( 'wp_users', 'wp_usermeta' ) );
	}

	/** @param string $sql */
	private function error_code( string $sql ): string {
		$res = KarMCP_SQL_Policy::check( $sql, array( 'wp_users', 'wp_usermeta' ) );
		return is_wp_error( $res ) ? $res->get_error_code() : '';
	}

	/** @param string $sql */
	private function sees_users( string $sql ): bool {
		return KarMCP_SQL_Policy::touches_tables( $sql, array( 'wp_users' ) );
	}

	// -----------------------------------------------------------------
	// The four readings that diverged from MySQL
	// -----------------------------------------------------------------

	/**
	 * `--` only opens a comment when whitespace follows it. `1--1` is arithmetic,
	 * so everything after it still executes — while a scanner that treated it as
	 * a comment stopped looking right there.
	 */
	public function test_a_dash_dash_without_a_space_does_not_hide_the_tail(): void {
		$this->assertTrue( $this->sees_users( 'SELECT 1--1 UNION SELECT user_pass FROM wp_users' ) );
	}

	/**
	 * Under NO_BACKSLASH_ESCAPES a backslash is an ordinary byte, so `'\'` is a
	 * COMPLETE string and the text after it is code. Assuming escapes are on
	 * reads that same text as string content and never sees the subquery.
	 */
	public function test_a_backslash_is_not_an_escape_under_every_session_mode(): void {
		$sql = "SELECT '\\' AS x, (SELECT user_pass FROM wp_users) AS y -- '";
		$this->assertTrue( $this->sees_users( $sql ) );
	}

	/**
	 * A quoted identifier may contain a quote character. Stripping the backticks
	 * before scanning turned that quote into the start of a string literal and
	 * desynchronized everything after it.
	 */
	public function test_a_quote_inside_a_backticked_identifier_does_not_desync(): void {
		$this->assertTrue( $this->sees_users( "SELECT `it's`, user_pass FROM wp_users" ) );
	}

	/**
	 * Under ANSI_QUOTES a double-quoted run is an IDENTIFIER, not a string, so a
	 * table named this way is a real reference.
	 */
	public function test_a_double_quoted_table_is_seen_under_ansi_quotes(): void {
		$this->assertTrue( $this->sees_users( 'SELECT * FROM "wp_users"' ) );
	}

	// -----------------------------------------------------------------
	// Keywords that are also read-only functions
	// -----------------------------------------------------------------

	/**
	 * REPLACE, INSERT and TRUNCATE each name both a write statement and an
	 * ordinary function. Denylisting the bare word refuses legitimate analysis
	 * queries and tells the agent its SELECT is unsafe, which is a lie it cannot
	 * act on.
	 */
	public function test_replace_the_function_is_allowed(): void {
		$this->assertTrue( $this->is_read( "SELECT REPLACE(post_title, 'foo', 'bar') FROM wp_posts" ) );
	}

	public function test_insert_the_function_is_allowed(): void {
		$this->assertTrue( $this->is_read( "SELECT INSERT(post_title, 1, 4, 'x') FROM wp_posts" ) );
	}

	public function test_truncate_the_function_is_allowed(): void {
		$this->assertTrue( $this->is_read( 'SELECT TRUNCATE(1.234, 1)' ) );
	}

	public function test_nested_replace_calls_are_allowed(): void {
		$this->assertTrue(
			$this->is_read( "SELECT REPLACE(REPLACE(guid, 'http://', ''), 'https://', '') FROM wp_posts" )
		);
	}

	/**
	 * The exemption needs the parenthesis to be ADJACENT, which is what MySQL
	 * itself requires of a built-in function name outside IGNORE_SPACE. Blocking
	 * is the safe direction, so the separated form is refused rather than guessed
	 * at.
	 */
	public function test_a_gap_before_the_parenthesis_is_not_a_function_call(): void {
		$this->assertSame( 'not_read_only', $this->error_code( "SELECT REPLACE (a, 'b', 'c') FROM wp_posts" ) );
	}

	public function test_replace_the_statement_is_still_blocked(): void {
		$this->assertSame( 'not_read_only', $this->error_code( "REPLACE INTO wp_options VALUES (1, 'a')" ) );
	}

	public function test_replace_the_statement_without_into_is_blocked(): void {
		$this->assertSame( 'not_read_only', $this->error_code( "REPLACE wp_options VALUES (1, 'a')" ) );
	}

	// -----------------------------------------------------------------
	// Statement shape
	// -----------------------------------------------------------------

	public function test_a_plain_select_is_allowed(): void {
		$this->assertTrue( $this->is_read( 'SELECT ID, post_title FROM wp_posts LIMIT 5' ) );
	}

	public function test_a_trailing_semicolon_is_allowed(): void {
		$this->assertTrue( $this->is_read( 'SELECT 1;' ) );
	}

	public function test_a_second_statement_is_blocked(): void {
		$this->assertSame( 'multi_statement', $this->error_code( 'SELECT 1; DROP TABLE wp_posts' ) );
	}

	public function test_a_write_is_blocked(): void {
		$this->assertSame( 'not_read_only', $this->error_code( "UPDATE wp_posts SET post_title = 'x'" ) );
	}

	public function test_a_parenthesized_union_branch_is_allowed(): void {
		$this->assertTrue( $this->is_read( '(SELECT ID FROM wp_posts) UNION (SELECT ID FROM wp_terms)' ) );
	}

	/**
	 * A word inside a literal is a value, not a keyword.
	 */
	public function test_a_keyword_inside_a_literal_is_just_text(): void {
		$this->assertTrue( $this->is_read( "SELECT ID FROM wp_posts WHERE post_title = 'drop table'" ) );
	}

	// -----------------------------------------------------------------
	// Side effects and resources
	// -----------------------------------------------------------------

	public function test_variable_assignment_is_blocked(): void {
		$this->assertSame( 'not_read_only', $this->error_code( 'SELECT @x := 1' ) );
	}

	public function test_select_into_a_variable_is_blocked(): void {
		$this->assertSame( 'not_read_only', $this->error_code( 'SELECT 1 INTO @x' ) );
	}

	public function test_sleep_is_blocked(): void {
		$this->assertSame( 'unsafe_function_blocked', $this->error_code( 'SELECT SLEEP(5)' ) );
	}

	public function test_benchmark_is_blocked(): void {
		$this->assertSame( 'unsafe_function_blocked', $this->error_code( "SELECT BENCHMARK(1000000, MD5('x'))" ) );
	}

	/**
	 * Only the CALL is blocked, so an ordinary column keeps working.
	 */
	public function test_a_column_named_sleep_is_allowed(): void {
		$this->assertTrue( $this->is_read( 'SELECT sleep FROM wp_naps' ) );
	}

	public function test_file_reads_are_blocked(): void {
		$this->assertSame( 'file_access_blocked', $this->error_code( "SELECT LOAD_FILE('/etc/passwd')" ) );
	}

	/**
	 * Two rules cover this one and INTO is reached first, so the assertion is on
	 * the refusal rather than on which rule got there — pinning the code would
	 * make the test fail the day the other rule wins, without anything being
	 * wrong.
	 */
	public function test_file_writes_are_blocked(): void {
		$this->assertNotSame( '', $this->error_code( "SELECT * FROM wp_posts INTO OUTFILE '/tmp/x'" ) );
	}

	/**
	 * OUTFILE on its own, with no INTO in front of it, still lands on the file
	 * rule.
	 */
	public function test_the_file_rule_catches_outfile_by_itself(): void {
		$this->assertSame( 'file_access_blocked', $this->error_code( "SELECT * FROM wp_posts OUTFILE '/tmp/x'" ) );
	}

	// -----------------------------------------------------------------
	// Protected data
	// -----------------------------------------------------------------

	public function test_the_user_table_is_blocked(): void {
		$this->assertSame( 'protected_read', $this->error_code( 'SELECT user_pass FROM wp_users' ) );
	}

	/**
	 * Whole identifiers are compared, so a differently named table is a different
	 * table rather than a substring hit.
	 */
	public function test_a_table_whose_name_merely_starts_the_same_is_allowed(): void {
		$this->assertTrue( $this->is_read( 'SELECT * FROM wp_users_backup' ) );
	}

	public function test_the_table_name_inside_a_literal_is_not_a_reference(): void {
		$this->assertTrue( $this->is_read( "SELECT ID FROM wp_posts WHERE post_title = 'wp_users'" ) );
	}

	public function test_the_server_account_schema_is_blocked(): void {
		$this->assertSame( 'protected_read', $this->error_code( 'SELECT user, host FROM mysql.user' ) );
	}

	public function test_information_schema_is_blocked(): void {
		$this->assertSame( 'protected_read', $this->error_code( 'SELECT table_name FROM information_schema.tables' ) );
	}

	public function test_a_backticked_system_schema_is_blocked(): void {
		$this->assertSame( 'protected_read', $this->error_code( 'SELECT * FROM `mysql`.`user`' ) );
	}

	// -----------------------------------------------------------------
	// Anything unreadable is refused, not guessed at
	// -----------------------------------------------------------------

	public function test_executable_comments_are_blocked(): void {
		$this->assertSame( 'executable_comment', $this->error_code( '/*!50000 SELECT */ 1' ) );
	}

	public function test_optimizer_hints_are_blocked(): void {
		$this->assertSame( 'executable_comment', $this->error_code( 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1' ) );
	}

	public function test_an_unterminated_comment_is_refused(): void {
		$this->assertSame( 'unterminated_comment', $this->error_code( 'SELECT 1 /* oops' ) );
	}

	public function test_a_byte_the_lexer_cannot_classify_is_refused(): void {
		$this->assertSame( 'sql_unparsable', $this->error_code( 'SELECT \\ 1' ) );
	}

	public function test_invalid_utf8_is_refused(): void {
		$this->assertSame( 'sql_not_utf8', $this->error_code( "SELECT '" . chr( 0xFF ) . "'" ) );
	}

	/**
	 * An unparsable statement is treated as touching everything, because the
	 * alternative is to let something through on the strength of a reading we
	 * just admitted we could not complete.
	 */
	public function test_an_unreadable_statement_counts_as_touching_the_user_table(): void {
		$this->assertTrue( $this->sees_users( 'SELECT \\ 1' ) );
	}

	public function test_an_empty_statement_touches_nothing(): void {
		$this->assertFalse( $this->sees_users( '   ' ) );
	}

	// -----------------------------------------------------------------
	// The row bound
	// -----------------------------------------------------------------

	public function test_a_trailing_limit_is_read(): void {
		$this->assertSame( 10, KarMCP_SQL_Policy::analyze( 'SELECT * FROM wp_posts LIMIT 10' )['limit'] );
	}

	/**
	 * `LIMIT skip, count` and `LIMIT count OFFSET skip` put the row count in
	 * different slots, so the larger number is the only safe reading of either.
	 */
	public function test_the_two_argument_limit_forms_report_the_row_count(): void {
		$this->assertSame( 10, KarMCP_SQL_Policy::analyze( 'SELECT * FROM wp_posts LIMIT 5, 10' )['limit'] );
		$this->assertSame( 10, KarMCP_SQL_Policy::analyze( 'SELECT * FROM wp_posts LIMIT 10 OFFSET 5' )['limit'] );
	}

	/**
	 * A LIMIT inside a subquery bounds the subquery, not the result the caller
	 * gets back, so it must not count as a bound.
	 */
	public function test_a_limit_inside_a_subquery_is_not_a_bound(): void {
		$sql = 'SELECT * FROM wp_posts WHERE ID IN (SELECT post_id FROM wp_postmeta LIMIT 5)';
		$this->assertNull( KarMCP_SQL_Policy::analyze( $sql )['limit'] );
	}
}
