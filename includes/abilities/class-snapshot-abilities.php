<?php
/**
 * Page Snapshot MCP ability — one normalized page digest.
 *
 * @package KarMCP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the read-only `get-page-snapshot` ability.
 *
 * @since 3.3.0
 */
class KarMCP_Snapshot_Abilities {

	/**
	 * The data access layer.
	 *
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param KarMCP_Data $data The data access layer.
	 */
	public function __construct( KarMCP_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/get-page-snapshot' );
	}

	/**
	 * Read permission callback.
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the ability with the WordPress Abilities API.
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/get-page-snapshot',
			array(
				'label'               => __( 'Get Page Snapshot', 'karmcp' ),
				'description'         => __( 'Returns ONE normalized digest of a page: structure tree + counts, global colors/typography/classes actually in use, per-device responsive overrides, content outline, and an SEO-lite summary, so you can reason about a page from a single call instead of chaining get-page-structure/get-global-settings/list-global-classes. Pass include:[performance,seo] for heavy audit summaries. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Target post/page ID.', 'karmcp' ),
						),
						'include'  => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'performance', 'a11y', 'seo' ),
							),
							'description' => __( 'Heavy opt-in sections. performance needs manage_options; seo returns the scored SEO audit for this page; a11y is not implemented yet and returns an unavailable stub.', 'karmcp' ),
						),
						'sections' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'post', 'structure', 'tokens', 'responsive', 'content', 'seo_lite', 'warnings' ),
							),
							'description' => __( 'Subset which core sections to return. Default: all.', 'karmcp' ),
						),
						'fresh'    => array(
							'type'        => 'boolean',
							'description' => __( 'Bypass the heavy-section cache.', 'karmcp' ),
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
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', __( 'A valid post_id is required.', 'karmcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ) );
		}

		$is_elementor = ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) );
		$builder      = self::detect_builder( $is_elementor, (string) $post->post_content );

		$args = array(
			'builder'  => $builder,
			'post'     => array(
				'id'                => $post_id,
				'title'             => get_the_title( $post_id ),
				'slug'              => $post->post_name,
				'status'            => $post->post_status,
				'type'              => $post->post_type,
				'url'               => get_permalink( $post_id ),
				'builder'           => $builder,
				'is_elementor'      => $is_elementor,
				'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			),
			'sections' => ( isset( $input['sections'] ) && is_array( $input['sections'] ) ) ? array_map( 'strval', $input['sections'] ) : null,
			'include'  => ( isset( $input['include'] ) && is_array( $input['include'] ) ) ? array_map( 'strval', $input['include'] ) : null,
			'fresh'    => ! empty( $input['fresh'] ),
		);

		$builder_obj = new KarMCP_Page_Snapshot( $this->data );
		return $builder_obj->build( $post_id, array_filter( $args, static fn( $v ) => null !== $v ) );
	}

	/**
	 * Detect which builder owns a post's content.
	 *
	 * @param bool   $is_elementor Whether Elementor edit-mode is set.
	 * @param string $content      Raw post content.
	 * @return string elementor|gutenberg|classic
	 */
	public static function detect_builder( bool $is_elementor, string $content ): string {
		if ( $is_elementor ) {
			return 'elementor';
		}
		$has_blocks = function_exists( 'has_blocks' ) ? has_blocks( $content ) : ( false !== strpos( $content, '<!-- wp:' ) );
		if ( $has_blocks ) {
			return 'gutenberg';
		}
		return 'classic';
	}
}
