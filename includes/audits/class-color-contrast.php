<?php
/**
 * WCAG contrast maths.
 *
 * Pure and closed: the formula is specified, so this is one of the few places
 * where an exact answer is available and worth insisting on.
 *
 * What is *not* exact is knowing which two colours to compare. Resolving the
 * cascade needs a CSS engine and a layout, neither of which exists here, so the
 * caller will often be unable to say what sits behind a given piece of text.
 * The honest answer there is `inconclusive`, never a pass — a false "meets AA"
 * is worse than no check at all, because it closes the question.
 *
 * @package KarMCP
 * @since   1.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses CSS colours and scores contrast ratios against WCAG.
 *
 * @since 1.16.0
 */
class KarMCP_Color_Contrast {

	/**
	 * WCAG 2.1 minimum ratios.
	 */
	const AA_NORMAL  = 4.5;
	const AA_LARGE   = 3.0;
	const AAA_NORMAL = 7.0;
	const AAA_LARGE  = 4.5;

	/**
	 * "Large text" starts at 18pt, or 14pt when bold — 24px and 18.66px.
	 */
	const LARGE_PX      = 24.0;
	const LARGE_BOLD_PX = 18.66;

	/**
	 * The CSS named colours worth carrying.
	 *
	 * Not the full list of 148: these are the ones that turn up in real theme
	 * and builder output. Anything unknown returns null and the caller reports
	 * inconclusive, which is the correct outcome for a colour we cannot read.
	 */
	const NAMED = array(
		'black'   => array( 0, 0, 0 ),
		'silver'  => array( 192, 192, 192 ),
		'gray'    => array( 128, 128, 128 ),
		'grey'    => array( 128, 128, 128 ),
		'white'   => array( 255, 255, 255 ),
		'maroon'  => array( 128, 0, 0 ),
		'red'     => array( 255, 0, 0 ),
		'purple'  => array( 128, 0, 128 ),
		'fuchsia' => array( 255, 0, 255 ),
		'green'   => array( 0, 128, 0 ),
		'lime'    => array( 0, 255, 0 ),
		'olive'   => array( 128, 128, 0 ),
		'yellow'  => array( 255, 255, 0 ),
		'navy'    => array( 0, 0, 128 ),
		'blue'    => array( 0, 0, 255 ),
		'teal'    => array( 0, 128, 128 ),
		'aqua'    => array( 0, 255, 255 ),
		'cyan'    => array( 0, 255, 255 ),
		'magenta' => array( 255, 0, 255 ),
		'orange'  => array( 255, 165, 0 ),
	);

	/**
	 * Parses a CSS colour into RGB plus alpha.
	 *
	 * @param string $color CSS colour value.
	 * @return array|null { r, g, b, a } with a in 0..1, or null when unreadable.
	 */
	public static function parse( string $color ): ?array {
		$color = strtolower( trim( $color ) );

		if ( '' === $color || 'transparent' === $color || 'inherit' === $color || 'currentcolor' === $color ) {
			return null;
		}

		if ( isset( self::NAMED[ $color ] ) ) {
			$rgb = self::NAMED[ $color ];
			return array(
				'r' => $rgb[0],
				'g' => $rgb[1],
				'b' => $rgb[2],
				'a' => 1.0,
			);
		}

		if ( 0 === strpos( $color, '#' ) ) {
			return self::parse_hex( substr( $color, 1 ) );
		}

		if ( preg_match( '/^rgba?\((.+)\)$/', $color, $matches ) ) {
			return self::parse_rgb( $matches[1] );
		}

		return null;
	}

	/**
	 * Parses the body of a hex colour.
	 *
	 * @param string $hex Hex digits, without the hash.
	 * @return array|null
	 */
	private static function parse_hex( string $hex ): ?array {
		if ( ! preg_match( '/^[0-9a-f]+$/', $hex ) ) {
			return null;
		}

		$length = strlen( $hex );

		if ( 3 === $length || 4 === $length ) {
			// #abc expands to #aabbcc — each digit doubles.
			$expanded = '';
			foreach ( str_split( $hex ) as $digit ) {
				$expanded .= $digit . $digit;
			}
			$hex    = $expanded;
			$length = strlen( $hex );
		}

		if ( 6 !== $length && 8 !== $length ) {
			return null;
		}

		return array(
			'r' => (int) hexdec( substr( $hex, 0, 2 ) ),
			'g' => (int) hexdec( substr( $hex, 2, 2 ) ),
			'b' => (int) hexdec( substr( $hex, 4, 2 ) ),
			'a' => 8 === $length ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0,
		);
	}

	/**
	 * Parses the arguments of rgb() / rgba(), in either the comma syntax or the
	 * modern space syntax with a slash before the alpha.
	 *
	 * @param string $args Argument list.
	 * @return array|null
	 */
	private static function parse_rgb( string $args ): ?array {
		$args  = str_replace( '/', ' ', $args );
		$parts = preg_split( '/[\s,]+/', trim( $args ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
			return null;
		}

		$channels = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$value = $parts[ $i ];
			if ( ! is_numeric( rtrim( $value, '%' ) ) ) {
				return null;
			}
			$number     = (float) rtrim( $value, '%' );
			$channels[] = (int) round( self::clamp( '%' === substr( $value, -1 ) ? $number * 2.55 : $number, 0, 255 ) );
		}

		$alpha = 1.0;
		if ( isset( $parts[3] ) && is_numeric( rtrim( $parts[3], '%' ) ) ) {
			$raw   = (float) rtrim( $parts[3], '%' );
			$alpha = self::clamp( '%' === substr( $parts[3], -1 ) ? $raw / 100 : $raw, 0, 1 );
		}

		return array(
			'r' => $channels[0],
			'g' => $channels[1],
			'b' => $channels[2],
			'a' => $alpha,
		);
	}

