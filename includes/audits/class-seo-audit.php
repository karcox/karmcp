<?php
/**
 * The SEO audit: rules over the rendered-page digest.
 *
 * Pure. HTML was already turned into a digest by `KarMCP_Content_Extractor`;
 * this file only reasons about that digest plus whatever the SEO plugin has
 * stored. No WordPress calls, no I/O, no globals — which is what lets the whole
 * rule set be covered by the test suite without a WordPress install.
 *
 * A rule that needs a fact it cannot see must receive it through `$ctx`. Do not
 * reach for `get_post_meta()` from in here; the caller assembles the context.
 *
 * The findings use the shape `KarMCP_Performance_Finding` already defines, so
 * the Optimize tab and anything else that renders findings keeps working.
 *
 * @package KarMCP
 * @since   1.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the SEO rule set over a page digest.
 *
 * @since 1.14.0
 */
class KarMCP_Seo_Audit {

	/**
	 * Title length window, in characters.
	 *
	 * Search engines truncate on pixel width, not characters, so these are a
	 * proxy and are graded as warnings rather than errors.
	 */
	const TITLE_MIN = 30;
	const TITLE_MAX = 60;

	/**
	 * Meta description length window, in characters.
	 */
	const DESCRIPTION_MIN = 70;
	const DESCRIPTION_MAX = 160;

	/**
	 * Word counts below which a page is thin.
	 */
	const THIN_WORDS      = 300;
	const VERY_THIN_WORDS = 100;

	/**
	 * How many offending examples to echo back per finding.
	 */
	const SAMPLE_CAP = 5;

	/**
	 * Runs every rule and returns the report.
	 *
	 * @param array $digest Digest from `KarMCP_Content_Extractor`.
	 * @param array $ctx    {
	 *     Optional context the digest cannot supply.
	 *
	 *     @type array  $seo   Result of `KarMCP_Seo_Meta::get()`.
	 *     @type array  $post  { title, url, type, status }.
	 *     @type string $scope 'content' or 'full'.
	 * }
	 * @return array { score, grade, counts, findings, title, description }
	 */
	public static function run( array $digest, array $ctx = array() ): array {
		$scope = isset( $ctx['scope'] ) && 'full' === $ctx['scope'] ? 'full' : 'content';

		$title       = self::effective_title( $digest, $ctx );
		$description = self::effective_description( $digest, $ctx );

		$findings = array();

		self::check_render( $digest, $findings );
		self::check_plugin_conflict( $ctx, $findings );
		self::check_title( $title, $findings );
		self::check_description( $description, $findings );
		self::check_headings( $digest, $scope, $findings );
		self::check_content( $digest, $findings );
		self::check_images( $digest, $findings );
		self::check_links( $digest, $findings );
		self::check_indexability( $digest, $ctx, $scope, $findings );
		self::check_language( $digest, $scope, $findings );
		self::check_social( $ctx, $findings );
		self::check_focus_keyword( $digest, $ctx, $title, $findings );

		$summary = KarMCP_Audit_Score::summarize( $findings );

		return array(
			'score'       => $summary['score'],
			'grade'       => $summary['grade'],
			'counts'      => $summary['counts'],
			'scope'       => $scope,
			'title'       => $title,
			'description' => $description,
			'findings'    => $findings,
		);
	}

