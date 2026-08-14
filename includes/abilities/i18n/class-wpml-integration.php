<?php
/**
 * WPML integration.
 *
 * WPML publishes no functions for this; it publishes hooks, and those are the
 * contract. Reads go through the `wpml_active_languages`,
 * `wpml_post_language_details` and `wpml_object_id` filters, and the one write
 * goes through the `wpml_set_element_language_details` action. Its tables are
 * left alone: `icl_translations` is joined by a translation group id that WPML
 * allocates itself, and a row written by hand belongs to no group.
 *
 * WPML's model differs from Polylang's in a way that matters here: a post is
 * attached to a *translation group*, so linking is per-post ("this is the es
 * version of that post"), not a group write. `link_group()` therefore points
 * every member at the first one rather than sending a map.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 1.1.0
 */
class KarMCP_WPML_Integration extends KarMCP_Translation_Integration {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'wpml';
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return 'WPML';
	}

	/**
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
	}

	/**
	 * @return array<int,array{code:string,name:string,default:bool}>
	 */
	protected function languages(): array {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$default   = (string) apply_filters( 'wpml_default_language', null );

		$out = array();
		foreach ( (array) $languages as $code => $language ) {
			$code  = (string) ( $language['language_code'] ?? $code );
			$out[] = array(
				'code'    => $code,
				'name'    => (string) ( $language['translated_name'] ?? $language['native_name'] ?? $code ),
				'default' => $code === $default,
			);
		}

		return $out;
	}

	/**
	 * @param int $post_id Post id.
	 * @return string
	 */
	protected function language_of( int $post_id ): string {
		$details = apply_filters(
			'wpml_post_language_details',
			null,
			$post_id
		);

		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}

		return '';
	}

	/**
	 * @param int $post_id Post id.
	 * @return array<string,int>
	 */
	protected function translations_of( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$out = array();
		foreach ( $this->languages() as $language ) {
			$translated = apply_filters( 'wpml_object_id', $post_id, $post->post_type, false, $language['code'] );
			$translated = (int) $translated;
			if ( $translated > 0 && get_post( $translated ) ) {
				$out[ $language['code'] ] = $translated;
			}
		}

		return $out;
	}

	/**
	 * @param int    $post_id Post id.
	 * @param string $code    Language code.
	 * @return true|WP_Error
	 */
	protected function assign_language( int $post_id, string $code ) {
		return $this->set_details( $post_id, $code, null );
	}

	/**
	 * Points every member of the group at the first one.
	 *
	 * @param array<string,int> $map Language code => post id.
	 * @return true|WP_Error
	 */
	protected function link_group( array $map ) {
		if ( count( $map ) < 2 ) {
			return true;
		}

		// The source of the group is the post in the default language when it is
		// present, and otherwise simply the first: WPML wants one original and
		// the rest hanging off it.
		$default = $this->default_language();
		$source  = isset( $map[ $default ] ) ? $default : (string) array_key_first( $map );

		$trid = $this->trid_of( (int) $map[ $source ] );
		if ( ! $trid ) {
			return new WP_Error(
				'wpml_no_trid',
				__( 'WPML has no translation group for the source post yet. Save it once in the WordPress editor and try again.', 'karmcp' ),
				array( 'status' => 409 )
			);
		}

		foreach ( $map as $code => $post_id ) {
			if ( $code === $source ) {
				continue;
			}
			$result = $this->set_details( (int) $post_id, (string) $code, $trid, $source );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Writes a post's language details through WPML's action.
	 *
	 * @param int         $post_id         Post id.
	 * @param string      $code            Language code.
	 * @param int|null    $trid            Translation group id, or null for a new group.
	 * @param string|null $source_language Language the translation was made from.
	 * @return true|WP_Error
	 */
	private function set_details( int $post_id, string $code, ?int $trid, ?string $source_language = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $post_id,
				'element_type'         => 'post_' . $post->post_type,
				'trid'                 => $trid,
				'language_code'        => $code,
				'source_language_code' => $source_language,
			)
		);

		return true;
	}

	/**
	 * The translation group id a post belongs to.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function trid_of( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}

		$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post->post_type );

		return (int) $trid;
	}
}
