<?php
/**
 * get-post-schema / set-post-schema — Schema.org JSON-LD on a post.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the structured-data abilities.
 *
 * @since 1.1.0
 */
class KarMCP_Structured_Data_Abilities {

	/**
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/get-post-schema', 'karmcp/set-post-schema' );
	}

	/**
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function check_edit_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers both abilities.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/get-post-schema',
			array(
				'label'               => __( 'Get Post Schema', 'karmcp' ),
				'description'         => __( 'Returns the Schema.org JSON-LD attached to a post, and tells you whether an SEO plugin on this site is already emitting its own structured data. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
			)
		);

		karmcp_register_ability(
			'karmcp/set-post-schema',
			array(
				'label'               => __( 'Set Post Schema', 'karmcp' ),
				'description'         => __( 'Attaches Schema.org JSON-LD to a post, printed in the page head. Send nodes:[{ type, data }] — supported types are Organization, LocalBusiness, Product, FAQPage, BreadcrumbList, Article, Person, Service and Event. Two shapes are made easy: FAQPage takes data.faqs:[{question,answer}] and BreadcrumbList takes data.items:[{name,url}], both expanded into the nested structure Google expects. Every node is validated before it is stored, because an invalid one is silently ignored by search engines rather than reported. Passing an empty nodes array removes the markup.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_set' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'karmcp' ),
						),
						'nodes'   => array(
							'type'        => 'array',
							'description' => __( 'The JSON-LD nodes to attach. Replaces whatever is there. An empty array clears it.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'type' => array(
										'type' => 'string',
										'enum' => array_keys( KarMCP_Structured_Data::TYPES ),
									),
									'data' => array(
										'type'        => 'object',
										'description' => __( 'Schema.org properties for the node.', 'karmcp' ),
									),
								),
							),
						),
					),
					'required'   => array( 'post_id', 'nodes' ),
				),
			)
		);
	}

	/**
	 * @since 1.1.0
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_get( $input ) {
		$post_id = $this->post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$nodes = KarMCP_Structured_Data::get( $post_id );

		return array(
			'post_id'   => $post_id,
			'nodes'     => $nodes,
			'count'     => count( $nodes ),
			'types'     => array_values( array_filter( array_column( $nodes, '@type' ) ) ),
			'html'      => KarMCP_Structured_Data::render( $nodes ),
			'seo_plugins_emitting_their_own' => KarMCP_Structured_Data::competing_emitters(),
		);
	}

	/**
	 * @since 1.1.0
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_set( $input ) {
		$post_id = $this->post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'karmcp' ), array( 'status' => 403 ) );
		}

		$input = is_array( $input ) ? $input : array();
		if ( ! isset( $input['nodes'] ) || ! is_array( $input['nodes'] ) ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: nodes (array).', 'karmcp' ), array( 'status' => 400 ) );
		}

		$built = array();
		foreach ( array_values( $input['nodes'] ) as $index => $node ) {
			if ( ! is_array( $node ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: %d: node position. */
						__( 'Node %d is not an object.', 'karmcp' ),
						$index + 1
					),
					array( 'status' => 400 )
				);
			}

			$type = (string) ( $node['type'] ?? $node['@type'] ?? '' );
			$data = ( isset( $node['data'] ) && is_array( $node['data'] ) ) ? $node['data'] : $node;
			unset( $data['type'] );

			$result = KarMCP_Structured_Data::build( $type, $data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$built[] = $result;
		}

		KarMCP_Structured_Data::save( $post_id, $built );

		$response = array(
			'updated' => true,
			'post_id' => $post_id,
			'count'   => count( $built ),
			'types'   => array_column( $built, '@type' ),
			'html'    => KarMCP_Structured_Data::render( $built ),
		);

		$emitters = KarMCP_Structured_Data::competing_emitters();
		if ( ! empty( $emitters ) && ! empty( $built ) ) {
			$response['note'] = sprintf(
				/* translators: %s: comma-separated plugin names. */
				__( '%s is also emitting structured data on this site. Duplicate nodes of the same type on one page make a search engine pick between them, so check the page with a rich-results test if you added Organization, Product or Article.', 'karmcp' ),
				implode( ', ', $emitters )
			);
		}

		return $response;
	}

	/**
	 * Reads and checks the post id.
	 *
	 * @param mixed $input Tool input.
	 * @return int|WP_Error
	 */
	private function post_id( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: post_id.', 'karmcp' ), array( 'status' => 400 ) );
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}

		return $post_id;
	}
}
