<?php
/**
 * Schema.org structured data (JSON-LD) attached to a post.
 *
 * Search engines read JSON-LD, not the page's visual design, so this is the one
 * piece of SEO an agent can get completely right or completely wrong without
 * anything on the page looking different. The failure mode is specific: an
 * invalid node is not an error, it is *ignored* — the rich result never appears
 * and nobody finds out, because there is nothing to see either way.
 *
 * So the builder validates. Every type declares the properties Google actually
 * requires for its rich result, a node missing one is refused rather than
 * stored, and the friendlier shapes (a list of questions, a list of crumbs) are
 * expanded here rather than asked of the caller — nested `mainEntity` and
 * `itemListElement` structures are where hand-written JSON-LD goes wrong.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds, stores and prints JSON-LD for a post.
 *
 * @since 1.1.0
 */
class KarMCP_Structured_Data {

	/**
	 * Post meta key holding the node list.
	 */
	const META_KEY = '_karmcp_structured_data';

	/**
	 * Supported types and the properties each one needs to be worth emitting.
	 *
	 * These are the properties the type is useless without, not everything
	 * schema.org allows: any other property the caller sends is carried through
	 * untouched.
	 */
	const TYPES = array(
		'Organization'   => array( 'name' ),
		'LocalBusiness'  => array( 'name', 'address' ),
		'Product'        => array( 'name' ),
		'FAQPage'        => array( 'mainEntity' ),
		'BreadcrumbList' => array( 'itemListElement' ),
		'Article'        => array( 'headline' ),
		'Person'         => array( 'name' ),
		'Service'        => array( 'name' ),
		'Event'          => array( 'name', 'startDate' ),
	);

	/**
	 * Builds one validated JSON-LD node.
	 *
	 * @since 1.1.0
	 *
	 * @param string $type Schema.org type.
	 * @param array  $data Properties, or one of the friendly shapes below.
	 * @return array|WP_Error
	 */
	public static function build( string $type, array $data ) {
		$type = trim( $type );

		if ( ! isset( self::TYPES[ $type ] ) ) {
			$match = self::closest_type( $type );
			return new WP_Error(
				'unsupported_type',
				$match
					? sprintf(
						/* translators: 1: given type, 2: closest supported type. */
						__( 'Unsupported schema type "%1$s". Did you mean "%2$s"?', 'karmcp' ),
						$type,
						$match
					)
					: sprintf(
						/* translators: 1: given type, 2: comma-separated supported types. */
						__( 'Unsupported schema type "%1$s". Supported: %2$s.', 'karmcp' ),
						$type,
						implode( ', ', array_keys( self::TYPES ) )
					),
				array( 'status' => 400 )
			);
		}

		$node = self::expand( $type, $data );
		if ( is_wp_error( $node ) ) {
			return $node;
		}

		foreach ( self::TYPES[ $type ] as $required ) {
			if ( ! isset( $node[ $required ] ) || '' === $node[ $required ] || array() === $node[ $required ] ) {
				return new WP_Error(
					'missing_property',
					sprintf(
						/* translators: 1: schema type, 2: property name. */
						__( 'A %1$s node needs a "%2$s" property; without it search engines ignore the whole node.', 'karmcp' ),
						$type,
						$required
					),
					array( 'status' => 400 )
				);
			}
		}

		return array_merge(
			array(
				'@context' => 'https://schema.org',
				'@type'    => $type,
			),
			$node
		);
	}

