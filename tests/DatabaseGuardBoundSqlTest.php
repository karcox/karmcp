<?php
/**
 * Bounding a read in the DATABASE rather than in the response.
 *
 * Slicing the rows after the fetch capped what the agent SAW, not what the
 * server did: the query still materialized every row and PHP still held them,
 * so a wide query was an out-of-memory fatal instead of a capped answer.
 *
 * The other half of the design is that an oversized caller LIMIT is REFUSED
 * rather than rewritten. Editing raw SQL around literals is the exact class of
 * trick the guard exists to stop, so the guard does not do it either.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sql/class-sql-lexer.php';
require_once __DIR__ . '/../includes/sql/class-sql-policy.php';
require_once __DIR__ . '/../includes/class-database-guard.php';

class DatabaseGuardBoundSqlTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
		// The gate asks $wpdb for the real names of the protected tables, so the
		// protected-table half of it is untestable without one. Without this the
		// check reads a property on null and silently passes everything.
		$wpdb           = new stdClass();
		$wpdb->users    = 'wp_users';
		$wpdb->usermeta = 'wp_usermeta';
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_a_missing_limit_is_added(): void {
		$out = KarMCP_Database_Guard::bound_sql( 'SELECT * FROM wp_posts', 100 );
		$this->assertStringEndsWith( 'LIMIT 100', (string) $out );
	}

	/**
	 * The clause goes on its own line because a trailing line comment would
	 * otherwise swallow it, and the query would come back unbounded.
	 */
	public function test_the_bound_survives_a_trailing_line_comment(): void {
		$out = KarMCP_Database_Guard::bound_sql( 'SELECT * FROM wp_posts -- everything', 100 );
		$this->assertStringEndsWith( "\n LIMIT 100", (string) $out );
		$this->assertSame( 100, KarMCP_SQL_Policy::analyze( (string) $out )['limit'] );
	}

	public function test_a_limit_within_the_cap_is_left_alone(): void {
		$out = KarMCP_Database_Guard::bound_sql( 'SELECT * FROM wp_posts LIMIT 5', 100 );
		$this->assertSame( 'SELECT * FROM wp_posts LIMIT 5', $out );
	}

	public function test_an_oversized_limit_is_refused_not_rewritten(): void {
		$out = KarMCP_Database_Guard::bound_sql( 'SELECT * FROM wp_posts LIMIT 5000', 100 );
		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'limit_too_large', $out->get_error_code() );
	}

	/**
	 * A LIMIT inside a subquery bounds the subquery. Reading it as the outer
	 * bound would leave the statement unbounded.
	 */
	public function test_a_subquery_limit_does_not_count_as_the_bound(): void {
		$sql = 'SELECT * FROM wp_posts WHERE ID IN (SELECT post_id FROM wp_postmeta LIMIT 5)';
		$out = KarMCP_Database_Guard::bound_sql( $sql, 100 );
		$this->assertStringEndsWith( 'LIMIT 100', (string) $out );
	}

	/**
	 * SHOW, DESCRIBE and EXPLAIN return their own small result and take no LIMIT
	 * clause at all, so appending one would turn a working call into a syntax
	 * error.
	 */
	public function test_show_is_left_alone(): void {
		$this->assertSame( 'SHOW TABLES', KarMCP_Database_Guard::bound_sql( 'SHOW TABLES', 100 ) );
	}

	public function test_describe_is_left_alone(): void {
		$this->assertSame( 'DESCRIBE wp_posts', KarMCP_Database_Guard::bound_sql( 'DESCRIBE wp_posts', 100 ) );
	}

	public function test_an_unreadable_statement_is_refused(): void {
		$this->assertTrue( is_wp_error( KarMCP_Database_Guard::bound_sql( 'SELECT \\ 1', 100 ) ) );
	}

	// -----------------------------------------------------------------
	// The single gate
	// -----------------------------------------------------------------

	/**
	 * check_read_query() exists so a raw read path cannot remember two of the
	 * three checks. Each of the three has to fail through it.
	 */
	public function test_the_gate_refuses_a_write(): void {
		$res = KarMCP_Database_Guard::check_read_query( 'DELETE FROM wp_posts' );
		$this->assertSame( 'not_read_only', $res->get_error_code() );
	}

	public function test_the_gate_refuses_a_system_schema(): void {
		$res = KarMCP_Database_Guard::check_read_query( 'SELECT * FROM mysql.user' );
		$this->assertSame( 'protected_read', $res->get_error_code() );
	}

	public function test_the_gate_refuses_the_user_table(): void {
		$res = KarMCP_Database_Guard::check_read_query( 'SELECT user_pass FROM wp_users' );
		$this->assertSame( 'protected_read', $res->get_error_code() );
	}

	public function test_the_gate_allows_an_ordinary_read(): void {
		$this->assertTrue( KarMCP_Database_Guard::check_read_query( 'SELECT ID FROM wp_posts LIMIT 5' ) );
	}
}
