<?php
/**
 * Themer extended tier: unlimited templates + granular display conditions.
 *
 * The upstream equivalent shipped in a private Pro overlay. KarMCP implements it
 * itself, in the open, as a normal part of the plugin.
 *
 * Nothing here re-architects the Themer. The engine in the free tree already
 * evaluates include/exclude sets (KarMCP_Themer_Conditions), ranks winners by
 * specificity then priority then recency (KarMCP_Themer_Resolver), and resolves
 * rules through a filterable registry (KarMCP_Themer_Matcher_Registry). This
 * class only supplies the five things that were missing:
 *
 *   1. `karmcp_themer_quota`            — lift the 1-per-type cap.
 *   2. `karmcp_themer_matchers`         — granular matchers (post/term/author/date).
 *   3. `karmcp_themer_selectors`        — allow those keys through save validation.
 *   4. `karmcp_themer_condition_schema` — expose them (plus Exclude) in the builder UI.
 *   5. `karmcp_themer_rank`             — actually read the stored priority.
 *
 * On (5): KarMCP_Themer_Index already persists a `priority` per template, but the
 * default ranker in the render controller returns 0 for every row, so priority is
 * inert until something supplies a real one. Without this the builder would let an
 * author set a priority that silently did nothing.
 *
 * Plus the object-search AJAX endpoint the builder calls to pick a specific page,
 * term or author.
 *
 * SPECIFICITY: the free matchers score 0 (entire-site), 10 (all-singular /
 * all-archives) and 20 (front-page / post-type / post-type-archive / tax-archive).
 * Everything added here scores ABOVE 20 so a granular rule always beats a broad
 * one — that ordering is what makes "site-wide header, except this one page"
 * resolve the way an author expects.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the granular Themer condition layer.
 */
final class KarMCP_Themer_Extended {

	/** A specific term archive, a post in a term, or an author. */
	private const SPECIFICITY_OBJECT = 30;

	/** One exact entry — the most specific rule there is. */
	private const SPECIFICITY_POST = 40;

	/** Date archives: as broad as the other archive selectors. */
	private const SPECIFICITY_DATE = 20;

	/** AJAX action + nonce name for the object picker (must match the metabox). */
	private const AJAX_ACTION = 'karmcp_themer_object_search';

	/** Max rows an object search returns. */
	private const SEARCH_LIMIT = 20;

	/**
	 * Wire the filters. Called from KarMCP_Themer_Module::register().
	 */
	public static function init(): void {
		add_filter( 'karmcp_themer_quota', array( __CLASS__, 'filter_quota' ), 10, 2 );
		add_filter( 'karmcp_themer_matchers', array( __CLASS__, 'filter_matchers' ) );
		add_filter( 'karmcp_themer_selectors', array( __CLASS__, 'filter_selectors' ) );
		add_filter( 'karmcp_themer_condition_schema', array( __CLASS__, 'filter_condition_schema' ), 10, 2 );
		add_filter( 'karmcp_themer_rank', array( __CLASS__, 'filter_rank' ) );
		add_filter( 'karmcp_themer_extended_tier', '__return_true' );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_object_search' ) );
	}

	/**
	 * Lift the per-type template cap.
	 *
	 * @param int    $cap  Incoming cap (1 in the base tier).
	 * @param string $type Template type.
	 * @return int
	 */
	public static function filter_quota( $cap, $type = '' ): int {
		unset( $cap, $type );
		return PHP_INT_MAX;
	}

	/**
	 * Supply the real priority ranker.
	 *
	 * The resolver breaks a specificity tie with this value (higher wins), then
	 * falls back to the newest id. The default ranker returns 0 for every row,
	 * which makes the stored priority a no-op — this replaces it.
	 *
	 * @param callable $ranker Incoming ranker (the zero default).
	 * @return callable fn(array $row): int
	 */
	public static function filter_rank( $ranker ): callable {
		unset( $ranker );
		return static function ( array $row ): int {
			return (int) ( $row['priority'] ?? 0 );
		};
	}

	/**
	 * Merge the granular matchers into the registry.
	 *
	 * @param array $matchers Existing matcher map.
	 * @return array
	 */
	public static function filter_matchers( $matchers ): array {
		return array_merge( is_array( $matchers ) ? $matchers : array(), self::matchers() );
	}

