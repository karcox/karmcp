<?php
/**
 * Normalized view of a page's *rendered* output.
 *
 * The agent writes builder JSON and, until now, had no way to see what came out
 * the other end. This class closes that loop: it renders a post the way a
 * visitor gets it, then reduces the HTML to one normalized digest — heading
 * outline, links, images, forms, landmarks, plus the warnings that catch what
 * an agent actually gets wrong (empty containers, missing alt text, placeholder
 * hrefs, shortcodes that never resolved).
 *
 * Two halves, deliberately split:
 *
 * - `analyze()` is **pure**: HTML string in, digest out, no WordPress. It is the
 *   testable core and the piece the SEO/a11y audits share.
 * - `extract()` is the WordPress half: it resolves a post id to HTML (builder
 *   render, or a loopback fetch for the full themed page) and hands it over.
 *
 * It is not a pixel diff. It cannot see a layout that wraps badly or text over a
 * background it can't read. What it does see is structure, and structure is
 * where most machine-written pages go wrong.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a post and reduces the result to one normalized digest.
 *
 * @since 1.1.0
 */
class KarMCP_Content_Extractor {

	/**
	 * Hard cap on the HTML we will parse, in bytes. Mirrors the performance
	 * audit's cap: past this, parsing costs more than the answer is worth.
	 */
	const MAX_HTML_BYTES = 2097152;

	/**
	 * Default cap on HTML echoed back to the caller when it asks for it. The
	 * digest is the product; raw HTML is a debugging aid and blows up a context
	 * window fast.
	 */
	const MAX_ECHO_BYTES = 200000;

	/**
	 * How many items of any one warning kind we list before summarizing. An
	 * agent needs examples to act on, not an exhaustive dump.
	 */
	const WARNING_SAMPLE_CAP = 10;

