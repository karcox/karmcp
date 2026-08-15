<?php
/**
 * Widget generator — compiles a validated spec into an Elementor widget class.
 *
 * This is the piece that lets the admin screen say, truthfully, that "the AI
 * never writes raw PHP": every byte of the generated file comes from here.
 * Values that originate in the spec (titles, labels, defaults, option maps,
 * template text) are emitted as PHP literals via var_export, and every value a
 * visitor could influence is wrapped in the escape function its control type
 * dictates — chosen here, never by the spec.
 *
 * The class is pure: it takes arrays and returns a string, touching neither
 * the database, the filesystem, nor Elementor. That is what lets
 * `validate-widget-spec` dry-run the real compiler instead of an approximation
 * of it, and what lets the test suite run the generated code.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles widget specs into `\Elementor\Widget_Base` subclasses.
 *
 * @since 1.12.0
 */
class KarMCP_Widget_Generator {

	/** Elementor panel category the generated widgets are filed under. */
	const CATEGORY = 'karmcp-custom';

	/** Fallback panel icon when the spec does not name a valid one. */
	const DEFAULT_ICON = 'eicon-code';

	/**
	 * Compiles a spec.
	 *
	 * @since 1.12.0
	 *
	 * @param array  $spec        The widget spec (validated here, again).
	 * @param string $class_name  PHP class name for the generated widget.
	 * @param string $widget_name Elementor widget name (its machine name).
	 * @param array  $opts        {
	 *     @type string $style_handle  Registered style handle, or ''.
	 *     @type string $script_handle Registered script handle, or ''.
	 * }
	 * @return string|WP_Error The PHP source, or the first validation error.
	 */
	public static function generate( array $spec, string $class_name, string $widget_name, array $opts = array() ) {
		$valid = KarMCP_Widget_Spec::validate( $spec );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $class_name ) ) {
			return new WP_Error( 'generator_class_name', __( 'The generated class name is not a valid PHP identifier.', 'karmcp' ) );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_\-]*$/', $widget_name ) ) {
			return new WP_Error( 'generator_widget_name', __( 'The generated widget name is not a valid Elementor widget name.', 'karmcp' ) );
		}

		$controls = self::index_controls( $spec );
		$types    = array();
		foreach ( $controls as $name => $control ) {
			$types[ $name ] = $control['type'];
		}

		$render = KarMCP_Sandbox_Template::compile(
			(string) $spec['template'],
			$types,
			array( __CLASS__, 'emit' ),
			array( __CLASS__, 'truthy' )
		);
		if ( is_wp_error( $render ) ) {
			return $render;
		}

		$meta          = isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();
		$style_handle  = isset( $opts['style_handle'] ) ? (string) $opts['style_handle'] : '';
		$script_handle = isset( $opts['script_handle'] ) ? (string) $opts['script_handle'] : '';
		$needs_icon    = in_array( 'icon', $types, true );

		$php  = self::file_header( $class_name );
		$php .= 'class ' . $class_name . " extends \\Elementor\\Widget_Base {\n\n";
		$php .= self::identity_methods( $widget_name, $meta );
		$php .= self::asset_methods( $style_handle, $script_handle );
		$php .= self::controls_method( $controls );
		$php .= self::render_method( $render );
		if ( $needs_icon ) {
			$php .= self::icon_helper();
		}
		$php .= "}\n";

		return $php;
	}

	// -------------------------------------------------------------------------
	// Expression emitters — where escaping is decided
	// -------------------------------------------------------------------------

	/**
	 * Returns the PHP expression behind one placeholder.
	 *
	 * Every branch ends in an escape function or a cast; there is deliberately
	 * no path that echoes a stored value unfiltered. `wysiwyg` is the single
	 * type allowed to emit markup, and it goes through wp_kses_post.
	 *
	 * @since 1.12.0
	 *
	 * @param string $name     Control name.
	 * @param string $type     Control type.
	 * @param string $subprop  Requested sub-property, or ''.
	 * @param string $modifier Requested modifier, or ''.
	 * @return string|WP_Error
	 */
	public static function emit( string $name, string $type, string $subprop, string $modifier ) {
		$definition = KarMCP_Widget_Spec::control_types()[ $type ] ?? null;
		if ( null === $definition ) {
			return new WP_Error( 'generator_unknown_type', __( 'Unknown control type in template.', 'karmcp' ) );
		}
		if ( '' !== $subprop && ! in_array( $subprop, $definition['subprops'], true ) ) {
			return new WP_Error(
				'generator_bad_subprop',
				sprintf(
					/* translators: 1: sub-property, 2: control name, 3: control type */
					__( '"%1$s" is not available on "%2$s" (a %3$s control).', 'karmcp' ),
					$subprop,
					$name,
					$type
				)
			);
		}
		if ( 'attr' === $modifier && ! $definition['attr'] ) {
			return new WP_Error(
				'generator_bad_modifier',
				sprintf(
					/* translators: 1: control name, 2: control type */
					__( '"%1$s" is a %2$s control, which is already escaped for its context — the |attr modifier does not apply.', 'karmcp' ),
					$name,
					$type
				)
			);
		}

		$raw  = self::accessor( $name );
		$attr = ( 'attr' === $modifier );

		switch ( $type ) {
			case 'text':
			case 'select':
			case 'switcher':
				return ( $attr ? 'esc_attr' : 'esc_html' ) . '( (string) ' . $raw . ' )';

			case 'textarea':
				return $attr
					? 'esc_attr( (string) ' . $raw . ' )'
					: 'nl2br( esc_html( (string) ' . $raw . ' ) )';

			case 'wysiwyg':
				return 'wp_kses_post( (string) ' . $raw . ' )';

			case 'number':
				return ( $attr ? 'esc_attr' : 'esc_html' ) . '( (string) ( 0 + (float) ' . $raw . ' ) )';

			case 'color':
				// A color is only ever meaningful inside an attribute.
				return 'esc_attr( (string) ' . $raw . ' )';

			case 'url':
				if ( 'target' === $subprop ) {
					return 'esc_attr( ! empty( ' . self::accessor( $name, 'is_external' ) . ' ) ? \'_blank\' : \'_self\' )';
				}
				if ( 'rel' === $subprop ) {
					return 'esc_attr( ! empty( ' . self::accessor( $name, 'nofollow' ) . ' ) ? \'nofollow\' : \'\' )';
				}
				return 'esc_url( (string) ' . self::accessor( $name, 'url' ) . ' )';

			case 'media':
				if ( 'alt' === $subprop ) {
					// Read the alt from the Media Library rather than trusting a
					// stored copy, so it stays correct after an edit there.
					return 'esc_attr( (string) get_post_meta( (int) ' . self::accessor( $name, 'id' ) . ', \'_wp_attachment_image_alt\', true ) )';
				}
				if ( 'id' === $subprop ) {
					return '(int) ' . self::accessor( $name, 'id' );
				}
				return 'esc_url( (string) ' . self::accessor( $name, 'url' ) . ' )';

			case 'icon':
				return '$this->karmcp_icon( ' . self::accessor( $name, '', 'array()' ) . ' )';
		}

		return new WP_Error( 'generator_unhandled_type', __( 'This control type cannot be rendered directly.', 'karmcp' ) );
	}

	/**
	 * Returns the PHP expression tested by `{{#if name}}`.
	 *
	 * @since 1.12.0
	 *
	 * @param string $name Control name.
	 * @param string $type Control type.
	 * @return string
	 */
	public static function truthy( string $name, string $type ): string {
		switch ( $type ) {
			case 'switcher':
				return "'yes' === (string) " . self::accessor( $name );
			case 'url':
			case 'media':
				return '! empty( ' . self::accessor( $name, 'url' ) . ' )';
			case 'icon':
				return '! empty( ' . self::accessor( $name, 'value' ) . ' )';
			case 'number':
				return '0 + (float) ' . self::accessor( $name ) . ' !== 0.0';
			default:
				return "'' !== trim( (string) " . self::accessor( $name ) . ' )';
		}
	}

	/**
	 * The `$settings` accessor for a control, with a null-coalesced fallback so
	 * a widget saved before a control existed still renders.
	 *
	 * @param string $name     Control name (already validated).
	 * @param string $key      Optional sub-key of an array-valued control.
	 * @param string $fallback PHP literal used when the value is absent.
	 * @return string
	 */
	private static function accessor( string $name, string $key = '', string $fallback = "''" ): string {
		$path = '$settings[' . var_export( $name, true ) . ']'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal.
		if ( '' !== $key ) {
			$path .= '[' . var_export( $key, true ) . ']'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal.
		}
		return '( ' . $path . ' ?? ' . $fallback . ' )';
	}

	// -------------------------------------------------------------------------
	// Code emission
	// -------------------------------------------------------------------------

	/**
	 * The generated file's header.
	 *
	 * @param string $class_name Generated class name.
	 * @return string
	 */
	private static function file_header( string $class_name ): string {
		return "<?php\n"
			. "/**\n"
			. " * GENERATED FILE — do not edit.\n"
			. " *\n"
			. " * Compiled by KarMCP from a widget spec. Editing this file by hand is\n"
			. " * pointless: the spec is the source of truth and the next update to the\n"
			. " * widget overwrites everything here. Change the spec instead.\n"
			. " *\n"
			. ' * @package KarMCP' . "\n"
			. " */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n"
			. "if ( class_exists( " . var_export( $class_name, true ) . " ) ) {\n\treturn;\n}\n\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal.
	}

	/**
	 * get_name / get_title / get_icon / get_keywords / get_categories.
	 *
	 * @param string $widget_name Elementor widget name.
	 * @param array  $meta        Spec meta.
	 * @return string
	 */
	private static function identity_methods( string $widget_name, array $meta ): string {
		$title = isset( $meta['title'] ) ? trim( (string) $meta['title'] ) : $widget_name;

		$icon = isset( $meta['icon'] ) ? (string) $meta['icon'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9\- ]{0,63}$/', $icon ) ) {
			$icon = self::DEFAULT_ICON;
		}

		$keywords = array();
		if ( isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ) {
			foreach ( array_slice( $meta['keywords'], 0, 10 ) as $keyword ) {
				if ( is_scalar( $keyword ) && '' !== trim( (string) $keyword ) ) {
					$keywords[] = trim( (string) $keyword );
				}
			}
		}

		$out  = "\tpublic function get_name() {\n\t\treturn " . self::literal( $widget_name ) . ";\n\t}\n\n";
		$out .= "\tpublic function get_title() {\n\t\treturn " . self::literal( $title ) . ";\n\t}\n\n";
		$out .= "\tpublic function get_icon() {\n\t\treturn " . self::literal( $icon ) . ";\n\t}\n\n";
		$out .= "\tpublic function get_keywords() {\n\t\treturn " . self::literal( $keywords ) . ";\n\t}\n\n";
		$out .= "\tpublic function get_categories() {\n\t\treturn " . self::literal( array( self::CATEGORY ) ) . ";\n\t}\n\n";

		return $out;
	}

	/**
	 * get_style_depends / get_script_depends, emitted only when the widget
	 * actually ships that asset — Elementor enqueues a listed handle whenever
	 * the widget renders, so an empty one would be a wasted request.
	 *
	 * @param string $style_handle  Style handle or ''.
	 * @param string $script_handle Script handle or ''.
	 * @return string
	 */
	private static function asset_methods( string $style_handle, string $script_handle ): string {
		$out = '';
		if ( '' !== $style_handle ) {
			$out .= "\tpublic function get_style_depends() {\n\t\treturn " . self::literal( array( $style_handle ) ) . ";\n\t}\n\n";
		}
		if ( '' !== $script_handle ) {
			$out .= "\tpublic function get_script_depends() {\n\t\treturn " . self::literal( array( $script_handle ) ) . ";\n\t}\n\n";
		}
		return $out;
	}

	/**
	 * register_controls(), one Elementor section per spec section.
	 *
	 * @param array $controls Indexed controls (name => control).
	 * @return string
	 */
	private static function controls_method( array $controls ): string {
		$sections = array(
			'content' => array(
				'label' => __( 'Content', 'karmcp' ),
				'tab'   => 'TAB_CONTENT',
				'items' => array(),
			),
			'style'   => array(
				'label' => __( 'Style', 'karmcp' ),
				'tab'   => 'TAB_STYLE',
				'items' => array(),
			),
		);

		foreach ( $controls as $name => $control ) {
			$section = isset( $control['section'] ) && isset( $sections[ $control['section'] ] )
				? (string) $control['section']
				: 'content';
			$sections[ $section ]['items'][ $name ] = $control;
		}

		$out = "\tprotected function register_controls() {\n";

		foreach ( $sections as $key => $section ) {
			if ( empty( $section['items'] ) ) {
				continue;
			}

			$out .= "\n\t\t\$this->start_controls_section(\n";
			$out .= "\t\t\t" . self::literal( 'karmcp_section_' . $key ) . ",\n";
			$out .= "\t\t\tarray(\n";
			$out .= "\t\t\t\t'label' => " . self::literal( $section['label'] ) . ",\n";
			$out .= "\t\t\t\t'tab'   => \\Elementor\\Controls_Manager::" . $section['tab'] . ",\n";
			$out .= "\t\t\t)\n";
			$out .= "\t\t);\n";

			foreach ( $section['items'] as $name => $control ) {
				$out .= self::control_call( $name, $control );
			}

			$out .= "\n\t\t\$this->end_controls_section();\n";
		}

		$out .= "\t}\n\n";

		return $out;
	}

	/**
	 * One `add_control()` call.
	 *
	 * @param string $name    Control name.
	 * @param array  $control Control definition from the spec.
	 * @return string
	 */
	private static function control_call( string $name, array $control ): string {
		$type       = (string) $control['type'];
		$definition = KarMCP_Widget_Spec::control_types()[ $type ];
		$label      = isset( $control['label'] ) && '' !== trim( (string) $control['label'] )
			? trim( (string) $control['label'] )
			: $name;

		$args  = "\t\t\t\t'label' => " . self::literal( $label ) . ",\n";
		$args .= "\t\t\t\t'type'  => \\Elementor\\Controls_Manager::" . $definition['elementor'] . ",\n";

		if ( 'select' === $type ) {
			$options = array();
			foreach ( (array) $control['options'] as $value => $option_label ) {
				$options[ (string) $value ] = (string) $option_label;
			}
			$args .= "\t\t\t\t'options' => " . self::literal( $options ) . ",\n";
		}

		// Array-valued controls (media/url/icon) take a structured default that a
		// spec cannot express, so a scalar default is dropped rather than
		// mangled into a shape Elementor would choke on.
		$scalar_default_types = array( 'text', 'textarea', 'wysiwyg', 'number', 'select', 'switcher', 'color' );
		if ( isset( $control['default'] ) && is_scalar( $control['default'] ) && in_array( $type, $scalar_default_types, true ) ) {
			$args .= "\t\t\t\t'default' => " . self::literal( (string) $control['default'] ) . ",\n";
		}

		if ( isset( $control['placeholder'] ) && is_scalar( $control['placeholder'] ) ) {
			$args .= "\t\t\t\t'placeholder' => " . self::literal( (string) $control['placeholder'] ) . ",\n";
		}

		if ( in_array( $type, array( 'textarea', 'wysiwyg' ), true ) ) {
			$args .= "\t\t\t\t'label_block' => true,\n";
		}

		return "\n\t\t\$this->add_control(\n"
			. "\t\t\t" . self::literal( $name ) . ",\n"
			. "\t\t\tarray(\n"
			. $args
			. "\t\t\t)\n"
			. "\t\t);\n";
	}

	/**
	 * render(), wrapping the compiled template.
	 *
	 * @param string $body Compiled template statements.
	 * @return string
	 */
	private static function render_method( string $body ): string {
		return "\tprotected function render() {\n"
			. "\t\t\$settings = \$this->get_settings_for_display();\n\n"
			. $body
			. "\t}\n";
	}

	/**
	 * A private helper for icon controls: Elementor's icon manager prints
	 * rather than returns, and the template compiler works in expressions, so
	 * the print is captured here once instead of at every call site.
	 *
	 * @return string
	 */
	private static function icon_helper(): string {
		return "\n\tprivate function karmcp_icon( \$icon ) {\n"
			. "\t\tif ( empty( \$icon['value'] ) || ! class_exists( '\\\\Elementor\\\\Icons_Manager' ) ) {\n"
			. "\t\t\treturn '';\n"
			. "\t\t}\n"
			. "\t\tob_start();\n"
			. "\t\t\\Elementor\\Icons_Manager::render_icon( \$icon, array( 'aria-hidden' => 'true' ) );\n"
			. "\t\treturn (string) ob_get_clean();\n"
			. "\t}\n";
	}

	/**
	 * Indexes the spec's control list by name.
	 *
	 * @param array $spec Validated spec.
	 * @return array<string, array>
	 */
	private static function index_controls( array $spec ): array {
		$out = array();
		foreach ( (array) ( $spec['controls'] ?? array() ) as $control ) {
			$out[ (string) $control['name'] ] = $control;
		}
		return $out;
	}

	/**
	 * A spec value as a PHP literal. Single funnel for everything that crosses
	 * from spec data into generated code.
	 *
	 * @param mixed $value Value to emit.
	 * @return string
	 */
	private static function literal( $value ): string {
		return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal emission.
	}
}
