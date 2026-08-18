<?php
/**
 * Auto-generates JSON Schema from Elementor widget control definitions.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates JSON Schema for widget settings based on Elementor's control registry.
 *
 * @since 1.0.0
 */
class KarMCP_Schema_Generator {

	/**
	 * Generates a JSON Schema for a widget type's settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name (e.g. 'heading', 'button').
	 * @return array|\WP_Error JSON Schema array on success, WP_Error if widget not found.
	 */
	/**
	 * A widget's raw registered controls, as Elementor holds them.
	 *
	 * The JSON Schema from generate() is a lossy view: it keeps the shape of a
	 * value and drops `range`, `selectors` and the original Controls_Manager
	 * type. Auditing the curated catalog needs exactly those — a control whose
	 * selector reads `calc({{SIZE}} * 100)` is a multiplier, and nothing in the
	 * generated schema says so.
	 *
	 * @since 1.20.2
	 *
	 * @param string $widget_type The widget type name.
	 * @return array|\WP_Error Control id => control definition, or WP_Error.
	 */
	public function controls( string $widget_type ) {
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			return new \WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget type name */
					__( 'Widget type "%s" not found.', 'karmcp' ),
					$widget_type
				)
			);
		}

		return $this->get_full_controls( $widget );
	}

	/**
	 * A non-widget element's raw registered controls (container, section, column).
	 *
	 * Containers are not widgets, so widgets_manager knows nothing about them --
	 * their controls live in the element registry instead. They matter because
	 * third-party plugins hang their heaviest controls there: Unlimited Elements
	 * registers its background sliders on the container, pre-filled with sample
	 * photographs, so a template of three containers carries three copies of them.
	 *
	 * @since 1.24.0
	 *
	 * @param string $el_type The element type name, e.g. 'container'.
	 * @return array|\WP_Error Control id => control definition, or WP_Error.
	 */
	public function element_controls( string $el_type ) {
		$element = \Elementor\Plugin::$instance->elements_manager->get_element_types( $el_type );

		if ( ! $element ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: %s: element type name */
					__( 'Element type "%s" not found.', 'karmcp' ),
					$el_type
				)
			);
		}

		return $this->get_full_controls( $element );
	}

	public function generate( string $widget_type ) {
		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
		$widget          = $widgets_manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			return new \WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget type name */
					__( 'Widget type "%s" not found.', 'karmcp' ),
					$widget_type
				)
			);
		}

		$controls   = $this->get_full_controls( $widget );
		$properties = array();

		if ( is_array( $controls ) ) {
			foreach ( $controls as $control_id => $control ) {
				$control_type = $control['type'] ?? '';

				if ( KarMCP_Control_Mapper::should_skip( $control_type ) ) {
					continue;
				}

				$schema_fragment = KarMCP_Control_Mapper::map( $control );
				if ( ! empty( $schema_fragment ) ) {
					$properties[ $control_id ] = $schema_fragment;
				}
			}
		}

		return array(
			'type'        => 'object',
			'description' => sprintf(
				/* translators: %s: widget title */
				__( 'Settings for the %s widget.', 'karmcp' ),
				$widget->get_title()
			),
			'properties'  => $properties,
		);
	}

	/**
	 * Returns a widget's COMPLETE control set, including the style controls
	 * (typography, colours, alignment, shadows…) that Elementor's "Optimized
	 * Control Loading" strips from get_controls() outside the editor.
	 *
	 * Elementor stores those controls separately and only merges them back when
	 * Performance::is_use_style_controls() is true — the same supported toggle
	 * its own CSS generator (core/files/css/base.php) uses. Without this, the
	 * schema is incomplete in non-editor contexts (notably the WP-CLI/stdio MCP
	 * bridge and any non-REST execution), so agents can't discover styling
	 * controls and settings validation can't recognise them.
	 *
	 * @since 2.2.0
	 *
	 * @param object $widget The Elementor widget instance.
	 * @return array The full controls array.
	 */
	private function get_full_controls( $widget ): array {
		$perf = '\Elementor\Core\Frontend\Performance';

		// Older Elementor without the Performance toggle: nothing to do.
		if ( ! class_exists( $perf ) || ! method_exists( $perf, 'set_use_style_controls' ) ) {
			$controls = $widget->get_controls();
			return is_array( $controls ) ? $controls : array();
		}

		$previous = method_exists( $perf, 'is_use_style_controls' ) ? $perf::is_use_style_controls() : false;
		$perf::set_use_style_controls( true );

		try {
			$controls = $widget->get_controls();
		} finally {
			// Always restore so we don't change CSS generation / rendering for
			// the rest of the request.
			$perf::set_use_style_controls( $previous );
		}

		return is_array( $controls ) ? $controls : array();
	}

	/**
	 * Generates schemas for all registered widgets.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of widget_type => JSON Schema.
	 */
	public function generate_all(): array {
		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
		$widgets         = $widgets_manager->get_widget_types();
		$schemas         = array();

		foreach ( $widgets as $name => $widget ) {
			$schema = $this->generate( $name );
			if ( ! is_wp_error( $schema ) ) {
				$schemas[ $name ] = $schema;
			}
		}

		return $schemas;
	}
}
