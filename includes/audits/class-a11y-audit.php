<?php
/**
 * The accessibility audit: WCAG rules over the rendered-page digest.
 *
 * Pure, like the SEO rules and for the same reason — the whole set is covered
 * by the suite without a WordPress install. Anything a rule cannot see from the
 * digest arrives through `$ctx`.
 *
 * **What this cannot do, stated once.** An automated check finds a minority of
 * WCAG failures. It can see a missing alt attribute; it cannot see whether the
 * alt text describes the image. It can see a heading level skipped; it cannot
 * see whether the headings mean anything. Reading order, focus order, keyboard
 * traps and meaningful sequence all need a browser and a person. A clean report
 * here is the floor, never a certificate.
 *
 * @package KarMCP
 * @since   1.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the accessibility rule set over a page digest.
 *
 * @since 1.16.0
 */
class KarMCP_A11y_Audit {

	/**
	 * How many offending examples to echo back per finding.
	 */
	const SAMPLE_CAP = 10;

	/**
	 * Runs every rule and returns the report.
	 *
	 * @param array $digest Digest from `KarMCP_Content_Extractor`.
	 * @param array $ctx    { scope }.
	 * @return array { score, grade, counts, scope, coverage, findings }
	 */
	public static function run( array $digest, array $ctx = array() ): array {
		$scope = isset( $ctx['scope'] ) && 'full' === $ctx['scope'] ? 'full' : 'content';

		$findings = array();

		self::check_images( $digest, $findings );
		self::check_links( $digest, $findings );
		self::check_headings( $digest, $scope, $findings );
		self::check_forms( $digest, $findings );
		self::check_document( $digest, $scope, $findings );
		self::check_landmarks( $digest, $scope, $findings );
		self::check_duplicate_ids( $digest, $findings );
		$contrast = self::check_contrast( $digest, $findings );

		$summary = KarMCP_Audit_Score::summarize( $findings );

		return array(
			'score'    => $summary['score'],
			'grade'    => $summary['grade'],
			'counts'   => $summary['counts'],
			'scope'    => $scope,
			'contrast' => $contrast,
			'findings' => $findings,
		);
	}

