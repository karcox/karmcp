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
				'description'         => __( 'Renders a page the way a visitor gets it and returns a digest of the result: heading outline, links, images, forms, landmarks, visible text, and warnings for what commonly goes wrong (containers that render empty, images with no alt, links still pointing at "#", shortcodes that never resolved, duplicate ids). Call this after building or editing a page to check your work: every other read tool shows you the builder data you just wrote, this one shows you the output. scope "content" (default) renders the post\'s own content and works on drafts; scope "full" fetches the whole themed page over a loopback request and needs the post published. Read-only.', 'karmcp' ),
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
							'description' => __( 'The page/post ID to render.', 'karmcp' ),
						),
						'scope'         => array(
							'type'        => 'string',
							'enum'        => array( 'content', 'full' ),
							'description' => __( 'content: the post\'s own rendered content, drafts included (default). full: the complete themed page including header and footer, published posts only.', 'karmcp' ),
						),
						'include_html'  => array(
							'type'        => 'boolean',
							'description' => __( 'Also return the rendered HTML, capped at 200 KB. Off by default: the digest is normally what you want, and raw markup is large.', 'karmcp' ),
						),
						'excerpt_chars' => array(
							'type'        => 'integer',
							'description' => __( 'How many characters of visible page text to return. Default 600, 0 for none.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id' ),
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', __( 'A valid post_id is required.', 'karmcp' ) );
		}

		// Rendering a draft shows unpublished content, so it takes the same
		// permission as editing the post, not the generic edit_posts of the
		// registration gate.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to view this post.', 'karmcp' ) );
		}

		if ( ! class_exists( 'KarMCP_Content_Extractor' ) ) {
			return new WP_Error( 'unavailable', __( 'The content extractor is not available on this install.', 'karmcp' ) );
		}

		return KarMCP_Content_Extractor::extract(
			$post_id,
			array(
				'scope'         => isset( $input['scope'] ) ? (string) $input['scope'] : 'content',
				'include_html'  => ! empty( $input['include_html'] ),
				'excerpt_chars' => isset( $input['excerpt_chars'] ) ? (int) $input['excerpt_chars'] : 600,
			)
		);
	}
}
