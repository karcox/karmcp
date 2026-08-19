<?php
/**
 * Unit tests for KarMCP_Schema_State — the installed-schema map that replaced
 * four per-store `karmcp_*_db_version` options written with autoload OFF.
 *
 * Two properties matter and neither has a visible symptom when it breaks:
 * the map must be written AUTOLOADED (otherwise every request pays a query to
 * ask a question whose answer only changes on upgrade), and a key that was
 * never written must read as 0 (otherwise a store whose module is switched on
 * later never installs its table).
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-schema-state.php';

class SchemaStateTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** @var string */
	private $state = 'KarMCP_Schema_State';

	public function test_unknown_key_reads_as_zero() {
		$s = $this->state;
		$this->assertSame( 0, $s::installed( $s::KEY_SEARCH ) );
		$this->assertSame( 0, $s::installed( 'never-heard-of-it' ) );
		$this->assertSame( array(), $s::all() );
	}

	public function test_mark_then_installed_round_trips() {
		$s = $this->state;
		$s::mark( $s::KEY_SEARCH, 1 );
		$s::mark( $s::KEY_OAUTH, 2 );

		$this->assertSame( 1, $s::installed( $s::KEY_SEARCH ) );
		$this->assertSame( 2, $s::installed( $s::KEY_OAUTH ) );
		// Marking one store must not disturb the others.
		$this->assertSame( 0, $s::installed( $s::KEY_REDIRECTS ) );
	}

	/**
	 * The whole point of the class. A map written with autoload off would still
	 * pass every other test here and still cost a query on every request.
	 */
	public function test_map_is_written_autoloaded() {
		$s = $this->state;
		$s::mark( $s::KEY_CHANGELOG, 1 );

		$this->assertTrue(
			$GLOBALS['karmcp_test']['option_autoload'][ $s::OPTION ],
			'The schema map must be autoloaded — it is read on init of every request.'
		);
	}

	public function test_forget_drops_only_its_own_key() {
		$s = $this->state;
		$s::mark( $s::KEY_SEARCH, 1 );
		$s::mark( $s::KEY_OAUTH, 2 );

		$s::forget( $s::KEY_SEARCH );

		$this->assertSame( 0, $s::installed( $s::KEY_SEARCH ) );
		$this->assertSame( 2, $s::installed( $s::KEY_OAUTH ) );
	}

	public function test_forget_on_absent_key_is_a_no_op() {
		$s = $this->state;
		$s::mark( $s::KEY_OAUTH, 2 );
		$s::forget( $s::KEY_REDIRECTS );

		$this->assertSame( array( $s::KEY_OAUTH => 2 ), $s::all() );
	}

	public function test_corrupt_option_reads_as_empty_not_fatal() {
		$s = $this->state;
		// A scalar where the map should be (hand-edited option, failed migration).
		$GLOBALS['karmcp_test']['options'][ $s::OPTION ] = 'nonsense';

		$this->assertSame( array(), $s::all() );
		$this->assertSame( 0, $s::installed( $s::KEY_SEARCH ) );
	}

	public function test_versions_are_cast_to_int() {
		$s = $this->state;
		// Options round-trip through the database as strings.
		$GLOBALS['karmcp_test']['options'][ $s::OPTION ] = array( $s::KEY_SEARCH => '3' );

		$this->assertSame( 3, $s::installed( $s::KEY_SEARCH ) );
	}

	/** Every store key must be distinct, or two tables share one version slot. */
	public function test_store_keys_are_unique() {
		$s    = $this->state;
		$keys = array( $s::KEY_SEARCH, $s::KEY_CHANGELOG, $s::KEY_OAUTH, $s::KEY_REDIRECTS );

		$this->assertSame( $keys, array_values( array_unique( $keys ) ) );
	}
}
