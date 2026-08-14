<?php
/**
 * Turns a site brief into an ordered, checked plan.
 *
 * `build-site` is the one tool that touches several domains at once — pages,
 * reading settings, a menu, the global palette — and that is exactly why the
 * decisions are made here, before anything is written. A composite that
 * validates as it goes fails halfway: three pages created, the menu missing,
 * and the front page pointing at something that does not exist. Planning first
 * means an unusable brief is refused with nothing written at all.
 *
 * Pure: no WordPress. The plan is data, and the executor is what has side
 * effects.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brief in, plan out.
 *
 * @since 1.1.0
 */
class KarMCP_Site_Plan {

	/**
	 * Global colour slots, in the order Elementor's system palette uses.
	 */
	const COLOR_SLOTS = array( 'primary', 'secondary', 'text', 'accent' );

	/**
	 * Cap on pages in a single run. Past this it is an import, not a brief, and
	 * a runaway list would create hundreds of empty pages before anyone noticed.
	 */
	const MAX_PAGES = 30;

	/**
	 * Builds the plan.
	 *
	 * @since 1.1.0
	 *
	 * @param array $brief {
	 *     @type string $site_name  Used for the menu name and titles.
	 *     @type array  $pages      [{ title, slug?, front_page?, in_menu?, status? }]
	 *     @type array  $menu       { name?, location? }
	 *     @type array  $colors     { primary?, secondary?, text?, accent? } as hex.
	 *     @type array  $typography { headings?, body? } as font family names.
	 * }
	 * @return array|WP_Error
	 */
	public static function build( array $brief ) {
		$pages = self::plan_pages( $brief['pages'] ?? array() );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$colors = self::plan_colors( $brief['colors'] ?? array() );
		if ( is_wp_error( $colors ) ) {
			return $colors;
		}

		$site_name = isset( $brief['site_name'] ) ? trim( (string) $brief['site_name'] ) : '';

		$menu = null;
		if ( ! isset( $brief['menu'] ) || false !== $brief['menu'] ) {
			$menu_brief = is_array( $brief['menu'] ?? null ) ? $brief['menu'] : array();
			$menu       = array(
				'name'     => isset( $menu_brief['name'] ) && '' !== trim( (string) $menu_brief['name'] )
					? trim( (string) $menu_brief['name'] )
					: ( '' !== $site_name ? $site_name : 'Main' ),
				'location' => isset( $menu_brief['location'] ) ? sanitize_key( (string) $menu_brief['location'] ) : '',
				'items'    => array_values(
					array_filter(
						$pages,
						static function ( array $page ): bool {
							return $page['in_menu'];
						}
					)
				),
			);
		}

		$typography = array();
		foreach ( array( 'headings', 'body' ) as $slot ) {
			if ( ! empty( $brief['typography'][ $slot ] ) ) {
				$typography[ $slot ] = trim( (string) $brief['typography'][ $slot ] );
			}
		}

		$front = null;
		foreach ( $pages as $page ) {
			if ( $page['front_page'] ) {
				$front = $page['slug'];
				break;
			}
		}

		return array(
			'site_name'  => $site_name,
			'pages'      => $pages,
			'front_page' => $front,
			'menu'       => $menu,
			'colors'     => $colors,
			'typography' => $typography,
		);
	}

