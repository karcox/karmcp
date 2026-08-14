<?php
/**
 * duplicate-post — copy a post with everything attached to it.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements `duplicate-post`.
 *
 * @since 1.1.0
 */
class KarMCP_Duplicate_Abilities {

	/**
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/duplicate-post' );
	}

	/**
	 * Coarse gate. The real check is per-post, in execute().
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function check_edit_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the ability.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/duplicate-post',
			array(
				'label'               => __( 'Duplicate Post', 'karmcp' ),
				'description'         => __( 'Copies a post, page or any custom post type with its content, its post meta and its taxonomy terms, as a draft. Use this when a post type belongs to another plugin (popups, listings, forms, most builder types): those keep their configuration in protected meta that create-post will not write, so create-post gives you an empty shell of the right type while this gives you a working copy to edit. Also the right way to start a translation. Copies the featured image, and deliberately does not copy the SKU or the edit lock. Requires confirm:true.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post to copy.', 'karmcp' ),
						),
						'title'      => array(
							'type'        => 'string',
							'description' => __( 'Title for the copy. Defaults to the original with "(copy)" appended.', 'karmcp' ),
						),
						'slug'       => array(
							'type'        => 'string',
							'description' => __( 'Slug for the copy. WordPress derives one from the title when omitted.', 'karmcp' ),
						),
						'status'     => array(
							'type'        => 'string',
							'description' => __( 'Status for the copy. Default draft.', 'karmcp' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'description' => __( 'Parent post id for the copy.', 'karmcp' ),
						),
						'copy_meta'  => array(
							'type'        => 'boolean',
							'description' => __( 'Copy post meta. Default true, and the reason to use this tool.', 'karmcp' ),
						),
						'copy_terms' => array(
							'type'        => 'boolean',
							'description' => __( 'Copy taxonomy terms. Default true.', 'karmcp' ),
						),
						'confirm'    => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true. Duplicating writes protected meta, so it is gated behind an explicit confirmation.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'confirm' ),
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
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: post_id.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$source = get_post( $post_id );
		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}

		// The copy carries the source's protected meta verbatim, so the gate is
		// the right to edit the source — anyone who can read that state already.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'karmcp' ), array( 'status' => 403 ) );
		}

		$post_type = get_post_type_object( $source->post_type );
		if ( $post_type && ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'forbidden',
				sprintf(
					/* translators: %s: post type label. */
					__( 'You are not allowed to create a %s.', 'karmcp' ),
					$source->post_type
				),
				array( 'status' => 403 )
			);
		}

		if ( ! isset( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Duplicating copies protected meta from the source post. Pass confirm:true to proceed.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'KarMCP_Post_Duplicator' ) ) {
			return new WP_Error( 'unavailable', __( 'The post duplicator is not available on this install.', 'karmcp' ), array( 'status' => 500 ) );
		}

		$new_id = KarMCP_Post_Duplicator::duplicate(
			$post_id,
			array(
				'title'      => isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '',
				'slug'       => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
				'status'     => isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft',
				'parent'     => isset( $input['parent'] ) ? absint( $input['parent'] ) : 0,
				'copy_meta'  => ! isset( $input['copy_meta'] ) || (bool) $input['copy_meta'],
				'copy_terms' => ! isset( $input['copy_terms'] ) || (bool) $input['copy_terms'],
			)
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		return array(
			'duplicated' => true,
			'post_id'    => $new_id,
			'source_id'  => $post_id,
			'post_type'  => $source->post_type,
			'title'      => get_the_title( $new_id ),
			'status'     => get_post_status( $new_id ),
			'edit_url'   => admin_url( 'post.php?post=' . $new_id . '&action=edit' ),
		);
	}
}
