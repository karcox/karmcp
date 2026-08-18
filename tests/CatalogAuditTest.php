<?php
/**
 * The catalog audit, against the three real defects that motivated it.
 *
 * Each fixture below is a real control, copied from the source at the line the
 * field reports cite. They are the regression: if the audit stops catching any
 * of these, the class of defect it exists for is loose again.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/schemas/class-control-mapper.php';
require_once __DIR__ . '/../includes/audits/class-catalog-audit.php';

class CatalogAuditTest extends TestCase {

	/**
	 * elementor/includes/widgets/traits/button-trait.php:496 — the control the
	 * button widget really has, next to nothing named button_padding.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function button_controls(): array {
		return array(
			'text_padding'      => array( 'type' => 'dimensions', 'label' => 'Padding' ),
			'button_text_color' => array( 'type' => 'color', 'label' => 'Text Color' ),
			'background_color'  => array( 'type' => 'color', 'label' => 'Color' ),
		);
	}

	/**
	 * elementor/includes/widgets/video.php:243 and :254.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function video_controls(): array {
		return array(
			'insert_url' => array( 'type' => 'switcher', 'label' => 'External URL' ),
			'hosted_url' => array( 'type' => 'media', 'label' => 'Choose Video File' ),
			'video_type' => array( 'type' => 'select', 'label' => 'Source' ),
		);
	}

	/**
	 * elementor-pro/modules/blockquote/widgets/blockquote.php:842.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function blockquote_controls(): array {
		return array(
			'quote_size' => array(
				'type'      => 'slider',
				'label'     => 'Size',
				'range'     => array( 'px' => array( 'min' => 0.5, 'max' => 2, 'step' => 0.1 ) ),
				'default'   => array( 'size' => 1 ),
				'selectors' => array(
					'{{WRAPPER}} .elementor-blockquote:before' => 'font-size: calc({{SIZE}}{{UNIT}} * 100)',
				),
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $findings Findings from the audit.
	 * @return string[] The checks that fired.
	 */
	private function checks( array $findings ): array {
		return array_column( $findings, 'check' );
	}

	/**
	 * Case 1: the param does not exist on this widget. It exists on the kit,
	 * which is what made it convincing.
	 */
	public function test_a_param_the_widget_does_not_register_is_reported(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'button',
			array( 'button_padding' => array( 'type' => 'object', 'description' => 'Button padding: {top, right, bottom, left}.' ) ),
			$this->button_controls()
		);

		$this->assertSame( array( 'missing_control' ), $this->checks( $findings ) );
		$this->assertSame( 'error', $findings[0]['severity'] );
	}

	/**
	 * And it points at the control that was meant, which is the part that saves
	 * the reader a trip into Elementor's source.
	 */
	public function test_the_missing_param_finding_names_the_nearest_real_control(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'button',
			array( 'button_padding' => array( 'type' => 'object', 'description' => 'Button padding.' ) ),
			$this->button_controls()
		);

		$this->assertSame( 'text_padding', $findings[0]['nearest'] );
	}

	/**
	 * Case 2: a switch documented as a URL object. Sending {url:"…"} is truthy,
	 * so it silently flips the widget to the external source.
	 */
	public function test_a_switcher_documented_as_an_object_is_reported(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'video',
			array( 'insert_url' => array( 'type' => 'object', 'description' => 'Self-hosted video URL object: {url}.' ) ),
			$this->video_controls()
		);

		$this->assertContains( 'type_mismatch', $this->checks( $findings ) );
	}

	/**
	 * Case 3: a multiplier rendered through calc(size * 100). Documented as a
	 * length, 62 becomes 6200px.
	 */
	public function test_a_value_transformed_on_render_is_reported(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'blockquote',
			array( 'quote_size' => array( 'type' => 'object', 'description' => 'Quotation mark size (quotation skin): {size, unit}.' ) ),
			$this->blockquote_controls()
		);

		$this->assertContains( 'undocumented_transform', $this->checks( $findings ) );
		$this->assertContains( 'undocumented_range', $this->checks( $findings ) );
	}

	/**
	 * The fixed entries must go quiet, or the audit is unusable: a report that
	 * still fires after the fix trains people to ignore it.
	 */
	public function test_the_corrected_entries_are_silent(): void {
		$this->assertSame(
			array(),
			KarMCP_Catalog_Audit::run(
				'button',
				array( 'text_padding' => array( 'type' => 'object', 'description' => 'Button padding: {top, right, bottom, left, unit, isLinked}.' ) ),
				$this->button_controls()
			)
		);

		$this->assertSame(
			array(),
			KarMCP_Catalog_Audit::run(
				'video',
				array(
					'insert_url' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => 'Use an EXTERNAL url for a self-hosted video.' ),
					'hosted_url' => array( 'type' => 'object', 'description' => 'The self-hosted video file: {url, id}.' ),
				),
				$this->video_controls()
			)
		);

		$this->assertSame(
			array(),
			KarMCP_Catalog_Audit::run(
				'blockquote',
				array( 'quote_size' => array( 'type' => 'object', 'description' => 'Size as a MULTIPLIER from 0.5 to 2, default 1. Not pixels — rendered as calc(size * 100).' ) ),
				$this->blockquote_controls()
			)
		);
	}

	/**
	 * Responsive variants are generated by Elementor, not registered, so the
	 * catalog documents them without a matching control. Flagging those would
	 * bury the real findings.
	 */
	public function test_responsive_variants_are_not_reported_as_missing(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'button',
			array( 'text_padding_mobile' => array( 'type' => 'object', 'description' => 'Padding on mobile.' ) ),
			$this->button_controls()
		);

		$this->assertSame( array(), $findings );
	}

	/**
	 * A nominal 0-100 slider is Elementor's default for almost everything and
	 * says nothing. Only a range that actually constrains is worth a finding.
	 */
	public function test_a_nominal_range_is_not_worth_reporting(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'heading',
			array( 'size' => array( 'type' => 'object', 'description' => 'Font size.' ) ),
			array(
				'size' => array(
					'type'  => 'slider',
					'label' => 'Size',
					'range' => array( 'px' => array( 'min' => 0, 'max' => 100 ) ),
				),
			)
		);

		$this->assertSame( array(), $findings );
	}

	/**
	 * String documented as number on a text field is noise, not a defect. What
	 * matters is scalar-vs-composite, which is what sends the wrong payload.
	 */
	public function test_a_harmless_scalar_difference_is_not_reported(): void {
		$findings = KarMCP_Catalog_Audit::run(
			'heading',
			array( 'title' => array( 'type' => 'string', 'description' => 'The heading text.' ) ),
			array( 'title' => array( 'type' => 'textarea', 'label' => 'Title' ) )
		);

		$this->assertSame( array(), $findings );
	}
}
