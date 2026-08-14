<?php
/**
 * Validates and normalizes product input before it reaches WooCommerce.
 *
 * WooCommerce stores a price as a string with a dot for decimals, and it does
 * not argue with what you give it: hand it "19,90" and it stores "19,90",
 * which `wc_format_decimal()` later reads as 19 — the product goes on sale for
 * nineteen euros and nobody sees a warning. An agent working in Spanish will
 * write "19,90" nine times out of ten, so parsing that correctly is not a nicety.
 *
 * Pure: no WooCommerce, no WordPress. Everything here is decided before a
 * single CRUD call happens, which is also what makes it testable.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product input in, clean field set out.
 *
 * @since 1.1.0
 */
class KarMCP_Woo_Product_Input {

	/**
	 * Product types this integration creates. `variable` is deliberately absent:
	 * a variable product is meaningless without its attributes and variations,
	 * and creating the shell alone leaves a product that cannot be bought.
	 */
	const TYPES = array( 'simple', 'grouped', 'external' );

	/**
	 * Publication states a product may be put in.
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Where the product shows up.
	 */
	const VISIBILITIES = array( 'visible', 'catalog', 'search', 'hidden' );

	/**
	 * Stock states.
	 */
	const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * Validates and normalizes a product payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input   Raw tool arguments.
	 * @param bool  $partial True for an update, where every field is optional and
	 *                       only what was sent gets written.
	 * @return array|WP_Error Normalized fields, or the first failure.
	 */
	public static function normalize( array $input, bool $partial = false ) {
		$out = array();

		// ---- identity ------------------------------------------------------

		if ( isset( $input['name'] ) ) {
			$name = trim( (string) $input['name'] );
			if ( '' === $name ) {
				return self::error( 'invalid_argument', __( 'name cannot be empty.', 'karmcp' ) );
			}
			$out['name'] = $name;
		} elseif ( ! $partial ) {
			return self::error( 'missing_argument', __( 'Missing required argument: name.', 'karmcp' ) );
		}

		if ( isset( $input['type'] ) ) {
			$type = strtolower( trim( (string) $input['type'] ) );
			if ( 'variable' === $type ) {
				return self::error(
					'unsupported_type',
					__( 'Variable products are not created by this tool: a variable product without its attributes and variations cannot be bought, and those need the WooCommerce editor. Create the variations there, or use a simple product per variant.', 'karmcp' )
				);
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return self::error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given type, 2: supported types. */
						__( 'Unsupported product type "%1$s". Supported: %2$s.', 'karmcp' ),
						$type,
						implode( ', ', self::TYPES )
					)
				);
			}
			$out['type'] = $type;
		} elseif ( ! $partial ) {
			$out['type'] = 'simple';
		}

		foreach ( array( 'description', 'short_description' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (string) $input[ $key ];
			}
		}

		if ( isset( $input['sku'] ) ) {
			$sku = trim( (string) $input['sku'] );
			if ( strlen( $sku ) > 100 ) {
				return self::error( 'invalid_argument', __( 'sku is limited to 100 characters.', 'karmcp' ) );
			}
			$out['sku'] = $sku;
		}

		if ( isset( $input['status'] ) ) {
			$status = strtolower( trim( (string) $input['status'] ) );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return self::error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given status, 2: supported statuses. */
						__( 'Unsupported status "%1$s". Supported: %2$s.', 'karmcp' ),
						$status,
						implode( ', ', self::STATUSES )
					)
				);
			}
			$out['status'] = $status;
		}

		if ( isset( $input['catalog_visibility'] ) ) {
			$visibility = strtolower( trim( (string) $input['catalog_visibility'] ) );
			if ( ! in_array( $visibility, self::VISIBILITIES, true ) ) {
				return self::error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given visibility, 2: supported values. */
						__( 'Unsupported catalog_visibility "%1$s". Supported: %2$s.', 'karmcp' ),
						$visibility,
						implode( ', ', self::VISIBILITIES )
					)
				);
			}
			$out['catalog_visibility'] = $visibility;
		}

		// ---- prices --------------------------------------------------------

		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			// An explicit empty string is how you clear a sale price, and that
			// has to survive: otherwise a discount can be set and never removed.
			if ( '' === $input[ $key ] || null === $input[ $key ] ) {
				$out[ $key ] = '';
				continue;
			}
			$price = self::parse_price( $input[ $key ] );
			if ( null === $price ) {
				return self::error(
					'invalid_argument',
					sprintf(
						/* translators: 1: argument name, 2: given value. */
						__( '%1$s is not a number: "%2$s".', 'karmcp' ),
						$key,
						(string) $input[ $key ]
					)
				);
			}
			if ( $price < 0 ) {
				return self::error(
					'invalid_argument',
					sprintf(
						/* translators: %s: argument name. */
						__( '%s cannot be negative.', 'karmcp' ),
						$key
					)
				);
			}
			$out[ $key ] = self::format_price( $price );
		}

		// A sale price above the regular one is not a discount; WooCommerce
		// accepts it and then never applies it, so the product quietly sells at
		// the higher price with a "sale" badge that does nothing.
		if ( isset( $out['regular_price'], $out['sale_price'] )
			&& '' !== $out['regular_price'] && '' !== $out['sale_price']
			&& (float) $out['sale_price'] > (float) $out['regular_price'] ) {
			return self::error(
				'invalid_argument',
				sprintf(
					/* translators: 1: sale price, 2: regular price. */
					__( 'sale_price (%1$s) is higher than regular_price (%2$s), so WooCommerce would never apply it.', 'karmcp' ),
					$out['sale_price'],
					$out['regular_price']
				)
			);
		}

		// ---- stock ---------------------------------------------------------

		if ( isset( $input['manage_stock'] ) ) {
			$out['manage_stock'] = self::truthy( $input['manage_stock'] );
		}

		if ( isset( $input['stock_quantity'] ) && '' !== $input['stock_quantity'] && null !== $input['stock_quantity'] ) {
			if ( ! is_numeric( $input['stock_quantity'] ) ) {
				return self::error( 'invalid_argument', __( 'stock_quantity must be a number.', 'karmcp' ) );
			}
			$out['stock_quantity'] = (int) $input['stock_quantity'];
			// Tracking a quantity without switching stock management on leaves
			// the number stored and ignored, which reads as a stock bug later.
			if ( ! isset( $out['manage_stock'] ) ) {
				$out['manage_stock'] = true;
			}
		}

		if ( isset( $input['stock_status'] ) ) {
			$stock_status = strtolower( trim( (string) $input['stock_status'] ) );
			if ( ! in_array( $stock_status, self::STOCK_STATUSES, true ) ) {
				return self::error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given status, 2: supported values. */
						__( 'Unsupported stock_status "%1$s". Supported: %2$s.', 'karmcp' ),
						$stock_status,
						implode( ', ', self::STOCK_STATUSES )
					)
				);
			}
			$out['stock_status'] = $stock_status;
		}

		// ---- flags and shipping --------------------------------------------

		foreach ( array( 'virtual', 'downloadable', 'featured' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = self::truthy( $input[ $flag ] );
			}
		}

		if ( isset( $input['weight'] ) && '' !== $input['weight'] ) {
			$weight = self::parse_price( $input['weight'] );
			if ( null === $weight || $weight < 0 ) {
				return self::error( 'invalid_argument', __( 'weight must be a non-negative number.', 'karmcp' ) );
			}
			$out['weight'] = self::format_price( $weight );
		}

		foreach ( array( 'length', 'width', 'height' ) as $dimension ) {
			if ( isset( $input[ $dimension ] ) && '' !== $input[ $dimension ] ) {
				$value = self::parse_price( $input[ $dimension ] );
				if ( null === $value || $value < 0 ) {
					return self::error(
						'invalid_argument',
						sprintf(
							/* translators: %s: dimension name. */
							__( '%s must be a non-negative number.', 'karmcp' ),
							$dimension
						)
					);
				}
				$out[ $dimension ] = self::format_price( $value );
			}
		}

		// ---- external product ----------------------------------------------

		if ( isset( $input['external_url'] ) ) {
			$out['external_url'] = trim( (string) $input['external_url'] );
		}
		if ( isset( $input['button_text'] ) ) {
			$out['button_text'] = trim( (string) $input['button_text'] );
		}

		if ( 'external' === ( $out['type'] ?? '' ) && empty( $out['external_url'] ) ) {
			return self::error(
				'missing_argument',
				__( 'An external product needs an external_url; without it the buy button goes nowhere.', 'karmcp' )
			);
		}

		// ---- taxonomies and media ------------------------------------------

		foreach ( array( 'categories', 'tags' ) as $taxonomy ) {
			if ( isset( $input[ $taxonomy ] ) ) {
				if ( ! is_array( $input[ $taxonomy ] ) ) {
					return self::error(
						'invalid_argument',
						sprintf(
							/* translators: %s: argument name. */
							__( '%s must be an array of names or term ids.', 'karmcp' ),
							$taxonomy
						)
					);
				}
				$out[ $taxonomy ] = array_values(
					array_filter(
						array_map(
							static function ( $term ) {
								return is_int( $term ) ? $term : trim( (string) $term );
							},
							$input[ $taxonomy ]
						),
						static function ( $term ) {
							return '' !== $term && 0 !== $term;
						}
					)
				);
			}
		}

		if ( isset( $input['image_ids'] ) ) {
			if ( ! is_array( $input['image_ids'] ) ) {
				return self::error( 'invalid_argument', __( 'image_ids must be an array of Media Library attachment ids.', 'karmcp' ) );
			}
			$ids = array();
			foreach ( $input['image_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$out['image_ids'] = array_values( array_unique( $ids ) );
		}

		if ( empty( $out ) ) {
			return self::error( 'missing_argument', __( 'Nothing to write: send at least one field.', 'karmcp' ) );
		}

		return $out;
	}

	/**
	 * Reads a price written by a human in any of the usual ways.
	 *
	 * Handles "19,90", "1.299,00", "1,299.00", "€19.90" and plain numbers. When
	 * both separators are present, the rightmost is the decimal one — that rule
	 * covers every European and Anglo convention without having to know which
	 * locale the caller had in mind.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return float|null Null when it is not a number at all.
	 */
	public static function parse_price( $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$text = trim( $value );
		if ( '' === $text ) {
			return null;
		}

		// Drop currency symbols, spaces and non-breaking spaces, keeping the
		// sign, the digits and the separators.
		$text = preg_replace( '/[^\d,.\-]/u', '', $text );
		if ( null === $text || '' === $text || ! preg_match( '/\d/', $text ) ) {
			return null;
		}

		$last_comma = strrpos( $text, ',' );
		$last_dot   = strrpos( $text, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			// Both present: the rightmost separates the decimals, the other
			// groups thousands.
			if ( $last_comma > $last_dot ) {
				$text = str_replace( '.', '', $text );
				$text = str_replace( ',', '.', $text );
			} else {
				$text = str_replace( ',', '', $text );
			}
		} elseif ( false !== $last_comma ) {
			$text = self::resolve_lone_comma( $text );
		}

		if ( ! is_numeric( $text ) ) {
			return null;
		}

		return (float) $text;
	}

	/**
	 * Decides what a comma means when it is the only separator present.
	 *
	 * This one is genuinely ambiguous — "1,299" is one thousand two hundred and
	 * ninety-nine to an English speaker and one point two nine nine to a Spanish
	 * one — so the rule is stated rather than guessed at, and tested:
	 *
	 * - More than one comma: grouping. "1,299,000" has no other reading.
	 * - Exactly three digits after a single comma, and a leading group of one to
	 *   three digits that does not start with a zero: grouping. Prices carry two
	 *   decimals, so "1,299" is a thousand-something. "0,125" is not — a
	 *   thousands group never starts with zero — so that stays a decimal.
	 * - Anything else: decimal separator. This is the common case — "19,90".
	 *
	 * @param string $text Digits, commas and an optional sign.
	 * @return string A number PHP can read.
	 */
	private static function resolve_lone_comma( string $text ): string {
		$parts = explode( ',', $text );

		if ( count( $parts ) > 2 ) {
			return implode( '', $parts );
		}
		if ( 3 === strlen( $parts[1] ) && preg_match( '/^-?[1-9]\d{0,2}$/', $parts[0] ) ) {
			return implode( '', $parts );
		}

		return str_replace( ',', '.', $text );
	}

	/**
	 * Renders a price the way WooCommerce stores one.
	 *
	 * @param float $price Price.
	 * @return string
	 */
	public static function format_price( float $price ): string {
		return rtrim( rtrim( number_format( $price, 4, '.', '' ), '0' ), '.' ) ?: '0';
	}

	/**
	 * Reads a boolean written as a boolean, a number, or any of the strings a
	 * JSON-speaking client might send.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value > 0;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( 'yes', 'true', 'on', '1' ), true );
	}

	/**
	 * Builds a 400-tagged error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