	/**
	 * Relative luminance, per the WCAG definition.
	 *
	 * @param array $color { r, g, b }.
	 * @return float 0..1.
	 */
	public static function luminance( array $color ): float {
		$channels = array();

		foreach ( array( 'r', 'g', 'b' ) as $key ) {
			$value = self::clamp( (float) ( $color[ $key ] ?? 0 ), 0, 255 ) / 255;

			$channels[] = $value <= 0.04045
				? $value / 12.92
				: pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * Contrast ratio between two colours, 1..21.
	 *
	 * @param array $first  { r, g, b }.
	 * @param array $second { r, g, b }.
	 * @return float Rounded to two decimals.
	 */
	public static function ratio( array $first, array $second ): float {
		$a = self::luminance( $first );
		$b = self::luminance( $second );

		$lighter = max( $a, $b );
		$darker  = min( $a, $b );

		return round( ( $lighter + 0.05 ) / ( $darker + 0.05 ), 2 );
	}

	/**
	 * Whether text of a given size counts as "large" for the relaxed threshold.
	 *
	 * @param float $font_size_px Font size in pixels.
	 * @param bool  $bold         Whether the text is bold.
	 * @return bool
	 */
	public static function is_large( float $font_size_px, bool $bold = false ): bool {
		return $bold ? $font_size_px >= self::LARGE_BOLD_PX : $font_size_px >= self::LARGE_PX;
	}

	/**
	 * The ratio a piece of text has to meet.
	 *
	 * @param bool   $large Whether the text is large.
	 * @param string $level 'AA' or 'AAA'.
	 * @return float
	 */
	public static function required( bool $large, string $level = 'AA' ): float {
		if ( 'AAA' === strtoupper( $level ) ) {
			return $large ? self::AAA_LARGE : self::AAA_NORMAL;
		}

		return $large ? self::AA_LARGE : self::AA_NORMAL;
	}

	/**
	 * Scores a foreground against a background.
	 *
	 * Returns `inconclusive` rather than a verdict whenever the answer would
	 * depend on something not visible here: a colour that could not be parsed,
	 * or a translucent one, whose effective value depends on whatever is
	 * painted underneath.
	 *
	 * When the font size is unknown — which is the normal case, since sizes
	 * usually come from a stylesheet — there is still a rigorous answer for two
	 * thirds of the range: below 3:1 the text fails at any size, at or above
	 * 4.5:1 it passes at any size, and only in between does the verdict actually
	 * turn on the size.
	 *
	 * @param string|null $foreground   CSS colour.
	 * @param string|null $background   CSS colour.
	 * @param float|null  $font_size_px Font size in pixels, or null when unknown.
	 * @param bool        $bold         Whether the text is bold.
	 * @param string      $level        'AA' or 'AAA'.
	 * @return array { status: pass|fail|inconclusive, ratio?, required?, large, reason? }
	 */
	public static function evaluate( ?string $foreground, ?string $background, ?float $font_size_px = 16.0, bool $bold = false, string $level = 'AA' ): array {
		$size_known = null !== $font_size_px;
		$large      = $size_known && self::is_large( $font_size_px, $bold );
		$required   = $size_known ? self::required( $large, $level ) : self::required( false, $level );

		$fg = null === $foreground ? null : self::parse( $foreground );
		$bg = null === $background ? null : self::parse( $background );

		if ( null === $fg || null === $bg ) {
			return array(
				'status'   => 'inconclusive',
				'large'    => $large,
				'required' => $required,
				'reason'   => null === $fg ? 'foreground_unknown' : 'background_unknown',
			);
		}

		// A translucent colour sits on top of whatever is behind it, and what is
		// behind it is exactly what we cannot see. Compositing against a guess
		// would produce a confident wrong answer.
		if ( $fg['a'] < 1 || $bg['a'] < 1 ) {
			return array(
				'status'   => 'inconclusive',
				'large'    => $large,
				'required' => $required,
				'reason'   => 'translucent',
			);
		}

		$ratio = self::ratio( $fg, $bg );

		if ( ! $size_known ) {
			$strict  = self::required( false, $level );
			$relaxed = self::required( true, $level );

			if ( $ratio >= $strict ) {
				// Passes even at the strict threshold, so the size cannot change it.
				return array(
					'status'   => 'pass',
					'ratio'    => $ratio,
					'required' => $strict,
					'large'    => false,
				);
			}

			if ( $ratio < $relaxed ) {
				// Fails even at the relaxed threshold, so the size cannot save it.
				return array(
					'status'   => 'fail',
					'ratio'    => $ratio,
					'required' => $relaxed,
					'large'    => false,
				);
			}

			return array(
				'status'   => 'inconclusive',
				'ratio'    => $ratio,
				'required' => $strict,
				'large'    => false,
				'reason'   => 'size_unknown',
			);
		}

		return array(
			'status'   => $ratio >= $required ? 'pass' : 'fail',
			'ratio'    => $ratio,
			'required' => $required,
			'large'    => $large,
		);
	}

	/**
	 * Clamps a number.
	 *
	 * @param float $value Value.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return float
	 */
	private static function clamp( float $value, float $min, float $max ): float {
		return max( $min, min( $max, $value ) );
	}
}
