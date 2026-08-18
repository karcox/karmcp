<?php
/**
 * Audits the curated widget catalog against the controls a widget really has.
 *
 * The catalog is what an agent reads BEFORE writing, so a wrong entry is the
 * most expensive kind of defect this plugin can carry: the write is accepted,
 * the value is stored, nothing warns, and the result is not what the
 * description promised. Finding those one at a time costs a course build each.
 *
 * Three of them arrived within two days, and they share a shape rather than a
 * cause, which is why this checks three different things:
 *
 *   button_padding  the param does not exist on that widget at all — it is the
 *                   kit's control, so it stores and never renders
 *   insert_url      the param exists but is a SWITCHER for an external URL,
 *                   documented as the self-hosted URL object: the opposite
 *   quote_size      the param exists and takes the shape given, but the widget
 *                   renders it as calc(size * 100), so 62 means 6200px
 *
 * Deliberately pure: controls in, findings out, no WordPress. That is what
 * lets the three real cases above be pinned as tests without an Elementor
 * install, and it is the same split KarMCP_Seo_Audit uses.
 *
 * @package KarMCP
 * @since   1.20.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares one catalog entry against one widget's registered controls.
 *
 * @since 1.20.2
 */
class KarMCP_Catalog_Audit {

	/**
	 * Runs the three checks over a single widget.
	 *
	 * @since 1.20.2
	 *
	 * @param string $widget_type The widget type.
	 * @param array  $params      The catalog's `params` map for it.
	 * @param array  $controls    Control id => control definition, from
	 *                            KarMCP_Schema_Generator::controls().
	 * @return array<int,array<string,mixed>> Findings, worst first.
	 */
	public static function run( string $widget_type, array $params, array $controls ): array {
		$findings = array();

		foreach ( $params as $param => $spec ) {
			$control = $controls[ $param ] ?? null;

			if ( null === $control ) {
				// Responsive variants are generated, not registered, so a
				// `_tablet`/`_mobile` suffix on a real control is legitimate.
				if ( self::is_responsive_variant_of( $param, $controls ) ) {
					continue;
				}

				$findings[] = array(
					'check'    => 'missing_control',
					'severity' => 'error',
					'widget'   => $widget_type,
					'param'    => $param,
					'message'  => sprintf(
						/* translators: 1: param name, 2: widget type */
						__( '"%1$s" is published for %2$s but the widget registers no such control. Writing it is accepted, stored and never rendered.', 'karmcp' ),
						$param,
						$widget_type
					),
					'nearest'  => self::nearest( $param, array_keys( $controls ) ),
				);
				continue;
			}

			$mismatch = self::type_mismatch( $spec, $control );
			if ( null !== $mismatch ) {
				$findings[] = array(
					'check'    => 'type_mismatch',
					'severity' => 'error',
					'widget'   => $widget_type,
					'param'    => $param,
					'message'  => sprintf(
						/* translators: 1: param, 2: documented type, 3: real control type, 4: real label */
						__( '"%1$s" is documented as %2$s but the control is a %3$s ("%4$s"). The description is describing something else.', 'karmcp' ),
						$param,
						$mismatch['documented'],
						$mismatch['actual'],
						$mismatch['label']
					),
				);
			}

			if ( self::range_undocumented( $spec, $control ) ) {
				$findings[] = array(
					'check'    => 'undocumented_range',
					'severity' => 'warning',
					'widget'   => $widget_type,
					'param'    => $param,
					'message'  => sprintf(
						/* translators: 1: param, 2: min, 3: max */
						__( '"%1$s" only accepts %2$s to %3$s, and the description does not say so. A value outside that range is stored without complaint.', 'karmcp' ),
						$param,
						(string) ( $control['range'][ self::first_unit( $control ) ]['min'] ?? '?' ),
						(string) ( $control['range'][ self::first_unit( $control ) ]['max'] ?? '?' )
					),
				);
			}

			if ( self::transform_undocumented( $spec, $control ) ) {
				$findings[] = array(
					'check'    => 'undocumented_transform',
					'severity' => 'error',
					'widget'   => $widget_type,
					'param'    => $param,
					'message'  => sprintf(
						/* translators: 1: param name, 2: the selector rule */
						__( '"%1$s" is not used as given: the widget renders it through "%2$s". A value read as a plain length will be wrong by that factor, and nothing reports it.', 'karmcp' ),
						$param,
						self::transforming_selector( $control )
					),
				);
			}
		}

		return $findings;
	}

