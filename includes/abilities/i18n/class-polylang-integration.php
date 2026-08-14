<?php
/**
 * Polylang integration.
 *
 * Built on Polylang's documented function API — the `pll_*` functions it
 * publishes for exactly this — never its taxonomy tables. Polylang stores
 * language and translation groups as hidden taxonomies with a serialized term
 * description, and writing that by hand produces a group the plugin's own cache
 * never learns about.
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
class KarMCP_Polylang_Integration extends KarMCP_Translation_Integration {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'polylang';
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return 'Polylang';
	}

	/**
	 * Every function this adapter calls is checked, not just the headline one:
	 * Polylang ships in several editions and the set is not identical across
	 * them, and a half-present API would fail mid-write with a fatal.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		foreach ( array( 'pll_languages_list', 'pll_get_post_language', 'pll_get_post_translations', 'pll_set_post_language', 'pll_save_post_translations' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array<int,array{code:string,name:string,default:bool}>
	 */
	protected function languages(): array {
		$codes   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';

		$out = array();
		foreach ( array_values( $codes ) as $index => $code ) {
			$out[] = array(
				'code'    => (string) $code,
				'name'    => isset( $names[ $index ] ) ? (string) $names[ $index ] : (string) $code,
				'default' => (string) $code === $default,
			);
		}

		return $out;
	}

	/**
	 * @param int $post_id Post id.
	 * @return string
	 */
	protected function language_of( int $post_id ): string {
		$code = pll_get_post_language( $post_id, 'slug' );
		return $code ? (string) $code : '';
	}

	/**
	 * @param int $post_id Post id.
	 * @return array<string,int>
	 */
	protected function translations_of( int $post_id ): array {
		$group = (array) pll_get_post_translations( $post_id );

		$out = array();
		foreach ( $group as $code => $id ) {
			$id = (int) $id;
			if ( $id > 0 && get_post( $id ) ) {
				$out[ (string) $code ] = $id;
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
		pll_set_post_language( $post_id, $code );
		return true;
	}

	/**
	 * @param array<string,int> $map Language code => post id.
	 * @return true|WP_Error
	 */
	protected function link_group( array $map ) {
		pll_save_post_translations( $map );
		return true;
	}
}