	/**
	 * Normalizes the page list: slugs derived, deduplicated, one front page.
	 *
	 * @param mixed $pages Raw page list.
	 * @return array|WP_Error
	 */
	private static function plan_pages( $pages ) {
		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return new WP_Error(
				'missing_argument',
				__( 'A site brief needs at least one page.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $pages ) > self::MAX_PAGES ) {
			return new WP_Error(
				'too_many_pages',
				sprintf(
					/* translators: %d: maximum page count. */
					__( 'A single run creates at most %d pages. Split the brief.', 'karmcp' ),
					self::MAX_PAGES
				),
				array( 'status' => 400 )
			);
		}

		$out   = array();
		$slugs = array();
		$front = 0;

		foreach ( array_values( $pages ) as $index => $page ) {
			if ( is_string( $page ) ) {
				$page = array( 'title' => $page );
			}
			if ( ! is_array( $page ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: %d: page position. */
						__( 'Page %d is neither a title nor an object.', 'karmcp' ),
						$index + 1
					),
					array( 'status' => 400 )
				);
			}

			$title = trim( (string) ( $page['title'] ?? '' ) );
			if ( '' === $title ) {
				return new WP_Error(
					'missing_argument',
					sprintf(
						/* translators: %d: page position. */
						__( 'Page %d has no title.', 'karmcp' ),
						$index + 1
					),
					array( 'status' => 400 )
				);
			}

			$slug = sanitize_title( '' !== trim( (string) ( $page['slug'] ?? '' ) ) ? (string) $page['slug'] : $title );
			if ( '' === $slug ) {
				$slug = 'page-' . ( $index + 1 );
			}
			if ( isset( $slugs[ $slug ] ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: %s: page slug. */
						__( 'Two pages would share the slug "%s". WordPress would rename one of them and the menu would point at the wrong page.', 'karmcp' ),
						$slug
					),
					array( 'status' => 400 )
				);
			}
			$slugs[ $slug ] = true;

			$is_front = ! empty( $page['front_page'] );
			if ( $is_front ) {
				++$front;
			}

			$out[] = array(
				'title'      => $title,
				'slug'       => $slug,
				'status'     => isset( $page['status'] ) ? sanitize_key( (string) $page['status'] ) : 'publish',
				'front_page' => $is_front,
				// A front page in the menu as well is a duplicate of the home
				// link most themes already render, so it stays out by default.
				'in_menu'    => array_key_exists( 'in_menu', $page ) ? (bool) $page['in_menu'] : ! $is_front,
			);
		}

		if ( $front > 1 ) {
			return new WP_Error(
				'invalid_argument',
				__( 'Only one page can be the front page.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}

		return $out;
	}

	/**
	 * Validates the palette.
	 *
	 * @param mixed $colors Raw colours.
	 * @return array|WP_Error
	 */
	private static function plan_colors( $colors ) {
		if ( empty( $colors ) ) {
			return array();
		}
		if ( ! is_array( $colors ) ) {
			return new WP_Error( 'invalid_argument', __( 'colors must be an object of slot => hex value.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$out = array();
		foreach ( $colors as $slot => $value ) {
			$slot = sanitize_key( (string) $slot );
			if ( ! in_array( $slot, self::COLOR_SLOTS, true ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given slot, 2: supported slots. */
						__( 'Unknown colour slot "%1$s". Supported: %2$s.', 'karmcp' ),
						$slot,
						implode( ', ', self::COLOR_SLOTS )
					),
					array( 'status' => 400 )
				);
			}

			$hex = self::normalize_hex( (string) $value );
			if ( null === $hex ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: 1: slot name, 2: given value. */
						__( 'Colour "%1$s" is not a hex value: "%2$s".', 'karmcp' ),
						$slot,
						(string) $value
					),
					array( 'status' => 400 )
				);
			}

			$out[ $slot ] = $hex;
		}

		return $out;
	}

	/**
	 * Reads a hex colour written any of the usual ways.
	 *
	 * Elementor stores `#RRGGBB` and does not normalize what it is given, so a
	 * three-digit shorthand or a missing hash is accepted here and expanded,
	 * rather than stored as something no colour picker will show.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Raw value.
	 * @return string|null
	 */
	public static function normalize_hex( string $value ): ?string {
		$hex = strtoupper( ltrim( trim( $value ), '#' ) );

		if ( preg_match( '/^[0-9A-F]{3}$/', $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9A-F]{6}$/', $hex ) ) {
			return null;
		}

		return '#' . $hex;
	}
}
