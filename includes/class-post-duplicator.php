<?php
/**
 * Copies a post with everything attached to it.
 *
 * This exists because of a gap that only shows up on real sites: `create-post`
 * refuses to write protected meta, which is correct — an agent has no business
 * writing another plugin's privileged state — but plugin CPTs (JetPopup,
 * JetEngine, and most builders' own types) keep their entire configuration in
 * exactly that meta. The result was a tool set that could fill a container it
 * could not create: you got a `jet-popup` post that was not a popup.
 *
 * Copying an existing one sidesteps the whole problem. The meta being written
 * is not agent-authored, it is a byte-for-byte copy of state a human already
 * approved on the source post, so the guarantee `create-post` protects stays
 * intact.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicates a post with its meta and taxonomy terms.
 *
 * @since 1.1.0
 */
class KarMCP_Post_Duplicator {

	/**
	 * Meta keys never copied, whatever the source carries.
	 *
	 * Each of these is either about the source post specifically or actively
	 * harmful on a copy:
	 *
	 * - `_edit_lock` / `_edit_last` — who had the editor open. Copying a lock
	 *   makes the new post look like someone else is editing it.
	 * - `_wp_old_slug` — the redirect history of a URL the copy never had.
	 * - `_elementor_css` — the cached stylesheet, keyed to the source id.
	 *   Leaving it makes the copy render with the original's CSS until
	 *   something invalidates it.
	 * - `_sku` — WooCommerce requires SKUs to be unique, so a copied one makes
	 *   the product unsaveable in the editor.
	 * - `_thumbnail_id` is deliberately NOT here: sharing a featured image
	 *   between a post and its copy is normal and correct.
	 */
	const SKIP_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_elementor_css',
		'_elementor_inline_svg',
		'_sku',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
	);

	/**
	 * Meta key prefixes never copied.
	 */
	const SKIP_META_PREFIXES = array(
		'_oembed_',
	);

	/**
	 * Whether a meta key is left behind when copying.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function should_skip_meta( string $key ): bool {
		if ( in_array( $key, self::SKIP_META, true ) ) {
			return true;
		}
		foreach ( self::SKIP_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Duplicates a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $post_id Source post id.
	 * @param array $args    {
	 *     Optional.
	 *
	 *     @type string $title     Title for the copy. Defaults to the source
	 *                             title with a suffix.
	 *     @type string $slug      Slug for the copy. WordPress derives one when
	 *                             omitted.
	 *     @type string $status    Post status for the copy. Default 'draft' —
	 *                             a copy going straight live is rarely wanted
	 *                             and always recoverable the other way round.
	 *     @type int    $parent    Parent post id.
	 *     @type bool   $copy_terms Copy taxonomy terms. Default true.
	 *     @type bool   $copy_meta  Copy post meta. Default true.
	 * }
	 * @return int|WP_Error The new post id.
	 */
	public static function duplicate( int $post_id, array $args = array() ) {
		$source = get_post( $post_id );
		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}

		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'draft';
		$title  = isset( $args['title'] ) && '' !== (string) $args['title']
			? (string) $args['title']
			: sprintf(
				/* translators: %s: source post title. */
				__( '%s (copy)', 'karmcp' ),
				$source->post_title
			);

		$postarr = array(
			'post_title'     => $title,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_type'      => $source->post_type,
			'post_status'    => $status,
			'post_author'    => get_current_user_id() ?: $source->post_author,
			'post_parent'    => isset( $args['parent'] ) ? absint( $args['parent'] ) : $source->post_parent,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_password'  => $source->post_password,
		);

		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $args['slug'] );
		}

		$new_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		$copied_meta = 0;
		if ( ! isset( $args['copy_meta'] ) || $args['copy_meta'] ) {
			$copied_meta = self::copy_meta( $post_id, $new_id );
		}

		$copied_terms = array();
		if ( ! isset( $args['copy_terms'] ) || $args['copy_terms'] ) {
			$copied_terms = self::copy_terms( $post_id, $new_id );
		}

		// Elementor caches a per-post stylesheet keyed by id. The copy has to
		// generate its own or it renders with the source's styles.
		if ( 'builder' === (string) get_post_meta( $new_id, '_elementor_edit_mode', true ) ) {
			delete_post_meta( $new_id, '_elementor_css' );
			if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}

		/**
		 * Fires after a post has been duplicated, so an integration can copy
		 * whatever lives outside post meta and terms.
		 *
		 * @since 1.1.0
		 *
		 * @param int $new_id Copy id.
		 * @param int $post_id Source id.
		 */
		do_action( 'karmcp_post_duplicated', $new_id, $post_id );

		return $new_id;
	}

	/**
	 * Copies every meta row except the ones that must not travel.
	 *
	 * Uses `get_post_meta()` with no key so protected keys come along: that is
	 * the entire point of this class, and the values are the source post's own,
	 * not something the caller supplied.
	 *
	 * @param int $from Source id.
	 * @param int $to   Target id.
	 * @return int Number of meta rows written.
	 */
	private static function copy_meta( int $from, int $to ): int {
		$meta = get_post_meta( $from );
		if ( ! is_array( $meta ) ) {
			return 0;
		}

		$written = 0;
		foreach ( $meta as $key => $values ) {
			if ( self::should_skip_meta( (string) $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// get_post_meta() in list mode returns serialized strings, so
				// they go back through maybe_unserialize() or the copy stores a
				// string where the source had an array.
				add_post_meta( $to, $key, maybe_unserialize( $value ) );
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Copies taxonomy terms across every taxonomy the post type registers.
	 *
	 * @param int $from Source id.
	 * @param int $to   Target id.
	 * @return string[] Taxonomies that received terms.
	 */
	private static function copy_terms( int $from, int $to ): array {
		$post = get_post( $from );
		if ( ! $post ) {
			return array();
		}

		$copied = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			wp_set_object_terms( $to, $terms, $taxonomy );
			$copied[] = $taxonomy;
		}

		return $copied;
	}
}
