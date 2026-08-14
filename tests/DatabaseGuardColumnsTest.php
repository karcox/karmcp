<?php
/**
 * Column-name validation for the structured database write tools.
 *
 * `$wpdb->insert()`, `->update()` and `->delete()` parameterize the VALUES but
 * interpolate COLUMN NAMES into the SQL between backticks without escaping a
 * backtick in the name. So an attacker-chosen key closes the identifier and
 * rewrites the statement — which is how a write to an unprotected table could
 * still read wp_users. These pin the check that closes it.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-database-guard.php';

class DatabaseGuardColumnsTest extends TestCase {

	/** A realistic wp_options column set. */
	private const KNOWN = array( 'option_id', 'option_name', 'option_value', 'autoload' );

	protected function setUp(): void {
		karmcp_test_reset();
	}

	public function test_real_columns_pass(): void {
		$this->assertSame(
			array(),
			KarMCP_Database_Guard::unknown_columns( array( 'option_name', 'option_value' ), self::KNOWN )
		);
	}

	public function test_column_names_are_case_insensitive(): void {
		// MySQL column names are case-insensitive; rejecting on case would break
		// ordinary use without adding any safety.
		$this->assertSame(
			array(),
			KarMCP_Database_Guard::unknown_columns( array( 'Option_Name', 'OPTION_VALUE' ), self::KNOWN )
		);
	}

	public function test_a_typo_is_reported(): void {
		$this->assertSame(
			array( 'option_nmae' ),
			KarMCP_Database_Guard::unknown_columns( array( 'option_nmae' ), self::KNOWN )
		);
	}

	/**
	 * The actual attack: a key carrying a backtick escapes the identifier and
	 * appends its own SQL. `$wpdb` would emit "`id` = 1 OR 1=1 #` = %s".
	 */
	public function test_backtick_injection_in_a_key_is_rejected(): void {
		$injected = 'option_id` = 1 OR 1=1 #';
		$this->assertSame(
			array( $injected ),
			KarMCP_Database_Guard::unknown_columns( array( $injected ), self::KNOWN )
		);
	}

	/** A subquery smuggled through a data key would exfiltrate password hashes. */
	public function test_subquery_injection_in_a_key_is_rejected(): void {
		$injected = 'option_value` = (SELECT user_pass FROM wp_users WHERE ID=1), `option_name';
		$this->assertSame(
			array( $injected ),
			KarMCP_Database_Guard::unknown_columns( array( $injected ), self::KNOWN )
		);
	}

	public function test_valid_and_injected_keys_together_still_reject(): void {
		$injected = 'x` = 1 OR 1=1 #';
		$this->assertSame(
			array( $injected ),
			KarMCP_Database_Guard::unknown_columns( array( 'option_name', $injected, 'autoload' ), self::KNOWN )
		);
	}

	/**
	 * A JSON array (rather than an object) arrives with integer keys, which can
	 * never be a column name — reported rather than silently coerced.
	 */
	public function test_non_string_keys_are_rejected(): void {
		$this->assertSame(
			array( '0', '1' ),
			KarMCP_Database_Guard::unknown_columns( array( 0, 1 ), self::KNOWN )
		);
	}

	public function test_empty_key_set_is_vacuously_fine(): void {
		// The executors reject an empty data/where set before reaching here.
		$this->assertSame( array(), KarMCP_Database_Guard::unknown_columns( array(), self::KNOWN ) );
	}

	/** With no known columns every key is unknown — validate_columns fails closed. */
	public function test_no_known_columns_rejects_everything(): void {
		$this->assertSame(
			array( 'option_name' ),
			KarMCP_Database_Guard::unknown_columns( array( 'option_name' ), array() )
		);
	}
}
