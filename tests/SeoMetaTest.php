<?php
/**
 * KarMCP_Seo_Meta — the mapping from each SEO plugin's keys to one vocabulary.
 *
 * Detection is deliberately not exercised here: it reads constants, and a
 * constant defined by one test stays defined for the whole run and would
 * silently change what every later test sees. `read()`/`write()` take the
 * provider explicitly for exactly that reason, and that is what gets covered.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-seo-meta.php';

final class SeoMetaTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	// ------------------------------------------------------------- shape

	public function test_empty_fields_has_every_field_and_typed_booleans(): void {
		$fields = KarMCP_Seo_Meta::empty_fields();

		foreach ( KarMCP_Seo_Meta::FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $fields );
		}

		$this->assertFalse( $fields['noindex'] );
		$this->assertFalse( $fields['nofollow'] );
		$this->assertSame( '', $fields['title'] );
	}

	public function test_unknown_provider_reads_empty_rather_than_failing(): void {
		$this->assertSame( KarMCP_Seo_Meta::empty_fields(), KarMCP_Seo_Meta::read( 1, 'none' ) );
		$this->assertSame( KarMCP_Seo_Meta::empty_fields(), KarMCP_Seo_Meta::read( 1, 'aioseo' ) );
	}

	public function test_detect_reports_none_when_no_plugin_is_active(): void {
		$detected = KarMCP_Seo_Meta::detect();

		$this->assertSame( 'none', $detected['source'] );
		$this->assertTrue( $detected['readable'] );
		$this->assertSame( array(), $detected['others'] );
	}

	// ------------------------------------------------------------- Yoast

	public function test_reads_yoast_scalar_fields(): void {
		update_post_meta( 7, '_yoast_wpseo_title', 'Camisetas de algodón' );
		update_post_meta( 7, '_yoast_wpseo_metadesc', 'Una descripción.' );
		update_post_meta( 7, '_yoast_wpseo_canonical', 'https://example.com/x' );
		update_post_meta( 7, '_yoast_wpseo_focuskw', 'camisetas' );
		update_post_meta( 7, '_yoast_wpseo_opengraph-image', 'https://example.com/og.jpg' );

		$fields = KarMCP_Seo_Meta::read( 7, 'yoast' );

		$this->assertSame( 'Camisetas de algodón', $fields['title'] );
		$this->assertSame( 'Una descripción.', $fields['description'] );
		$this->assertSame( 'https://example.com/x', $fields['canonical'] );
		$this->assertSame( 'camisetas', $fields['focus_keyword'] );
		$this->assertSame( 'https://example.com/og.jpg', $fields['og_image'] );
	}

	/**
	 * Yoast stores three states, and only '1' is a noindex. Reading '2' — the
	 * explicit "index" — as truthy would report every deliberately-indexed page
	 * as excluded from search.
	 */
	public function test_yoast_noindex_is_tri_state(): void {
		update_post_meta( 1, '_yoast_wpseo_meta-robots-noindex', '1' );
		$this->assertTrue( KarMCP_Seo_Meta::read( 1, 'yoast' )['noindex'] );

		update_post_meta( 2, '_yoast_wpseo_meta-robots-noindex', '2' );
		$this->assertFalse( KarMCP_Seo_Meta::read( 2, 'yoast' )['noindex'] );

		$this->assertFalse( KarMCP_Seo_Meta::read( 3, 'yoast' )['noindex'] );
	}

	public function test_writes_yoast_clearing_noindex_as_explicit_index(): void {
		KarMCP_Seo_Meta::write( 5, array( 'noindex' => false ), 'yoast' );

		// '2' means "index", '' would mean "use the site default" — a different
		// thing, and not what clearing a noindex was asked to do.
		$this->assertSame( '2', get_post_meta( 5, '_yoast_wpseo_meta-robots-noindex', true ) );
	}

	public function test_write_round_trips_through_read(): void {
		KarMCP_Seo_Meta::write(
			9,
			array(
				'title'       => 'Título nuevo',
				'description' => 'Descripción nueva',
				'noindex'     => true,
			),
			'yoast'
		);

		$fields = KarMCP_Seo_Meta::read( 9, 'yoast' );

		$this->assertSame( 'Título nuevo', $fields['title'] );
		$this->assertSame( 'Descripción nueva', $fields['description'] );
		$this->assertTrue( $fields['noindex'] );
	}

	public function test_write_ignores_unknown_field_names(): void {
		$this->assertFalse( KarMCP_Seo_Meta::write( 4, array( 'not_a_field' => 'x' ), 'yoast' ) );
	}

	public function test_write_only_touches_supplied_fields(): void {
		update_post_meta( 11, '_yoast_wpseo_metadesc', 'Original' );

		KarMCP_Seo_Meta::write( 11, array( 'title' => 'Solo el título' ), 'yoast' );

		$this->assertSame( 'Original', get_post_meta( 11, '_yoast_wpseo_metadesc', true ) );
	}

	// ---------------------------------------------------------- Rank Math

	public function test_reads_rank_math_robots_array(): void {
		update_post_meta( 12, 'rank_math_robots', array( 'noindex', 'nofollow' ) );

		$fields = KarMCP_Seo_Meta::read( 12, 'rankmath' );

		$this->assertTrue( $fields['noindex'] );
		$this->assertTrue( $fields['nofollow'] );
	}

	public function test_rank_math_robots_absent_reads_false(): void {
		$fields = KarMCP_Seo_Meta::read( 13, 'rankmath' );

		$this->assertFalse( $fields['noindex'] );
		$this->assertFalse( $fields['nofollow'] );
	}

	/**
	 * Rank Math keeps unrelated directives in the same array. Overwriting it
	 * wholesale would silently drop a noarchive somebody set on purpose.
	 */
	public function test_rank_math_write_preserves_unmanaged_directives(): void {
		update_post_meta( 14, 'rank_math_robots', array( 'noarchive', 'index' ) );

		KarMCP_Seo_Meta::write( 14, array( 'noindex' => true ), 'rankmath' );

		$robots = get_post_meta( 14, 'rank_math_robots', true );

		$this->assertContains( 'noarchive', $robots );
		$this->assertContains( 'noindex', $robots );
		$this->assertNotContains( 'index', $robots );
	}

	public function test_rank_math_write_does_not_duplicate_directives(): void {
		update_post_meta( 15, 'rank_math_robots', array( 'noindex' ) );

		KarMCP_Seo_Meta::write( 15, array( 'noindex' => true ), 'rankmath' );

		$robots = get_post_meta( 15, 'rank_math_robots', true );

		$this->assertSame( array( 'noindex' ), array_values( $robots ) );
	}

	// ----------------------------------------------------------- Slim SEO

	public function test_reads_slim_seo_single_meta_array(): void {
		update_post_meta(
			16,
			'slim_seo',
			array(
				'title'          => 'Slim título',
				'description'    => 'Slim descripción',
				'facebook_image' => 'https://example.com/fb.jpg',
				'noindex'        => true,
			)
		);

		$fields = KarMCP_Seo_Meta::read( 16, 'slimseo' );

		$this->assertSame( 'Slim título', $fields['title'] );
		$this->assertSame( 'Slim descripción', $fields['description'] );
		$this->assertSame( 'https://example.com/fb.jpg', $fields['og_image'] );
		$this->assertTrue( $fields['noindex'] );
	}

	public function test_slim_seo_write_merges_into_the_stored_array(): void {
		update_post_meta( 17, 'slim_seo', array( 'description' => 'Se queda' ) );

		KarMCP_Seo_Meta::write( 17, array( 'title' => 'Nuevo' ), 'slimseo' );

		$stored = get_post_meta( 17, 'slim_seo', true );

		$this->assertSame( 'Nuevo', $stored['title'] );
		$this->assertSame( 'Se queda', $stored['description'] );
	}

	public function test_slim_seo_non_array_meta_reads_empty(): void {
		update_post_meta( 18, 'slim_seo', 'corrupto' );

		$this->assertSame( KarMCP_Seo_Meta::empty_fields(), KarMCP_Seo_Meta::read( 18, 'slimseo' ) );
	}

	// -------------------------------------------------- template variables

	public function test_detects_both_template_variable_dialects(): void {
		// Yoast writes %%var%%, Rank Math writes %var%.
		$this->assertTrue( KarMCP_Seo_Meta::has_template_vars( '%%title%% %%sep%% %%sitename%%' ) );
		$this->assertTrue( KarMCP_Seo_Meta::has_template_vars( '%title% %sep%' ) );
	}

	public function test_plain_text_is_not_mistaken_for_a_template(): void {
		$this->assertFalse( KarMCP_Seo_Meta::has_template_vars( 'Camisetas de algodón' ) );
		$this->assertFalse( KarMCP_Seo_Meta::has_template_vars( 'Rebajas del 50% en toda la tienda' ) );
		$this->assertFalse( KarMCP_Seo_Meta::has_template_vars( '' ) );
	}

	public function test_templated_fields_names_only_the_templated_ones(): void {
		$templated = KarMCP_Seo_Meta::templated_fields(
			array(
				'title'       => '%%title%% %%sitename%%',
				'description' => 'Una descripción normal.',
				'noindex'     => true,
			)
		);

		$this->assertSame( array( 'title' ), $templated );
	}
}
