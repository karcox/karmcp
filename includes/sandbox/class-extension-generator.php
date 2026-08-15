<?php
/**
 * Element extension generator — compiles a validated spec into the PHP class
 * that registers the extension with Elementor.
 *
 * The generated class does exactly three things, one per seam (all four
 * verified against Elementor 4.2.2 — see docs/ROADMAP-ELEMENT-EXTENSIONS.md):
 *
 *   props()    hooks `elementor/atomic-widgets/props-schema` so the value is
 *              declared and therefore saved at all.
 *   controls() hooks `elementor/atomic-widgets/controls` so a section shows up
 *              in the panel of the targeted elements.
 *   render()   hooks `elementor/frontend/before_render` — which also runs for
 *              the editor's server-side render — to put classes and data
 *              attributes on the element, and to bring in the assets.
 *
 * On escaping: the generated code does NOT call esc_attr on attribute values.
 * That is deliberate and verified, not an omission — Elementor escapes every
 * wrapper attribute when it prints it (`Utils::render_html_attributes()` in
 * includes/utils.php:534), so escaping here would double-encode. What the
 * generator does instead is force the shape by type: numbers are cast, sizes
 * are rebuilt from a cast number plus a unit checked against a fixed list, and
 * every literal that comes from the spec is emitted with var_export.
 *
 * Pure: arrays in, string out. No database, no filesystem, no Elementor.
 *
 * @package KarMCP
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles element extension specs into PHP.
 *
 * @since 1.13.0
 */
class KarMCP_Extension_Generator {

	/** Namespace of Elementor's atomic prop types. */
	const PROP_NS = '\\Elementor\\Modules\\AtomicWidgets\\PropTypes\\';

	/** Namespace of Elementor's atomic controls. */
	const CONTROL_NS = '\\Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\';

	/** The Section class that groups controls in the panel. */
	const SECTION_CLASS = '\\Elementor\\Modules\\AtomicWidgets\\Controls\\Section';

	/** Units a `size` prop may carry into an attribute value. */
	const UNITS = array( 'px', 'em', 'rem', '%', 'vh', 'vw', 'deg', 's', 'ms' );

