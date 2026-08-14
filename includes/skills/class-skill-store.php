<?php
/**
 * Agent Skills store.
 *
 * A skill is a short operating manual you write once and every connected agent
 * reads: how you build a landing page, the tone your copy uses, what "done"
 * means before publishing. The plugin keeps them as an ordinary post type, which
 * buys the editor, revisions, search and autosave for free.
 *
 * The mapping onto post fields is deliberate, so nothing needs custom meta:
 *   - post_title   → the skill's human name
 *   - post_name    → its machine name, what `get-skill` asks for
 *   - post_excerpt → the one-line summary; the only part that ships in the
 *                    discovery context, so an agent can tell what it is worth
 *                    fetching without paying for every body on every connection
 *   - post_content → the body
 *   - post_status  → publish means live, draft means an agent never sees it
 *
 * Editing is restricted to administrators. A skill steers how an agent behaves
 * across the whole site, so it is closer to configuration than to content, and
 * an author who can publish a post should not be able to rewrite the
 * instructions every agent follows.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the skill post type and reads skills back.
 *
 * @since 1.0.0
 */
class KarMCP_Skill_Store {

	/**
	 * Post type name. Twelve characters — `wp_posts.post_type` is varchar(20),
	 * and a type that overflows it fails to register in silence.
	 */
	const POST_TYPE = 'karmcp_skill';

	/** Capability required to read, write and manage skills. */
	const CAP = 'manage_options';

	/**
	 * Register the post type. Hooked to `init` regardless of module state: an
	 * admin must be able to write skills before switching the exposure on, and
	 * an unregistered type would make existing ones vanish from the dashboard.
	 */
	public static function register_post_type(): void {
		$cap = self::CAP;

		register_post_type(
			self::POST_TYPE,
			array(
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				// Inside the KarMCP menu rather than a top-level entry of its own:
				// a skill is plugin configuration, not site content.
				//
				// Guarded, not a bare constant reference: KarMCP_Admin is only
				// required on admin requests, while this runs on `init` for every
				// request — including the REST calls that carry MCP traffic. An
				// unguarded KarMCP_Admin::PAGE_SLUG there is a fatal.
				'show_in_menu'        => class_exists( 'KarMCP_Admin' ) ? KarMCP_Admin::PAGE_SLUG : 'karmcp',
				'show_in_admin_bar'   => false,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'map_meta_cap'        => true,
				'capability_type'     => 'post',
				'capabilities'        => array(
					'create_posts'           => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
				),
				'supports'            => array( 'title', 'editor', 'excerpt', 'revisions', 'page-attributes' ),
				'labels'              => array(
					'name'               => __( 'Skills', 'karmcp' ),
					'singular_name'      => __( 'Skill', 'karmcp' ),
					'menu_name'          => __( 'Skills', 'karmcp' ),
					'all_items'          => __( 'Skills', 'karmcp' ),
					'add_new'            => __( 'Add Skill', 'karmcp' ),
					'add_new_item'       => __( 'Add Skill', 'karmcp' ),
					'new_item'           => __( 'New Skill', 'karmcp' ),
					'edit_item'          => __( 'Edit Skill', 'karmcp' ),
					'view_item'          => __( 'View Skill', 'karmcp' ),
					'search_items'       => __( 'Search Skills', 'karmcp' ),
					'not_found'          => __( 'No skills yet.', 'karmcp' ),
					'not_found_in_trash' => __( 'No skills in the trash.', 'karmcp' ),
				),
			)
		);
	}

	/**
	 * Published skills, in menu order then title — so an admin can put the one
	 * that matters most at the top of what the agent reads.
	 *
	 * @return WP_Post[]
	 */
	public static function active(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'suppress_filters' => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * One published skill by machine name.
	 *
	 * @param string $name Machine name (the post slug).
	 * @return WP_Post|null
	 */
	public static function find( string $name ): ?WP_Post {
		$name = sanitize_title( $name );
		if ( '' === $name || ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'name'             => $name,
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		return ( is_array( $posts ) && isset( $posts[0] ) ) ? $posts[0] : null;
	}

	/**
	 * Index entry for a skill: everything except the body.
	 *
	 * @param WP_Post $skill Skill post.
	 * @return array{name:string,title:string,summary:string}
	 */
	public static function summarize( WP_Post $skill ): array {
		return array(
			'name'    => (string) $skill->post_name,
			'title'   => (string) $skill->post_title,
			'summary' => trim( (string) $skill->post_excerpt ),
		);
	}

	/**
	 * Full skill, body included.
	 *
	 * @param WP_Post $skill Skill post.
	 * @return array{name:string,title:string,summary:string,body:string}
	 */
	public static function expand( WP_Post $skill ): array {
		$out         = self::summarize( $skill );
		$out['body'] = trim( (string) $skill->post_content );
		return $out;
	}
}