	/**
	 * The granular matcher map.
	 *
	 * Public and static on purpose: it is a pure value, so the tests exercise the
	 * callbacks directly without needing a filter registry.
	 *
	 * Rule `object` strings and the context keys they read:
	 *   post:<id>                  is_singular + post_id
	 *   in-term:<taxonomy>:<id>    is_singular + term_ids[taxonomy]
	 *   author:<id>                is_singular + author_id
	 *   term:<taxonomy>:<id>       queried_taxonomy + queried_term_id
	 *   author-archive:<id>        is_author + author_id
	 *   date                       is_date
	 *
	 * @return array<string,array{specificity:int,callback:callable}>
	 */
	public static function matchers(): array {
		return array(
			// A single entry. Beats every broad rule.
			'post'           => array(
				'specificity' => self::SPECIFICITY_POST,
				'callback'    => static function ( array $rule, array $ctx ): bool {
					$id = (int) KarMCP_Themer_Matcher_Registry::param( $rule );
					return $id > 0 && ! empty( $ctx['is_singular'] ) && (int) ( $ctx['post_id'] ?? 0 ) === $id;
				},
			),
			// A singular entry that carries a given term.
			'in-term'        => array(
				'specificity' => self::SPECIFICITY_OBJECT,
				'callback'    => static function ( array $rule, array $ctx ): bool {
					if ( empty( $ctx['is_singular'] ) ) {
						return false;
					}
					$taxonomy = KarMCP_Themer_Matcher_Registry::param( $rule );
					$term_id  = (int) KarMCP_Themer_Matcher_Registry::param2( $rule );
					if ( '' === $taxonomy || $term_id <= 0 ) {
						return false;
					}
					$by_tax = $ctx['term_ids'] ?? array();
					$ids    = is_array( $by_tax ) && isset( $by_tax[ $taxonomy ] ) ? (array) $by_tax[ $taxonomy ] : array();
					return in_array( $term_id, array_map( 'intval', $ids ), true );
				},
			),
			// A singular entry written by a given author.
			'author'         => array(
				'specificity' => self::SPECIFICITY_OBJECT,
				'callback'    => static function ( array $rule, array $ctx ): bool {
					$id = (int) KarMCP_Themer_Matcher_Registry::param( $rule );
					return $id > 0 && ! empty( $ctx['is_singular'] ) && (int) ( $ctx['author_id'] ?? 0 ) === $id;
				},
			),
			// One specific term archive.
			'term'           => array(
				'specificity' => self::SPECIFICITY_OBJECT,
				'callback'    => static function ( array $rule, array $ctx ): bool {
					$taxonomy = KarMCP_Themer_Matcher_Registry::param( $rule );
					$term_id  = (int) KarMCP_Themer_Matcher_Registry::param2( $rule );
					if ( '' === $taxonomy || $term_id <= 0 ) {
						return false;
					}
					return ( $ctx['queried_taxonomy'] ?? '' ) === $taxonomy
						&& (int) ( $ctx['queried_term_id'] ?? 0 ) === $term_id;
				},
			),
			// One author's archive page.
			'author-archive' => array(
				'specificity' => self::SPECIFICITY_OBJECT,
				'callback'    => static function ( array $rule, array $ctx ): bool {
					$id = (int) KarMCP_Themer_Matcher_Registry::param( $rule );
					return $id > 0 && ! empty( $ctx['is_author'] ) && (int) ( $ctx['author_id'] ?? 0 ) === $id;
				},
			),
			// Any date-based archive.
			'date'           => array(
				'specificity' => self::SPECIFICITY_DATE,
				'callback'    => static function ( array $rule, array $ctx ): bool {
					unset( $rule );
					return ! empty( $ctx['is_date'] );
				},
			),
		);
	}

	/**
	 * Allow the granular selector keys through the metabox save validation.
	 *
	 * Registering `post` here is also what flips the builder UI into its granular
	 * mode — KarMCP_Themer_Metabox::is_pro() probes for exactly that key.
	 *
	 * @param array $selectors Existing allowed selector keys.
	 * @return array
	 */
	public static function filter_selectors( $selectors ): array {
		return array_values(
			array_unique(
				array_merge(
					is_array( $selectors ) ? $selectors : array(),
					array_keys( self::matchers() )
				)
			)
		);
	}

