<?php
/**
 * Pause records reconciled against what is actually active.
 *
 * The drop-in pauses a plugin by taking it out of `active_plugins` and writing
 * a row in `karmcp_fatal_paused`. Only `resume-plugin` cleared that row, so
 * reactivating the plugin any other way left the record behind and two of our
 * own tools disagreeing: `list-plugins` said active, `list-paused-plugins` said
 * paused. Nothing breaks, which is why it survived — the only symptom is an
 * agent being told something untrue about the site.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security/class-fatal-handler-template.php';

class PausedPluginReconcileTest extends TestCase {

	private const VICTIM = 'fxc-authoring/fxc-authoring.php';
	private const OTHER  = 'some-plugin/some-plugin.php';

	protected function setUp(): void {
		$GLOBALS['karmcp_test']['options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['karmcp_test']['options'] = array();
	}

	/**
	 * Puts a site state in place.
	 *
	 * @param string[]             $active Active plugin files.
	 * @param array<string,array>  $paused Pause records.
	 */
	private function site( array $active, array $paused ): void {
		$GLOBALS['karmcp_test']['options']['active_plugins'] = $active;
		$GLOBALS['karmcp_test']['options'][ KarMCP_Fatal_Handler_Template::OPTION_PAUSED ] = $paused;
	}

	/** A pause record, shaped the way the drop-in writes it. */
	private function record( int $at = 1787313958 ): array {
		return array( 'at' => $at, 'hits' => 3, 'window' => 600 );
	}

	/**
	 * The case that was actually observed: paused at 14:05, reactivated by hand
	 * an hour later, still reported as paused.
	 */
	public function test_a_reactivated_plugin_is_no_longer_reported_as_paused(): void {
		$this->site(
			array( 'karmcp/karmcp.php', self::VICTIM ),
			array( self::VICTIM => $this->record() )
		);

		$this->assertSame( array(), KarMCP_Fatal_Handler_Template::paused() );
	}

	/**
	 * And the pause that IS real still shows, or the tool would be useless.
	 */
	public function test_a_plugin_that_is_still_deactivated_stays_paused(): void {
		$this->site(
			array( 'karmcp/karmcp.php' ),
			array( self::VICTIM => $this->record() )
		);

		$paused = KarMCP_Fatal_Handler_Template::paused();
		$this->assertArrayHasKey( self::VICTIM, $paused );
		$this->assertSame( 3, $paused[ self::VICTIM ]['hits'] );
	}

	/**
	 * A mixed map keeps only the half that is true.
	 */
	public function test_only_the_stale_entries_are_dropped(): void {
		$this->site(
			array( 'karmcp/karmcp.php', self::VICTIM ),
			array(
				self::VICTIM => $this->record(),
				self::OTHER  => $this->record( 1787200000 ),
			)
		);

		$this->assertSame( array( self::OTHER ), array_keys( KarMCP_Fatal_Handler_Template::paused() ) );
	}

	/**
	 * Reading must not write. `list-paused-plugins` is annotated readonly, and a
	 * reader that quietly rewrote an option would make that annotation false —
	 * which matters, because Guardrails and dispatcher mode both act on it.
	 */
	public function test_reading_does_not_write_the_option(): void {
		$this->site(
			array( self::VICTIM ),
			array( self::VICTIM => $this->record() )
		);

		KarMCP_Fatal_Handler_Template::paused();

		$this->assertSame(
			array( self::VICTIM => $this->record() ),
			$GLOBALS['karmcp_test']['options'][ KarMCP_Fatal_Handler_Template::OPTION_PAUSED ],
			'paused() must leave the stored option untouched'
		);
	}

	/**
	 * forget() is the write half, hooked to `activated_plugin`, and it is what
	 * actually removes the row.
	 */
	public function test_forget_drops_the_record_and_keeps_the_rest(): void {
		$this->site(
			array( self::VICTIM ),
			array(
				self::VICTIM => $this->record(),
				self::OTHER  => $this->record( 1787200000 ),
			)
		);

		KarMCP_Fatal_Handler_Template::forget( self::VICTIM );

		$stored = $GLOBALS['karmcp_test']['options'][ KarMCP_Fatal_Handler_Template::OPTION_PAUSED ];
		$this->assertSame( array( self::OTHER ), array_keys( $stored ) );
	}

	/**
	 * And it stays off the autoloaded set: this option is read only by the
	 * recovery screen and its two tools, never on a page view.
	 */
	public function test_forget_writes_the_option_without_autoload(): void {
		$this->site( array( self::VICTIM ), array( self::VICTIM => $this->record() ) );

		KarMCP_Fatal_Handler_Template::forget( self::VICTIM );

		$this->assertFalse(
			$GLOBALS['karmcp_test']['option_autoload'][ KarMCP_Fatal_Handler_Template::OPTION_PAUSED ]
		);
	}

	/**
	 * Activating something that was never paused must not rewrite anything —
	 * `activated_plugin` fires for every activation on the site.
	 */
	public function test_forget_is_a_no_op_for_a_plugin_that_was_not_paused(): void {
		$this->site( array( self::OTHER ), array() );
		unset( $GLOBALS['karmcp_test']['option_autoload'] );

		KarMCP_Fatal_Handler_Template::forget( self::OTHER );

		$this->assertArrayNotHasKey(
			KarMCP_Fatal_Handler_Template::OPTION_PAUSED,
			$GLOBALS['karmcp_test']['option_autoload'] ?? array()
		);
	}

	/**
	 * A malformed option must not take the recovery screen down with it — this
	 * is read on a site that has already proved it can break.
	 */
	public function test_a_corrupt_option_reads_as_nothing_paused(): void {
		$GLOBALS['karmcp_test']['options']['active_plugins'] = array( 'karmcp/karmcp.php' );
		$GLOBALS['karmcp_test']['options'][ KarMCP_Fatal_Handler_Template::OPTION_PAUSED ] = 'not-an-array';

		$this->assertSame( array(), KarMCP_Fatal_Handler_Template::paused() );
	}

	/**
	 * A corrupt active_plugins must not resurrect every pause either.
	 */
	public function test_a_corrupt_active_plugins_still_reports_real_pauses(): void {
		$GLOBALS['karmcp_test']['options']['active_plugins'] = 'not-an-array';
		$GLOBALS['karmcp_test']['options'][ KarMCP_Fatal_Handler_Template::OPTION_PAUSED ] = array(
			self::VICTIM => $this->record(),
		);

		$this->assertArrayHasKey( self::VICTIM, KarMCP_Fatal_Handler_Template::paused() );
	}
}
