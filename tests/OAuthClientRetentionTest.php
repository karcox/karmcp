<?php
/**
 * Which registered clients the housekeeping sweep is allowed to delete.
 *
 * Orphan pruning used to key on "this client has no tokens right now", which is
 * not the same thing as "this registration was abandoned". A connected app
 * whose refresh token lapsed after 30 days idle also has zero tokens, and
 * deleting its row turned a recoverable "sign in again" into a permanent
 * "Invalid client": the app still had the client_id cached, so it reopened the
 * authorize page on a loop and could never reconnect. A client that completed
 * an authorization is stamped and kept.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-schema-state.php';
require_once __DIR__ . '/../includes/oauth/class-oauth-store.php';

/**
 * Recording $wpdb double: keeps the SQL each call was built from, so the tests
 * can assert on the statement rather than on a database.
 */
class KarMCP_Test_Recording_Wpdb {

	/** @var string[] */
	public $queries = array();

	/** @var string */
	public $prefix = 'wp_';

	/** @var int */
	public $insert_id = 0;

	/** @var string */
	public $last_error = '';

	public function prepare( $sql, ...$args ) {
		// Enough substitution for the assertions: identifiers and scalars alike
		// collapse to their literal, keeping the shape of the statement intact.
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : (string) $arg;
			$sql         = preg_replace( '/%[ids]/', $replacement, (string) $sql, 1 );
		}
		return (string) $sql;
	}

	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		return 1;
	}

	public function insert( $table, $data, $formats ) {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->insert_id = 7;
		return 1;
	}

	public function get_charset_collate() {
		return '';
	}

	/**
	 * Every statement recorded, as one blob, for substring assertions.
	 */
	public function sql(): string {
		return implode( "\n", $this->queries );
	}
}

class OAuthClientRetentionTest extends TestCase {

	/** @var KarMCP_Test_Recording_Wpdb */
	private $wpdb;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->wpdb    = new KarMCP_Test_Recording_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * The regression itself: the sweep must never touch a client that signed in.
	 */
	public function test_the_sweep_only_deletes_clients_that_never_authorized(): void {
		KarMCP_OAuth_Store::gc();

		$sql = $this->wpdb->sql();
		$this->assertStringContainsString( 'DELETE c FROM', $sql );
		$this->assertStringContainsString( 'c.authorized_at = 0', $sql );
	}

	/**
	 * Issuing a token is the proof that an authorization completed.
	 */
	public function test_issuing_a_token_stamps_the_client(): void {
		KarMCP_OAuth_Store::issue_token( 'access', 'client-abc', 1, 'mcp', 3600 );

		$sql = $this->wpdb->sql();
		$this->assertStringContainsString( 'SET authorized_at =', $sql );
		$this->assertStringContainsString( 'client-abc', $sql );
	}

	/**
	 * Idempotent: the stamp is only written the first time.
	 */
	public function test_the_stamp_is_written_once(): void {
		KarMCP_OAuth_Store::mark_client_authorized( 'client-abc' );

		$this->assertStringContainsString( 'AND authorized_at = 0', $this->wpdb->sql() );
	}

	public function test_an_empty_client_id_writes_nothing(): void {
		KarMCP_OAuth_Store::mark_client_authorized( '' );

		$this->assertSame( array(), $this->wpdb->queries );
	}

	/**
	 * The upgrade backfill: an existing connection must be stamped before the
	 * first sweep after the update, or it is purged exactly once.
	 */
	public function test_the_backfill_stamps_clients_that_hold_a_token(): void {
		KarMCP_OAuth_Store::backfill_authorized_clients();

		$sql = $this->wpdb->sql();
		$this->assertStringContainsString( 'UPDATE', $sql );
		$this->assertStringContainsString( 'c.authorized_at = 0', $sql );
		$this->assertStringContainsString( 'EXISTS', $sql );
	}

	/**
	 * The column has to exist for any of the above to mean anything, and the
	 * schema version has to move or no site ever adds it.
	 */
	public function test_the_schema_carries_the_column(): void {
		$this->assertGreaterThanOrEqual( 3, KarMCP_OAuth_Store::DB_VERSION );
	}
}
