<?php
/**
 * Normalized read/write access to the SEO metadata a post has stored.
 *
 * Every SEO plugin keeps the same handful of ideas — title, description,
 * canonical, robots, social — under its own key names. This class reduces them
 * to one vocabulary so the audit does not need to know which plugin is running.
 *
 * The vocabulary is the one already in use by `KarMCP_KarSEO_Integration`
 * (title, description, canonical, noindex, nofollow, og_image, twitter_image),
 * extended with the fields the audit needs. Do not invent a second one.
 *
 * **This class reports stored intent, not rendered output.** A stored title of
 * `%%title%% %%sep%% %%sitename%%` is a template, and what the visitor gets is
 * whatever the plugin expands it to. The rendered truth lives in the digest
 * that `KarMCP_Content_Extractor` produces; the audit compares the two, which
 * is the only way to catch a template that expands to nothing.
 *
 * Capability checks belong to the caller. This is a primitive.
 *
 * @package KarMCP
 * @since   1.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes SEO metadata across the supported SEO plugins.
 *
 * @since 1.14.0
 */
class KarMCP_Seo_Meta {

	/**
	 * The unified field vocabulary. Every provider maps onto exactly these.
	 */
	const FIELDS = array(
		'title',
		'description',
		'canonical',
		'focus_keyword',
		'noindex',
		'nofollow',
		'og_title',
		'og_description',
		'og_image',
		'twitter_title',
		'twitter_description',
		'twitter_image',
	);

	/**
	 * Boolean fields, so callers never have to guess which are flags.
	 */
	const BOOLEAN_FIELDS = array( 'noindex', 'nofollow' );

	/**
	 * Providers whose storage we read directly, in detection precedence order.
	 *
	 * Precedence only matters while a site is mid-migration with two SEO plugins
	 * installed at once. The rest are reported in `others` so the audit can say
	 * so out loud, because two active SEO plugins is a real and confusing bug.
	 */
	const READABLE_PROVIDERS = array( 'yoast', 'rankmath', 'karseo' );

	/**
	 * Providers we can detect but deliberately do not read.
	 *
	 * These keep their data in their own database tables rather than postmeta.
	 * Guessing at a foreign schema is how you ship a reader that silently
	 * returns empty strings forever, so instead we say we cannot read it and
	 * leave `karmcp_seo_meta` for an integration that actually knows.
	 */
	const OPAQUE_PROVIDERS = array( 'aioseo', 'seopress' );

	/**
	 * Returns the empty normalized shape. Always the same keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_fields(): array {
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = in_array( $field, self::BOOLEAN_FIELDS, true ) ? false : '';
		}
		return $fields;
	}

	/**
	 * Which SEO plugins are active.
	 *
	 * @return array {
	 *     @type string   $source   Provider id that wins, or 'none'.
	 *     @type bool     $readable Whether that provider's storage is readable here.
	 *     @type string[] $others   Other active providers, which should be zero.
	 * }
	 */
	public static function detect(): array {
		$active = array();

		foreach ( array_merge( self::READABLE_PROVIDERS, self::OPAQUE_PROVIDERS ) as $provider ) {
			if ( self::provider_active( $provider ) ) {
				$active[] = $provider;
			}
		}

		if ( empty( $active ) ) {
			return array(
				'source'   => 'none',
				'readable' => true,
				'others'   => array(),
			);
		}

		$source = $active[0];

		return array(
			'source'   => $source,
			'readable' => in_array( $source, self::READABLE_PROVIDERS, true ),
			'others'   => array_values( array_slice( $active, 1 ) ),
		);
	}