	/**
	 * Whether a catalog param is a responsive variant of a real control.
	 *
	 * @param string $param    The param name.
	 * @param array  $controls The registered controls.
	 * @return bool
	 */
	private static function is_responsive_variant_of( string $param, array $controls ): bool {
		foreach ( array( '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ) as $suffix ) {
			if ( str_ends_with( $param, $suffix )
				&& isset( $controls[ substr( $param, 0, -strlen( $suffix ) ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compares the documented JSON type against the control's real one.
	 *
	 * Only flags the pairs that genuinely mislead. A SWITCHER documented as an
	 * object is `insert_url`: the agent sends `{url: "..."}` and gets a truthy
	 * value, which flips the widget to a source it never set.
	 *
	 * @param array $spec    The catalog entry for the param.
	 * @param array $control The registered control.
	 * @return array{documented:string,actual:string,label:string}|null
	 */
	private static function type_mismatch( array $spec, array $control ): ?array {
		$documented = (string) ( $spec['type'] ?? '' );
		$actual     = (string) ( $control['type'] ?? '' );

		if ( '' === $documented || '' === $actual ) {
			return null;
		}

		// What the control mapper would have produced from the real control.
		$mapped   = class_exists( 'KarMCP_Control_Mapper' ) ? KarMCP_Control_Mapper::map( $control ) : array();
		$expected = (string) ( $mapped['type'] ?? '' );

		if ( '' === $expected || $expected === $documented ) {
			return null;
		}

		/*
		 * A scalar documented as a scalar is close enough: string vs number on
		 * a text field costs nobody anything, and Elementor coerces. What is
		 * worth a finding is a shape mismatch — scalar vs object/array — since
		 * that is the one that makes an agent send the wrong thing entirely.
		 */
		$shape = static fn( string $t ): string => in_array( $t, array( 'object', 'array' ), true ) ? 'composite' : 'scalar';

		if ( $shape( $documented ) === $shape( $expected ) ) {
			return null;
		}

		/*
		 * Repeaters are written as an array of rows whatever the control calls
		 * itself, and third-party ones carry their own type name — Elementor
		 * Pro's form fields come through as `form-fields-repeater`. Documenting
		 * those as arrays is right, so flagging them would be reporting the
		 * catalog for being correct.
		 */
		if ( 'array' === $documented && str_contains( $actual, 'repeater' ) ) {
			return null;
		}

		return array(
			'documented' => $documented,
			'actual'     => $actual,
			'label'      => (string) ( $control['label'] ?? '' ),
		);
	}

	/**
	 * @param array $control The registered control.
	 * @return string The first unit its range is declared in.
	 */
	private static function first_unit( array $control ): string {
		$range = (array) ( $control['range'] ?? array() );
		return (string) ( array_key_first( $range ) ?? 'px' );
	}

	/**
	 * Whether the control declares a bounded range the description omits.
	 *
	 * @param array $spec    The catalog entry.
	 * @param array $control The registered control.
	 * @return bool
	 */
	private static function range_undocumented( array $spec, array $control ): bool {
		$unit  = self::first_unit( $control );
		$range = (array) ( $control['range'][ $unit ] ?? array() );

		if ( ! isset( $range['min'], $range['max'] ) ) {
			return false;
		}

		// Elementor gives almost every slider a nominal 0-100; only a range
		// that actually constrains is worth documenting.
		if ( (float) $range['min'] <= 0 && (float) $range['max'] >= 100 ) {
			return false;
		}

		$description = (string) ( $spec['description'] ?? '' );

		return ! ( str_contains( $description, (string) $range['min'] ) && str_contains( $description, (string) $range['max'] ) );
	}

	/**
	 * The selector that puts the value through an operation, if any.
	 *
	 * `calc({{SIZE}}{{UNIT}} * 100)` is the quote_size case: what the agent
	 * sends is not what the browser gets.
	 *
	 * @param array $control The registered control.
	 * @return string The offending CSS rule, or ''.
	 */
	private static function transforming_selector( array $control ): string {
		$transformed = '';

		foreach ( (array) ( $control['selectors'] ?? array() ) as $rule ) {
			$rule = (string) $rule;

			if ( preg_match( '/\{\{SIZE\}\}[^;]*[*\/]|[*\/][^;]*\{\{SIZE\}\}|-\s*\{\{SIZE\}\}/', $rule ) ) {
				$transformed = trim( $rule );
				continue;
			}

			/*
			 * The value also lands somewhere untouched, so what the caller sends
			 * IS what that property gets. Plenty of controls drive a second,
			 * derived rule off the same value — a offset computed from a size,
			 * say — and reporting those would bury the case worth reporting:
			 * `quote_size`, where calc() is the only thing the value ever feeds.
			 */
			if ( str_contains( $rule, '{{SIZE}}' ) ) {
				return '';
			}
		}

		return $transformed;
	}

	/**
	 * Whether the value is transformed on render and the description is silent.
	 *
	 * @param array $spec    The catalog entry.
	 * @param array $control The registered control.
	 * @return bool
	 */
	private static function transform_undocumented( array $spec, array $control ): bool {
		if ( '' === self::transforming_selector( $control ) ) {
			return false;
		}

		$description = strtolower( (string) ( $spec['description'] ?? '' ) );

		foreach ( array( 'multiplier', 'multiplicador', 'calc(', 'not pixels', 'times' ) as $hint ) {
			if ( str_contains( $description, $hint ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The registered control whose name is closest to a param that has none.
	 *
	 * Same reasoning as the unknown-key suggestions: the misses are near-misses
	 * of a real name, and a shared trailing segment finds them where edit
	 * distance does not (button_padding → text_padding).
	 *
	 * @param string   $param      The unmatched param.
	 * @param string[] $candidates Registered control names.
	 * @return string The closest name, or ''.
	 */
	private static function nearest( string $param, array $candidates ): string {
		$best  = '';
		$score = 0;

		foreach ( $candidates as $candidate ) {
			$points = 0;
			$tail   = strrchr( $param, '_' );

			if ( false !== $tail && $tail === strrchr( $candidate, '_' ) ) {
				$points += 100;
			}
			if ( str_contains( $param, $candidate ) || str_contains( $candidate, $param ) ) {
				$points += 60;
			}
			if ( levenshtein( $param, $candidate ) <= 3 ) {
				$points += 30;
			}

			if ( $points > $score ) {
				$score = $points;
				$best  = $candidate;
			}
		}

		return $best;
	}
}