	/**
	 * Extend the condition-builder schema: add the Exclude relation, attach
	 * object-search descriptors to the post-type and taxonomy nodes, and add the
	 * author / date nodes.
	 *
	 * @param array  $schema Base schema { relations, groups }.
	 * @param string $type   Template type.
	 * @return array
	 */
	public static function filter_condition_schema( $schema, $type = '' ): array {
		if ( ! is_array( $schema ) ) {
			return array();
		}

		// 1. Exclude relation ("everywhere EXCEPT …").
		$relations = isset( $schema['relations'] ) && is_array( $schema['relations'] ) ? $schema['relations'] : array();
		$has_excl  = false;
		foreach ( $relations as $relation ) {
			if ( isset( $relation['value'] ) && 'exclude' === $relation['value'] ) {
				$has_excl = true;
				break;
			}
		}
		if ( ! $has_excl ) {
			$relations[] = array(
				'value' => 'exclude',
				'label' => __( 'Exclude', 'karmcp' ),
			);
		}
		$schema['relations'] = $relations;

		// 2. Object descriptors on the nodes that carry a post type / taxonomy, and
		//    the extra granular nodes per group.
		$groups = isset( $schema['groups'] ) && is_array( $schema['groups'] ) ? $schema['groups'] : array();
		foreach ( $groups as &$group ) {
			if ( empty( $group['subs'] ) || ! is_array( $group['subs'] ) ) {
				continue;
			}
			$is_singular = isset( $group['value'] ) && 'singular' === $group['value'];
			$is_archive  = isset( $group['value'] ) && 'archive' === $group['value'];

			foreach ( $group['subs'] as &$sub ) {
				// "Pages" → also let the author pick one specific page.
				if ( ! empty( $sub['post_type'] ) ) {
					$sub['object'] = array(
						'kind'             => 'post',
						'post_type'        => (string) $sub['post_type'],
						'specificSelector' => 'post:%d',
						'label'            => __( 'Any', 'karmcp' ),
					);
				}
				// "Categories" → also let the author pick one specific term.
				if ( ! empty( $sub['taxonomy'] ) ) {
					$sub['object'] = array(
						'kind'             => 'term',
						'taxonomy'         => (string) $sub['taxonomy'],
						'specificSelector' => 'term:' . $sub['taxonomy'] . ':%d',
						'label'            => __( 'All terms', 'karmcp' ),
					);
				}
			}
			unset( $sub );

			if ( $is_singular ) {
				// In a specific term (a post filed under a category).
				foreach ( self::public_taxonomies() as $tax_name => $tax_label ) {
					$group['subs'][] = array(
						'value'    => 'in-term:' . $tax_name,
						/* translators: %s: taxonomy label */
						'label'    => sprintf( __( 'In %s', 'karmcp' ), $tax_label ),
						'selector' => '',
						'object'   => array(
							'kind'             => 'term',
							'taxonomy'         => $tax_name,
							'specificSelector' => 'in-term:' . $tax_name . ':%d',
							'label'            => __( 'Choose a term', 'karmcp' ),
						),
					);
				}
				// Written by a specific author.
				$group['subs'][] = array(
					'value'    => 'author',
					'label'    => __( 'By author', 'karmcp' ),
					'selector' => '',
					'object'   => array(
						'kind'             => 'author',
						'specificSelector' => 'author:%d',
						'label'            => __( 'Choose an author', 'karmcp' ),
					),
				);
			}

			if ( $is_archive ) {
				$group['subs'][] = array(
					'value'    => 'author-archive',
					'label'    => __( 'Author archive', 'karmcp' ),
					'selector' => '',
					'object'   => array(
						'kind'             => 'author',
						'specificSelector' => 'author-archive:%d',
						'label'            => __( 'Choose an author', 'karmcp' ),
					),
				);
				$group['subs'][] = array(
					'value'    => 'date',
					'label'    => __( 'Date archives', 'karmcp' ),
					'selector' => 'date',
				);
			}
		}
		unset( $group );
		$schema['groups'] = $groups;
		unset( $type );

		return $schema;
	}

	/**
	 * Public taxonomies as name => label.
	 *
	 * @return array<string,string>
	 */
	private static function public_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[ $tax->name ] = $tax->label;
		}
		return $out;
	}

	/**
	 * Object-search endpoint for the builder's picker.
	 *
	 * Responds { items: [ { id, label } ] }. Nonce + capability gated: this reads
	 * post/term/user titles, so it is not open to unauthenticated callers.
	 */
	public static function ajax_object_search(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'karmcp' ) ), 403 );
		}

		$raw        = isset( $_POST['object'] ) ? wp_unslash( $_POST['object'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded + per-key sanitized below.
		$descriptor = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$descriptor = is_array( $descriptor ) ? $descriptor : array();

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( '' === $query ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$kind = isset( $descriptor['kind'] ) ? sanitize_key( $descriptor['kind'] ) : '';

		switch ( $kind ) {
			case 'post':
				$items = self::search_posts( $query, isset( $descriptor['post_type'] ) ? sanitize_key( $descriptor['post_type'] ) : 'post' );
				break;
			case 'term':
				$items = self::search_terms( $query, isset( $descriptor['taxonomy'] ) ? sanitize_key( $descriptor['taxonomy'] ) : 'category' );
				break;
			case 'author':
				$items = self::search_authors( $query );
				break;
			default:
				$items = array();
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * @param string $query     Search text.
	 * @param string $post_type Post type to search.
	 * @return array<int,array{id:int,label:string}>
	 */
	private static function search_posts( string $query, string $post_type ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'           => $post_type,
				'post_status'         => array( 'publish', 'draft', 'private' ),
				's'                   => $query,
				'posts_per_page'      => self::SEARCH_LIMIT,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'label' => get_the_title( $post ) . ' (#' . (int) $post->ID . ')',
			);
		}
		return $out;
	}

	/**
	 * @param string $query    Search text.
	 * @param string $taxonomy Taxonomy to search.
	 * @return array<int,array{id:int,label:string}>
	 */
	private static function search_terms( string $query, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'search'     => $query,
				'number'     => self::SEARCH_LIMIT,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'    => (int) $term->term_id,
				'label' => $term->name,
			);
		}
		return $out;
	}

	/**
	 * @param string $query Search text.
	 * @return array<int,array{id:int,label:string}>
	 */
	private static function search_authors( string $query ): array {
		$users = get_users(
			array(
				'search'         => '*' . $query . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'display_name', 'user_email' ),
				'number'         => self::SEARCH_LIMIT,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'id'    => (int) $user->ID,
				'label' => $user->display_name . ' (' . $user->user_login . ')',
			);
		}
		return $out;
	}
}
