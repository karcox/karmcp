<?php
/**
 * SEO audit MCP ability.
 *
 * Renders a post, reduces it to the shared digest, reads whatever the active
 * SEO plugin has stored, and grades the two together. Read-only.
 *
 * The point of pairing the two halves: the stored metadata is intent and the
 * rendered page is result, and the gap between them is where the findings that
 * matter live — a title template that expands to nothing, a description the
 * theme never emits, a noindex nobody meant to leave on.
 *
 * @package KarMCP
 * @since   1.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements `audit-page-seo`.
 *
 * @since 1.14.0
 */
class KarMCP_Seo_Audit_Abilities {

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/audit-page-seo' );
	}

	/**
	 * Read permission callback.
	 *
	 * The per-post check happens in execute(), where the id is known.
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
			'karmcp/audit-page-seo',
			array(
				'label'               => __( 'Audit Page SEO', 'karmcp' ),
				'description'         => __( 'Audits one page for SEO and returns scored findings with a recommendation on each. Renders the page the way a visitor gets it and grades that against the metadata the active SEO plugin (Yoast, Rank Math, Slim SEO) has stored: title and description presence and length, H1 and heading outline, content depth, image alt text, placeholder links, indexability, canonical, focus keyword placement. scope:"content" works on drafts; scope:"full" fetches the served page and is the only one that can judge the real title, canonical and lang. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Target post/page ID.', 'karmcp' ),
						),
						'scope'   => array(
							'type'        => 'string',
							'enum'        => array( 'content', 'full' ),
							'description' => __( 'content = builder output only, works on drafts. full = the served page over a loopback request, required to judge title, canonical and lang. Default: content.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post'        => array( 'type' => 'object' ),
						'seo_plugin'  => array( 'type' => 'object' ),
						'score'       => array( 'type' => 'integer' ),
						'grade'       => array( 'type' => 'string' ),
						'scope'       => array( 'type' => 'string' ),
						'counts'      => array( 'type' => 'object' ),
						'title'       => array( 'type' => 'object' ),
						'description' => array( 'type' => 'object' ),
						'findings'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Fills the `seo` section of `get-page-snapshot`.
	 *
	 * The snapshot is already a large payload, so this is the summary and the
	 * findings that need acting on — the passes are what `audit-page-seo`
	 * itself is for.
	 *
	 * Scope is `content` here on purpose: a snapshot is built for one post, and
	 * a loopback request per snapshot is too expensive to do implicitly.
	 */
	public static function register_snapshot_section(): void {
		add_filter(
			'karmcp_page_snapshot_sections',
			static function ( $sections, $post_id, $include, $args ) {
				unset( $args );

				if ( ! is_array( $sections ) || ! in_array( 'seo', (array) $include, true ) ) {
					return $sections;
				}

				if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
					$sections['seo'] = array(
						'available' => false,
						'reason'    => 'forbidden',
					);
					return $sections;
				}

				$digest = KarMCP_Content_Extractor::extract(
					(int) $post_id,
					array(
						'scope'         => 'content',
						'excerpt_chars' => 0,
					)
				);

				if ( is_wp_error( $digest ) ) {
					$sections['seo'] = array(
						'available' => false,
						'reason'    => $digest->get_error_code(),
					);
					return $sections;
				}

				$post = get_post( (int) $post_id );
				$seo  = KarMCP_Seo_Meta::get( (int) $post_id );

				$report = KarMCP_Seo_Audit::run(
					$digest,
					array(
						'scope' => 'content',
						'seo'   => $seo,
						'post'  => array(
							'title'  => get_the_title( (int) $post_id ),
							'status' => $post ? $post->post_status : '',
						),
					)
				);

				$actionable = array_values(
					array_filter(
						$report['findings'],
						static function ( $finding ) {
							return in_array( $finding['status'], array( 'critical', 'warning' ), true );
						}
					)
				);

				$sections['seo'] = array(
					'available'  => true,
					'scope'      => 'content',
					'seo_plugin' => $seo['source'],
					'score'      => $report['score'],
					'grade'      => $report['grade'],
					'counts'     => $report['counts'],
					'findings'   => $actionable,
					'note'       => __( 'Content scope. Run audit-page-seo with scope "full" to judge the served title, canonical and lang.', 'karmcp' ),
				);

				return $sections;
			},
			10,
			4
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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to audit this post.', 'karmcp' ) );
		}

		$scope = ( isset( $input['scope'] ) && 'full' === $input['scope'] ) ? 'full' : 'content';

		$digest = KarMCP_Content_Extractor::extract(
			$post_id,
			array(
				'scope'         => $scope,
				'excerpt_chars' => 0,
			)
		);

		if ( is_wp_error( $digest ) ) {
			return $digest;
		}

		$seo = KarMCP_Seo_Meta::get( $post_id );

		$report = KarMCP_Seo_Audit::run(
			$digest,
			array(
				'scope' => $scope,
				'seo'   => $seo,
				'post'  => array(
					'title'  => get_the_title( $post_id ),
					'url'    => get_permalink( $post_id ),
					'type'   => $post->post_type,
					'status' => $post->post_status,
				),
			)
		);

		return array(
			'post'        => array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'url'    => get_permalink( $post_id ),
				'type'   => $post->post_type,
				'status' => $post->post_status,
			),
			'seo_plugin'  => array(
				'source'   => $seo['source'],
				'label'    => $seo['source_label'],
				'readable' => $seo['readable'],
				'others'   => $seo['others'],
			),
			'score'       => $report['score'],
			'grade'       => $report['grade'],
			'scope'       => $report['scope'],
			'counts'      => $report['counts'],
			'title'       => $report['title'],
			'description' => $report['description'],
			'findings'    => $report['findings'],
		);
	}
}