	/**
	 * 1.1.1 Non-text Content.
	 *
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_images( array $digest, array &$findings ): void {
		$missing = self::warning_by_code( $digest, 'image_missing_alt' );

		if ( null !== $missing ) {
			$findings[] = self::finding(
				'img-missing-alt',
				'wcag-1.1.1',
				__( 'Image alt text', 'karmcp' ),
				'critical',
				isset( $missing['examples'] ) ? $missing['examples'] : true,
				$missing['message'],
				__( 'Describe what the image conveys, or set alt="" if it is decorative. A screen reader reads the filename when the attribute is absent.', 'karmcp' )
			);
			return;
		}

		if ( ! empty( $digest['counts']['images'] ) ) {
			$decorative = count(
				array_filter(
					isset( $digest['images'] ) ? $digest['images'] : array(),
					static function ( $image ) {
						return ! empty( $image['decorative'] );
					}
				)
			);

			$findings[] = self::finding(
				'img-alt-present',
				'wcag-1.1.1',
				__( 'Image alt text', 'karmcp' ),
				'pass',
				(int) $digest['counts']['images'],
				sprintf(
					/* translators: 1: total images, 2: how many are marked decorative. */
					__( 'All %1$d images carry an alt attribute (%2$d marked decorative). Whether the text describes the image is not something this can check.', 'karmcp' ),
					(int) $digest['counts']['images'],
					$decorative
				)
			);
		}
	}

	/**
	 * 2.4.4 Link Purpose / 4.1.2 Name, Role, Value.
	 *
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_links( array $digest, array &$findings ): void {
		$unlabelled = self::warning_by_code( $digest, 'link_no_label' );

		if ( null !== $unlabelled ) {
			$findings[] = self::finding(
				'link-no-accessible-name',
				'wcag-4.1.2',
				__( 'Link names', 'karmcp' ),
				'critical',
				isset( $unlabelled['examples'] ) ? $unlabelled['examples'] : true,
				$unlabelled['message'],
				__( 'Give the link visible text, or an aria-label, or label the icon inside it. Without a name a screen reader announces the URL, which is unusable.', 'karmcp' )
			);
		}

		$placeholder = self::warning_by_code( $digest, 'link_placeholder' );
		if ( null !== $placeholder ) {
			$findings[] = self::finding(
				'link-placeholder-href',
				'wcag-2.1.1',
				__( 'Links that go nowhere', 'karmcp' ),
				'warning',
				isset( $placeholder['examples'] ) ? $placeholder['examples'] : true,
				$placeholder['message'],
				__( 'A link on "#" takes focus and does nothing, which strands a keyboard user. Point it somewhere, or make it a button.', 'karmcp' )
			);
		}
	}

	/**
	 * 1.3.1 Info and Relationships / 2.4.6 Headings and Labels.
	 *
	 * @param array  $digest   Page digest.
	 * @param string $scope    Render scope.
	 * @param array  $findings Findings, by reference.
	 */
	private static function check_headings( array $digest, string $scope, array &$findings ): void {
		$skip = self::warning_by_code( $digest, 'heading_skip' );
		if ( null !== $skip ) {
			$findings[] = self::finding(
				'heading-level-skipped',
				'wcag-1.3.1',
				__( 'Heading outline', 'karmcp' ),
				'warning',
				isset( $skip['examples'] ) ? $skip['examples'] : true,
				$skip['message'],
				__( 'Screen-reader users navigate by heading level. A skipped level reads as a missing section.', 'karmcp' )
			);
		}

		$empty = self::warning_by_code( $digest, 'empty_heading' );
		if ( null !== $empty ) {
			$findings[] = self::finding(
				'heading-empty',
				'wcag-1.3.1',
				__( 'Empty headings', 'karmcp' ),
				'warning',
				true,
				$empty['message'],
				__( 'An empty heading is announced as a heading with nothing in it, and still occupies a level in the outline.', 'karmcp' )
			);
		}

		if ( 'full' !== $scope ) {
			return;
		}

		$h1 = array_filter(
			isset( $digest['headings'] ) ? $digest['headings'] : array(),
			static function ( $heading ) {
				return isset( $heading['level'] ) && 1 === (int) $heading['level'];
			}
		);

		if ( empty( $h1 ) ) {
			$findings[] = self::finding(
				'h1-missing',
				'wcag-1.3.1',
				__( 'H1 heading', 'karmcp' ),
				'warning',
				0,
				__( 'The served page has no H1, so the heading outline has no top level to start from.', 'karmcp' ),
				__( 'Add exactly one H1 naming the page.', 'karmcp' )
			);
		}
	}

	/**
	 * 3.3.2 Labels or Instructions.
	 *
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_forms( array $digest, array &$findings ): void {
		$unlabelled = self::warning_by_code( $digest, 'form_unlabeled_fields' );
		if ( null !== $unlabelled ) {
			$findings[] = self::finding(
				'form-field-no-label',
				'wcag-3.3.2',
				__( 'Form labels', 'karmcp' ),
				'critical',
				isset( $unlabelled['examples'] ) ? $unlabelled['examples'] : true,
				$unlabelled['message'],
				__( 'Every field needs a label tied to it, or an aria-label. Placeholder text is not a label: it disappears the moment somebody types.', 'karmcp' )
			);
		}

		$no_submit = self::warning_by_code( $digest, 'form_no_submit' );
		if ( null !== $no_submit ) {
			$findings[] = self::finding(
				'form-no-submit',
				'wcag-3.2.2',
				__( 'Form submission', 'karmcp' ),
				'warning',
				true,
				$no_submit['message'],
				__( 'Without a submit control the form cannot be completed from the keyboard.', 'karmcp' )
			);
		}
	}

	/**
	 * 2.4.2 Page Titled / 3.1.1 Language of Page.
	 *
	 * @param array  $digest   Page digest.
	 * @param string $scope    Render scope.
	 * @param array  $findings Findings, by reference.
	 */
	private static function check_document( array $digest, string $scope, array &$findings ): void {
		if ( 'full' !== $scope ) {
			return;
		}

		$lang = trim( (string) ( $digest['document']['lang'] ?? '' ) );
		if ( '' === $lang ) {
			$findings[] = self::finding(
				'html-lang-missing',
				'wcag-3.1.1',
				__( 'Declared language', 'karmcp' ),
				'critical',
				'',
				__( 'The html element declares no lang attribute, so a screen reader reads the page with whatever voice it defaults to — often the wrong language entirely.', 'karmcp' ),
				__( 'Almost always the theme dropping language_attributes() from header.php.', 'karmcp' )
			);
		} else {
			$findings[] = self::finding(
				'html-lang-present',
				'wcag-3.1.1',
				__( 'Declared language', 'karmcp' ),
				'pass',
				$lang,
				sprintf(
					/* translators: %s: language attribute value. */
					__( 'The page declares its language as "%s".', 'karmcp' ),
					$lang
				)
			);
		}

		$title = trim( (string) ( $digest['document']['title'] ?? '' ) );
		if ( '' === $title ) {
			$findings[] = self::finding(
				'page-title-missing',
				'wcag-2.4.2',
				__( 'Page title', 'karmcp' ),
				'critical',
				'',
				__( 'The page has no title element. It is the first thing a screen reader announces and the only way to tell tabs apart.', 'karmcp' ),
				__( 'Check the theme header and the SEO plugin.', 'karmcp' )
			);
		}
	}

	/**
	 * 1.3.1 Info and Relationships — landmarks.
	 *
	 * @param array  $digest   Page digest.
	 * @param string $scope    Render scope.
	 * @param array  $findings Findings, by reference.
	 */
	private static function check_landmarks( array $digest, string $scope, array &$findings ): void {
		if ( 'full' !== $scope ) {
			return;
		}

		$main = (int) ( $digest['landmarks']['main'] ?? 0 );

		if ( 0 === $main ) {
			$findings[] = self::finding(
				'landmark-main-missing',
				'wcag-1.3.1',
				__( 'Main landmark', 'karmcp' ),
				'warning',
				0,
				__( 'The page declares no main landmark, so "skip to content" has nowhere to land and a screen-reader user has to walk the whole header every time.', 'karmcp' ),
				__( 'Wrap the page content in a <main> element. Usually one line in the theme template.', 'karmcp' )
			);
		} elseif ( $main > 1 ) {
			$findings[] = self::finding(
				'landmark-main-duplicated',
				'wcag-1.3.1',
				__( 'Main landmark', 'karmcp' ),
				'warning',
				$main,
				sprintf(
					/* translators: %d: number of main landmarks. */
					__( 'The page declares %d main landmarks. There should be exactly one.', 'karmcp' ),
					$main
				),
				__( 'Keep the outermost one and demote the rest to section or div.', 'karmcp' )
			);
		}
	}

	/**
	 * 4.1.1 Parsing — duplicate ids break every attribute that points at one.
	 *
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_duplicate_ids( array $digest, array &$findings ): void {
		$duplicate = self::warning_by_code( $digest, 'duplicate_id' );
		if ( null === $duplicate ) {
			return;
		}

		$findings[] = self::finding(
			'duplicate-id',
			'wcag-4.1.1',
			__( 'Duplicate element ids', 'karmcp' ),
			'warning',
			isset( $duplicate['examples'] ) ? $duplicate['examples'] : true,
			$duplicate['message'],
			__( 'A label pointing at a duplicated id attaches to the first match, so the second field silently loses its label.', 'karmcp' )
		);
	}

	/**
	 * 1.4.3 Contrast (Minimum), as far as markup allows.
	 *
	 * Only the text that declares its own colour inline can be judged at all,
	 * and only some of that resolves to a verdict. The summary reports what was
	 * looked at so a clean result is not mistaken for full coverage.
	 *
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 * @return array Coverage summary.
	 */
	private static function check_contrast( array $digest, array &$findings ): array {
		$samples = isset( $digest['inline_colors'] ) && is_array( $digest['inline_colors'] )
			? $digest['inline_colors']
			: array();

		$summary = array(
			'samples'      => count( $samples ),
			'pass'         => 0,
			'fail'         => 0,
			'inconclusive' => 0,
		);

		if ( empty( $samples ) ) {
			return $summary;
		}

		$failures = array();

		foreach ( $samples as $sample ) {
			$result = KarMCP_Color_Contrast::evaluate(
				isset( $sample['color'] ) ? (string) $sample['color'] : null,
				isset( $sample['background'] ) ? $sample['background'] : null,
				isset( $sample['font_size'] ) && null !== $sample['font_size'] ? (float) $sample['font_size'] : null,
				! empty( $sample['bold'] )
			);

			++$summary[ $result['status'] ];

			if ( 'fail' === $result['status'] ) {
				$failures[] = sprintf(
					'%s — %s:1 (needs %s:1) · %s',
					$sample['text'] ?? '',
					$result['ratio'],
					$result['required'],
					$sample['color'] ?? ''
				);
			}
		}

		if ( ! empty( $failures ) ) {
			$findings[] = self::finding(
				'contrast-insufficient',
				'wcag-1.4.3',
				__( 'Text contrast', 'karmcp' ),
				'critical',
				count( $failures ),
				sprintf(
					/* translators: 1: failing count, 2: how many were checked. */
					__( '%1$d of the %2$d text elements that declare their own colour fall below the required contrast ratio.', 'karmcp' ),
					count( $failures ),
					$summary['samples']
				),
				__( 'Darken the text or lighten the background until it reaches the ratio. Changing a brand colour is a design decision, so propose it rather than applying it.', 'karmcp' ),
				array_slice( $failures, 0, self::SAMPLE_CAP )
			);
		}

		if ( $summary['inconclusive'] > 0 ) {
			$findings[] = self::finding(
				'contrast-inconclusive',
				'wcag-1.4.3',
				__( 'Text contrast not determinable', 'karmcp' ),
				'info',
				$summary['inconclusive'],
				sprintf(
					/* translators: %d: number of elements. */
					__( '%d text elements could not be judged: the colour comes from a stylesheet or a variable, sits on a gradient or an image, or is translucent. Resolving those needs a browser.', 'karmcp' ),
					$summary['inconclusive']
				),
				__( 'Not a failure and not a pass. Check these by eye, or with a browser tool that can read the computed styles.', 'karmcp' )
			);
		}

		if ( 0 === $summary['fail'] && $summary['pass'] > 0 ) {
			$findings[] = self::finding(
				'contrast-checked-ok',
				'wcag-1.4.3',
				__( 'Text contrast', 'karmcp' ),
				'pass',
				$summary['pass'],
				sprintf(
					/* translators: %d: number of elements verified. */
					__( '%d text elements with an inline colour meet the required ratio. Text coloured from the stylesheet is not covered.', 'karmcp' ),
					$summary['pass']
				)
			);
		}

		return $summary;
	}

	/**
	 * Builds one finding, carrying the WCAG criterion in the category.
	 *
	 * @param string $id             Machine id.
	 * @param string $criterion      WCAG success criterion.
	 * @param string $label          Human label.
	 * @param string $status         pass|warning|critical|info.
	 * @param mixed  $value          Measured value.
	 * @param string $message        What is true.
	 * @param string $recommendation What to do about it.
	 * @param array  $examples       Sample offenders.
	 * @return array
	 */
	private static function finding( string $id, string $criterion, string $label, string $status, $value, string $message, string $recommendation = '', array $examples = array() ): array {
		$finding = KarMCP_Performance_Finding::make( $id, 'a11y:' . $criterion, $label, $status, $value, $message, $recommendation );

		if ( ! empty( $examples ) ) {
			$finding['examples'] = array_values( $examples );
		}

		return $finding;
	}

	/**
	 * Finds one extractor warning by its code.
	 *
	 * @param array  $digest Page digest.
	 * @param string $code   Warning code.
	 * @return array|null
	 */
	private static function warning_by_code( array $digest, string $code ): ?array {
		foreach ( ( isset( $digest['warnings'] ) ? $digest['warnings'] : array() ) as $warning ) {
			if ( isset( $warning['code'] ) && $code === $warning['code'] ) {
				return $warning;
			}
		}

		return null;
	}
}
