<?php
/**
 * Factory for building valid Elementor element JSON structures.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds properly structured Elementor element arrays.
 *
 * @since 1.0.0
 */
class KarMCP_Element_Factory {

	/**
	 * Creates a container element.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings The container settings.
	 * @param array $children Child elements array.
	 * @return array The container element structure.
	 */
	public function create_container( array $settings = array(), array $children = array() ): array {
		$settings = self::apply_background_activator( self::normalize_container_settings( $settings ) );

		$defaults = array(
			'container_type' => 'flex',
			'content_width'  => 'boxed',
		);

		$merged = array_merge( $defaults, $settings );

		$is_grid   = ( 'grid' === ( $merged['container_type'] ?? 'flex' ) );
		$direction = $merged['flex_direction'] ?? '';
		$is_row    = ( 'row' === $direction || 'row-reverse' === $direction );

		// Auto-center alignment for flex column containers so widgets like
		// headings, icons, and text are centered on the page. Row
		// containers rely on Elementor's default flex behavior.
		// Grid containers handle alignment via grid_justify_items/grid_align_items.
		if ( ! $is_grid && ! $is_row && ! isset( $settings['flex_align_items'] ) ) {
			$merged['flex_align_items'] = 'center';
		}

		return array(
			'id'         => KarMCP_Id_Generator::generate(),
			'elType'     => 'container',
			'widgetType' => null,
			'isInner'    => false,
			'settings'   => $merged,
			'elements'   => $children,
		);
	}