	/**
	 * Whether a given provider is active.
	 *
	 * Detection is by the constant each plugin defines, which is cheaper and
	 * more reliable than matching a plugin path.
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	private static function provider_active( string $provider ): bool {
		switch ( $provider ) {
			case 'yoast':
				return defined( 'WPSEO_VERSION' );
			case 'rankmath':
				return defined( 'RANK_MATH_VERSION' );
			case 'karseo':
				// KarSEO also defines SLIM_SEO_VER as a back-compat alias, so only
				// KAR_SEO_VER identifies it rather than the plugin it forked from.
				return defined( 'KAR_SEO_VER' );
			case 'aioseo':
				return defined( 'AIOSEO_VERSION' );
			case 'seopress':
				return defined( 'SEOPRESS_VERSION' );
		}
		return false;
	}

	/**
	 * Human label for a provider id.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function provider_label( string $provider ): string {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'karseo'   => 'KarSEO',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'none'     => __( 'No SEO plugin', 'karmcp' ),
		);
		return $labels[ $provider ] ?? $provider;
	}

	/**
	 * Reads the stored SEO metadata for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array {
	 *     @type string              $source        Provider id.
	 *     @type string              $source_label  Human label.
	 *     @type bool                $readable      False when the provider keeps its own tables.
	 *     @type string[]            $others        Other active SEO plugins.
	 *     @type array<string,mixed> $fields        The unified vocabulary.
	 *     @type string[]            $templated     Fields whose value contains unexpanded variables.
	 * }
	 */
	public static function get( int $post_id ): array {
		$detected = self::detect();

		$fields = ( $post_id > 0 && $detected['readable'] )
			? self::read( $post_id, $detected['source'] )
			: self::empty_fields();

		$result = array(
			'source'       => $detected['source'],
			'source_label' => self::provider_label( $detected['source'] ),
			'readable'     => $detected['readable'],
			'others'       => $detected['others'],
			'fields'       => $fields,
			'templated'    => self::templated_fields( $fields ),
		);

		/**
		 * Filters the normalized SEO metadata read for a post.
		 *
		 * This is the seam for SEO plugins that keep their data outside
		 * postmeta: return the same shape with `readable` set to true and
		 * `fields` filled in.
		 *
		 * @since 1.14.0
		 *
		 * @param array $result  Normalized read result.
		 * @param int   $post_id Post id.
		 */
		return apply_filters( 'karmcp_seo_meta', $result, $post_id );
	}

	/**
	 * Writes SEO metadata for a post.
	 *
	 * Only the fields present in `$fields` are touched; anything else keeps its
	 * stored value. Unknown field names are ignored rather than guessed at.
	 *
	 * The caller is responsible for the capability check.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  Subset of the unified vocabulary.
	 * @return true|WP_Error True on success, error when nothing can write.
	 */
	public static function set( int $post_id, array $fields ) {
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', __( 'A valid post_id is required.', 'karmcp' ) );
		}

		$detected = self::detect();

		if ( 'none' === $detected['source'] ) {
			return new WP_Error(
				'no_seo_plugin',
				__( 'No supported SEO plugin is active, so there is nowhere to store SEO metadata.', 'karmcp' )
			);
		}

		if ( ! $detected['readable'] ) {
			return new WP_Error(
				'provider_not_writable',
				sprintf(
					/* translators: %s: SEO plugin name. */
					__( '%s stores its data outside postmeta and is not written directly. Use its own integration tool.', 'karmcp' ),
					self::provider_label( $detected['source'] )
				)
			);
		}

		if ( ! self::write( $post_id, $fields, $detected['source'] ) ) {
			return new WP_Error( 'nothing_to_write', __( 'No recognized SEO fields were supplied.', 'karmcp' ) );
		}

