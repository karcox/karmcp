<?php
/**
 * KarMCP_Themer_Hello_Adapter::plan() — the Hello Elementor decision table.
 *
 * plan() is pure, so the whole matrix (which slots we fill, when we suppress the
 * theme, which parts we have to hand back) is testable without WordPress.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/themer/class-themer-hello-adapter.php';

final class ThemerHelloAdapterTest extends TestCase {

	/**
	 * @param int|null $header Header template id, or null.
	 * @param int|null $footer Footer template id, or null.
	 * @return array
	 */
	private function slots( $header, $footer ): array {
		return array( 'header' => $header, 'footer' => $footer, 'body' => null );
	}

	public function test_no_slots_means_hands_off(): void {
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( null, null ), false, false );

		$this->assertFalse( $plan['suppress'], 'must not touch the theme when we render nothing' );
		$this->assertFalse( $plan['header'] );
		$this->assertFalse( $plan['footer'] );
	}

	public function test_both_slots_replace_the_theme_entirely(): void {
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( 10, 20 ), false, false );

		$this->assertTrue( $plan['header'] );
		$this->assertTrue( $plan['footer'] );
		$this->assertTrue( $plan['suppress'] );
		// Nothing to hand back — we render both.
		$this->assertFalse( $plan['restore_header'] );
		$this->assertFalse( $plan['restore_footer'] );
	}

	public function test_header_only_hands_the_theme_footer_back(): void {
		// The suppression filter kills header AND footer together, so a header-only
		// template must re-emit the theme's footer or the site loses it.
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( 10, null ), false, false );

		$this->assertTrue( $plan['header'] );
		$this->assertFalse( $plan['footer'] );
		$this->assertTrue( $plan['suppress'] );
		$this->assertFalse( $plan['restore_header'] );
		$this->assertTrue( $plan['restore_footer'], 'the theme footer must be restored' );
	}

	public function test_footer_only_hands_the_theme_header_back(): void {
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( null, 20 ), false, false );

		$this->assertTrue( $plan['footer'] );
		$this->assertTrue( $plan['restore_header'], 'the theme header must be restored' );
		$this->assertFalse( $plan['restore_footer'] );
	}

	// ------------------------------------------- Elementor Pro takes precedence

	public function test_elementor_pro_header_wins_over_ours(): void {
		// Hello calls elementor_theme_do_location('header') first, so Pro already
		// printed one. Injecting would stack a second header.
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( 10, null ), true, false );

		$this->assertFalse( $plan['header'], 'must defer to Elementor Pro' );
		$this->assertFalse( $plan['suppress'], 'nothing left to do, so leave the theme alone' );
	}

	public function test_elementor_owning_one_location_does_not_block_the_other(): void {
		// Pro owns the header; we still supply the footer.
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( 10, 20 ), true, false );

		$this->assertFalse( $plan['header'] );
		$this->assertTrue( $plan['footer'] );
		$this->assertTrue( $plan['suppress'] );
		// Pro renders the header, so the theme's header must NOT be handed back.
		$this->assertFalse( $plan['restore_header'], 'Elementor already renders it' );
	}

	public function test_elementor_owning_both_locations_is_a_full_stand_down(): void {
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( 10, 20 ), true, true );

		$this->assertFalse( $plan['header'] );
		$this->assertFalse( $plan['footer'] );
		$this->assertFalse( $plan['suppress'] );
	}

	public function test_never_restores_a_part_the_theme_would_not_have_printed(): void {
		// Pro owns the footer, we fill the header: the theme footer was never going
		// to render, so restoring it would duplicate Elementor's.
		$plan = KarMCP_Themer_Hello_Adapter::plan( $this->slots( 10, null ), false, true );

		$this->assertTrue( $plan['header'] );
		$this->assertFalse( $plan['restore_footer'] );
	}
}