	/**
	 * Creates a widget element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name (e.g. 'heading', 'button').
	 * @param array  $settings    The widget settings.
	 * @return array The widget element structure.
	 */
	public function create_widget( string $widget_type, array $settings = array() ): array {
		return array(
			'id'         => KarMCP_Id_Generator::generate(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'isInner'    => false,
			'settings'   => self::apply_background_activator( self::normalize_background_settings( $settings ) ),
			'elements'   => array(),
		);
	}

	/**
	 * Creates a section element (legacy layout).
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings The section settings.
	 * @param array $columns  Child column elements.
	 * @return array The section element structure.
	 */
	public function create_section( array $settings = array(), array $columns = array() ): array {
		return array(
			'id'         => KarMCP_Id_Generator::generate(),
			'elType'     => 'section',
			'widgetType' => null,
			'isInner'    => false,
			'settings'   => $settings,
			'elements'   => $columns,
		);
	}

	/**
	 * Creates a column element (legacy layout).
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings The column settings.
	 * @param array $widgets  Child widget elements.
	 * @return array The column element structure.
	 */
	public function create_column( array $settings = array(), array $widgets = array() ): array {
		$defaults = array(
			'_column_size' => 100,
		);

		return array(
			'id'         => KarMCP_Id_Generator::generate(),
			'elType'     => 'column',
			'widgetType' => null,
			'isInner'    => false,
			'settings'   => array_merge( $defaults, $settings ),
			'elements'   => $widgets,
		);
	}

	/**
	 * Map of MCP-shorthand container keys to the keys Elementor's flex group
	 * actually reads. Without this remap, settings like `justify_content` and
	 * `align_items` are persisted under names Elementor's CSS generator never
	 * looks at, so the corresponding `--justify-content` / `--align-items`
	 * custom properties never get emitted and the container renders with
	 * default alignment on the front-end (issue #32).
	 *
	 * @since 1.4.4
	 *
	 * @var array<string, string>
	 */
	private const CONTAINER_KEY_ALIASES = array(
		'justify_content' => 'flex_justify_content',
		'align_items'     => 'flex_align_items',
		'align_content'   => 'flex_align_content',
	);

	/**
	 * Rewrites the unprefixed flex shorthand keys (`justify_content`,
	 * `align_items`, `align_content`) to the prefixed keys that Elementor's
	 * container schema reads.
	 *
	 * Caller-supplied prefixed keys win over the aliased shorthand if both
	 * are provided in the same payload.
	 *
	 * @since 1.4.4
	 *
	 * @param array $settings Raw container settings.
	 * @return array Settings with shorthand keys remapped.
	 */
	public static function normalize_container_settings( array $settings ): array {
		foreach ( self::CONTAINER_KEY_ALIASES as $shorthand => $flex_key ) {
			if ( ! array_key_exists( $shorthand, $settings ) ) {
				continue;
			}

			if ( ! array_key_exists( $flex_key, $settings ) ) {
				$settings[ $flex_key ] = $settings[ $shorthand ];
			}

			unset( $settings[ $shorthand ] );
		}

		$settings = self::normalize_css_classes_key( 'container', $settings );

		return self::normalize_background_settings( $settings );
	}

	/**
	 * Rewrites the CSS-class key to the one the target element type actually
	 * reads, in whichever direction is needed.
	 *
	 * Widgets read `_css_classes`; every other classic element type — container,
	 * section, column — reads `css_classes`, with no leading underscore. The
	 * underscore is not a naming convention, it is the prefix `Widget_Common`
	 * adds when it injects the shared Advanced controls into widgets; elements
	 * declare their own, unprefixed. Verified against a live Elementor 4.2.2
	 * container schema: it exposes `css_classes` and no `_css_classes` (while
	 * still exposing `_element_id`, which is why guessing from a sibling key
	 * gets it wrong).
	 *
	 * Sending the wrong one is silent: Elementor stores any key it is handed, so
	 * the class reads back correctly from `_elementor_data` and simply never
	 * reaches the HTML — and every stylesheet rule hanging off that class dies
	 * with it. Same class of bug as the flex shorthand above (issue #32).
	 *
	 * Atomic (v4) elements are NOT covered here and must not be passed through
	 * this: their classes live in the typed `classes` prop, and renaming
	 * anything there would corrupt the element.
	 *
	 * @since 1.17.0
	 *
	 * @param string $el_type  The element's `elType` (`widget`, `container`, …).
	 * @param array  $settings Raw element settings.
	 * @return array Settings with the CSS-class key rewritten for this element type.
	 */
	public static function normalize_css_classes_key( string $el_type, array $settings ): array {
		$is_widget = ( 'widget' === $el_type );
		$target    = $is_widget ? '_css_classes' : 'css_classes';
		$source    = $is_widget ? 'css_classes' : '_css_classes';

		if ( ! array_key_exists( $source, $settings ) ) {
			return $settings;
		}

		// A caller-supplied correct key wins; the wrong one is dropped either way
		// so it cannot linger as a decoy in the saved data.
		if ( ! array_key_exists( $target, $settings ) ) {
			$settings[ $target ] = $settings[ $source ];
		}

		unset( $settings[ $source ] );

		return $settings;
	}

	/**
	 * Coerces the intuitive-but-wrong background shorthand that agents (weak
	 * local models especially) routinely emit into the flat keys Elementor's
	 * background group control actually reads. Three fixes:
	 *
	 *  1. A nested `background` group — `background => { background_image, size,
	 *     ... }` — is flattened to top-level `background_*` keys. Elementor has
	 *     no `background` group setting, so a nested object is silently dropped.
	 *  2. `background_image` given as an array of `{ id, url }` objects (the
	 *     model mirrors media-repeater shape) is unwrapped to the single
	 *     `{ id, url }` object the control expects.
	 *
	 * This reshapes the payload only. Injecting the missing `background_background`
	 * activator is a separate step — see apply_background_activator() — because it
	 * is the one decision here that cannot be made from the payload alone.
	 *
	 * Idempotent and non-destructive: caller-supplied flat keys always win over
	 * anything lifted out of the nested group, and settings with no background
	 * keys pass through untouched.
	 *
	 * @since 3.5.1
	 *
	 * @param array $settings Raw element settings.
	 * @return array Settings with background shorthand normalized.
	 */
	public static function normalize_background_settings( array $settings ): array {
		// 1. Flatten a nested `background` group into top-level keys.
		if ( isset( $settings['background'] ) && is_array( $settings['background'] ) ) {
			$nested = $settings['background'];
			// Only treat it as a group if its keys look like background_* controls
			// (guards against an element that legitimately stores something else
			// under `background`, which Elementor does not).
			$looks_like_group = false;
			foreach ( $nested as $k => $v ) {
				if ( is_string( $k ) && 0 === strpos( $k, 'background' ) ) {
					$looks_like_group = true;
					break;
				}
			}
			if ( $looks_like_group ) {
				unset( $settings['background'] );
				foreach ( $nested as $k => $v ) {
					if ( ! array_key_exists( $k, $settings ) ) {
						$settings[ $k ] = $v;
					}
				}
			}
		}

		// 2. Unwrap a `background_image` array-of-objects to a single object.
		if ( isset( $settings['background_image'] ) && is_array( $settings['background_image'] )
			&& isset( $settings['background_image'][0] ) && is_array( $settings['background_image'][0] ) ) {
			$settings['background_image'] = $settings['background_image'][0];
		}

		return $settings;
	}

	/**
	 * Injects the `classic` background activator when a background exists
	 * without one — without an activator Elementor renders no background at all.
	 *
	 * Run this on the FINAL settings of an element, never on an incoming partial
	 * payload. The activator says which *kind* of background is active, so
	 * deciding it from the payload alone silently destroys a setting that was
	 * already right: an update sending only `background_color` at a container
	 * whose stored `background_background` is `gradient` looks background-less
	 * to a payload-only check, gets `classic` injected, and the gradient
	 * collapses to a flat colour — an ajuste the caller never mentioned.
	 *
	 * On a creation path "final" and "incoming" are the same thing, so calling
	 * it there is the same as before; on an update it must run after the merge
	 * with the element's existing settings.
	 *
	 * Idempotent: an activator that is already set is never overwritten, and
	 * settings with no background pass through untouched.
	 *
	 * @since 1.17.0
	 *
	 * @param array $settings The element's complete settings.
	 * @return array Settings with the activator injected when it was missing.
	 */
	public static function apply_background_activator( array $settings ): array {
		$has_bg = ( ! empty( $settings['background_image'] ) || ! empty( $settings['background_color'] ) );

		if ( $has_bg && empty( $settings['background_background'] ) ) {
			$settings['background_background'] = 'classic';
		}

		return $settings;
	}

	// =========================================================================
	// Atomic elements (Elementor 4.0+)
	// =========================================================================

	/**
	 * Creates an atomic widget element (Elementor 4.0+).
	 *
	 * Atomic widgets use the same elType=widget structure but with $$type-wrapped
	 * settings and additional top-level keys (styles, interactions).
	 *
	 * @since 1.5.0
	 *
	 * @param string $widget_type The atomic widget type (e.g. 'e-heading', 'e-button').
	 * @param array  $settings    The widget settings (already $$type-wrapped).
	 * @return array The atomic widget element structure.
	 */
	public function create_atomic_widget( string $widget_type, array $settings = array() ): array {
		if ( ! isset( $settings['classes'] ) ) {
			$settings['classes'] = KarMCP_Atomic_Props::classes();
		}

		return array(
			'id'              => KarMCP_Id_Generator::generate(),
			'elType'          => 'widget',
			'widgetType'      => $widget_type,
			'isInner'         => false,
			'settings'        => $settings,
			'elements'        => array(),
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);
	}

	/**
	 * Creates an atomic flexbox container (Elementor 4.0+).
	 *
	 * @since 1.5.0
	 *
	 * @param array  $settings    Container settings ($$type-wrapped props).
	 * @param array  $children    Child elements.
	 * @param array  $style_props Flat layout params to convert into a local style class.
	 * @return array The flexbox element structure.
	 */
	public function create_flexbox( array $settings = array(), array $children = array(), array $style_props = array() ): array {
		$id = KarMCP_Id_Generator::generate();

		if ( ! isset( $settings['tag'] ) ) {
			$settings['tag'] = KarMCP_Atomic_Props::string( 'div' );
		}
		if ( ! isset( $settings['classes'] ) ) {
			$settings['classes'] = KarMCP_Atomic_Props::classes();
		}

		$element = array(
			'id'              => $id,
			'elType'          => 'e-flexbox',
			'settings'        => $settings,
			'elements'        => $children,
			'isInner'         => false,
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		// Build and apply flex layout styles if provided.
		$flex_css = KarMCP_Atomic_Styles::build_flex_props( $style_props );
		$common_css = KarMCP_Atomic_Styles::build_common_props( $style_props );
		$all_css = array_merge( $flex_css, $common_css );

		if ( ! empty( $all_css ) ) {
			$style = KarMCP_Atomic_Styles::create_local_class( $id, $all_css );
			KarMCP_Atomic_Styles::apply_to_element( $element, $style['class_id'], $style['style_def'] );
		}

		return $element;
	}

	/**
	 * Creates an atomic div-block container (Elementor 4.0+).
	 *
	 * @since 1.5.0
	 *
	 * @param array $settings    Container settings ($$type-wrapped props).
	 * @param array $children    Child elements.
	 * @param array $style_props Flat style params to convert into a local style class.
	 * @return array The div-block element structure.
	 */
	public function create_div_block( array $settings = array(), array $children = array(), array $style_props = array() ): array {
		$id = KarMCP_Id_Generator::generate();

		if ( ! isset( $settings['tag'] ) ) {
			$settings['tag'] = KarMCP_Atomic_Props::string( 'div' );
		}
		if ( ! isset( $settings['classes'] ) ) {
			$settings['classes'] = KarMCP_Atomic_Props::classes();
		}

		$element = array(
			'id'              => $id,
			'elType'          => 'e-div-block',
			'settings'        => $settings,
			'elements'        => $children,
			'isInner'         => false,
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		$common_css = KarMCP_Atomic_Styles::build_common_props( $style_props );

		if ( ! empty( $common_css ) ) {
			$style = KarMCP_Atomic_Styles::create_local_class( $id, $common_css );
			KarMCP_Atomic_Styles::apply_to_element( $element, $style['class_id'], $style['style_def'] );
		}

		return $element;
	}
}
