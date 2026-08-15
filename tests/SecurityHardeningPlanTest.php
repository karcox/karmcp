<?php
/**
 * The hardening plan, and the freshness of a report.
 *
 * Two pure decisions, and the second is the one with teeth.
 *
 * The plan has to sort findings into "I can fix this", "already fixed" and
 * "a person decides this", and it must never silently drop the third pile —
 * an unfixable finding that vanishes from the output is the same lie as
 * calling it fixed.
 *
 * Freshness is the rule the whole Security tab rests on: a scan that never ran,
 * or failed, or is old, must not be able to render as "you are clean". That is
 * why it answers with four states instead of a boolean, and why every one of
 * them is pinned here.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security/class-security-hardening-fixer.php';
require_once __DIR__ . '/../includes/security/class-security-monitor.php';

class SecurityHardeningPlanTest extends TestCase {

	/** Shapes a finding the way KarMCP_Security_Finding::make() does. */
	private function finding( string $id, string $status ): array {
		return array(
			'id'       => $id,
			'category' => 'hardening',
			'label'    => $id,
			'status'   => $status,
			'message'  => '',
		);
	}

	// -----------------------------------------------------------------
	// plan()
	// -----------------------------------------------------------------

	public function test_a_warning_with_a_known_fix_is_offered(): void {
		$plan = KarMCP_Security_Hardening_Fixer::plan(
			array( $this->finding( 'harden_xmlrpc', 'warning' ) ),
			array()
		);

		$this->assertCount( 1, $plan['fixable'] );
		$this->assertSame( 'disable_xmlrpc', $plan['fixable'][0]['id'] );
		// The side effect is part of the plan, not a footnote: this one breaks
		// Jetpack and the mobile app.
		$this->assertNotSame( '', $plan['fixable'][0]['breaks'] );
	}

	public function test_a_fix_already_switched_on_is_not_offered_again(): void {
		$plan = KarMCP_Security_Hardening_Fixer::plan(
			array( $this->finding( 'harden_xmlrpc', 'warning' ) ),
			array( 'disable_xmlrpc' )
		);

		$this->assertSame( array(), $plan['fixable'] );
		$this->assertCount( 1, $plan['already_applied'] );
	}

	public function test_passing_findings_are_not_offered_as_fixes(): void {
		$plan = KarMCP_Security_Hardening_Fixer::plan(
			array( $this->finding( 'harden_xmlrpc', 'pass' ) ),
			array()
		);

		$this->assertSame( array(), $plan['fixable'] );
		$this->assertSame( array( 'harden_xmlrpc' ), $plan['passing'] );
	}

	/**
	 * The three that a machine must not decide have to appear in the output with
	 * a reason. Dropping them would turn "I will not touch this" into "there is
	 * nothing here", which is the failure mode this whole tab exists to avoid.
	 */
	public function test_the_unfixable_findings_are_reported_with_a_reason(): void {
		$plan = KarMCP_Security_Hardening_Fixer::plan(
			array(
				$this->finding( 'harden_admin_user', 'warning' ),
				$this->finding( 'harden_https', 'warning' ),
				$this->finding( 'harden_debug_display', 'warning' ),
			),
			array()
		);

		$this->assertSame( array(), $plan['fixable'] );
		$this->assertCount( 3, $plan['manual'] );
		foreach ( $plan['manual'] as $m ) {
			$this->assertNotSame( '', $m['why'], $m['finding'] );
		}
	}

	public function test_findings_from_other_categories_are_ignored(): void {
		$plan = KarMCP_Security_Hardening_Fixer::plan(
			array(
				array( 'id' => 'integrity_modified', 'category' => 'integrity', 'status' => 'critical' ),
				array( 'id' => 'software_abandoned', 'category' => 'software', 'status' => 'warning' ),
			),
			array()
		);

		$this->assertSame( array(), $plan['fixable'] );
		$this->assertSame( array(), $plan['manual'] );
		$this->assertSame( array(), $plan['passing'] );
	}

	public function test_every_catalog_entry_declares_what_it_breaks(): void {
		foreach ( KarMCP_Security_Hardening_Fixer::catalog() as $finding => $fix ) {
			$this->assertNotSame( '', $fix['id'], $finding );
			$this->assertNotSame( '', $fix['does'], $finding );
			$this->assertNotSame( '', $fix['breaks'], $finding );
		}
	}

	// -----------------------------------------------------------------
	// freshness()
	// -----------------------------------------------------------------

	public function test_nothing_stored_is_never_not_clean(): void {
		$f = KarMCP_Security_Monitor::freshness( array(), 1000000 );
		$this->assertSame( 'never', $f['state'] );
	}

	public function test_a_record_without_a_finish_time_counts_as_never(): void {
		$f = KarMCP_Security_Monitor::freshness( array( 'summary' => array( 'score' => 100 ) ), 1000000 );
		$this->assertSame( 'never', $f['state'] );
	}

	/**
	 * The important one. A failed scan must not be able to present itself as a
	 * recent good result just because it finished recently.
	 */
	public function test_a_failed_scan_is_failed_however_recent_it_is(): void {
		$f = KarMCP_Security_Monitor::freshness(
			array( 'finished_at' => 999999, 'failed' => true ),
			1000000
		);

		$this->assertSame( 'failed', $f['state'] );
		$this->assertNotSame( 'fresh', $f['state'] );
	}

	public function test_a_recent_scan_is_fresh(): void {
		$f = KarMCP_Security_Monitor::freshness(
			array( 'finished_at' => 1000000 - 3600, 'failed' => false ),
			1000000
		);

		$this->assertSame( 'fresh', $f['state'] );
		$this->assertSame( 3600, $f['age'] );
	}

	public function test_an_old_scan_is_stale(): void {
		$f = KarMCP_Security_Monitor::freshness(
			array( 'finished_at' => 1000000 - ( 3 * 86400 ), 'failed' => false ),
			1000000
		);

		$this->assertSame( 'stale', $f['state'] );
	}

	public function test_the_staleness_boundary_is_configurable(): void {
		$stored = array( 'finished_at' => 1000000 - 100, 'failed' => false );

		$this->assertSame( 'fresh', KarMCP_Security_Monitor::freshness( $stored, 1000000, 200 )['state'] );
		$this->assertSame( 'stale', KarMCP_Security_Monitor::freshness( $stored, 1000000, 50 )['state'] );
	}

	/** A clock that went backwards must not produce a negative age. */
	public function test_a_future_finish_time_does_not_produce_a_negative_age(): void {
		$f = KarMCP_Security_Monitor::freshness(
			array( 'finished_at' => 1000500, 'failed' => false ),
			1000000
		);
		$this->assertSame( 0, $f['age'] );
	}
}
