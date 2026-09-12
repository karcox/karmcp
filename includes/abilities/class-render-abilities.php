<?php
/**
 * render-page — what the visitor actually gets.
 *
 * Every other read tool describes the page as the builder stores it. This one
 * describes it as it renders, which is the only version that can disagree with
 * what the agent believed it wrote.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the read-only `render-page` ability.
 *
 * @since 1.1.0
 */
class KarMCP_Render_Abilities {

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/render-page' );
	}

	/**
	 * Read permission callback.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the ability with the WordPress Abilities API.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/render-page',
			array(
				'label'               => __( 'Render Page', 'karmcp' ),
				'description'         => __( 'Renders a page the way a visitor gets it and returns a digest of the result: heading outline, links, images, forms, landmarks, visible text, and warnings for what commonly goes wrong (containers that render empty, images with no alt, links still pointing at "#", shortcodes that never resolved, duplicate ids). Call this after building or editing a page to check your work: every other read tool shows you the builder data you just wrote, this one shows you the output. scope "content" (default) renders the post\'s own content and works on drafts; scope "full" fetches the whole themed page over a loopback request and needs the post published. Pass `url` instead of post_id for anything that is not a single post — an archive, a search result, a paginated page — or omit both to render the front page; either way it must be a URL on this site. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'The page/post ID to render. Omit it, with no url either, to render the front page.', 'karmcp' ),
						),
						'url'           => array(
							'type'        => 'string',
							'description' => __( 'A URL on this site to render instead of a post: an archive, a search result, a paginated page, anything that is not a single post. Must be this site — the tool refuses to fetch anywhere else. Mutually exclusive with post_id.', 'karmcp' ),
						),
						'html_offset'   => array(
							'type'        => 'integer',
							'description' => __( 'Byte offset to resume include_html from, for a page larger than the 200 KB cap. Use the next_offset the previous call returned, and check its checksum first: a page that changed in between cannot be spliced.', 'karmcp' ),
						),
						'scope'         => array(
							'type'        => 'string',
							'enum'        => array( 'content', 'full' ),
							'description' => __( 'content: the post\'s own rendered content, drafts included (default). full: the complete themed page including header and footer, published posts only.', 'karmcp' ),
						),
						'include_html'  => array(
							'type'        => 'boolean',
							'description' => __( 'Also return the rendered HTML, capped at 200 KB per call. Off by default: the digest is normally what you want, and raw markup is large. When the page is bigger than the cap the response says so in html_chunk, with the offset to resume from.', 'karmcp' ),
						),
						'excerpt_chars' => array(
							'type'        => 'integer',
							'description' => __( 'How many characters of visible page text to return. Default 600, 0 for none.', 'karmcp' ),
						),
						'query_args'    => array(
							'type'        => 'object',
							'description' => __( 'Extra query arguments for the scope "full" loopback request, e.g. {"preview_key":"abc"}. The loopback carries no session, so a site behind an access wall answers it with its login page; pass whatever key that site accepts to get the real page. Ignored for scope "content", which needs no request.', 'karmcp' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Execute callback.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute( array $input ) {
		if ( ! class_exists( 'KarMCP_Content_Extractor' ) ) {
			return new WP_Error( 'unavailable', __( 'The content extractor is not available on this install.', 'karmcp' ) );
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$url     = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';

		if ( $post_id > 0 && '' !== $url ) {
			return new WP_Error(
				'ambiguous_target',
				__( 'Pass post_id or url, not both: they would name different pages and only one can be rendered.', 'karmcp' )
			);
		}

		$common = array(
			'include_html'  => ! empty( $input['include_html'] ),
			'excerpt_chars' => isset( $input['excerpt_chars'] ) ? (int) $input['excerpt_chars'] : 600,
			'html_offset'   => isset( $input['html_offset'] ) ? (int) $input['html_offset'] : 0,
		);

		// No post_id is not an error any more: it means the front page, which on
		// most sites is a query rather than a post and so had no way to be
		// asked for at all.
		if ( $post_id <= 0 ) {
			return KarMCP_Content_Extractor::extract_url( $url, $common );
		}

		// Rendering a draft shows unpublished content, so it takes the same
		// permission as editing the post, not the generic edit_posts of the
		// registration gate. The URL path above needs no such check: the
		// loopback carries no session, so it sees what an anonymous visitor sees.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to view this post.', 'karmcp' ) );
		}

		return KarMCP_Content_Extractor::extract(
			$post_id,
			array_merge(
				$common,
				array(
					'scope'      => isset( $input['scope'] ) ? (string) $input['scope'] : 'content',
					'query_args' => isset( $input['query_args'] ) ? (array) $input['query_args'] : array(),
				)
			)
		);
	}
}
