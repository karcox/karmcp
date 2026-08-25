<?php
/**
 * The Tools screen and the ability registry have to agree about what exists.
 *
 * `get_all_tools()` drops every category flagged `'pro' => true`, because those
 * describe integrations whose implementation is not in this build and listing a
 * toggle for a tool that can never register would be a lie the screen tells the
 * admin. That filter is correct. What nothing watches is whether the flag still
 * matches reality, and it drifts in both directions:
 *
 *   - A group gets implemented and stays flagged. Its slugs are seeded disabled
 *     and then hidden from the only screen that could switch them back on, so
 *     the tool is unreachable. CLAUDE.md records this happening to the Widget
 *     and Block Builders up to 1.12.0, and this gate found three more the day
 *     it was written.
 *   - A group is listed unflagged and never registers. The screen then offers
 *     toggles for tools that cannot exist — the exact lie the filter exists to
 *     prevent, arriving through the other door.
 *
 * Neither shows up at runtime. Both are one boolean away.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/lib-ability-scan.php';

class AbilityCatalogParityTest extends TestCase {

	/**
	 * Catalog slugs whose `pro` flag disagrees with the tree, frozen so the
	 * drift cannot grow while the decisions get made.
	 *
	 * **Empty, and that is the point.** It opened at seventeen entries — the
	 * drift this gate found the day it was written — and every one was fixed
	 * rather than frozen:
	 *
	 *   - `wp_woo` lost its flag. The integration is implemented, so the group
	 *     was hiding a read dispatcher that shipped enabled with no way to turn
	 *     it off, and a write dispatcher that shipped disabled with no way to
	 *     turn it on.
	 *   - The SEO and accessibility groups were split. Each had one implemented
	 *     page audit and an absent remainder, so neither a flag nor its absence
	 *     could be right for the whole group.
	 *   - `theme_generatepress`, `theme_blocksy` and `brand_kits` gained the
	 *     flag. All twelve of their slugs are absent, so the screen was offering
	 *     toggles that did nothing.
	 *
	 * Being empty is the strong state, not a reason to delete the constant: the
	 * next disagreement now fails outright instead of landing on a list. Add an
	 * entry only for a disagreement that is a deliberate product decision, with
	 * the reason, the way phpstan-baseline.neon and
	 * DeferredAbilityLoadTest::ALLOWED carry theirs — and
	 * test_known_drift_lists_nothing_that_is_already_fixed will fail the moment
	 * it stops being true.
	 *
	 * @var array<string,string> slug => what is wrong with it.
	 */
	private const KNOWN_DRIFT = array();

	/**
	 * The scanner has to account for every registration it walks past.
	 *
	 * A parity gate built on a scanner that quietly skips an idiom reports
	 * "clean" for the tools it could not see, which is worse than not having the
	 * gate: the green is load-bearing and hollow. Anything here means a new way
	 * of naming an ability was introduced and lib-ability-scan.php has to learn
	 * it before the rest of this file means anything.
	 */
	public function test_the_scanner_resolves_every_registration(): void {
		$unresolved = KarMCP_Ability_Scan::unattributed();

		$this->assertSame(
			array(),
			$unresolved,
			"lib-ability-scan.php walked past a karmcp_register_ability() call it could not resolve to a name:\n  "
				. implode( "\n  ", array_keys( $unresolved ) )
				. "\nTeach it that idiom, or the parity gates silently under-report."
		);
	}

	/**
	 * Sanity: the scanner found the surface at all. Every assertion below is a
	 * comparison between two sets, and two empty sets agree about everything.
	 */
	public function test_the_scanner_found_the_surface(): void {
		$this->assertGreaterThan( 200, count( KarMCP_Ability_Scan::abilities() ), 'Ability scan came back near-empty — the scanner broke, not the tree.' );
		$this->assertGreaterThan( 50, count( KarMCP_Ability_Scan::catalog_categories() ), 'Catalog scan came back near-empty — get_tool_catalog() changed shape.' );
	}

	/**
	 * Every place the catalog's `pro` flag and the tree disagree.
	 *
	 * Both directions in one pass, so the three assertions below are set
	 * comparisons rather than loops that can quietly iterate over nothing. A
	 * loop with a `fail()` inside asserts nothing on the happy path — PHPUnit
	 * calls that risky, and it is: it is the same hollow green as a step that
	 * cannot fail.
	 *
	 * @return array<string,string> slug => what disagrees about it.
	 */
	private function disagreements(): array {
		$abilities = KarMCP_Ability_Scan::abilities();
		$out       = array();

		foreach ( KarMCP_Ability_Scan::catalog_categories() as $category => $meta ) {
			foreach ( $meta['slugs'] as $slug ) {
				$implemented = isset( $abilities[ $slug ] );

				if ( $meta['pro'] && $implemented ) {
					$out[ $slug ] = "category '$category' is flagged 'pro' => true, so get_all_tools() drops it, but"
						. " $slug is implemented in {$abilities[ $slug ]['file']} — it registers and then cannot be"
						. ' enabled from the Tools tab. Unflag the category, or split the implemented part out of it.';
				}

				if ( ! $meta['pro'] && ! $implemented ) {
					$out[ $slug ] = "category '$category' is not flagged 'pro', so the Tools tab shows a toggle for"
						. " $slug — but nothing in includes/ registers it. Flag the category, or drop the entry.";
				}
			}
		}
		return $out;
	}

	/**
	 * A tool that exists must be reachable from the screen that enables it, and
	 * a toggle on that screen must correspond to a tool that can register.
	 */
	public function test_the_pro_flag_matches_what_the_tree_implements(): void {
		$found = $this->disagreements();
		$known = array_keys( self::KNOWN_DRIFT );

		$unexpected = array_diff( array_keys( $found ), $known );

		$detail = '';
		foreach ( $unexpected as $slug ) {
			$detail .= "\n  $slug — {$found[ $slug ]}";
		}

		$this->assertSame(
			array(),
			array_values( $unexpected ),
			'The catalog\'s pro flag disagrees with the tree:' . $detail
				. "\nFix the catalog, or add the slug to KNOWN_DRIFT with the reason."
		);
	}

	/**
	 * The ratchet only ratchets if it cannot quietly widen.
	 *
	 * An entry that no longer describes a real disagreement means the drift was
	 * fixed and the line should go, so this fails in the good direction. With
	 * KNOWN_DRIFT empty it still asserts — on the empty set — which is the whole
	 * reason it is written as a comparison.
	 */
	public function test_known_drift_lists_nothing_that_is_already_fixed(): void {
		$stale = array_diff( array_keys( self::KNOWN_DRIFT ), array_keys( $this->disagreements() ) );

		$this->assertSame(
			array(),
			array_values( $stale ),
			"KNOWN_DRIFT lists slugs the catalog and the tree now agree about:\n  "
				. implode( "\n  ", $stale )
				. "\nThe drift is fixed — remove the lines so the gate starts holding them."
		);
	}
}