	/**
	 * Resolves the title a visitor actually gets, and says where it came from.
	 *
	 * Order matters. The rendered `<title>` is the only value that is certainly
	 * true, and it only exists in a full-page render. A stored value that still
	 * carries `%%variables%%` is a template, so measuring its length would be
	 * measuring the template rather than the result — in that case we fall back
	 * and say so instead of reporting a number that means nothing.
	 *
	 * @param array $digest Page digest.
	 * @param array $ctx    Audit context.
	 * @return array { value, source, templated }
	 */
	public static function effective_title( array $digest, array $ctx ): array {
		$rendered = isset( $digest['document']['title'] ) ? trim( (string) $digest['document']['title'] ) : '';
		if ( '' !== $rendered ) {
			return array(
				'value'     => $rendered,
				'source'    => 'rendered',
				'templated' => false,
			);
		}

		$stored = isset( $ctx['seo']['fields']['title'] ) ? trim( (string) $ctx['seo']['fields']['title'] ) : '';
		if ( '' !== $stored ) {
			$templated = KarMCP_Seo_Meta::has_template_vars( $stored );
			if ( ! $templated ) {
				return array(
					'value'     => $stored,
					'source'    => 'stored',
					'templated' => false,
				);
			}

			return array(
				'value'     => '',
				'source'    => 'template',
				'templated' => true,
			);
		}

		$post_title = isset( $ctx['post']['title'] ) ? trim( (string) $ctx['post']['title'] ) : '';

		return array(
			'value'     => $post_title,
			'source'    => '' !== $post_title ? 'post_title' : 'none',
			'templated' => false,
		);
	}

	/**
	 * Resolves the meta description the same way.
	 *
	 * @param array $digest Page digest.
	 * @param array $ctx    Audit context.
	 * @return array { value, source, templated }
	 */
	public static function effective_description( array $digest, array $ctx ): array {
		$rendered = isset( $digest['document']['meta_description'] ) ? trim( (string) $digest['document']['meta_description'] ) : '';
		if ( '' !== $rendered ) {
			return array(
				'value'     => $rendered,
				'source'    => 'rendered',
				'templated' => false,
			);
		}

		$stored = isset( $ctx['seo']['fields']['description'] ) ? trim( (string) $ctx['seo']['fields']['description'] ) : '';
		if ( '' !== $stored ) {
			$templated = KarMCP_Seo_Meta::has_template_vars( $stored );

			return array(
				'value'     => $templated ? '' : $stored,
				'source'    => $templated ? 'template' : 'stored',
				'templated' => $templated,
			);
		}

		return array(
			'value'     => '',
			'source'    => 'none',
			'templated' => false,
		);
	}

