<?php
/**
 * Every slug the default-disabled seeding names has to mean something.
 *
 * `maybe_apply_default_disabled_tools()` is how a powerful tool ships switched
 * off. It works on slug strings, and a slug string that matches nothing does
 * exactly what a correct one does: nothing visible. So a tool renamed without
 * its seed line being updated is not seeded — it ships *enabled* — and the only
 * way to find out is to install the plugin and look at the Tools tab.
 *
 * That is the same failure AjaxActionContractTest was written for: a name on
 * one side of a boundary and nothing on the other, with no error either way.
 *
 * What this deliberately does not assert is the other direction — that every
 * write tool is seeded. Which tools ship off is a judgement ("what writes,
 * deletes, or affects the whole site", per CLAUDE.md), not a property: five
 * tools annotated destructive ship enabled on purpose, because an agent that
 * can create a post has to be able to delete one. Encoding that as a rule would
 * mean an exemption list longer than the rule, which is a snapshot pretending
 * to be a gate. It is a question for review, not for a test.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/lib-ability-scan.php';

class AbilitySeedTest extends TestCase {

	public function test_every_seeded_slug_is_a_slug_something_uses(): void {
		$abilities = array_keys( KarMCP_Ability_Scan::abilities() );
		$retired   = KarMCP_Ability_Scan::retired_slugs();
		$catalog   = KarMCP_Ability_Scan::catalog_slugs();
		$seeded    = KarMCP_Ability_Scan::seeded_slugs();

		$this->assertNotEmpty( $seeded, 'No seeded slugs found — the scanner broke, not the tree.' );

		$orphans = array_values( array_diff( $seeded, $abilities, $retired, $catalog ) );

		$this->assertSame(
			array(),
			$orphans,
			"maybe_apply_default_disabled_tools() seeds slugs that are not registered anywhere, not listed in the\n"
				. "catalog, and not on a retirement list:\n  " . implode( "\n  ", $orphans )
				. "\nA seed line that matches nothing silently ships that tool enabled."
		);
	}

	/**
	 * The retirement lists name tools that are *gone*: v5 strips the 62
	 * per-widget Pro slugs the consolidation removed, v14 the pre-release ACF
	 * per-operation slugs. A name reappearing as a live ability means the strip
	 * now deletes a real user setting on upgrade.
	 */
	public function test_retired_slugs_have_not_come_back(): void {
		$abilities = KarMCP_Ability_Scan::abilities();

		foreach ( KarMCP_Ability_Scan::retired_slugs() as $slug ) {
			$this->assertArrayNotHasKey(
				$slug,
				$abilities,
				"$slug is on a retirement list, so the seeding routine strips it from the stored option on upgrade —"
					. ' but it is registered again in ' . ( $abilities[ $slug ]['file'] ?? '?' ) . '.'
					. " An admin's choice for that tool is being deleted every time the defaults version moves."
			);
		}
		$this->assertTrue( true );
	}

	/**
	 * DEFAULTS_VERSION only has to be monotonic and matched by a guarded step;
	 * what it must never be is lower than a step that exists, which would leave
	 * that step permanently unreachable.
	 */
	public function test_defaults_version_covers_every_seeding_step(): void {
		$admin = (string) file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );

		$this->assertSame( 1, preg_match( '/const DEFAULTS_VERSION = (\d+);/', $admin, $m ), 'DEFAULTS_VERSION is gone or changed shape.' );
		$version = (int) $m[1];

		$this->assertSame(
			1,
			preg_match( '/function maybe_apply_default_disabled_tools\(\): void \{(.*?)\n\t\}/s', $admin, $body ),
			'maybe_apply_default_disabled_tools() changed shape.'
		);
		$found = preg_match_all( '/\$applied < (\d+)/', $body[1], $steps );
		$this->assertGreaterThan( 0, $found, 'No guarded seeding steps found — the scanner broke, not the tree.' );

		$highest = max( array_map( 'intval', $steps[1] ) );
		$this->assertGreaterThanOrEqual(
			$highest,
			$version,
			"A seeding step guards on \$applied < $highest but DEFAULTS_VERSION is $version, so the step never runs"
				. ' and the tools it seeds ship enabled.'
		);
	}
}