		return true;
	}

	/**
	 * Reads one named provider's storage, bypassing detection.
	 *
	 * Detection and reading are separate on purpose: which plugin is active is
	 * a global fact that a test cannot un-set once a constant is defined, while
	 * the mapping from a provider's keys to the unified vocabulary is exactly
	 * the part worth covering.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $provider Provider id.
	 * @return array<string,mixed> Unified fields; all-empty for an unknown provider.
	 */
	public static function read( int $post_id, string $provider ): array {
		switch ( $provider ) {
			case 'yoast':
				return self::read_yoast( $post_id );
			case 'rankmath':
				return self::read_rankmath( $post_id );
			case 'karseo':
				return self::read_karseo( $post_id );
		}

		return self::empty_fields();
	}

	/**
	 * Writes one named provider's storage, bypassing detection.
	 *
	 * The caller is responsible for the capability check and for having
	 * established that this provider is the one in charge.
	 *
	 * @param int    $post_id  Post id.
	 * @param array  $fields   Subset of the unified vocabulary.
	 * @param string $provider Provider id.
	 * @return bool Whether anything was written.
	 */
	public static function write( int $post_id, array $fields, string $provider ): bool {
		$known = array_intersect_key( $fields, array_flip( self::FIELDS ) );
		if ( empty( $known ) ) {
			return false;
		}

		switch ( $provider ) {
			case 'yoast':
				self::write_mapped( $post_id, $known, self::yoast_map() );
				self::write_yoast_robots( $post_id, $known );
				return true;
			case 'rankmath':
				self::write_mapped( $post_id, $known, self::rankmath_map() );
				self::write_rankmath_robots( $post_id, $known );
				return true;
			case 'karseo':
				self::write_karseo( $post_id, $known );
				return true;
		}

		return false;
	}

	/**
	 * Yoast's scalar postmeta keys, keyed by unified field.
	 *
	 * Robots live outside this map because Yoast encodes them as a tri-state
	 * string rather than a boolean.
	 *
	 * @return array<string,string>
	 */
	private static function yoast_map(): array {
		return array(
			'title'               => '_yoast_wpseo_title',
			'description'         => '_yoast_wpseo_metadesc',
			'canonical'           => '_yoast_wpseo_canonical',
			'focus_keyword'       => '_yoast_wpseo_focuskw',
			'og_title'            => '_yoast_wpseo_opengraph-title',
			'og_description'      => '_yoast_wpseo_opengraph-description',
			'og_image'            => '_yoast_wpseo_opengraph-image',
			'twitter_title'       => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
			'twitter_image'       => '_yoast_wpseo_twitter-image',
		);
	}

	/**
	 * Rank Math's scalar postmeta keys, keyed by unified field.
	 *
	 * @return array<string,string>
	 */
	private static function rankmath_map(): array {
		return array(
			'title'               => 'rank_math_title',
			'description'         => 'rank_math_description',
			'canonical'           => 'rank_math_canonical_url',
			'focus_keyword'       => 'rank_math_focus_keyword',
			'og_title'            => 'rank_math_facebook_title',
			'og_description'      => 'rank_math_facebook_description',
			'og_image'            => 'rank_math_facebook_image',
			'twitter_title'       => 'rank_math_twitter_title',
			'twitter_description' => 'rank_math_twitter_description',
			'twitter_image'       => 'rank_math_twitter_image',
		);
	}

	/**
	 * Reads Yoast SEO.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private static function read_yoast( int $post_id ): array {
		$fields = self::read_mapped( $post_id, self::yoast_map() );

		// Yoast stores three states, not a boolean: '1' means noindex, '2' means
		// an explicit index, and empty means "whatever the site default is".
		// Only '1' is a noindex.
		$fields['noindex']  = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$fields['nofollow'] = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

		return $fields;
	}

	/**
	 * Reads Rank Math.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private static function read_rankmath( int $post_id ): array {
		$fields = self::read_mapped( $post_id, self::rankmath_map() );

		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : array();

		$fields['noindex']  = in_array( 'noindex', $robots, true );
		$fields['nofollow'] = in_array( 'nofollow', $robots, true );

		return $fields;
	}

	/**
	 * Reads KarSEO's single meta array.
	 *
	 * The key is `slim_seo`: KarSEO rebranded its surface and deliberately left
	 * its storage alone, so an existing install keeps its data.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private static function read_karseo( int $post_id ): array {
		$fields = self::empty_fields();

		$data = get_post_meta( $post_id, 'slim_seo', true );
		if ( ! is_array( $data ) ) {
			return $fields;
		}

		$map = array(
			'title'         => 'title',
			'description'   => 'description',
			'canonical'     => 'canonical',
			'og_image'      => 'facebook_image',
			'twitter_image' => 'twitter_image',
		);

		foreach ( $map as $field => $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$fields[ $field ] = (string) $data[ $key ];
			}
		}

		$fields['noindex']  = ! empty( $data['noindex'] );
		$fields['nofollow'] = ! empty( $data['nofollow'] );

		return $fields;
	}

	/**
	 * Reads a flat field => meta-key map into the unified shape.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string,string> $map     Field to meta key.
	 * @return array<string,mixed>
	 */
	private static function read_mapped( int $post_id, array $map ): array {
		$fields = self::empty_fields();

		foreach ( $map as $field => $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( is_scalar( $value ) ) {
				$fields[ $field ] = (string) $value;
			}
		}

		return $fields;
	}

	/**
	 * Writes a flat field => meta-key map.
	 *
	 * @param int                  $post_id Post id.
	 * @param array                $fields  Supplied fields.
	 * @param array<string,string> $map     Field to meta key.
	 */
	private static function write_mapped( int $post_id, array $fields, array $map ): void {
		foreach ( $map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}
			update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $fields[ $field ] ) );
		}
	}

	/**
	 * Writes Yoast's tri-state robots values.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  Supplied fields.
	 */
	private static function write_yoast_robots( int $post_id, array $fields ): void {
		if ( array_key_exists( 'noindex', $fields ) ) {
			// '2' is Yoast's explicit "index", which is what clearing a noindex
			// means. Writing '' would fall back to the site default instead,
			// which is a different thing and not what the caller asked for.
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $fields['noindex'] ? '1' : '2' );
		}
		if ( array_key_exists( 'nofollow', $fields ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $fields['nofollow'] ? '1' : '0' );
		}
	}

	/**
	 * Writes Rank Math's robots array, preserving directives we do not manage.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  Supplied fields.
	 */
	private static function write_rankmath_robots( int $post_id, array $fields ): void {
		if ( ! array_key_exists( 'noindex', $fields ) && ! array_key_exists( 'nofollow', $fields ) ) {
			return;
		}

		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : array();

		foreach ( array( 'noindex', 'nofollow' ) as $directive ) {
			if ( ! array_key_exists( $directive, $fields ) ) {
				continue;
			}

			$opposite = 'noindex' === $directive ? 'index' : 'follow';
			$robots   = array_values( array_diff( $robots, array( $directive, $opposite ) ) );
			$robots[] = $fields[ $directive ] ? $directive : $opposite;
		}

		update_post_meta( $post_id, 'rank_math_robots', array_values( array_unique( $robots ) ) );
	}

	/**
	 * Writes KarSEO's single meta array, merging into what is stored.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  Supplied fields.
	 */
	private static function write_karseo( int $post_id, array $fields ): void {
		$current = get_post_meta( $post_id, 'slim_seo', true );
		$current = is_array( $current ) ? $current : array();

		$map = array(
			'title'         => 'title',
			'description'   => 'description',
			'canonical'     => 'canonical',
			'og_image'      => 'facebook_image',
			'twitter_image' => 'twitter_image',
		);

		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$current[ $key ] = sanitize_text_field( (string) $fields[ $field ] );
			}
		}

		foreach ( self::BOOLEAN_FIELDS as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$current[ $field ] = (bool) $fields[ $field ];
			}
		}

		update_post_meta( $post_id, 'slim_seo', $current );
	}

	/**
	 * Which stored values still contain unexpanded template variables.
	 *
	 * Yoast writes `%%title%%`, Rank Math writes `%title%`. Either way the
	 * stored string is not what the visitor reads, so an audit that measured
	 * its length would be measuring the template.
	 *
	 * @param array $fields Unified fields.
	 * @return string[] Field names containing variables.
	 */
	public static function templated_fields( array $fields ): array {
		$templated = array();

		foreach ( $fields as $field => $value ) {
			if ( is_string( $value ) && self::has_template_vars( $value ) ) {
				$templated[] = $field;
			}
		}

		return $templated;
	}

	/**
	 * Whether a value contains SEO-plugin template variables.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function has_template_vars( string $value ): bool {
		return 1 === preg_match( '/%%[a-z0-9_-]+%%|%[a-z0-9_]+%/i', $value );
	}
}
