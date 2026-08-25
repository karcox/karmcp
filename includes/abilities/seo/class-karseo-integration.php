<?php
/**
 * KarSEO integration — two dispatcher tools (karseo-read / karseo-write) over
 * KarSEO's `slim_seo` post/term meta + option.
 *
 * KarSEO stores per-post and per-term SEO in a single meta array (keys: title,
 * description, canonical, noindex, nofollow, facebook_image, twitter_image);
 * site settings live in the option of the same name.
 *
 * The meta and option key is `slim_seo`, not `kar_seo`, and that is deliberate
 * on KarSEO's side rather than an oversight here: the plugin rebranded its
 * surface (namespace, text domain, `KAR_SEO_*` constants) and left the storage
 * layer untouched so an existing install keeps its data. Renaming the constant
 * here would read every post as empty and report success doing it.
 *
 * @package KarMCP
 * @since   3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.5.0
 */
class KarMCP_KarSEO_Integration extends KarMCP_SEO_Integration {

	const META_KEY = 'slim_seo';

	/** @return string */
	public function id(): string {
		return 'karseo';
	}

	/** @return string */
	public function label(): string {
		return 'KarSEO';
	}

	/**
	 * KarSEO also defines `SLIM_SEO_VER` as a back-compat alias, so that
	 * constant cannot tell the two apart. `KAR_SEO_VER` only exists in KarSEO.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'KAR_SEO_VER' );
	}

	/** @return array<string,array> */
	protected function operations(): array {
		$edit_posts = static function (): bool {
			return current_user_can( 'edit_posts' );
		};
		$manage     = static function (): bool {
			return current_user_can( 'manage_options' );
		};

		return array(
			'get-post-seo'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_post_seo' ),
				'perm' => $edit_posts,
				'desc' => 'Get a post\'s KarSEO metadata by { post_id } (title, description, canonical, noindex, nofollow, og_image, twitter_image).',
			),
			'get-term-seo'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_term_seo' ),
				'perm' => $edit_posts,
				'desc' => 'Get a term\'s KarSEO metadata by { term_id }.',
			),
			'get-settings'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_settings' ),
				'perm' => $manage,
				'desc' => 'Get KarSEO site settings.',
			),
			'update-post-seo' => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_post_seo' ),
				'perm' => $edit_posts,
				'desc' => 'Update a post\'s KarSEO metadata: { post_id, title?, description?, canonical?, noindex?, nofollow?, og_image?, twitter_image? }. Only provided fields change.',
			),
			'update-term-seo' => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_term_seo' ),
				'perm' => $edit_posts,
				'desc' => 'Update a term\'s KarSEO metadata: { term_id, title?, description?, ... }.',
			),
		);
	}

	/**
	 * Unified field => KarSEO meta-array key.
	 *
	 * @return array<string,string>
	 */
	private function map(): array {
		return array(
			'title'         => 'title',
			'description'   => 'description',
			'canonical'     => 'canonical',
			'noindex'       => 'noindex',
			'nofollow'      => 'nofollow',
			'og_image'      => 'facebook_image',
			'twitter_image' => 'twitter_image',
		);
	}

	/** The single meta array is snapshotted by the base for the ledger. */
	protected function recordable_meta_keys( string $object ): array {
		return array( self::META_KEY );
	}

	/**
	 * Shape a stored KarSEO array into the unified read view.
	 *
	 * @param array $data Stored meta.
	 * @return array<string,mixed>
	 */
	private function read_view( array $data ): array {
		$out = array();
		foreach ( $this->map() as $field => $key ) {
			$val = $data[ $key ] ?? '';
			if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
				$out[ $field ] = ! empty( $val );
			} else {
				$out[ $field ] = is_scalar( $val ) ? (string) $val : $val;
			}
		}
		return $out;
	}

	/**
	 * Merge unified input fields into a stored KarSEO array.
	 *
	 * @param array $current Existing meta.
	 * @param array $args    Operation arguments.
	 * @return array
	 */
	private function apply( array $current, array $args ): array {
		foreach ( $this->map() as $field => $key ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}
			if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
				$current[ $key ] = ! empty( $args[ $field ] ) ? true : false;
			} else {
				$current[ $key ] = is_scalar( $args[ $field ] ) ? (string) $args[ $field ] : $args[ $field ];
			}
		}
		return $current;
	}

	/**
	 * @param array $args { post_id }.
	 * @return array|WP_Error
	 */
	public function op_get_post_seo( array $args ) {
		$id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		if ( ! $id || ! get_post( $id ) ) {
			return $this->missing_or_not_found( 'post_id', $id, 'post' );
		}
		$data = get_post_meta( $id, self::META_KEY, true );
		return array( 'post_id' => $id, 'seo' => $this->read_view( is_array( $data ) ? $data : array() ) );
	}

	/**
	 * @param array $args { term_id }.
	 * @return array|WP_Error
	 */
	public function op_get_term_seo( array $args ) {
		$id = isset( $args['term_id'] ) ? absint( $args['term_id'] ) : 0;
		if ( ! $id || ! get_term( $id ) ) {
			return $this->missing_or_not_found( 'term_id', $id, 'term' );
		}
		$data = get_term_meta( $id, self::META_KEY, true );
		return array( 'term_id' => $id, 'seo' => $this->read_view( is_array( $data ) ? $data : array() ) );
	}

	/**
	 * @param array $args Unused.
	 * @return array
	 */
	public function op_get_settings( array $args ): array {
		$opt = get_option( self::META_KEY, array() );
		return array( 'settings' => is_array( $opt ) ? $opt : array() );
	}

	/**
	 * @param array $args { post_id, ...fields }.
	 * @return array|WP_Error
	 */
	public function op_update_post_seo( array $args ) {
		$id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		if ( ! $id || ! get_post( $id ) ) {
			return $this->missing_or_not_found( 'post_id', $id, 'post' );
		}
		$current = get_post_meta( $id, self::META_KEY, true );
		$merged  = $this->apply( is_array( $current ) ? $current : array(), $args );
		update_post_meta( $id, self::META_KEY, $merged );
		return array( 'updated' => true, 'post_id' => $id, 'seo' => $this->read_view( $merged ) );
	}

	/**
	 * @param array $args { term_id, ...fields }.
	 * @return array|WP_Error
	 */
	public function op_update_term_seo( array $args ) {
		$id = isset( $args['term_id'] ) ? absint( $args['term_id'] ) : 0;
		if ( ! $id || ! get_term( $id ) ) {
			return $this->missing_or_not_found( 'term_id', $id, 'term' );
		}
		$current = get_term_meta( $id, self::META_KEY, true );
		$merged  = $this->apply( is_array( $current ) ? $current : array(), $args );
		update_term_meta( $id, self::META_KEY, $merged );
		return array( 'updated' => true, 'term_id' => $id, 'seo' => $this->read_view( $merged ) );
	}

	/**
	 * @param string $field Argument name.
	 * @param int    $id    Id.
	 * @param string $what  Object type.
	 * @return WP_Error
	 */
	private function missing_or_not_found( string $field, int $id, string $what ): WP_Error {
		if ( ! $id ) {
			return new WP_Error(
				'missing_argument',
				sprintf(
					/* translators: %s: argument name */
					__( 'Missing required argument: %s.', 'karmcp' ),
					$field
				),
				array( 'status' => 400 )
			);
		}
		return new WP_Error(
			'not_found',
			sprintf(
				/* translators: 1: object type, 2: id */
				__( 'No %1$s with id %2$d.', 'karmcp' ),
				$what,
				$id
			),
			array( 'status' => 404 )
		);
	}
}