	/**
	 * Elements treated as containers when hunting for empty ones.
	 */
	const CONTAINER_TAGS = array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'li' );

	/**
	 * Elements that make a container non-empty even with no text: something is
	 * being shown, it just isn't words.
	 */
	const SUBSTANTIVE_TAGS = array( 'img', 'svg', 'iframe', 'video', 'audio', 'canvas', 'input', 'select', 'textarea', 'button', 'picture', 'object', 'embed', 'table', 'hr' );

	/**
	 * href values that mean "nobody set this link".
	 */
	const PLACEHOLDER_HREFS = array( '', '#', '#0', 'http://', 'https://', 'javascript:void(0)', 'javascript:void(0);', 'javascript:;' );

	/**
	 * Reduces rendered HTML to one normalized digest.
	 *
	 * Pure: no WordPress calls, no I/O, no globals. Everything the SEO and
	 * accessibility audits need to reason about a page comes out of here, which
	 * is why it is worth keeping honest.
	 *
	 * @since 1.1.0
	 *
	 * @param string $html  Rendered HTML — a full document or a fragment.
	 * @param array  $args  {
	 *     Optional.
	 *
	 *     @type string $scope        'content' or 'full'. Only affects how strictly
	 *                                document-level findings (a missing H1) are
	 *                                graded: in a fragment the theme may own them.
	 *     @type int    $excerpt_chars Characters of visible text to return. Default 600.
	 * }
	 * @return array Digest. Always has the same shape, even for empty input.
	 */
	public static function analyze( string $html, array $args = array() ): array {
		$scope         = ( isset( $args['scope'] ) && 'full' === $args['scope'] ) ? 'full' : 'content';
		$excerpt_chars = isset( $args['excerpt_chars'] ) ? max( 0, (int) $args['excerpt_chars'] ) : 600;

		$digest = self::empty_digest( $scope );

		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			$html                 = substr( $html, 0, self::MAX_HTML_BYTES );
			$digest['truncated']  = true;
		}

		if ( '' === trim( $html ) ) {
			$digest['warnings'][] = self::warning(
				'empty_render',
				'error',
				__( 'The page rendered to nothing at all. Either it has no content, or the builder data failed to render.', 'karmcp' )
			);
			return $digest;
		}

		$dom = self::to_dom( $html );
		if ( null === $dom ) {
			$digest['warnings'][] = self::warning(
				'unparsable_html',
				'error',
				__( 'The rendered HTML could not be parsed. The DOM extension may be missing on this server.', 'karmcp' )
			);
			return $digest;
		}

		$xpath = new DOMXPath( $dom );

		self::collect_document( $dom, $xpath, $digest );
		self::collect_headings( $xpath, $digest, $scope );
		self::collect_images( $xpath, $digest );
		self::collect_links( $xpath, $digest );
		self::collect_forms( $xpath, $digest );
		self::collect_landmarks( $xpath, $digest );
		self::collect_empty_containers( $xpath, $digest );
		self::collect_duplicate_ids( $xpath, $digest );

		$body = $xpath->query( '//body' )->item( 0 );
		$text = self::visible_text( $body instanceof DOMNode ? $body : $dom );

		self::collect_text_leftovers( $text, $digest );

		$words                     = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$digest['text']['words']   = is_array( $words ) ? count( $words ) : 0;
		$digest['text']['excerpt'] = $excerpt_chars > 0 ? self::truncate( $text, $excerpt_chars ) : '';

		$digest['counts']['iframes'] = $xpath->query( '//iframe' )->length;
		$digest['counts']['scripts'] = $xpath->query( '//script' )->length;

		return $digest;
	}

	/**
	 * Renders a post and returns its digest.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    {
	 *     Optional.
	 *
	 *     @type string $scope         'content' (builder output only, the default) or
	 *                                 'full' (the themed page over a loopback request).
	 *     @type bool   $include_html  Echo the rendered HTML back, capped. Default false.
	 *     @type int    $excerpt_chars Characters of visible text to return. Default 600.
	 * }
	 * @return array|WP_Error Digest, or an error when the post cannot be rendered.
	 */
	public static function extract( int $post_id, array $args = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ) );
		}

		$scope = ( isset( $args['scope'] ) && 'full' === $args['scope'] ) ? 'full' : 'content';

		$rendered = ( 'full' === $scope )
			? self::render_full( $post_id )
			: self::render_content( $post_id );

		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		$digest = self::analyze(
			$rendered['html'],
			array(
				'scope'         => $scope,
				'excerpt_chars' => isset( $args['excerpt_chars'] ) ? (int) $args['excerpt_chars'] : 600,
			)
		);

		$digest['post'] = array(
			'id'      => $post_id,
			'title'   => get_the_title( $post_id ),
			'type'    => $post->post_type,
			'status'  => $post->post_status,
			'url'     => get_permalink( $post_id ),
			'builder' => class_exists( 'KarMCP_Themer_Content_Renderer' )
				? KarMCP_Themer_Content_Renderer::detect_builder( $post_id )
				: 'unknown',
		);

		$digest['render'] = array(
			'scope'  => $scope,
			'source' => $rendered['source'],
			'bytes'  => strlen( $rendered['html'] ),
		);
		if ( isset( $rendered['status_code'] ) ) {
			$digest['render']['status_code'] = $rendered['status_code'];
		}

		if ( ! empty( $args['include_html'] ) ) {
			$digest['html'] = self::truncate_bytes( $rendered['html'], self::MAX_ECHO_BYTES );
		}

		return $digest;
	}

	/**
	 * Renders just the post's own built content, no theme chrome.
	 *
	 * Delegates to the shared content renderer, which already dispatches to
	 * Elementor's frontend, `do_blocks`, or the `the_content` filter. Works on
	 * drafts, which is the reason this is the default scope: an agent building a
	 * page wants to check it before publishing.
	 *
	 * @param int $post_id Post id.
	 * @return array|WP_Error { html, source }
	 */
	private static function render_content( int $post_id ) {
		if ( ! class_exists( 'KarMCP_Themer_Content_Renderer' ) ) {
			return new WP_Error( 'renderer_unavailable', __( 'The content renderer is not available on this install.', 'karmcp' ) );
		}

		// The builder writes against the global post (dynamic tags, the loop),
		// so set it up and put it back afterwards.
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$post     = get_post( $post_id );

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( function_exists( 'setup_postdata' ) ) {
			setup_postdata( $post );
		}

		try {
			$html = KarMCP_Themer_Content_Renderer::render( $post_id );
		} catch ( \Throwable $e ) {
			$html = '';
			$error = $e->getMessage();
		} finally {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( function_exists( 'wp_reset_postdata' ) ) {
				wp_reset_postdata();
			}
		}

		if ( isset( $error ) ) {
			return new WP_Error(
				'render_failed',
				sprintf(
					/* translators: %s: PHP error message. */
					__( 'Rendering the page threw an error: %s', 'karmcp' ),
					$error
				)
			);
		}

		return array(
			'html'   => (string) $html,
			'source' => 'builder',
		);
	}

	/**
	 * Fetches the whole themed page over a loopback request.
	 *
	 * The URL is always derived from the post id via `get_permalink()` and never
	 * taken from caller input, and the fetch itself goes through the performance
	 * audit's fetcher, which re-checks every redirect hop against the origin
	 * host. Published posts only: a draft would come back as a 404 and the
	 * resulting digest would describe the wrong page.
	 *
	 * @param int $post_id Post id.
	 * @return array|WP_Error { html, source, status_code }
	 */
	private static function render_full( int $post_id ) {
		if ( ! class_exists( 'KarMCP_Performance_Page_Audit' ) ) {
			return new WP_Error( 'fetcher_unavailable', __( 'The loopback fetcher is not available on this install.', 'karmcp' ) );
		}

		$status = get_post_status( $post_id );
		if ( 'publish' !== $status ) {
			return new WP_Error(
				'not_public',
				__( 'scope "full" fetches the live URL, so it only works on published posts. Use scope "content" to check a draft.', 'karmcp' )
			);
		}

		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return new WP_Error( 'no_permalink', __( 'This post has no public URL.', 'karmcp' ) );
		}

		$audit = new KarMCP_Performance_Page_Audit();
		$res   = $audit->fetch( $url );

		if ( empty( $res['ok'] ) ) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %s: fetch error description. */
					__( 'Could not fetch the page over a loopback request: %s', 'karmcp' ),
					(string) ( $res['error'] ?? 'unknown' )
				)
			);
		}

		return array(
			'html'        => (string) ( $res['body'] ?? '' ),
			'source'      => 'loopback',
			'status_code' => (int) ( $res['status_code'] ?? 0 ),
		);
	}

	// -----------------------------------------------------------------------
	// Collectors. Each takes the digest by reference and fills its own slice.
	// -----------------------------------------------------------------------

	/**
	 * Document-level facts that only exist in a full page.
	 *
	 * @param DOMDocument $dom    Parsed document.
	 * @param DOMXPath    $xpath  Query engine.
	 * @param array       $digest Digest, by reference.
	 */
	private static function collect_document( DOMDocument $dom, DOMXPath $xpath, array &$digest ): void {
		$title = $xpath->query( '//title' )->item( 0 );
		if ( $title instanceof DOMNode ) {
			$digest['document']['title'] = self::node_text( $title );
		}

		$description = $xpath->query( '//meta[translate(@name,"DESCRIPTION","description")="description"]/@content' )->item( 0 );
		if ( $description instanceof DOMNode ) {
			$digest['document']['meta_description'] = trim( $description->nodeValue );
		}

		$canonical = $xpath->query( '//link[@rel="canonical"]/@href' )->item( 0 );
		if ( $canonical instanceof DOMNode ) {
			$digest['document']['canonical'] = trim( $canonical->nodeValue );
		}

		$lang = $xpath->query( '//html/@lang' )->item( 0 );
		if ( $lang instanceof DOMNode ) {
			$digest['document']['lang'] = trim( $lang->nodeValue );
		}

		// Open Graph is declared with `property`, but enough plugins emit it as
		// `name` that querying only one of them misses real tags.
		$og_image = $xpath->query( '//meta[@property="og:image" or @name="og:image"]/@content' )->item( 0 );
		if ( $og_image instanceof DOMNode ) {
			$digest['document']['og_image'] = trim( $og_image->nodeValue );
		}
	}

	/**
	 * Heading outline plus the three ways an outline goes wrong.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 * @param string   $scope  Render scope.
	 */
	private static function collect_headings( DOMXPath $xpath, array &$digest, string $scope ): void {
		$nodes    = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		$previous = 0;
		$empty    = array();
		$skips    = array();

		foreach ( $nodes as $node ) {
			$level = (int) substr( $node->nodeName, 1 );
			$text  = self::node_text( $node );

			$digest['headings'][] = array(
				'level' => $level,
				'text'  => self::truncate( $text, 160 ),
				'id'    => self::attr( $node, 'id' ),
			);

			if ( '' === $text ) {
				$empty[] = $level;
			}
			if ( $previous > 0 && $level > $previous + 1 ) {
				$skips[] = sprintf( 'h%d -> h%d', $previous, $level );
			}
			$previous = $level;
		}

		$digest['counts']['headings'] = count( $digest['headings'] );

		$h1 = array_values(
			array_filter(
				$digest['headings'],
				static function ( $heading ) {
					return 1 === $heading['level'];
				}
			)
		);

		if ( empty( $h1 ) ) {
			// In a fragment the theme usually supplies the H1, so this is only a
			// finding worth acting on when we looked at the whole page.
			$digest['warnings'][] = self::warning(
				'no_h1',
				'full' === $scope ? 'warning' : 'info',
				'full' === $scope
					? __( 'The page has no H1. Every page should have exactly one.', 'karmcp' )
					: __( 'No H1 in the page content. That is fine if the theme renders the title, worth checking if it does not.', 'karmcp' )
			);
		} elseif ( count( $h1 ) > 1 ) {
			$digest['warnings'][] = self::warning(
				'multiple_h1',
				'warning',
				sprintf(
					/* translators: %d: number of H1 elements found. */
					__( 'The page has %d H1 headings. Use one, and demote the rest to H2.', 'karmcp' ),
					count( $h1 )
				),
				array_slice( array_column( $h1, 'text' ), 0, self::WARNING_SAMPLE_CAP )
			);
		}

		if ( ! empty( $skips ) ) {
			$digest['warnings'][] = self::warning(
				'heading_skip',
				'warning',
				__( 'The heading outline skips levels. Screen-reader users navigate by it, so go down one level at a time.', 'karmcp' ),
				array_slice( array_unique( $skips ), 0, self::WARNING_SAMPLE_CAP )
			);
		}

		if ( ! empty( $empty ) ) {
			$digest['warnings'][] = self::warning(
				'empty_heading',
				'warning',
				sprintf(
					/* translators: %d: number of empty headings. */
					__( '%d heading elements render with no text. Usually a widget whose title was never filled in.', 'karmcp' ),
					count( $empty )
				)
			);
		}
	}

	/**
	 * Images, and the alt text that decides whether they are usable.
	 *
	 * A missing `alt` attribute and `alt=""` are different things: the empty one
	 * is a deliberate "this is decoration, skip it", which is correct and common.
	 * Only the absent attribute is a finding.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 */
	private static function collect_images( DOMXPath $xpath, array &$digest ): void {
		$missing_alt = array();
		$empty_src   = 0;

		foreach ( $xpath->query( '//img' ) as $node ) {
			$src         = self::attr( $node, 'src' );
			$has_alt     = $node instanceof DOMElement && $node->hasAttribute( 'alt' );
			$alt         = self::attr( $node, 'alt' );
			$decorative  = $has_alt && '' === $alt;

			$digest['images'][] = array(
				'src'        => self::truncate( $src, 300 ),
				'alt'        => self::truncate( $alt, 200 ),
				'has_alt'    => $has_alt,
				'decorative' => $decorative,
				'loading'    => self::attr( $node, 'loading' ),
			);

			if ( ! $has_alt ) {
				$missing_alt[] = self::truncate( '' !== $src ? $src : '(no src)', 160 );
			}
			if ( '' === $src && '' === self::attr( $node, 'srcset' ) && '' === self::attr( $node, 'data-src' ) ) {
				++$empty_src;
			}
		}

		$digest['counts']['images'] = count( $digest['images'] );

		if ( ! empty( $missing_alt ) ) {
			$digest['warnings'][] = self::warning(
				'image_missing_alt',
				'warning',
				sprintf(
					/* translators: %d: number of images without an alt attribute. */
					__( '%d images have no alt attribute. Add descriptive text, or alt="" if the image is purely decorative.', 'karmcp' ),
					count( $missing_alt )
				),
				array_slice( $missing_alt, 0, self::WARNING_SAMPLE_CAP )
			);
		}

		if ( $empty_src > 0 ) {
			$digest['warnings'][] = self::warning(
				'image_no_source',
				'error',
				sprintf(
					/* translators: %d: number of images with no source. */
					__( '%d image elements have no src at all, so nothing is shown. Usually an image widget whose media was never set.', 'karmcp' ),
					$empty_src
				)
			);
		}
	}

	/**
	 * Links, with the two failures that make a page look finished and not be:
	 * a button that goes nowhere, and a link with nothing to read.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 */
	private static function collect_links( DOMXPath $xpath, array &$digest ): void {
		$placeholder = array();
		$no_label    = array();

		foreach ( $xpath->query( '//a' ) as $node ) {
			$href = self::attr( $node, 'href' );
			$text = self::node_text( $node );

			$label = '' !== $text
				? $text
				: ( self::attr( $node, 'aria-label' ) ?: self::attr( $node, 'title' ) );

			if ( '' === $label ) {
				// An icon link is fine as long as the icon itself is labelled.
				foreach ( $xpath->query( './/img|.//svg', $node ) as $child ) {
					$candidate = self::attr( $child, 'alt' ) ?: self::attr( $child, 'aria-label' );
					if ( '' !== $candidate ) {
						$label = $candidate;
						break;
					}
				}
			}

			$digest['links'][] = array(
				'href'   => self::truncate( $href, 300 ),
				'text'   => self::truncate( $label, 160 ),
				'target' => self::attr( $node, 'target' ),
				'rel'    => self::attr( $node, 'rel' ),
			);

			if ( in_array( strtolower( trim( $href ) ), self::PLACEHOLDER_HREFS, true ) ) {
				$placeholder[] = self::truncate( '' !== $label ? $label : '(no text)', 120 );
			}
			if ( '' === $label ) {
				$no_label[] = self::truncate( '' !== $href ? $href : '(no href)', 120 );
			}
		}

		$digest['counts']['links'] = count( $digest['links'] );

		if ( ! empty( $placeholder ) ) {
			$digest['warnings'][] = self::warning(
				'link_placeholder',
				'warning',
				sprintf(
					/* translators: %d: number of links with a placeholder href. */
					__( '%d links still point at a placeholder href such as "#". They look like working buttons and go nowhere.', 'karmcp' ),
					count( $placeholder )
				),
				array_slice( $placeholder, 0, self::WARNING_SAMPLE_CAP )
			);
		}

		if ( ! empty( $no_label ) ) {
			$digest['warnings'][] = self::warning(
				'link_no_label',
				'warning',
				sprintf(
					/* translators: %d: number of links with no accessible name. */
					__( '%d links have no text, no aria-label and no labelled icon, so they are unreadable to a screen reader.', 'karmcp' ),
					count( $no_label )
				),
				array_slice( $no_label, 0, self::WARNING_SAMPLE_CAP )
			);
		}
	}

	/**
	 * Forms: how many, where they post, and whether they can be submitted.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 */
	private static function collect_forms( DOMXPath $xpath, array &$digest ): void {
		foreach ( $xpath->query( '//form' ) as $node ) {
			$fields    = $xpath->query( './/input|.//textarea|.//select', $node )->length;
			$submits   = $xpath->query( './/button[not(@type) or @type="submit"]|.//input[@type="submit"]', $node )->length;
			$unlabeled = 0;

			foreach ( $xpath->query( './/input[not(@type="hidden") and not(@type="submit")]|.//textarea|.//select', $node ) as $field ) {
				$id       = self::attr( $field, 'id' );
				$labelled = '' !== self::attr( $field, 'aria-label' )
					|| '' !== self::attr( $field, 'placeholder' )
					|| ( '' !== $id && $xpath->query( sprintf( '//label[@for="%s"]', $id ) )->length > 0 );
				if ( ! $labelled ) {
					++$unlabeled;
				}
			}

			$digest['forms'][] = array(
				'action'           => self::truncate( self::attr( $node, 'action' ), 300 ),
				'method'           => strtolower( self::attr( $node, 'method' ) ?: 'get' ),
				'fields'           => $fields,
				'has_submit'       => $submits > 0,
				'unlabeled_fields' => $unlabeled,
			);

			if ( 0 === $submits ) {
				$digest['warnings'][] = self::warning(
					'form_no_submit',
					'error',
					__( 'A form on this page has no submit control, so it cannot be sent.', 'karmcp' )
				);
			}
			if ( $unlabeled > 0 ) {
				$digest['warnings'][] = self::warning(
					'form_unlabeled_fields',
					'warning',
					sprintf(
						/* translators: %d: number of unlabelled form fields. */
						__( '%d form fields have no label, no aria-label and no placeholder.', 'karmcp' ),
						$unlabeled
					)
				);
			}
		}

		$digest['counts']['forms'] = count( $digest['forms'] );
	}

	/**
	 * Landmark counts. Cheap, and the fastest way to spot a page assembled out
	 * of bare divs.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 */
	private static function collect_landmarks( DOMXPath $xpath, array &$digest ): void {
		foreach ( array( 'header', 'nav', 'main', 'footer', 'aside', 'section', 'article' ) as $tag ) {
			$digest['landmarks'][ $tag ] = $xpath->query( '//' . $tag )->length;
		}
	}

	/**
	 * Containers that render to nothing.
	 *
	 * This is the single most common artefact of machine-built pages: a column
	 * or section gets created and then never filled, and it survives every check
	 * that only reads the builder JSON, because in the JSON it looks fine.
	 *
	 * A container counts as empty only when it has no visible text *and* no
	 * substantive descendant *and* no background image, so decorative spacers
	 * with a background do not get flagged.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 */
	private static function collect_empty_containers( DOMXPath $xpath, array &$digest ): void {
		$found = array();

		// One query over every container tag, so the results arrive in document
		// order and the outermost of a nested set is always seen first. Querying
		// tag by tag would report an inner div before its enclosing section, and
		// the outer-wins rule below would then never fire.
		$containers = $xpath->query( '//' . implode( '|//', self::CONTAINER_TAGS ) );

		foreach ( $containers as $node ) {
			if ( '' !== self::node_text( $node ) ) {
				continue;
			}
			if ( $xpath->query( './/' . implode( '|.//', self::SUBSTANTIVE_TAGS ), $node )->length > 0 ) {
				continue;
			}
			$style = self::attr( $node, 'style' );
			if ( false !== stripos( $style, 'background' ) ) {
				continue;
			}
			// A container whose only child is another empty container is the
			// same finding reported twice; keep the outermost.
			if ( self::has_ancestor_in( $node, $found ) ) {
				continue;
			}

			$found[] = $node;

			$digest['empty_containers'][] = array(
				'tag'   => strtolower( $node->nodeName ),
				'class' => self::truncate( self::attr( $node, 'class' ), 160 ),
				'id'    => self::attr( $node, 'id' ),
			);
		}

		if ( ! empty( $digest['empty_containers'] ) ) {
			$digest['warnings'][] = self::warning(
				'empty_container',
				'warning',
				sprintf(
					/* translators: %d: number of empty containers. */
					__( '%d containers render completely empty. In the builder they look like real sections; on the page they are blank space.', 'karmcp' ),
					count( $digest['empty_containers'] )
				),
				array_slice(
					array_map(
						static function ( $container ) {
							return trim( $container['tag'] . ' ' . $container['class'] );
						},
						$digest['empty_containers']
					),
					0,
					self::WARNING_SAMPLE_CAP
				)
			);
		}
	}

	/**
	 * Duplicate DOM ids. They break anchor links, label/for pairs and any
	 * scripted behaviour that queries by id, and duplicating a section is
	 * exactly how an agent creates them.
	 *
	 * @param DOMXPath $xpath  Query engine.
	 * @param array    $digest Digest, by reference.
	 */
	private static function collect_duplicate_ids( DOMXPath $xpath, array &$digest ): void {
		$seen = array();
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$id = self::attr( $node, 'id' );
			if ( '' === $id ) {
				continue;
			}
			$seen[ $id ] = ( $seen[ $id ] ?? 0 ) + 1;
		}

		$duplicates = array_keys(
			array_filter(
				$seen,
				static function ( $count ) {
					return $count > 1;
				}
			)
		);

		if ( ! empty( $duplicates ) ) {
			$digest['warnings'][] = self::warning(
				'duplicate_id',
				'warning',
				sprintf(
					/* translators: %d: number of duplicated DOM ids. */
					__( '%d DOM ids appear more than once. Anchor links and label/for pairs resolve to the first one only.', 'karmcp' ),
					count( $duplicates )
				),
				array_slice( $duplicates, 0, self::WARNING_SAMPLE_CAP )
			);
		}
	}

	/**
	 * Things that should have been replaced before the page reached a visitor:
	 * shortcodes that never resolved, template placeholders, filler copy.
	 *
	 * @param string $text   Visible text.
	 * @param array  $digest Digest, by reference.
	 */
	private static function collect_text_leftovers( string $text, array &$digest ): void {
		if ( preg_match_all( '/\[\/?([a-z][a-z0-9_-]{2,})(?:\s[^\]]*)?\]/i', $text, $matches ) ) {
			$tags = array_values( array_unique( $matches[1] ) );
			$digest['warnings'][] = self::warning(
				'unresolved_shortcode',
				'warning',
				__( 'Shortcode-looking text survived rendering, so it is being shown to visitors literally. Usually the plugin that owns it is inactive.', 'karmcp' ),
				array_slice( $tags, 0, self::WARNING_SAMPLE_CAP )
			);
		}

		if ( preg_match_all( '/\{\{\s*[a-z0-9_.\-]+\s*\}\}|%%[a-z0-9_]+%%/i', $text, $matches ) ) {
			$digest['warnings'][] = self::warning(
				'unrendered_placeholder',
				'warning',
				__( 'Template placeholders are visible in the page text.', 'karmcp' ),
				array_slice( array_values( array_unique( $matches[0] ) ), 0, self::WARNING_SAMPLE_CAP )
			);
		}

		if ( preg_match( '/lorem ipsum|dolor sit amet|texto de ejemplo/i', $text ) ) {
			$digest['warnings'][] = self::warning(
				'placeholder_text',
				'info',
				__( 'The page still contains filler copy.', 'karmcp' )
			);
		}
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * The shape every digest has, filled or not. Callers can rely on every key
	 * existing, which keeps the tool contract stable on an empty page.
	 *
	 * @param string $scope Render scope.
	 * @return array
	 */
	private static function empty_digest( string $scope ): array {
		return array(
			'render'           => array( 'scope' => $scope ),
			'document'         => array(),
			'headings'         => array(),
			'images'           => array(),
			'links'            => array(),
			'forms'            => array(),
			'landmarks'        => array(),
			'empty_containers' => array(),
			'text'             => array(
				'words'   => 0,
				'excerpt' => '',
			),
			'counts'           => array(
				'headings' => 0,
				'images'   => 0,
				'links'    => 0,
				'forms'    => 0,
				'iframes'  => 0,
				'scripts'  => 0,
			),
			'warnings'         => array(),
			'truncated'        => false,
		);
	}

	/**
	 * Builds one warning entry.
	 *
	 * @param string $code     Machine code.
	 * @param string $severity error|warning|info.
	 * @param string $message  Human sentence.
	 * @param array  $examples Sample offenders.
	 * @return array
	 */
	private static function warning( string $code, string $severity, string $message, array $examples = array() ): array {
		$warning = array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);
		if ( ! empty( $examples ) ) {
			$warning['examples'] = array_values( $examples );
		}
		return $warning;
	}

	/**
	 * Parses HTML into a DOM, tolerating the malformed markup real themes emit.
	 *
	 * A fragment gets wrapped in a minimal document with an explicit UTF-8 meta
	 * tag; without it libxml assumes ISO-8859-1 and mangles every accent, which
	 * on a Spanish-language site means every other heading.
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|null Null when the DOM extension is missing.
	 */
	private static function to_dom( string $html ): ?DOMDocument {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		if ( ! preg_match( '/<html[\s>]/i', $html ) ) {
			$html = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
				. $html . '</body></html>';
		} elseif ( ! preg_match( '/charset\s*=/i', $html ) ) {
			$html = preg_replace(
				'/<head(\s[^>]*)?>/i',
				'$0<meta http-equiv="Content-Type" content="text/html; charset=utf-8">',
				$html,
				1
			);
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Text of a node with whitespace collapsed.
	 *
	 * Deliberately does no stripping: it runs once per heading, link, image and
	 * container, and copying subtrees to remove scripts would turn a linear pass
	 * over the page into a quadratic one. A container holding only a script is
	 * not empty anyway, so the naive reading is also the right one here.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function node_text( DOMNode $node ): string {
		return self::normalize_space( (string) $node->textContent );
	}

	/**
	 * Reader-visible text of a subtree: script, style, template and noscript
	 * removed first. Used once, for the page-level excerpt and the leftover
	 * scan, where inline JS would otherwise read as page copy.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function visible_text( DOMNode $node ): string {
		$element = $node instanceof DOMDocument ? $node->documentElement : $node;
		if ( ! $element instanceof DOMElement ) {
			return self::node_text( $node );
		}

		$doc = new DOMDocument();
		$doc->appendChild( $doc->importNode( $element, true ) );

		$xpath = new DOMXPath( $doc );
		foreach ( iterator_to_array( $xpath->query( '//script|//style|//template|//noscript' ) ) as $strip ) {
			if ( $strip->parentNode ) {
				$strip->parentNode->removeChild( $strip );
			}
		}

		return self::normalize_space( (string) $doc->textContent );
	}

	/**
	 * Collapses every run of whitespace — non-breaking spaces included — to one
	 * plain space.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_space( string $text ): string {
		$text = preg_replace( '/\x{00A0}/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Whether any node in the list is an ancestor of this one.
	 *
	 * @param DOMNode   $node      Candidate.
	 * @param DOMNode[] $ancestors Already-reported nodes.
	 * @return bool
	 */
	private static function has_ancestor_in( DOMNode $node, array $ancestors ): bool {
		for ( $parent = $node->parentNode; $parent instanceof DOMNode; $parent = $parent->parentNode ) {
			foreach ( $ancestors as $candidate ) {
				if ( $candidate === $parent ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Attribute value, or an empty string.
	 *
	 * @param DOMNode $node Node.
	 * @param string  $name Attribute name.
	 * @return string
	 */
	private static function attr( DOMNode $node, string $name ): string {
		if ( ! $node instanceof DOMElement ) {
			return '';
		}
		return trim( $node->getAttribute( $name ) );
	}

	/**
	 * Truncates on character count, appending an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Characters.
	 * @return string
	 */
	private static function truncate( string $text, int $limit ): string {
		if ( $limit <= 0 || '' === $text ) {
			return '';
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $length <= $limit ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
		return $cut . '…';
	}

	/**
	 * Truncates on byte count without splitting a multibyte character.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Bytes.
	 * @return string
	 */
	private static function truncate_bytes( string $text, int $limit ): string {
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = substr( $text, 0, $limit );
		// Drop a trailing partial UTF-8 sequence.
		while ( '' !== $cut && ( ord( $cut[ strlen( $cut ) - 1 ] ) & 0xC0 ) === 0x80 ) {
			$cut = substr( $cut, 0, -1 );
		}
		return substr( $cut, 0, -1 );
	}
}
