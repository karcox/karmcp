<?php
/**
 * Removes settings whose value is already the control's own default.
 *
 * A template copied from a real page carries every control the original ever
 * touched, including the ones left at their factory value. Most of that is
 * invisible and enormous, and it is not on the widgets: measured on this site,
 * the navigation template is 5 elements and 28,499 characters, of which 24,714
 * — 87% — is three Unlimited Elements background sliders registered on each of
 * its three containers, one of them pre-filled with six Unsplash photographs
 * nobody ever sees. Site-wide that is 10,992 containers carrying the same
 * sample rows, around 80% of all the Elementor data stored. It rides into every
 * module of every course, and into the SCORM export.
 *
 * The operation is deliberately narrow, and that is what makes it safe:
 * **a setting identical to its control's default is removed, nothing else.**
 * The widget resolves an absent setting to that same default, so the rendered
 * result is unchanged by construction — this only stops the value being stored
 * and shipped. Anything a person actually chose differs from the default and
 * stays.
 *
 * That is why this is not part of strip_media. Media stripping is a judgement
 * about content ("these pictures belong to the previous brand"), and it cannot
 * tell a sample slider from one somebody wanted. This makes no judgement at
 * all.
 *
 * Pure: settings and controls in, settings out.
 *
 * @package KarMCP
 * @since   1.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strips at-default settings from an element tree.
 *
 * @since 1.23.0
 */
class KarMCP_Default_Stripper {

	/**
	 * Walks an element tree, dropping settings that equal their default.
	 *
	 * @since 1.23.0
	 *
	 * @param array    $elements The element tree.
	 * @param callable $controls fn( string $type, string $widget_type ): array
	 *                           returning the registered controls for an element.
	 * @param int      $removed  Running count, by reference.
	 * @return array The tree, lighter.
	 */
	public static function strip( array $elements, callable $controls, int &$removed ): array {
		foreach ( $elements as &$element ) {
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::strip_settings(
					$element['settings'],
					(array) call_user_func(
						$controls,
						(string) ( $element['elType'] ?? '' ),
						(string) ( $element['widgetType'] ?? '' )
					),
					$removed
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::strip( $element['elements'], $controls, $removed );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * One element's settings, minus the ones already at their default.
	 *
	 * @param array $settings The element settings.
	 * @param array $controls Control id => control definition.
	 * @param int   $removed  Running count, by reference.
	 * @return array
	 */
	private static function strip_settings( array $settings, array $controls, int &$removed ): array {
		if ( ! $controls ) {
			// No control list means no way to know what the default is, and
			// guessing here would delete somebody's content. Leave it alone.
			return $settings;
		}

		foreach ( $settings as $key => $value ) {
			// Structural keys are not controls and carry the bindings that make
			// globals work; they have no default to compare against.
			if ( '__globals__' === $key || '__dynamic__' === $key ) {
				continue;
			}

			$control = $controls[ $key ] ?? null;
			if ( null === $control || ! array_key_exists( 'default', $control ) ) {
				continue;
			}

			if ( self::same( $value, $control['default'] ) ) {
				unset( $settings[ $key ] );
				++$removed;
			}
		}

		return $settings;
	}

	/**
	 * Whether a stored value is the control's default.
	 *
	 * Compares structurally rather than with `==`: Elementor stores numbers as
	 * strings often enough ("0" for 0, "" for an unset size) that a loose
	 * comparison would call `0` and `""` equal and delete a real zero, while a
	 * strict one would keep every value the editor round-tripped through a form.
	 * Arrays are compared key by key, order-insensitively, because a repeater
	 * row rebuilt by the editor holds the same fields in a different order.
	 *
	 * @param mixed $value   The stored value.
	 * @param mixed $default The control's default.
	 * @return bool
	 */
	private static function same( $value, $default ): bool {
		if ( is_array( $value ) !== is_array( $default ) ) {
			return false;
		}

		if ( ! is_array( $value ) ) {
			// Both scalar: compare as strings, but never let null, false or ''
			// collapse into each other — those distinctions carry meaning in
			// Elementor, where '' means "unset" and '0' can mean zero.
			if ( null === $value || null === $default ) {
				return $value === $default;
			}
			if ( is_bool( $value ) || is_bool( $default ) ) {
				return $value === $default;
			}

			return (string) $value === (string) $default;
		}

		// Elementor stamps every repeater row with an `_id` when it saves, and
		// that id is generated per save: three containers built from the same
		// factory default hold byte-identical rows under three different ids,
		// and the declared default has none at all. Comparing them would mean
		// no repeater ever matches its default, which is precisely the case
		// this exists for. The id is Elementor's bookkeeping, not content — it
		// is regenerated on the way back in.
		$value   = self::without_row_id( $value );
		$default = self::without_row_id( $default );

		if ( count( $value ) !== count( $default ) ) {
			return false;
		}

		foreach ( $value as $k => $v ) {
			if ( ! array_key_exists( $k, $default ) ) {
				return false;
			}
			if ( ! self::same( $v, $default[ $k ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * An array without Elementor's generated repeater row id.
	 *
	 * Narrow on purpose: only `_id`, and only as a key of the array being
	 * compared. Everything a person can set — including the row ids third
	 * parties write themselves, like Unlimited Elements' `_generated_id` — is
	 * left in place and still has to match.
	 *
	 * @since 1.24.0
	 *
	 * @param array $value An array value.
	 * @return array The same array, minus `_id`.
	 */
	private static function without_row_id( array $value ): array {
		unset( $value['_id'] );

		return $value;
	}
}