	/**
	 * Turns the friendly shapes into real schema.org structures.
	 *
	 * @param string $type Type.
	 * @param array  $data Raw properties.
	 * @return array|WP_Error
	 */
	private static function expand( string $type, array $data ) {
		unset( $data['@context'], $data['@type'] );

		if ( 'FAQPage' === $type && ! isset( $data['mainEntity'] ) && isset( $data['faqs'] ) ) {
			if ( ! is_array( $data['faqs'] ) ) {
				return new WP_Error( 'invalid_argument', __( 'faqs must be an array of { question, answer } objects.', 'karmcp' ), array( 'status' => 400 ) );
			}
			$entities = array();
			foreach ( $data['faqs'] as $faq ) {
				$question = is_array( $faq ) ? trim( (string) ( $faq['question'] ?? '' ) ) : '';
				$answer   = is_array( $faq ) ? trim( (string) ( $faq['answer'] ?? '' ) ) : '';
				if ( '' === $question || '' === $answer ) {
					return new WP_Error(
						'invalid_argument',
						__( 'Every FAQ needs both a question and an answer. A question with no answer invalidates the whole node.', 'karmcp' ),
						array( 'status' => 400 )
					);
				}
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $question,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $answer,
					),
				);
			}
			unset( $data['faqs'] );
			$data['mainEntity'] = $entities;
		}

		if ( 'BreadcrumbList' === $type && ! isset( $data['itemListElement'] ) && isset( $data['items'] ) ) {
			if ( ! is_array( $data['items'] ) ) {
				return new WP_Error( 'invalid_argument', __( 'items must be an array of { name, url } objects.', 'karmcp' ), array( 'status' => 400 ) );
			}
			$elements = array();
			foreach ( array_values( $data['items'] ) as $index => $crumb ) {
				$name = is_array( $crumb ) ? trim( (string) ( $crumb['name'] ?? '' ) ) : trim( (string) $crumb );
				if ( '' === $name ) {
					return new WP_Error( 'invalid_argument', __( 'Every breadcrumb needs a name.', 'karmcp' ), array( 'status' => 400 ) );
				}
				// position is 1-based and must be contiguous, which is exactly
				// what hand-written breadcrumbs get wrong.
				$element = array(
					'@type'    => 'ListItem',
					'position' => $index + 1,
					'name'     => $name,
				);
				$url = is_array( $crumb ) ? trim( (string) ( $crumb['url'] ?? '' ) ) : '';
				if ( '' !== $url ) {
					$element['item'] = $url;
				}
				$elements[] = $element;
			}
			unset( $data['items'] );
			$data['itemListElement'] = $elements;
		}

		if ( 'Product' === $type && isset( $data['price'] ) ) {
			// A price without a currency is not an offer, and Google drops the
			// node rather than guessing.
			$currency = trim( (string) ( $data['priceCurrency'] ?? '' ) );
			if ( '' === $currency ) {
				return new WP_Error(
					'missing_property',
					__( 'A Product price needs priceCurrency alongside it (for example "EUR").', 'karmcp' ),
					array( 'status' => 400 )
				);
			}
			$offer = array(
				'@type'         => 'Offer',
				'price'         => (string) $data['price'],
				'priceCurrency' => $currency,
			);
			if ( isset( $data['availability'] ) ) {
				$offer['availability'] = self::availability_url( (string) $data['availability'] );
				unset( $data['availability'] );
			}
			if ( isset( $data['url'] ) ) {
				$offer['url'] = (string) $data['url'];
			}
			unset( $data['price'], $data['priceCurrency'] );
			$data['offers'] = $offer;
		}

		return $data;
	}

	/**
	 * Normalizes an availability word to the schema.org URL Google expects.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function availability_url( string $value ): string {
		$value = trim( $value );
		if ( 0 === strpos( $value, 'http' ) ) {
			return $value;
		}

		$known = array(
			'instock'        => 'InStock',
			'in_stock'       => 'InStock',
			'outofstock'     => 'OutOfStock',
			'out_of_stock'   => 'OutOfStock',
			'preorder'       => 'PreOrder',
			'backorder'      => 'BackOrder',
			'onbackorder'    => 'BackOrder',
			'discontinued'   => 'Discontinued',
		);

		$key = strtolower( str_replace( array( ' ', '-' ), '_', $value ) );
		$key = str_replace( '_', '', $key );

		foreach ( $known as $needle => $canonical ) {
			if ( str_replace( '_', '', $needle ) === $key ) {
				return 'https://schema.org/' . $canonical;
			}
		}

		return 'https://schema.org/' . $value;
	}

	/**
	 * The supported type closest to what the caller wrote, for a useful error.
	 *
	 * @param string $type Given type.
	 * @return string
	 */
	private static function closest_type( string $type ): string {
		$best     = '';
		$distance = 3; // Anything further away is not a typo.

		foreach ( array_keys( self::TYPES ) as $candidate ) {
			$this_distance = levenshtein( strtolower( $type ), strtolower( $candidate ) );
			if ( $this_distance < $distance ) {
				$distance = $this_distance;
				$best     = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Renders nodes as a script tag.
	 *
	 * @since 1.1.0
	 *
	 * @param array $nodes Node list.
	 * @return string
	 */
	public static function render( array $nodes ): string {
		if ( empty( $nodes ) ) {
			return '';
		}

		$payload = 1 === count( $nodes ) ? reset( $nodes ) : $nodes;
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return '';
		}

		// A literal </script> inside a value would close the tag early and spill
		// the rest of the payload into the page as markup. < is a valid JSON
		// escape, so the data survives intact while the markup cannot break out.
		$json = str_replace( array( '<', '>', '&' ), array( '\u003C', '\u003E', '\u0026' ), $json );

		return '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}

	/**
	 * Reads a post's nodes.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function get( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Replaces a post's nodes.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $post_id Post id.
	 * @param array $nodes   Built nodes.
	 * @return bool
	 */
	public static function save( int $post_id, array $nodes ): bool {
		if ( empty( $nodes ) ) {
			return (bool) delete_post_meta( $post_id, self::META_KEY );
		}
		return false !== update_post_meta( $post_id, self::META_KEY, $nodes );
	}

	/**
	 * Prints the current post's nodes in the document head.
	 *
	 * @since 1.1.0
	 */
	public static function print_head(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$nodes = self::get( $post_id );
		if ( empty( $nodes ) ) {
			return;
		}

		echo self::render( $nodes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() encodes and escapes its own payload.
	}

	/**
	 * Wires the front-end output.
	 *
	 * @since 1.1.0
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 20 );
	}

	/**
	 * SEO plugins that emit their own JSON-LD, so the agent can be told when a
	 * node it is adding will land next to one that already exists.
	 *
	 * Two Organization nodes on a page is not fatal, but it is the kind of
	 * duplicate that makes a search engine pick one arbitrarily.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Names of the active ones.
	 */
	public static function competing_emitters(): array {
		$candidates = array(
			'Yoast SEO'   => 'WPSEO_VERSION',
			'Rank Math'   => 'RANK_MATH_VERSION',
			'All in One SEO' => 'AIOSEO_VERSION',
			'SEOPress'    => 'SEOPRESS_VERSION',
			'Slim SEO'    => 'SLIM_SEO_VER',
		);

		$active = array();
		foreach ( $candidates as $label => $constant ) {
			if ( defined( $constant ) ) {
				$active[] = $label;
			}
		}

		return $active;
	}
}