	/**
	 * The render itself failing outranks every other finding: there is nothing
	 * to index, so nothing else on the page is worth grading.
	 *
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_render( array $digest, array &$findings ): void {
		foreach ( array( 'empty_render', 'unparsable_html' ) as $code ) {
			$warning = self::warning_by_code( $digest, $code );
			if ( null === $warning ) {
				continue;
			}

			$findings[] = self::finding(
				'render-' . str_replace( '_', '-', $code ),
				'render',
				__( 'Page renders', 'karmcp' ),
				'critical',
				false,
				$warning['message'],
				__( 'Nothing else in this report is meaningful until the page renders. Check the builder data and the template that owns this post type.', 'karmcp' )
			);
		}

		foreach ( array( 'unresolved_shortcode', 'unrendered_placeholder', 'placeholder_text' ) as $code ) {
			$warning = self::warning_by_code( $digest, $code );
			if ( null === $warning ) {
				continue;
			}

			$findings[] = self::finding(
				'content-' . str_replace( '_', '-', $code ),
				'content',
				__( 'Leftover placeholder content', 'karmcp' ),
				'warning',
				isset( $warning['examples'] ) ? $warning['examples'] : true,
				$warning['message'],
				__( 'Search engines index what the visitor sees, including the leftovers. Replace or remove it.', 'karmcp' )
			);
		}
	}

	/**
	 * Two active SEO plugins is a real bug and a confusing one: both emit tags,
	 * and which one wins depends on hook order rather than on anything visible.
	 *
	 * @param array $ctx      Audit context.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_plugin_conflict( array $ctx, array &$findings ): void {
		$others = isset( $ctx['seo']['others'] ) && is_array( $ctx['seo']['others'] ) ? $ctx['seo']['others'] : array();
		if ( empty( $others ) ) {
			return;
		}

		$source = isset( $ctx['seo']['source'] ) ? (string) $ctx['seo']['source'] : 'unknown';

		$findings[] = self::finding(
			'seo-plugin-conflict',
			'config',
			__( 'One SEO plugin active', 'karmcp' ),
			'critical',
			array_merge( array( $source ), $others ),
			sprintf(
				/* translators: %d: number of active SEO plugins. */
				__( '%d SEO plugins are active at once. They each emit their own title and meta tags, and which one wins comes down to hook order.', 'karmcp' ),
				count( $others ) + 1
			),
			__( 'Keep one and deactivate the rest. Migrate its stored metadata first — deactivating does not move it.', 'karmcp' )
		);
	}

	/**
	 * @param array $title    Effective title.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_title( array $title, array &$findings ): void {
		if ( $title['templated'] ) {
			$findings[] = self::finding(
				'title-template-only',
				'meta',
				__( 'Title', 'karmcp' ),
				'info',
				null,
				__( 'The stored title is a template with variables that only expand when the page is served, so its final length cannot be measured from here.', 'karmcp' ),
				__( 'Audit this page with scope "full" to measure the title a visitor actually gets.', 'karmcp' )
			);
			return;
		}

		if ( '' === $title['value'] ) {
			$findings[] = self::finding(
				'title-missing',
				'meta',
				__( 'Title', 'karmcp' ),
				'critical',
				'',
				__( 'The page has no title at all. It is the single strongest on-page signal and the line people click in the results.', 'karmcp' ),
				__( 'Write a title that names the page and the site, around 50-60 characters.', 'karmcp' )
			);
			return;
		}

		$length = self::length( $title['value'] );

		if ( $length < self::TITLE_MIN ) {
			$findings[] = self::finding(
				'title-short',
				'meta',
				__( 'Title length', 'karmcp' ),
				'warning',
				$length,
				sprintf(
					/* translators: 1: character count, 2: recommended minimum. */
					__( 'The title is %1$d characters, under the %2$d that usually fit a useful phrase.', 'karmcp' ),
					$length,
					self::TITLE_MIN
				),
				__( 'Add the qualifier a searcher would type — what it is, who it is for, or where.', 'karmcp' )
			);
			return;
		}

		if ( $length > self::TITLE_MAX ) {
			$findings[] = self::finding(
				'title-long',
				'meta',
				__( 'Title length', 'karmcp' ),
				'warning',
				$length,
				sprintf(
					/* translators: 1: character count, 2: recommended maximum. */
					__( 'The title is %1$d characters and will likely be cut off around %2$d.', 'karmcp' ),
					$length,
					self::TITLE_MAX
				),
				__( 'Put what matters first, so the part that survives truncation is the part that sells the click.', 'karmcp' )
			);
			return;
		}

		$findings[] = self::finding(
			'title-ok',
			'meta',
			__( 'Title length', 'karmcp' ),
			'pass',
			$length,
			sprintf(
				/* translators: %d: character count. */
				__( 'The title is %d characters, within the window that displays in full.', 'karmcp' ),
				$length
			)
		);
	}

	/**
	 * @param array $description Effective description.
	 * @param array $findings    Findings, by reference.
	 */
	private static function check_description( array $description, array &$findings ): void {
		if ( $description['templated'] ) {
			$findings[] = self::finding(
				'description-template-only',
				'meta',
				__( 'Meta description', 'karmcp' ),
				'info',
				null,
				__( 'The stored meta description is a template, so its final length cannot be measured from here.', 'karmcp' ),
				__( 'Audit with scope "full" to measure what is actually served.', 'karmcp' )
			);
			return;
		}

		if ( '' === $description['value'] ) {
			$findings[] = self::finding(
				'description-missing',
				'meta',
				__( 'Meta description', 'karmcp' ),
				'critical',
				'',
				__( 'The page has no meta description, so the search engine writes its own from whatever text it finds first.', 'karmcp' ),
				__( 'Write one sentence that answers what this page is for, around 150 characters.', 'karmcp' )
			);
			return;
		}

		$length = self::length( $description['value'] );

		if ( $length < self::DESCRIPTION_MIN ) {
			$findings[] = self::finding(
				'description-short',
				'meta',
				__( 'Meta description length', 'karmcp' ),
				'warning',
				$length,
				sprintf(
					/* translators: 1: character count, 2: recommended minimum. */
					__( 'The meta description is %1$d characters, under the %2$d that make a useful summary.', 'karmcp' ),
					$length,
					self::DESCRIPTION_MIN
				),
				__( 'Say what the visitor gets from the page, not just what the page is called.', 'karmcp' )
			);
			return;
		}

		if ( $length > self::DESCRIPTION_MAX ) {
			$findings[] = self::finding(
				'description-long',
				'meta',
				__( 'Meta description length', 'karmcp' ),
				'warning',
				$length,
				sprintf(
					/* translators: 1: character count, 2: recommended maximum. */
					__( 'The meta description is %1$d characters and will be truncated around %2$d.', 'karmcp' ),
					$length,
					self::DESCRIPTION_MAX
				),
				__( 'Lead with the sentence that has to survive; move the rest to the page.', 'karmcp' )
			);
			return;
		}

		$findings[] = self::finding(
			'description-ok',
			'meta',
			__( 'Meta description length', 'karmcp' ),
			'pass',
			$length,
			sprintf(
				/* translators: %d: character count. */
				__( 'The meta description is %d characters, within the window that displays in full.', 'karmcp' ),
				$length
			)
		);
	}

	/**
	 * @param array  $digest   Page digest.
	 * @param string $scope    Render scope.
	 * @param array  $findings Findings, by reference.
	 */
	private static function check_headings( array $digest, string $scope, array &$findings ): void {
		$headings = isset( $digest['headings'] ) && is_array( $digest['headings'] ) ? $digest['headings'] : array();

		$h1 = array_values(
			array_filter(
				$headings,
				static function ( $heading ) {
					return isset( $heading['level'] ) && 1 === (int) $heading['level'];
				}
			)
		);

		if ( empty( $h1 ) ) {
			// In a content-only render the theme usually owns the H1, so this is
			// only actionable when we looked at the whole served page.
			$findings[] = self::finding(
				'h1-missing',
				'headings',
				__( 'H1 heading', 'karmcp' ),
				'full' === $scope ? 'critical' : 'info',
				0,
				'full' === $scope
					? __( 'The served page has no H1. It is the heading that tells both readers and crawlers what this page is about.', 'karmcp' )
					: __( 'The content has no H1, which is fine if the theme renders the page title as one. Re-run with scope "full" to be sure.', 'karmcp' ),
				'full' === $scope
					? __( 'Add exactly one H1 that states the subject of the page.', 'karmcp' )
					: ''
			);
		} elseif ( count( $h1 ) > 1 ) {
			$findings[] = self::finding(
				'h1-multiple',
				'headings',
				__( 'H1 heading', 'karmcp' ),
				'warning',
				count( $h1 ),
				sprintf(
					/* translators: %d: number of H1 headings. */
					__( 'The page has %d H1 headings, so none of them reads as the subject.', 'karmcp' ),
					count( $h1 )
				),
				__( 'Keep the one that names the page and demote the rest to H2.', 'karmcp' ),
				array_slice( array_column( $h1, 'text' ), 0, self::SAMPLE_CAP )
			);
		} else {
			$findings[] = self::finding(
				'h1-ok',
				'headings',
				__( 'H1 heading', 'karmcp' ),
				'pass',
				1,
				__( 'The page has exactly one H1.', 'karmcp' )
			);
		}

		$skip = self::warning_by_code( $digest, 'heading_skip' );
		if ( null !== $skip ) {
			$findings[] = self::finding(
				'heading-outline-skips',
				'headings',
				__( 'Heading outline', 'karmcp' ),
				'warning',
				isset( $skip['examples'] ) ? $skip['examples'] : true,
				$skip['message'],
				__( 'Go down one level at a time. The outline is how a crawler and a screen reader both understand the page structure.', 'karmcp' )
			);
		}

		$empty = self::warning_by_code( $digest, 'empty_heading' );
		if ( null !== $empty ) {
			$findings[] = self::finding(
				'heading-empty',
				'headings',
				__( 'Empty headings', 'karmcp' ),
				'warning',
				true,
				$empty['message'],
				__( 'Fill the heading in or remove the widget. An empty heading still occupies a level in the outline.', 'karmcp' )
			);
		}
	}

	/**
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_content( array $digest, array &$findings ): void {
		$words = isset( $digest['text']['words'] ) ? (int) $digest['text']['words'] : 0;

		if ( $words < self::VERY_THIN_WORDS ) {
			$findings[] = self::finding(
				'content-very-thin',
				'content',
				__( 'Content depth', 'karmcp' ),
				'critical',
				$words,
				sprintf(
					/* translators: %d: word count. */
					__( 'The page has %d words of visible text. There is almost nothing here to rank.', 'karmcp' ),
					$words
				),
				__( 'Either write the page properly or keep it out of the index. A published page with no content competes with your own good ones.', 'karmcp' )
			);
			return;
		}

		if ( $words < self::THIN_WORDS ) {
			$findings[] = self::finding(
				'content-thin',
				'content',
				__( 'Content depth', 'karmcp' ),
				'warning',
				$words,
				sprintf(
					/* translators: 1: word count, 2: recommended minimum. */
					__( 'The page has %1$d words, under the %2$d that a content page usually needs to say anything complete.', 'karmcp' ),
					$words,
					self::THIN_WORDS
				),
				__( 'Fine for a landing page with one job. Worth expanding if this page is meant to attract search traffic.', 'karmcp' )
			);
			return;
		}

		$findings[] = self::finding(
			'content-depth-ok',
			'content',
			__( 'Content depth', 'karmcp' ),
			'pass',
			$words,
			sprintf(
				/* translators: %d: word count. */
				__( 'The page has %d words of visible text.', 'karmcp' ),
				$words
			)
		);
	}

	/**
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_images( array $digest, array &$findings ): void {
		$missing = self::warning_by_code( $digest, 'image_missing_alt' );
		if ( null !== $missing ) {
			$findings[] = self::finding(
				'image-missing-alt',
				'images',
				__( 'Image alt text', 'karmcp' ),
				'warning',
				isset( $missing['examples'] ) ? $missing['examples'] : true,
				$missing['message'],
				__( 'Describe what the image shows, or set alt="" if it is pure decoration. Both are correct; a missing attribute is not.', 'karmcp' )
			);
		}

		$no_source = self::warning_by_code( $digest, 'image_no_source' );
		if ( null !== $no_source ) {
			$findings[] = self::finding(
				'image-no-source',
				'images',
				__( 'Broken images', 'karmcp' ),
				'critical',
				true,
				$no_source['message'],
				__( 'An image element with no source renders as nothing. Set the media or remove the widget.', 'karmcp' )
			);
		}

		if ( null === $missing && null === $no_source && ! empty( $digest['counts']['images'] ) ) {
			$findings[] = self::finding(
				'images-ok',
				'images',
				__( 'Image alt text', 'karmcp' ),
				'pass',
				(int) $digest['counts']['images'],
				sprintf(
					/* translators: %d: number of images. */
					__( 'All %d images carry an alt attribute.', 'karmcp' ),
					(int) $digest['counts']['images']
				)
			);
		}
	}

	/**
	 * @param array $digest   Page digest.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_links( array $digest, array &$findings ): void {
		$placeholder = self::warning_by_code( $digest, 'link_placeholder' );
		if ( null !== $placeholder ) {
			$findings[] = self::finding(
				'link-placeholder',
				'links',
				__( 'Links that go nowhere', 'karmcp' ),
				'warning',
				isset( $placeholder['examples'] ) ? $placeholder['examples'] : true,
				$placeholder['message'],
				__( 'Point them at the page they were meant to reach. A button on "#" looks finished and is not.', 'karmcp' )
			);
		}

		$no_label = self::warning_by_code( $digest, 'link_no_label' );
		if ( null !== $no_label ) {
			$findings[] = self::finding(
				'link-no-label',
				'links',
				__( 'Links with no text', 'karmcp' ),
				'warning',
				isset( $no_label['examples'] ) ? $no_label['examples'] : true,
				$no_label['message'],
				__( 'Give the link readable text, or label the icon inside it. Link text is what tells a crawler where the link goes.', 'karmcp' )
			);
		}
	}

	/**
	 * @param array  $digest   Page digest.
	 * @param array  $ctx      Audit context.
	 * @param string $scope    Render scope.
	 * @param array  $findings Findings, by reference.
	 */
	private static function check_indexability( array $digest, array $ctx, string $scope, array &$findings ): void {
		$noindex = ! empty( $ctx['seo']['fields']['noindex'] );
		$status  = isset( $ctx['post']['status'] ) ? (string) $ctx['post']['status'] : '';

		if ( $noindex ) {
			$findings[] = self::finding(
				'robots-noindex',
				'indexability',
				__( 'Indexable', 'karmcp' ),
				'publish' === $status ? 'warning' : 'info',
				true,
				'publish' === $status
					? __( 'This page is published but marked noindex, so it will not appear in search results at all.', 'karmcp' )
					: __( 'This page is marked noindex.', 'karmcp' ),
				'publish' === $status
					? __( 'Deliberate for thank-you and utility pages. If it is not deliberate here, clear the noindex — no other fix on this page matters while it is set.', 'karmcp' )
					: ''
			);
		}

		if ( ! empty( $ctx['seo']['fields']['nofollow'] ) ) {
			$findings[] = self::finding(
				'robots-nofollow',
				'indexability',
				__( 'Links followed', 'karmcp' ),
				'info',
				true,
				__( 'This page is marked nofollow, so crawlers will not follow the links it contains.', 'karmcp' ),
				''
			);
		}

		if ( 'full' !== $scope ) {
			return;
		}

		$canonical = isset( $digest['document']['canonical'] ) ? trim( (string) $digest['document']['canonical'] ) : '';
		if ( '' === $canonical ) {
			$findings[] = self::finding(
				'canonical-missing',
				'indexability',
				__( 'Canonical URL', 'karmcp' ),
				'warning',
				'',
				__( 'The served page has no canonical link. WordPress emits one by default, so something has removed it.', 'karmcp' ),
				__( 'Check the SEO plugin settings and the theme header. Without it, duplicate URLs of this page compete with each other.', 'karmcp' )
			);
		} else {
			$findings[] = self::finding(
				'canonical-ok',
				'indexability',
				__( 'Canonical URL', 'karmcp' ),
				'pass',
				$canonical,
				__( 'The page declares a canonical URL.', 'karmcp' )
			);
		}
	}

	/**
	 * @param array  $digest   Page digest.
	 * @param string $scope    Render scope.
	 * @param array  $findings Findings, by reference.
	 */
	private static function check_language( array $digest, string $scope, array &$findings ): void {
		if ( 'full' !== $scope ) {
			return;
		}

		$lang = isset( $digest['document']['lang'] ) ? trim( (string) $digest['document']['lang'] ) : '';
		if ( '' !== $lang ) {
			return;
		}

		$findings[] = self::finding(
			'html-lang-missing',
			'document',
			__( 'Declared language', 'karmcp' ),
			'warning',
			'',
			__( 'The html element declares no lang attribute, so neither a crawler nor a screen reader knows what language this is.', 'karmcp' ),
			__( 'Almost always the theme dropping language_attributes() from header.php.', 'karmcp' )
		);
	}

	/**
	 * @param array $ctx      Audit context.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_social( array $ctx, array &$findings ): void {
		if ( empty( $ctx['seo']['readable'] ) ) {
			return;
		}

		$image = isset( $ctx['seo']['fields']['og_image'] ) ? trim( (string) $ctx['seo']['fields']['og_image'] ) : '';
		if ( '' !== $image ) {
			return;
		}

		$findings[] = self::finding(
			'og-image-missing',
			'social',
			__( 'Social share image', 'karmcp' ),
			'info',
			'',
			__( 'No share image is set for this page, so a link to it will be posted without one unless the site sets a fallback.', 'karmcp' ),
			__( 'Set one per page for the pages people actually share, or configure a site-wide fallback in the SEO plugin.', 'karmcp' )
		);
	}

	/**
	 * The focus keyword is only checked when one has been stored: inventing an
	 * opinion about a keyword nobody chose is noise.
	 *
	 * @param array $digest   Page digest.
	 * @param array $ctx      Audit context.
	 * @param array $title    Effective title.
	 * @param array $findings Findings, by reference.
	 */
	private static function check_focus_keyword( array $digest, array $ctx, array $title, array &$findings ): void {
		$keyword = isset( $ctx['seo']['fields']['focus_keyword'] ) ? trim( (string) $ctx['seo']['fields']['focus_keyword'] ) : '';
		if ( '' === $keyword ) {
			return;
		}

		// Rank Math stores secondary keywords in the same field, comma separated.
		$parts   = explode( ',', $keyword );
		$primary = trim( (string) reset( $parts ) );
		if ( '' === $primary ) {
			return;
		}

		$in_title = '' !== $title['value'] && self::contains( $title['value'], $primary );

		$h1_text = '';
		foreach ( ( isset( $digest['headings'] ) ? $digest['headings'] : array() ) as $heading ) {
			if ( isset( $heading['level'] ) && 1 === (int) $heading['level'] ) {
				$h1_text = isset( $heading['text'] ) ? (string) $heading['text'] : '';
				break;
			}
		}
		$in_h1 = '' !== $h1_text && self::contains( $h1_text, $primary );

		if ( $in_title && $in_h1 ) {
			$findings[] = self::finding(
				'focus-keyword-placed',
				'content',
				__( 'Focus keyword', 'karmcp' ),
				'pass',
				$primary,
				sprintf(
					/* translators: %s: focus keyword. */
					__( 'The focus keyword "%s" appears in both the title and the H1.', 'karmcp' ),
					$primary
				)
			);
			return;
		}

		$missing_from = array();
		if ( ! $in_title ) {
			$missing_from[] = __( 'the title', 'karmcp' );
		}
		if ( ! $in_h1 ) {
			$missing_from[] = __( 'the H1', 'karmcp' );
		}

		$findings[] = self::finding(
			'focus-keyword-absent',
			'content',
			__( 'Focus keyword', 'karmcp' ),
			'warning',
			$primary,
			sprintf(
				/* translators: 1: focus keyword, 2: comma-separated list of places. */
				__( 'The focus keyword "%1$s" does not appear in %2$s.', 'karmcp' ),
				$primary,
				implode( ', ', $missing_from )
			),
			__( 'Work it in where it reads naturally, or change the focus keyword to what the page is really about. Do not force it.', 'karmcp' )
		);
	}

	/**
	 * Builds one finding in the shape the rest of the plugin already uses.
	 *
	 * @param string $id             Machine id.
	 * @param string $category       Sub-area.
	 * @param string $label          Human label.
	 * @param string $status         pass|warning|critical|info.
	 * @param mixed  $value          Measured value.
	 * @param string $message        What is true.
	 * @param string $recommendation What to do about it.
	 * @param array  $examples       Sample offenders.
	 * @return array
	 */
	private static function finding( string $id, string $category, string $label, string $status, $value, string $message, string $recommendation = '', array $examples = array() ): array {
		$finding = KarMCP_Performance_Finding::make( $id, 'seo:' . $category, $label, $status, $value, $message, $recommendation );

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
		$warnings = isset( $digest['warnings'] ) && is_array( $digest['warnings'] ) ? $digest['warnings'] : array();

		foreach ( $warnings as $warning ) {
			if ( isset( $warning['code'] ) && $code === $warning['code'] ) {
				return $warning;
			}
		}

		return null;
	}

	/**
	 * Character length, counting multibyte characters as one.
	 *
	 * Every accented character in a Spanish title is two bytes; measuring bytes
	 * would report a title as over the limit while it displays perfectly.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Case-insensitive substring test that is multibyte-safe.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private static function contains( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return false !== strpos( mb_strtolower( $haystack, 'UTF-8' ), mb_strtolower( $needle, 'UTF-8' ) );
		}

		return false !== stripos( $haystack, $needle );
	}
}
