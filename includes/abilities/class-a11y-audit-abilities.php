<?php
/**
 * Accessibility audit MCP ability.
 *
 * Renders a post, reduces it to the shared digest, and runs the WCAG rule set
 * over it. Read-only.
 *
 * @package KarMCP
 * @since   1.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements `audit-page-a11y`.
 *
 * @since 1.16.0
 */
class KarMCP_A11y_Audit_Abilities {

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/audit-page-a11y' );
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
			'karmcp/audit-page-a11y',
			array(
				'label'               => __( 'Audit Page Accessibility', 'karmcp' ),
				'description'         => __( 'Audits one page against WCAG and returns scored findings with a recommendation on each: image alt text, link and form-field names, heading outline, declared language, page title, landmarks, duplicate ids, and text contrast for any text that declares its colour inline. scope:"content" works on drafts; scope:"full" fetches the served page and is the only one that can judge language, title and landmarks. Automated checks find a minority of WCAG failures — a clean report is a floor, not a certificate. Read-only.', 'karmcp' ),
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
							'description' => __( 'content = builder output only, works on drafts. full = the served page over a loopback request, required to judge language, title and landmarks. Default: content.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post'     => array( 'type' => 'object' ),
						'score'    => array( 'type' => 'integer' ),
						'grade'    => array( 'type' => 'string' ),
						'scope'    => array( 'type' => 'string' ),
						'counts'   => array( 'type' => 'object' ),
						'contrast' => array( 'type' => 'object' ),
						'coverage' => array( 'type' => 'string' ),
						'findings' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
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
	 * Fills the `a11y` section of `get-page-snapshot`, the other half of the
	 * seam that has been reserved since the beginning.
	 */
	public static function register_snapshot_section(): void {
		add_filter(
			'karmcp_page_snapshot_sections',
			static function ( $sections, $post_id, $include, $args ) {
				unset( $args );

				if ( ! is_array( $sections ) || ! in_array( 'a11y', (array) $include, true ) ) {
					return $sections;
				}

				if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
					$sections['a11y'] = array(
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
					$sections['a11y'] = array(
						'available' => false,
						'reason'    => $digest->get_error_code(),
					);
					return $sections;
				}

				$report = KarMCP_A11y_Audit::run( $digest, array( 'scope' => 'content' ) );

				$sections['a11y'] = array(
					'available' => true,
					'scope'     => 'content',
					'score'     => $report['score'],
					'grade'     => $report['grade'],
					'counts'    => $report['counts'],
					'findings'  => array_values(
						array_filter(
							$report['findings'],
							static function ( $finding ) {
								return in_array( $finding['status'], array( 'critical', 'warning' ), true );
							}
						)
					),
					'note'      => __( 'Content scope. Run audit-page-a11y with scope "full" to judge language, title and landmarks.', 'karmcp' ),
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

		$report = KarMCP_A11y_Audit::run( $digest, array( 'scope' => $scope ) );

		return array(
			'post'     => array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'url'    => get_permalink( $post_id ),
				'type'   => $post->post_type,
				'status' => $post->post_status,
			),
			'score'    => $report['score'],
			'grade'    => $report['grade'],
			'scope'    => $report['scope'],
			'counts'   => $report['counts'],
			'contrast' => $report['contrast'],
			'coverage' => __( 'Automated checks find a minority of WCAG failures. This sees missing alt text, not whether the text describes the image; a skipped heading level, not whether the headings mean anything. Reading order, focus order and keyboard traps need a browser and a person.', 'karmcp' ),
			'findings' => $report['findings'],
		);
	}
}
