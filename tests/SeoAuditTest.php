<?php
/**
 * KarMCP_Seo_Audit — the rule set, and KarMCP_Audit_Score — the curve.
 *
 * Both are pure: a digest and a context go in, findings come out. No render, no
 * WordPress, no database. That is the whole reason the rules were written this
 * way, so this file is where the coverage lives.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/performance/class-performance-finding.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-meta.php';
require_once dirname( __DIR__ ) . '/includes/audits/class-audit-score.php';
require_once dirname( __DIR__ ) . '/includes/audits/class-seo-audit.php';

final class SeoAuditTest extends TestCase {

	/**
	 * A digest in the shape `KarMCP_Content_Extractor` produces, overridable.
	 *
	 * @param array $overrides Partial digest.
	 * @return array
	 */
	private function digest( array $overrides = array() ): array {
		$base = array(
			'render'           => array( 'scope' => 'content' ),
			'document'         => array(),
			'headings'         => array(),
			'images'           => array(),
			'links'            => array(),
			'forms'            => array(),
			'landmarks'        => array(),
			'empty_containers' => array(),
			'text'             => array( 'words' => 500, 'excerpt' => '' ),
			'counts'           => array( 'headings' => 0, 'images' => 0, 'links' => 0, 'forms' => 0, 'iframes' => 0, 'scripts' => 0 ),
			'warnings'         => array(),
			'truncated'        => false,
		);

		return array_replace_recursive( $base, $overrides );
	}

	/**
	 * A context with a readable SEO plugin and no stored values.
	 *
	 * @param array $fields Stored SEO fields.
	 * @param array $extra  Extra context.
	 * @return array
	 */
	private function ctx( array $fields = array(), array $extra = array() ): array {
		return array_replace_recursive(
			array(
				'scope' => 'content',
				'seo'   => array(
					'source'   => 'yoast',
					'readable' => true,
					'others'   => array(),
					'fields'   => array_merge( KarMCP_Seo_Meta::empty_fields(), $fields ),
				),
				'post'  => array( 'title' => 'Post title', 'status' => 'publish' ),
			),
			$extra
		);
	}

	/**
	 * Finds one finding by id.
	 *
	 * @param array  $report Report.
	 * @param string $id     Finding id.
	 * @return array|null
	 */
	private function finding( array $report, string $id ): ?array {
		foreach ( $report['findings'] as $finding ) {
			if ( $finding['id'] === $id ) {
				return $finding;
			}
		}
		return null;
	}

	/**
	 * Asserts a finding exists with the expected status.
	 *
	 * @param array  $report Report.
	 * @param string $id     Finding id.
	 * @param string $status Expected status.
	 */
	private function assertFinding( array $report, string $id, string $status ): void {
		$finding = $this->finding( $report, $id );
		$this->assertNotNull( $finding, "expected finding '$id'" );
		$this->assertSame( $status, $finding['status'], "finding '$id' status" );
	}

	/**
	 * Asserts no finding with this id was emitted.
	 *
	 * @param array  $report Report.
	 * @param string $id     Finding id.
	 */
	private function assertNoFinding( array $report, string $id ): void {
		$this->assertNull( $this->finding( $report, $id ), "did not expect finding '$id'" );
	}

	// -------------------------------------------------------------- title

	public function test_title_in_window_passes(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'title' => 'Camisetas de algodón orgánico para verano' ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'title-ok', 'pass' );
	}

	public function test_short_title_warns(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'title' => 'Inicio' ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'title-short', 'warning' );
	}

	public function test_long_title_warns(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'title' => str_repeat( 'a', 90 ) ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'title-long', 'warning' );
	}

	public function test_no_title_anywhere_is_critical(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(),
			$this->ctx( array(), array( 'post' => array( 'title' => '' ) ) )
		);

		$this->assertFinding( $report, 'title-missing', 'critical' );
	}

	/**
	 * Accented characters are two bytes each. Measuring bytes would report a
	 * perfectly sized Spanish title as over the limit.
	 */
	public function test_title_length_counts_characters_not_bytes(): void {
		$title = 'Diseño y creación de páginas rápidas para pequeñas pymes';

		// The fixture only proves anything if it fits the window in characters
		// and overflows it in bytes. Assert that first, so a future edit to the
		// string fails here with the reason rather than somewhere confusing.
		$this->assertLessThanOrEqual( KarMCP_Seo_Audit::TITLE_MAX, mb_strlen( $title, 'UTF-8' ) );
		$this->assertGreaterThan( KarMCP_Seo_Audit::TITLE_MAX, strlen( $title ) );

		$report  = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'title' => $title ) ) ),
			$this->ctx()
		);
		$finding = $this->finding( $report, 'title-ok' );

		$this->assertNotNull( $finding, 'a title that fits in characters must not be failed on its byte length' );
		$this->assertSame( mb_strlen( $title, 'UTF-8' ), $finding['value'] );
	}

	/**
	 * A stored template is not a title. Measuring its length would grade the
	 * template rather than what the visitor reads.
	 */
	public function test_templated_title_is_reported_not_measured(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(),
			$this->ctx( array( 'title' => '%%title%% %%sep%% %%sitename%%' ) )
		);

		$this->assertFinding( $report, 'title-template-only', 'info' );
		$this->assertNoFinding( $report, 'title-short' );
		$this->assertNoFinding( $report, 'title-long' );
		$this->assertNoFinding( $report, 'title-ok' );
	}

	public function test_rendered_title_wins_over_stored(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'title' => 'Lo que ve el visitante de verdad' ) ) ),
			$this->ctx( array( 'title' => 'Lo que hay guardado' ) )
		);

		$this->assertSame( 'rendered', $report['title']['source'] );
		$this->assertSame( 'Lo que ve el visitante de verdad', $report['title']['value'] );
	}

	public function test_falls_back_to_post_title_when_nothing_is_stored(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		$this->assertSame( 'post_title', $report['title']['source'] );
	}

	// -------------------------------------------------------- description

	public function test_missing_description_is_critical(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		$this->assertFinding( $report, 'description-missing', 'critical' );
	}

	public function test_description_in_window_passes(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'meta_description' => str_repeat( 'a', 120 ) ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'description-ok', 'pass' );
	}

	public function test_long_description_warns(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'meta_description' => str_repeat( 'a', 200 ) ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'description-long', 'warning' );
	}

	// ----------------------------------------------------------- headings

	public function test_single_h1_passes(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'headings' => array( array( 'level' => 1, 'text' => 'Título' ) ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'h1-ok', 'pass' );
	}

	public function test_multiple_h1_warns_and_lists_them(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'headings' => array(
						array( 'level' => 1, 'text' => 'Uno' ),
						array( 'level' => 1, 'text' => 'Dos' ),
					),
				)
			),
			$this->ctx()
		);

		$this->assertFinding( $report, 'h1-multiple', 'warning' );
		$this->assertSame( array( 'Uno', 'Dos' ), $this->finding( $report, 'h1-multiple' )['examples'] );
	}

	/**
	 * In a content-only render the theme usually supplies the H1, so a missing
	 * one is information. On the served page it is a real defect.
	 */
	public function test_missing_h1_severity_depends_on_scope(): void {
		$content = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );
		$this->assertFinding( $content, 'h1-missing', 'info' );

		$full = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx( array(), array( 'scope' => 'full' ) ) );
		$this->assertFinding( $full, 'h1-missing', 'critical' );
	}

	public function test_heading_skip_warning_becomes_a_finding(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'headings' => array( array( 'level' => 1, 'text' => 'Uno' ) ),
					'warnings' => array(
						array( 'code' => 'heading_skip', 'severity' => 'warning', 'message' => 'skips', 'examples' => array( 'h2 -> h4' ) ),
					),
				)
			),
			$this->ctx()
		);

		$this->assertFinding( $report, 'heading-outline-skips', 'warning' );
	}

	// ------------------------------------------------------------ content

	public function test_thin_content_warns_and_very_thin_is_critical(): void {
		$thin = KarMCP_Seo_Audit::run(
			$this->digest( array( 'text' => array( 'words' => 180 ) ) ),
			$this->ctx()
		);
		$this->assertFinding( $thin, 'content-thin', 'warning' );

		$very = KarMCP_Seo_Audit::run(
			$this->digest( array( 'text' => array( 'words' => 20 ) ) ),
			$this->ctx()
		);
		$this->assertFinding( $very, 'content-very-thin', 'critical' );
	}

	public function test_enough_content_passes(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		$this->assertFinding( $report, 'content-depth-ok', 'pass' );
	}

	// ------------------------------------------------------------- images

	public function test_missing_alt_warning_becomes_a_finding(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'counts'   => array( 'images' => 3 ),
					'warnings' => array(
						array( 'code' => 'image_missing_alt', 'severity' => 'warning', 'message' => '2 images', 'examples' => array( 'a.jpg' ) ),
					),
				)
			),
			$this->ctx()
		);

		$this->assertFinding( $report, 'image-missing-alt', 'warning' );
		$this->assertNoFinding( $report, 'images-ok' );
	}

	public function test_images_all_labelled_passes(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'counts' => array( 'images' => 4 ) ) ),
			$this->ctx()
		);

		$this->assertFinding( $report, 'images-ok', 'pass' );
	}

	public function test_no_images_at_all_emits_no_image_finding(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		$this->assertNoFinding( $report, 'images-ok' );
		$this->assertNoFinding( $report, 'image-missing-alt' );
	}

	// ------------------------------------------------------ indexability

	/**
	 * A noindex on a draft is normal. On a published page it overrides every
	 * other finding in the report, so it must not read as a footnote.
	 */
	public function test_noindex_severity_depends_on_post_status(): void {
		$published = KarMCP_Seo_Audit::run(
			$this->digest(),
			$this->ctx( array( 'noindex' => true ) )
		);
		$this->assertFinding( $published, 'robots-noindex', 'warning' );

		$draft = KarMCP_Seo_Audit::run(
			$this->digest(),
			$this->ctx( array( 'noindex' => true ), array( 'post' => array( 'status' => 'draft' ) ) )
		);
		$this->assertFinding( $draft, 'robots-noindex', 'info' );
	}

	public function test_canonical_is_only_judged_on_the_served_page(): void {
		$content = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );
		$this->assertNoFinding( $content, 'canonical-missing' );
		$this->assertNoFinding( $content, 'canonical-ok' );

		$full = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx( array(), array( 'scope' => 'full' ) ) );
		$this->assertFinding( $full, 'canonical-missing', 'warning' );
	}

	public function test_lang_is_only_judged_on_the_served_page(): void {
		$content = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );
		$this->assertNoFinding( $content, 'html-lang-missing' );

		$full = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx( array(), array( 'scope' => 'full' ) ) );
		$this->assertFinding( $full, 'html-lang-missing', 'warning' );
	}

	// ------------------------------------------------------ focus keyword

	public function test_focus_keyword_absent_from_title_warns(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'document' => array( 'title' => 'Una página sobre otra cosa entera' ),
					'headings' => array( array( 'level' => 1, 'text' => 'Otra cosa' ) ),
				)
			),
			$this->ctx( array( 'focus_keyword' => 'camisetas' ) )
		);

		$this->assertFinding( $report, 'focus-keyword-absent', 'warning' );
	}

	public function test_focus_keyword_in_title_and_h1_passes_case_insensitively(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'document' => array( 'title' => 'Camisetas de algodón orgánico baratas' ),
					'headings' => array( array( 'level' => 1, 'text' => 'Nuestras CAMISETAS' ) ),
				)
			),
			$this->ctx( array( 'focus_keyword' => 'camisetas' ) )
		);

		$this->assertFinding( $report, 'focus-keyword-placed', 'pass' );
	}

	/**
	 * Rank Math stores secondary keywords in the same comma-separated field.
	 * Only the first one is the focus.
	 */
	public function test_only_the_primary_focus_keyword_is_judged(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'document' => array( 'title' => 'Camisetas de algodón orgánico baratas' ),
					'headings' => array( array( 'level' => 1, 'text' => 'Camisetas' ) ),
				)
			),
			$this->ctx( array( 'focus_keyword' => 'camisetas, pantalones, gorras' ) )
		);

		$this->assertFinding( $report, 'focus-keyword-placed', 'pass' );
	}

	public function test_no_focus_keyword_means_no_opinion(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		$this->assertNoFinding( $report, 'focus-keyword-absent' );
		$this->assertNoFinding( $report, 'focus-keyword-placed' );
	}

	// ------------------------------------------------------------- render

	public function test_empty_render_is_critical(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'warnings' => array(
						array( 'code' => 'empty_render', 'severity' => 'error', 'message' => 'nothing rendered' ),
					),
				)
			),
			$this->ctx()
		);

		$this->assertFinding( $report, 'render-empty-render', 'critical' );
	}

	public function test_unresolved_shortcode_is_a_content_finding(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(
				array(
					'warnings' => array(
						array( 'code' => 'unresolved_shortcode', 'severity' => 'warning', 'message' => 'shortcode left', 'examples' => array( '[foo]' ) ),
					),
				)
			),
			$this->ctx()
		);

		$this->assertFinding( $report, 'content-unresolved-shortcode', 'warning' );
	}

	// ------------------------------------------------------------ conflict

	public function test_two_active_seo_plugins_is_critical(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(),
			$this->ctx( array(), array( 'seo' => array( 'others' => array( 'rankmath' ) ) ) )
		);

		$this->assertFinding( $report, 'seo-plugin-conflict', 'critical' );
	}

	// ------------------------------------------------- no SEO plugin at all

	/**
	 * A site with no SEO plugin has nowhere to put a meta description or a
	 * social image — WordPress core ships neither field. Found on a real site
	 * after 1.14.0: the audit was telling the reader to "write one sentence"
	 * and to "configure a fallback in the SEO plugin" when there was no plugin
	 * and no screen to do it in.
	 */
	private function ctxWithoutPlugin(): array {
		return $this->ctx(
			array(),
			array( 'seo' => array( 'source' => 'none', 'readable' => true ) )
		);
	}

	public function test_missing_seo_plugin_is_named_once_as_the_root_cause(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctxWithoutPlugin() );

		$this->assertFinding( $report, 'seo-plugin-missing', 'info' );
	}

	public function test_no_advice_points_at_an_seo_plugin_that_is_not_installed(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest(),
			array_replace_recursive( $this->ctxWithoutPlugin(), array( 'scope' => 'full' ) )
		);

		foreach ( $report['findings'] as $finding ) {
			if ( 'seo-plugin-missing' === $finding['id'] ) {
				// This one is allowed to name them: recommending you install one
				// is the whole point of it.
				continue;
			}

			$this->assertStringNotContainsStringIgnoringCase(
				'SEO plugin',
				$finding['recommendation'],
				"finding '{$finding['id']}' sends the reader to a plugin this site does not have"
			);
		}
	}

	public function test_social_image_is_not_reported_when_there_is_nowhere_to_set_one(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctxWithoutPlugin() );

		$this->assertNoFinding( $report, 'og-image-missing' );
	}

	public function test_missing_description_is_still_reported_without_a_plugin(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctxWithoutPlugin() );

		// The fact stays true and keeps costing points; only the advice changes.
		$this->assertFinding( $report, 'description-missing', 'critical' );
	}

	public function test_a_site_with_a_plugin_still_gets_the_social_finding(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		$this->assertFinding( $report, 'og-image-missing', 'info' );
		$this->assertNoFinding( $report, 'seo-plugin-missing' );
	}

	/**
	 * Found on a real site: no recognised SEO plugin, and a perfectly good
	 * 160-character meta description served anyway, because the theme or
	 * another plugin was emitting it. The report claimed the field "cannot be
	 * set at all" two lines above measuring the one that existed.
	 */
	public function test_report_does_not_contradict_itself_when_tags_come_from_elsewhere(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'meta_description' => str_repeat( 'a', 120 ) ) ) ),
			$this->ctxWithoutPlugin()
		);

		$this->assertFinding( $report, 'description-ok', 'pass' );
		$this->assertFinding( $report, 'seo-plugin-missing', 'info' );

		$cause = $this->finding( $report, 'seo-plugin-missing' );
		$this->assertStringNotContainsStringIgnoringCase(
			'cannot be set',
			$cause['message'],
			'the report says the description cannot exist while measuring the one it just found'
		);
		$this->assertSame( array( 'meta_description' ), $cause['examples'] );
	}

	public function test_the_blunt_message_survives_when_nothing_is_emitted(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctxWithoutPlugin() );

		$this->assertStringContainsStringIgnoringCase(
			'cannot be set',
			$this->finding( $report, 'seo-plugin-missing' )['message']
		);
	}

	/**
	 * Same mistake, other half: nothing stored is not nothing served.
	 */
	public function test_social_image_served_by_the_theme_is_not_reported_missing(): void {
		$report = KarMCP_Seo_Audit::run(
			$this->digest( array( 'document' => array( 'og_image' => 'https://example.com/og.jpg' ) ) ),
			$this->ctx()
		);

		$this->assertNoFinding( $report, 'og-image-missing' );
	}

	// --------------------------------------------------------------- shape

	public function test_every_non_pass_finding_carries_a_recommendation(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		foreach ( $report['findings'] as $finding ) {
			if ( in_array( $finding['status'], array( 'critical', 'warning' ), true ) ) {
				$this->assertNotSame(
					'',
					$finding['recommendation'],
					"finding '{$finding['id']}' tells the reader something is wrong without saying what to do"
				);
			}
		}
	}

	public function test_findings_are_namespaced_by_category(): void {
		$report = KarMCP_Seo_Audit::run( $this->digest(), $this->ctx() );

		foreach ( $report['findings'] as $finding ) {
			$this->assertStringStartsWith( 'seo:', $finding['category'] );
		}
	}

	// --------------------------------------------------------------- score

	public function test_a_clean_page_scores_100(): void {
		$this->assertSame( 100, KarMCP_Audit_Score::score( array() ) );
		$this->assertSame( 'A', KarMCP_Audit_Score::grade( 100 ) );
	}

	public function test_score_weights_by_status(): void {
		$findings = array(
			array( 'status' => 'critical' ),
			array( 'status' => 'warning' ),
			array( 'status' => 'info' ),
			array( 'status' => 'pass' ),
		);

		$this->assertSame( 80, KarMCP_Audit_Score::score( $findings ) );
	}

	public function test_score_never_goes_below_zero(): void {
		$findings = array_fill( 0, 40, array( 'status' => 'critical' ) );

		$this->assertSame( 0, KarMCP_Audit_Score::score( $findings ) );
		$this->assertSame( 'F', KarMCP_Audit_Score::grade( 0 ) );
	}

	public function test_grade_boundaries(): void {
		$this->assertSame( 'A', KarMCP_Audit_Score::grade( 90 ) );
		$this->assertSame( 'B', KarMCP_Audit_Score::grade( 89 ) );
		$this->assertSame( 'C', KarMCP_Audit_Score::grade( 70 ) );
		$this->assertSame( 'D', KarMCP_Audit_Score::grade( 60 ) );
		$this->assertSame( 'F', KarMCP_Audit_Score::grade( 59 ) );
	}

	public function test_counts_always_report_every_status(): void {
		$counts = KarMCP_Audit_Score::counts( array( array( 'status' => 'warning' ) ) );

		$this->assertSame( 0, $counts['critical'] );
		$this->assertSame( 1, $counts['warning'] );
		$this->assertSame( 0, $counts['info'] );
		$this->assertSame( 0, $counts['pass'] );
	}
}
