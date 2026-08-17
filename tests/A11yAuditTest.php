<?php
/**
 * KarMCP_A11y_Audit — the WCAG rule set over a page digest.
 *
 * Pure, so the whole set is covered here without WordPress.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/performance/class-performance-finding.php';
require_once dirname( __DIR__ ) . '/includes/audits/class-audit-score.php';
require_once dirname( __DIR__ ) . '/includes/audits/class-color-contrast.php';
require_once dirname( __DIR__ ) . '/includes/audits/class-a11y-audit.php';

final class A11yAuditTest extends TestCase {

	/**
	 * A digest in the shape the extractor produces.
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
			'landmarks'        => array( 'header' => 0, 'nav' => 0, 'main' => 1, 'footer' => 0, 'aside' => 0 ),
			'empty_containers' => array(),
			'inline_colors'    => array(),
			'text'             => array( 'words' => 400, 'excerpt' => '', 'prose' => '', 'prose_words' => 0 ),
			'counts'           => array( 'headings' => 0, 'images' => 0, 'links' => 0, 'forms' => 0, 'iframes' => 0, 'scripts' => 0 ),
			'warnings'         => array(),
			'truncated'        => false,
		);

		return array_replace_recursive( $base, $overrides );
	}

	/**
	 * One extractor warning.
	 *
	 * @param string $code     Code.
	 * @param array  $examples Examples.
	 * @return array
	 */
	private function warning( string $code, array $examples = array() ): array {
		$warning = array( 'code' => $code, 'severity' => 'warning', 'message' => 'mensaje de ' . $code );
		if ( ! empty( $examples ) ) {
			$warning['examples'] = $examples;
		}
		return $warning;
	}

	private function finding( array $report, string $id ): ?array {
		foreach ( $report['findings'] as $finding ) {
			if ( $finding['id'] === $id ) {
				return $finding;
			}
		}
		return null;
	}

	private function assertFinding( array $report, string $id, string $status ): void {
		$finding = $this->finding( $report, $id );
		$this->assertNotNull( $finding, "expected finding '$id'" );
		$this->assertSame( $status, $finding['status'], "finding '$id' status" );
	}

	private function assertNoFinding( array $report, string $id ): void {
		$this->assertNull( $this->finding( $report, $id ), "did not expect finding '$id'" );
	}

	// -------------------------------------------------------------- images

	public function test_missing_alt_is_critical(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'warnings' => array( $this->warning( 'image_missing_alt', array( 'a.jpg' ) ) ) ) )
		);

		$this->assertFinding( $report, 'img-missing-alt', 'critical' );
	}

	/**
	 * The pass message has to say what it did not check, or a clean line reads
	 * as "the alt text is good" when all it means is "the attribute is there".
	 */
	public function test_the_alt_pass_admits_what_it_cannot_judge(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'counts' => array( 'images' => 3 ),
					'images' => array(
						array( 'decorative' => true ),
						array( 'decorative' => false ),
						array( 'decorative' => false ),
					),
				)
			)
		);

		$finding = $this->finding( $report, 'img-alt-present' );

		$this->assertSame( 'pass', $finding['status'] );
		$this->assertStringContainsString( 'not something this can check', $finding['message'] );
	}

	// --------------------------------------------------------------- links

	public function test_a_link_with_no_accessible_name_is_critical(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'warnings' => array( $this->warning( 'link_no_label' ) ) ) )
		);

		$this->assertFinding( $report, 'link-no-accessible-name', 'critical' );
	}

	public function test_placeholder_links_are_a_keyboard_problem(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'warnings' => array( $this->warning( 'link_placeholder' ) ) ) )
		);

		$this->assertFinding( $report, 'link-placeholder-href', 'warning' );
	}

	// ------------------------------------------------------------ document

	public function test_language_and_landmarks_are_only_judged_on_the_served_page(): void {
		$content = KarMCP_A11y_Audit::run( $this->digest(), array( 'scope' => 'content' ) );
		$this->assertNoFinding( $content, 'html-lang-missing' );
		$this->assertNoFinding( $content, 'landmark-main-missing' );

		$full = KarMCP_A11y_Audit::run( $this->digest(), array( 'scope' => 'full' ) );
		$this->assertFinding( $full, 'html-lang-missing', 'critical' );
	}

	public function test_a_declared_language_passes(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'document' => array( 'lang' => 'es' ) ) ),
			array( 'scope' => 'full' )
		);

		$this->assertFinding( $report, 'html-lang-present', 'pass' );
	}

	public function test_a_missing_main_landmark_is_reported(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'landmarks' => array( 'main' => 0 ) ) ),
			array( 'scope' => 'full' )
		);

		$this->assertFinding( $report, 'landmark-main-missing', 'warning' );
	}

	public function test_more_than_one_main_landmark_is_reported(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'landmarks' => array( 'main' => 3 ) ) ),
			array( 'scope' => 'full' )
		);

		$this->assertFinding( $report, 'landmark-main-duplicated', 'warning' );
	}

	public function test_duplicate_ids_are_reported(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'warnings' => array( $this->warning( 'duplicate_id', array( 'header' ) ) ) ) )
		);

		$this->assertFinding( $report, 'duplicate-id', 'warning' );
	}

	// --------------------------------------------------------------- forms

	public function test_unlabelled_form_fields_are_critical(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'warnings' => array( $this->warning( 'form_unlabeled_fields' ) ) ) )
		);

		$finding = $this->finding( $report, 'form-field-no-label' );

		$this->assertSame( 'critical', $finding['status'] );
		$this->assertStringContainsString( 'Placeholder text is not a label', $finding['recommendation'] );
	}

	// ------------------------------------------------------------ contrast

	public function test_a_failing_contrast_pair_is_critical(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'inline_colors' => array(
						array( 'tag' => 'p', 'text' => 'Gris claro', 'color' => '#cccccc', 'background' => '#ffffff', 'font_size' => 16.0, 'bold' => false ),
					),
				)
			)
		);

		$this->assertFinding( $report, 'contrast-insufficient', 'critical' );
		$this->assertSame( 1, $report['contrast']['fail'] );
	}

	public function test_a_passing_pair_is_reported_with_its_limits(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'inline_colors' => array(
						array( 'tag' => 'p', 'text' => 'Negro', 'color' => '#000000', 'background' => '#ffffff', 'font_size' => 16.0, 'bold' => false ),
					),
				)
			)
		);

		$finding = $this->finding( $report, 'contrast-checked-ok' );

		$this->assertSame( 'pass', $finding['status'] );
		$this->assertStringContainsString( 'not covered', $finding['message'] );
	}

	/**
	 * The rule the roadmap fixed before any code existed: what cannot be
	 * resolved is reported as unresolved, never as a pass.
	 */
	public function test_an_unresolvable_background_is_inconclusive_not_a_pass(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'inline_colors' => array(
						array( 'tag' => 'p', 'text' => 'Sobre nada', 'color' => '#777777', 'background' => null, 'font_size' => 16.0, 'bold' => false ),
					),
				)
			)
		);

		$this->assertFinding( $report, 'contrast-inconclusive', 'info' );
		$this->assertNoFinding( $report, 'contrast-insufficient' );
		$this->assertNoFinding( $report, 'contrast-checked-ok' );
		$this->assertSame( 1, $report['contrast']['inconclusive'] );
	}

	/**
	 * Found on a real Elementor page: zero samples, and the report said nothing
	 * at all about contrast. A reader cannot tell "fine" from "never looked
	 * at", and the ambiguity flatters the tool.
	 */
	public function test_no_colour_samples_says_so_instead_of_going_quiet(): void {
		$report = KarMCP_A11y_Audit::run( $this->digest() );

		$this->assertFinding( $report, 'contrast-not-checked', 'info' );
		$this->assertNoFinding( $report, 'contrast-checked-ok' );
		$this->assertNoFinding( $report, 'contrast-inconclusive' );
		$this->assertSame( 0, $report['contrast']['samples'] );

		$this->assertStringContainsString(
			'not a pass',
			$this->finding( $report, 'contrast-not-checked' )['message']
		);
	}

	public function test_having_samples_replaces_the_not_checked_notice(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'inline_colors' => array(
						array( 'tag' => 'p', 'text' => 'Negro', 'color' => '#000000', 'background' => '#ffffff', 'font_size' => 16.0, 'bold' => false ),
					),
				)
			)
		);

		$this->assertNoFinding( $report, 'contrast-not-checked' );
	}

	// --------------------------------------------------------------- shape

	public function test_findings_carry_their_wcag_criterion(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'warnings' => array( $this->warning( 'image_missing_alt' ) ) ) )
		);

		$this->assertSame( 'a11y:wcag-1.1.1', $this->finding( $report, 'img-missing-alt' )['category'] );
	}

	public function test_every_non_pass_finding_carries_a_recommendation(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'landmarks' => array( 'main' => 0 ),
					'warnings'  => array(
						$this->warning( 'image_missing_alt' ),
						$this->warning( 'link_no_label' ),
						$this->warning( 'heading_skip' ),
						$this->warning( 'duplicate_id' ),
						$this->warning( 'form_unlabeled_fields' ),
					),
				)
			),
			array( 'scope' => 'full' )
		);

		foreach ( $report['findings'] as $finding ) {
			if ( in_array( $finding['status'], array( 'critical', 'warning' ), true ) ) {
				$this->assertNotSame( '', $finding['recommendation'], "finding '{$finding['id']}' has no recommendation" );
			}
		}
	}

	public function test_a_clean_page_scores_well(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest(
				array(
					'document' => array( 'lang' => 'es', 'title' => 'Inicio' ),
					'headings' => array( array( 'level' => 1, 'text' => 'Inicio' ) ),
				)
			),
			array( 'scope' => 'full' )
		);

		$this->assertSame( 100, $report['score'] );
		$this->assertSame( 'A', $report['grade'] );
	}

	public function test_a_served_page_with_no_h1_is_flagged(): void {
		$report = KarMCP_A11y_Audit::run(
			$this->digest( array( 'document' => array( 'lang' => 'es', 'title' => 'Inicio' ) ) ),
			array( 'scope' => 'full' )
		);

		$this->assertFinding( $report, 'h1-missing', 'warning' );
	}
}