	/**
	 * Compiles a spec.
	 *
	 * @since 1.13.0
	 *
	 * @param array  $spec       The extension spec (validated here, again).
	 * @param string $class_name PHP class name for the generated extension.
	 * @param array  $opts       {
	 *     @type string $style_handle  Registered style handle, or ''.
	 *     @type string $script_handle Registered script handle, or ''.
	 * }
	 * @return string|WP_Error The PHP source, or the first validation error.
	 */
	public static function generate( array $spec, string $class_name, array $opts = array() ) {
		$valid = KarMCP_Extension_Spec::validate( $spec );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $class_name ) ) {
			return new WP_Error( 'generator_class_name', __( 'The generated class name is not a valid PHP identifier.', 'karmcp' ) );
		}

		$props    = self::index_props( $spec );
		$targets  = array_values( (array) $spec['targets'] );
		$style    = isset( $opts['style_handle'] ) ? (string) $opts['style_handle'] : '';
		$script   = isset( $opts['script_handle'] ) ? (string) $opts['script_handle'] : '';
		$critical = self::critical_css( $spec );

		$render = self::render_method( $spec, $props, $style, $script, $critical );
		if ( is_wp_error( $render ) ) {
			return $render;
		}

		$php  = self::file_header( $class_name );
		$php .= 'class ' . $class_name . " {\n\n";
		$php .= "\tconst TARGETS = " . self::literal( $targets ) . ";\n\n";
		$php .= self::register_method();
		$php .= self::props_method( $spec );
		$php .= self::controls_method( $spec );
		$php .= $render;
		$php .= self::applies_to_method();
		$php .= "}\n";

		return $php;
	}

	// -------------------------------------------------------------------------
	// Methods of the generated class
	// -------------------------------------------------------------------------

	/**
	 * The file header.
	 *
	 * @param string $class_name Generated class name.
	 * @return string
	 */
	private static function file_header( string $class_name ): string {
		return "<?php\n"
			. "/**\n"
			. " * GENERATED FILE — do not edit.\n"
			. " *\n"
			. " * Compiled by KarMCP from an element extension spec. The spec is the source\n"
			. " * of truth; the next update to the extension overwrites everything here.\n"
			. " *\n"
			. " * Attribute values are not escaped here on purpose: Elementor escapes every\n"
			. " * wrapper attribute when it prints it (Utils::render_html_attributes).\n"
			. " *\n"
			. " * @package KarMCP\n"
			. " */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n"
			. 'if ( class_exists( ' . self::literal( $class_name ) . " ) ) {\n\treturn;\n}\n\n";
	}

	/**
	 * register_hooks(): the three seams.
	 *
	 * @return string
	 */
	private static function register_method(): string {
		return "\tpublic function register_hooks() {\n"
			. "\t\tadd_filter( 'elementor/atomic-widgets/props-schema', array( \$this, 'props' ) );\n"
			. "\t\tadd_filter( 'elementor/atomic-widgets/controls', array( \$this, 'controls' ), 10, 2 );\n"
			. "\t\tadd_action( 'elementor/frontend/before_render', array( \$this, 'render' ) );\n"
			. "\t}\n\n";
	}

	/**
	 * props(): declares the props on every atomic element's schema.
	 *
	 * The filter receives only the schema, with no way to tell which element it
	 * belongs to, so the props are declared everywhere. A declared prop that no
	 * control edits and no rule reads is inert — the targeting happens in
	 * controls() and render().
	 *
	 * @param array $spec Validated spec.
	 * @return string
	 */
	private static function props_method( array $spec ): string {
		$out = "\tpublic function props( \$schema ) {\n"
			. "\t\tif ( ! is_array( \$schema ) ) {\n\t\t\treturn \$schema;\n\t\t}\n\n";

		foreach ( (array) $spec['props'] as $prop ) {
			$name       = (string) $prop['name'];
			$type       = (string) $prop['type'];
			$definition = KarMCP_Extension_Spec::prop_types()[ $type ];

			$expression = self::PROP_NS . str_replace( '\\\\', '\\', $definition['prop_type'] ) . '::make()';

			if ( 'select' === $type ) {
				$expression .= '->enum( ' . self::literal( array_map( 'strval', array_keys( (array) $prop['options'] ) ) ) . ' )';
			}

			$default = self::default_expression( $prop, $type );
			if ( '' !== $default ) {
				$expression .= '->default( ' . $default . ' )';
			}

			$out .= "\t\t\$schema[" . self::literal( $name ) . '] = ' . $expression . ";\n";
		}

		$out .= "\n\t\treturn \$schema;\n\t}\n\n";

		return $out;
	}

	/**
	 * The `->default()` argument for a prop, or '' when it takes none.
	 *
	 * @param array  $prop Prop definition.
	 * @param string $type Prop type.
	 * @return string
	 */
	private static function default_expression( array $prop, string $type ): string {
		if ( 'size' === $type ) {
			// A size default is a structured value the spec cannot express.
			return '';
		}
		if ( ! isset( $prop['default'] ) || ! is_scalar( $prop['default'] ) ) {
			return '';
		}

		switch ( $type ) {
			case 'switch':
				return self::literal( (bool) $prop['default'] );
			case 'number':
				return self::literal( 0 + (float) $prop['default'] );
			default:
				return self::literal( (string) $prop['default'] );
		}
	}

	/**
	 * controls(): adds the section to the panel of the targeted elements.
	 *
	 * @param array $spec Validated spec.
	 * @return string
	 */
	private static function controls_method( array $spec ): string {
		$label = isset( $spec['section']['label'] ) && '' !== trim( (string) $spec['section']['label'] )
			? trim( (string) $spec['section']['label'] )
			: trim( (string) $spec['meta']['title'] );

		$out = "\tpublic function controls( \$controls, \$element ) {\n"
			. "\t\tif ( ! is_array( \$controls ) || ! \$this->applies_to( \$element ) ) {\n\t\t\treturn \$controls;\n\t\t}\n\n"
			. "\t\t\$controls[] = " . self::SECTION_CLASS . "::make()\n"
			. "\t\t\t->set_label( " . self::literal( $label ) . " )\n"
			. "\t\t\t->set_items( array(\n";

		foreach ( (array) $spec['props'] as $prop ) {
			$out .= self::control_item( $prop );
		}

		$out .= "\t\t\t) );\n\n\t\treturn \$controls;\n\t}\n\n";

		return $out;
	}

	/**
	 * One control inside the section.
	 *
	 * @param array $prop Prop definition.
	 * @return string
	 */
	private static function control_item( array $prop ): string {
		$name       = (string) $prop['name'];
		$type       = (string) $prop['type'];
		$definition = KarMCP_Extension_Spec::prop_types()[ $type ];
		$label      = isset( $prop['label'] ) && '' !== trim( (string) $prop['label'] )
			? trim( (string) $prop['label'] )
			: $name;

		$out = "\t\t\t\t" . self::CONTROL_NS . $definition['control'] . '::bind_to( ' . self::literal( $name ) . " )\n"
			. "\t\t\t\t\t->set_label( " . self::literal( $label ) . ' )';

		if ( 'select' === $type ) {
			// Elementor wants a list of {value,label} pairs, not the map the
			// spec uses (verified in div-block.php).
			$options = array();
			foreach ( (array) $prop['options'] as $value => $option_label ) {
				$options[] = array(
					'value' => (string) $value,
					'label' => (string) $option_label,
				);
			}
			$out .= "\n\t\t\t\t\t->set_options( " . self::literal( $options ) . ' )';
		}

		if ( isset( $prop['placeholder'] ) && is_scalar( $prop['placeholder'] )
			&& in_array( $type, array( 'text', 'textarea', 'select' ), true ) ) {
			$out .= "\n\t\t\t\t\t->set_placeholder( " . self::literal( (string) $prop['placeholder'] ) . ' )';
		}

		return $out . ",\n";
	}

	/**
	 * render(): applies the output rules to the element being rendered.
	 *
	 * @param array                $spec     Validated spec.
	 * @param array<string,string> $props    Declared props.
	 * @param string               $style    Style handle or ''.
	 * @param string               $script   Script handle or ''.
	 * @param string               $critical Critical CSS, or ''.
	 * @return string|WP_Error
	 */
	private static function render_method( array $spec, array $props, string $style, string $script, string $critical ) {
		$body = '';

		foreach ( (array) $spec['output'] as $rule ) {
			$condition = self::condition_expression( $rule, $props );
			if ( is_wp_error( $condition ) ) {
				return $condition;
			}

			$indent  = '' === $condition ? "\t\t" : "\t\t\t";
			$applied = '';

			if ( isset( $rule['class'] ) ) {
				$applied .= $indent . "\$element->add_render_attribute( '_wrapper', 'class', " . self::literal( (string) $rule['class'] ) . " );\n";
			}

			foreach ( (array) ( $rule['attributes'] ?? array() ) as $attribute => $value ) {
				$expression = self::value_expression( (string) $value, $props );
				if ( is_wp_error( $expression ) ) {
					return $expression;
				}
				$applied .= $indent . "\$element->add_render_attribute( '_wrapper', " . self::literal( (string) $attribute ) . ', ' . $expression . " );\n";
			}

			$applied .= $indent . "\$applied = true;\n";

			if ( '' === $condition ) {
				$body .= $applied;
			} else {
				$body .= "\t\tif ( " . $condition . " ) {\n" . $applied . "\t\t}\n\n";
			}
		}

		$out = "\tpublic function render( \$element ) {\n"
			. "\t\tif ( ! \$this->applies_to( \$element ) || ! method_exists( \$element, 'get_atomic_settings' ) ) {\n\t\t\treturn;\n\t\t}\n\n"
			. "\t\t\$settings = \$element->get_atomic_settings();\n"
			. "\t\t\$applied  = false;\n\n"
			. $body;

		$out .= "\t\tif ( ! \$applied ) {\n\t\t\treturn;\n\t\t}\n\n";
		$out .= self::assets_block( $style, $script, $critical );
		$out .= "\t}\n\n";

		return $out;
	}

	/**
	 * The block that brings in styles and scripts once the effect is in use.
	 *
	 * @param string $style    Style handle or ''.
	 * @param string $script   Script handle or ''.
	 * @param string $critical Critical CSS, or ''.
	 * @return string
	 */
	private static function assets_block( string $style, string $script, string $critical ): string {
		$out = '';

		if ( '' !== $critical ) {
			// Printed once per request, before the first element that uses the
			// effect: the stylesheet below is enqueued after wp_head and would
			// otherwise arrive late enough to flash.
			$out .= "\t\tstatic \$critical_printed = false;\n"
				. "\t\tif ( ! \$critical_printed ) {\n"
				. "\t\t\t\$critical_printed = true;\n"
				. "\t\t\techo '<style>' . " . self::literal( $critical ) . " . '</style>';\n"
				. "\t\t}\n\n";
		}

		if ( '' !== $style ) {
			$out .= "\t\tif ( wp_style_is( " . self::literal( $style ) . ", 'registered' ) ) {\n"
				. "\t\t\twp_enqueue_style( " . self::literal( $style ) . " );\n"
				. "\t\t}\n";
		}
		if ( '' !== $script ) {
			$out .= "\t\tif ( wp_script_is( " . self::literal( $script ) . ", 'registered' ) ) {\n"
				. "\t\t\twp_enqueue_script( " . self::literal( $script ) . " );\n"
				. "\t\t}\n";
		}

		return $out;
	}

	/**
	 * applies_to(): the target check, shared by controls() and render().
	 *
	 * @return string
	 */
	private static function applies_to_method(): string {
		return "\tprivate function applies_to( \$element ) {\n"
			. "\t\tif ( ! is_object( \$element ) || ! method_exists( \$element, 'get_element_type' ) ) {\n\t\t\treturn false;\n\t\t}\n"
			. "\t\tif ( in_array( '" . KarMCP_Extension_Spec::TARGET_ALL . "', self::TARGETS, true ) ) {\n\t\t\treturn true;\n\t\t}\n\n"
			. "\t\treturn in_array( \$element::get_element_type(), self::TARGETS, true );\n"
			. "\t}\n";
	}

	// -------------------------------------------------------------------------
	// Expressions
	// -------------------------------------------------------------------------

	/**
	 * The PHP condition for a rule, or '' when the rule is unconditional.
	 *
	 * @param array                $rule  Output rule.
	 * @param array<string,string> $props Declared props.
	 * @return string|WP_Error
	 */
	private static function condition_expression( array $rule, array $props ) {
		if ( ! isset( $rule['when'] ) || ! is_array( $rule['when'] ) ) {
			return '';
		}

		$when = $rule['when'];
		$name = (string) ( $when['prop'] ?? '' );

		if ( ! isset( $props[ $name ] ) ) {
			return new WP_Error( 'generator_condition_prop', __( 'A condition references a prop that is not declared.', 'karmcp' ) );
		}

		$type     = $props[ $name ]['type'];
		$accessor = self::accessor( $name, $props[ $name ] );

		if ( array_key_exists( 'equals', $when ) ) {
			return self::comparison( $accessor, $type, $when['equals'], true );
		}
		if ( array_key_exists( 'not', $when ) ) {
			return self::comparison( $accessor, $type, $when['not'], false );
		}

		// Truthy.
		if ( 'number' === $type ) {
			return '0 + (float) ' . $accessor . ' !== 0.0';
		}
		if ( 'switch' === $type ) {
			return '! empty( ' . $accessor . ' )';
		}

		return "'' !== trim( (string) " . $accessor . ' )';
	}

	/**
	 * An equals / not-equals comparison, typed.
	 *
	 * @param string $accessor PHP accessor for the value.
	 * @param string $type     Prop type.
	 * @param mixed  $value    Value from the spec.
	 * @param bool   $equal    True for equals, false for not.
	 * @return string
	 */
	private static function comparison( string $accessor, string $type, $value, bool $equal ): string {
		if ( 'number' === $type ) {
			return '0 + (float) ' . $accessor . ( $equal ? ' === ' : ' !== ' ) . self::literal( 0 + (float) $value );
		}
		if ( 'switch' === $type ) {
			return '(bool) ' . $accessor . ( $equal ? ' === ' : ' !== ' ) . self::literal( (bool) $value );
		}

		return '(string) ' . $accessor . ( $equal ? ' === ' : ' !== ' ) . self::literal( (string) $value );
	}

	/**
	 * The PHP expression for an attribute value, interpolating `{{prop}}`.
	 *
	 * Literal text comes from the spec and is emitted with var_export; every
	 * interpolated value is forced into a shape by its type.
	 *
	 * @param string               $value Attribute value from the spec.
	 * @param array<string,string> $props Declared props.
	 * @return string|WP_Error
	 */
	private static function value_expression( string $value, array $props ) {
		$parts = preg_split( '/(\{\{\s*karmcp_[a-z0-9_]+\s*\}\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'generator_value', __( 'An attribute value could not be parsed.', 'karmcp' ) );
		}

		$pieces = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '/^\{\{\s*(karmcp_[a-z0-9_]+)\s*\}\}$/', $part, $matches ) ) {
				$name = $matches[1];

				if ( ! isset( $props[ $name ] ) ) {
					return new WP_Error(
						'generator_value_prop',
						sprintf(
							/* translators: %s: prop name */
							__( 'An attribute interpolates "%s", which is not declared.', 'karmcp' ),
							$name
						)
					);
				}

				$pieces[] = self::typed_value( $name, $props[ $name ] );
				continue;
			}

			$pieces[] = self::literal( $part );
		}

		if ( empty( $pieces ) ) {
			return self::literal( '' );
		}

		return implode( ' . ', $pieces );
	}

	/**
	 * The expression that reads one prop, shaped by its type.
	 *
	 * @param string $name Prop name.
	 * @param array  $prop Indexed prop (type + default).
	 * @return string
	 */
	private static function typed_value( string $name, array $prop ): string {
		$accessor = self::accessor( $name, $prop );
		$type     = $prop['type'];

		switch ( $type ) {
			case 'number':
				return '(string) ( 0 + (float) ' . $accessor . ' )';

			case 'switch':
				return '( ! empty( ' . $accessor . " ) ? 'true' : 'false' )";

			case 'size':
				// A size arrives as { size, unit }. The number is cast and the
				// unit is checked against a fixed list, so neither half can
				// carry anything but a number and a known keyword.
				return '( (string) ( 0 + (float) ( ' . $accessor . "['size'] ?? 0 ) )\n"
					. "\t\t\t\t. ( in_array( (string) ( " . $accessor . "['unit'] ?? '' ), " . self::literal( self::UNITS ) . ", true )\n"
					. "\t\t\t\t\t? (string) " . $accessor . "['unit']\n"
					. "\t\t\t\t\t: 'px' ) )";

			default:
				return '(string) ' . $accessor;
		}
	}

	/**
	 * Indexes the spec's props by name, keeping the declared default.
	 *
	 * The default matters more than it looks: every element on the site gets
	 * this extension's props declared, and most of them will have nothing
	 * stored. If an absent value read as an empty string, a rule like
	 * `not: "none"` would be true everywhere and the effect would land on every
	 * container nobody ever configured.
	 *
	 * @param array $spec Validated spec.
	 * @return array<string, array{type:string,default:mixed}>
	 */
	private static function index_props( array $spec ): array {
		$out = array();

		foreach ( (array) ( $spec['props'] ?? array() ) as $prop ) {
			$out[ (string) $prop['name'] ] = array(
				'type'    => (string) $prop['type'],
				'default' => $prop['default'] ?? null,
			);
		}

		return $out;
	}

	/**
	 * The `$settings` accessor for a prop. Falls back to the prop's declared
	 * default, so an element that never touched this extension evaluates as if
	 * the control were at its resting position.
	 *
	 * @param string $name Prop name (already validated).
	 * @param array  $prop Indexed prop (type + default).
	 * @return string
	 */
	private static function accessor( string $name, array $prop ): string {
		return '( $settings[' . self::literal( $name ) . '] ?? ' . self::fallback_literal( $prop ) . ' )';
	}

	/**
	 * The literal an absent value falls back to, by type.
	 *
	 * @param array $prop Indexed prop (type + default).
	 * @return string
	 */
	private static function fallback_literal( array $prop ): string {
		$type    = $prop['type'];
		$default = $prop['default'];

		if ( 'size' === $type ) {
			return 'array()';
		}
		if ( null === $default || ! is_scalar( $default ) ) {
			switch ( $type ) {
				case 'switch':
					return self::literal( false );
				case 'number':
					return self::literal( 0 );
				default:
					return self::literal( '' );
			}
		}

		switch ( $type ) {
			case 'switch':
				return self::literal( (bool) $default );
			case 'number':
				return self::literal( 0 + (float) $default );
			default:
				return self::literal( (string) $default );
		}
	}

	/**
	 * The critical CSS from a spec, whichever shape `styles` came in.
	 *
	 * @param array $spec Validated spec.
	 * @return string
	 */
	private static function critical_css( array $spec ): string {
		$styles = $spec['styles'] ?? array();

		return is_array( $styles ) ? trim( (string) ( $styles['critical'] ?? '' ) ) : '';
	}

	/**
	 * The deferred CSS from a spec — what the store writes as a file.
	 *
	 * @since 1.13.0
	 *
	 * @param array $spec Validated spec.
	 * @return string
	 */
	public static function deferred_css( array $spec ): string {
		$styles = $spec['styles'] ?? array();

		if ( is_string( $styles ) ) {
			return trim( $styles );
		}

		return is_array( $styles ) ? trim( (string) ( $styles['deferred'] ?? '' ) ) : '';
	}

	/**
	 * A spec value as a PHP literal. Single funnel from spec data to code.
	 *
	 * @param mixed $value Value to emit.
	 * @return string
	 */
	private static function literal( $value ): string {
		return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal emission.
	}
}
