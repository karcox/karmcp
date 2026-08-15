<?php
/**
 * Element extension spec — the contract between an AI agent and the extension
 * compiler.
 *
 * The third artifact kind of the sandbox. A widget or a block is something you
 * insert; an extension is an option that shows up on elements that already
 * exist — a control in the panel of any Elementor 4.2+ container that does
 * something when you turn it on.
 *
 * The spec declares four things: which elements it attaches to, which props it
 * adds, which controls edit them, and what ends up in the element's HTML. The
 * agent never writes PHP, and — as in the other two compilers — escaping is
 * decided by the declared type, not by whoever wrote the spec.
 *
 * Two rules here exist because this artifact writes into a namespace it does
 * not own. Prop names must start with `karmcp_`, because Elementor's props
 * schema is shared with the core and with Pro and a collision would silently
 * shadow someone else's prop. And output attributes are restricted to `data-`
 * and `aria-`, because an extension that could emit `onclick`, `style` or
 * `href` would be an XSS vector wearing a nice UI.
 *
 * Pure: no database, no filesystem, no Elementor. See
 * docs/ROADMAP-ELEMENT-EXTENSIONS.md for the seams this is built on.
 *
 * @package KarMCP
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vocabulary and validation for element extension specs.
 *
 * @since 1.13.0
 */
class KarMCP_Extension_Spec {

	/** Current spec format. Bump only with a migration in the store. */
	const SPEC_VERSION = 1;

	/** Every prop an extension declares must start with this. */
	const PREFIX = 'karmcp_';

	const MAX_PROPS        = 12;
	const MAX_OPTIONS      = 60;
	const MAX_TITLE        = 120;
	const MAX_TARGETS      = 30;
	const MAX_RULES        = 12;
	const MAX_ATTRIBUTES   = 8;
	const MAX_CRITICAL_CSS = 2048;
	const MAX_ASSET        = 40000;

	/** Target that means "every atomic element". */
	const TARGET_ALL = '*';

	/**
	 * Attribute prefixes an extension may write. Everything else — class, id,
	 * style, href, on* — is refused: those are either owned by the element or a
	 * scripting vector.
	 *
	 * @var string[]
	 */
	const ATTRIBUTE_PREFIXES = array( 'data-', 'aria-' );

	/**
	 * The supported prop types.
	 *
	 * `prop_type` is the Elementor class the generator emits, `control` the
	 * control class, both fully qualified at emission. `value` says how the
	 * stored value reaches an attribute.
	 *
	 * There is deliberately no `color`: Elementor 4.2 ships no color control
	 * under controls/types, because colour lives in the Style tab.
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, array>
	 */
	public static function prop_types(): array {
		return array(
			'switch'   => array(
				'label'       => __( 'Toggle', 'karmcp' ),
				'prop_type'   => 'Primitives\\Boolean_Prop_Type',
				'control'     => 'Switch_Control',
				'value'       => 'bool',
				'description' => __( 'On/off. Use it in a rule condition rather than as an attribute value.', 'karmcp' ),
			),
			'select'   => array(
				'label'       => __( 'Select', 'karmcp' ),
				'prop_type'   => 'Primitives\\String_Prop_Type',
				'control'     => 'Select_Control',
				'value'       => 'string',
				'description' => __( 'One of a fixed set of options. Requires "options"; the values are enforced by the prop type itself.', 'karmcp' ),
			),
			'text'     => array(
				'label'       => __( 'Text', 'karmcp' ),
				'prop_type'   => 'Primitives\\String_Prop_Type',
				'control'     => 'Text_Control',
				'value'       => 'string',
				'description' => __( 'Single-line text. Escaped with esc_attr on output.', 'karmcp' ),
			),
			'textarea' => array(
				'label'       => __( 'Textarea', 'karmcp' ),
				'prop_type'   => 'Primitives\\String_Prop_Type',
				'control'     => 'Textarea_Control',
				'value'       => 'string',
				'description' => __( 'Multi-line text. Escaped with esc_attr on output.', 'karmcp' ),
			),
			'number'   => array(
				'label'       => __( 'Number', 'karmcp' ),
				'prop_type'   => 'Primitives\\Number_Prop_Type',
				'control'     => 'Number_Control',
				'value'       => 'number',
				'description' => __( 'A number. Cast before output, so it can never carry markup.', 'karmcp' ),
			),
			'size'     => array(
				'label'       => __( 'Size', 'karmcp' ),
				'prop_type'   => 'Size_Prop_Type',
				'control'     => 'Size_Control',
				'value'       => 'size',
				'description' => __( 'A number with a unit. Emitted as "40px" — the number is cast and the unit checked against a fixed list.', 'karmcp' ),
			),
		);
	}

	/**
	 * Whether a prop type exists.
	 *
	 * @since 1.13.0
	 *
	 * @param string $type Prop type.
	 * @return bool
	 */
	public static function has_type( string $type ): bool {
		return isset( self::prop_types()[ $type ] );
	}

	/**
	 * The payload behind `list-element-targets` (minus the site's element list,
	 * which the ability adds from Elementor's own registry).
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	public static function describe(): array {
		$types = array();
		foreach ( self::prop_types() as $type => $def ) {
			$types[] = array(
				'type'        => $type,
				'label'       => $def['label'],
				'description' => $def['description'],
			);
		}

		return array(
			'spec_version' => self::SPEC_VERSION,
			'prop_types'   => $types,
			'prop_prefix'  => self::PREFIX,
			'output'       => array(
				'attributes' => array(
					'allowed_prefixes' => self::ATTRIBUTE_PREFIXES,
					'interpolation'    => '{{karmcp_prop_name}}',
				),
				'conditions' => array(
					'equals' => array( 'prop' => 'karmcp_effect', 'equals' => 'snow' ),
					'not'    => array( 'prop' => 'karmcp_effect', 'not' => 'none' ),
					'truthy' => array( 'prop' => 'karmcp_enabled' ),
				),
			),
			'notes'        => array(
				__( 'Prop names must start with karmcp_: the props schema is shared with Elementor and with Elementor Pro.', 'karmcp' ),
				__( 'Only data-* and aria-* attributes can be written. class, id, style and event handlers are refused.', 'karmcp' ),
				__( 'The extension shows up in the element\'s settings panel, not in the Style tab — Style cannot be extended from PHP in 4.2.', 'karmcp' ),
				__( 'Targets are element types like "e-div-block" or "e-flexbox". Use list-element-targets to see what this site actually has.', 'karmcp' ),
			),
			'limits'       => array(
				'props'        => self::MAX_PROPS,
				'targets'      => self::MAX_TARGETS,
				'rules'        => self::MAX_RULES,
				'critical_css' => self::MAX_CRITICAL_CSS,
				'asset'        => self::MAX_ASSET,
			),
		);
	}

	/**
	 * Validates a spec.
	 *
	 * @since 1.13.0
	 *
	 * @param array $spec The spec to check.
	 * @return true|WP_Error
	 */
	public static function validate( array $spec ) {
		$version = isset( $spec['spec_version'] ) ? (int) $spec['spec_version'] : self::SPEC_VERSION;
		if ( $version < 1 || $version > self::SPEC_VERSION ) {
			return new WP_Error(
				'spec_version',
				sprintf(
					/* translators: %d: the highest supported spec version */
					__( 'Unsupported spec_version. This build understands up to %d.', 'karmcp' ),
					self::SPEC_VERSION
				)
			);
		}

		$checked = self::validate_meta( $spec );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$checked = self::validate_targets( $spec );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$props = self::validate_props( $spec );
		if ( is_wp_error( $props ) ) {
			return $props;
		}

		$checked = self::validate_output( $spec, $props );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		return self::validate_assets( $spec );
	}

	/**
	 * Returns the declared prop names, indexed by name => type. Assumes a spec
	 * that already validated.
	 *
	 * @since 1.13.0
	 *
	 * @param array $spec Validated spec.
	 * @return array<string, string>
	 */
	public static function prop_map( array $spec ): array {
		$map = array();
		foreach ( (array) ( $spec['props'] ?? array() ) as $prop ) {
			if ( is_array( $prop ) && isset( $prop['name'], $prop['type'] ) ) {
				$map[ (string) $prop['name'] ] = (string) $prop['type'];
			}
		}
		return $map;
	}

	// -------------------------------------------------------------------------
	// Sections
	// -------------------------------------------------------------------------

	/**
	 * meta.title and the panel section label.
	 *
	 * @param array $spec Spec.
	 * @return true|WP_Error
	 */
	private static function validate_meta( array $spec ) {
		$meta  = isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();
		$title = isset( $meta['title'] ) ? trim( (string) $meta['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'spec_title', __( 'meta.title is required — it names the extension for the person managing it.', 'karmcp' ) );
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE ) {
			return new WP_Error(
				'spec_title_long',
				sprintf(
					/* translators: %d: character limit */
					__( 'meta.title is longer than %d characters.', 'karmcp' ),
					self::MAX_TITLE
				)
			);
		}

		$section = isset( $spec['section'] ) && is_array( $spec['section'] ) ? $spec['section'] : array();
		$label   = isset( $section['label'] ) ? trim( (string) $section['label'] ) : '';

		if ( '' !== $label && mb_strlen( $label ) > self::MAX_TITLE ) {
			return new WP_Error(
				'spec_section_label',
				sprintf(
					/* translators: %d: character limit */
					__( 'section.label is longer than %d characters.', 'karmcp' ),
					self::MAX_TITLE
				)
			);
		}

		return true;
	}

	/**
	 * targets: the element types the extension attaches to.
	 *
	 * @param array $spec Spec.
	 * @return true|WP_Error
	 */
	private static function validate_targets( array $spec ) {
		$targets = isset( $spec['targets'] ) && is_array( $spec['targets'] ) ? $spec['targets'] : array();

		if ( empty( $targets ) ) {
			return new WP_Error( 'spec_targets', __( 'targets is required: list the element types, or ["*"] for every atomic element.', 'karmcp' ) );
		}
		if ( count( $targets ) > self::MAX_TARGETS ) {
			return new WP_Error(
				'spec_targets_many',
				sprintf(
					/* translators: %d: target limit */
					__( 'An extension may target at most %d element types.', 'karmcp' ),
					self::MAX_TARGETS
				)
			);
		}

		foreach ( $targets as $target ) {
			if ( ! is_string( $target ) ) {
				return new WP_Error( 'spec_target_type', __( 'Every target must be a string.', 'karmcp' ) );
			}
			if ( self::TARGET_ALL === $target ) {
				continue;
			}
			// Atomic element types are prefixed `e-` (e-div-block, e-flexbox).
			if ( ! preg_match( '/^e-[a-z][a-z0-9-]{0,63}$/', $target ) ) {
				return new WP_Error(
					'spec_target_name',
					sprintf(
						/* translators: %s: the offending target */
						__( '"%s" is not a valid element type. Atomic elements look like "e-div-block"; call list-element-targets for this site\'s list.', 'karmcp' ),
						$target
					)
				);
			}
		}

		return true;
	}

	/**
	 * props: name, type, options, default.
	 *
	 * @param array $spec Spec.
	 * @return array<string,string>|WP_Error Prop map on success.
	 */
	private static function validate_props( array $spec ) {
		$props = isset( $spec['props'] ) && is_array( $spec['props'] ) ? $spec['props'] : array();

		if ( empty( $props ) ) {
			return new WP_Error( 'spec_props', __( 'props is required: an extension with no props adds nothing to the panel.', 'karmcp' ) );
		}
		if ( count( $props ) > self::MAX_PROPS ) {
			return new WP_Error(
				'spec_props_many',
				sprintf(
					/* translators: %d: prop limit */
					__( 'An extension may declare at most %d props.', 'karmcp' ),
					self::MAX_PROPS
				)
			);
		}

		$map = array();

		foreach ( $props as $index => $prop ) {
			if ( ! is_array( $prop ) ) {
				return new WP_Error(
					'spec_prop_shape',
					sprintf(
						/* translators: %d: position in the props array */
						__( 'Prop #%d is not an object.', 'karmcp' ),
						(int) $index + 1
					)
				);
			}

			$name = isset( $prop['name'] ) ? (string) $prop['name'] : '';
			if ( ! preg_match( '/^karmcp_[a-z][a-z0-9_]{0,31}$/', $name ) ) {
				return new WP_Error(
					'spec_prop_name',
					sprintf(
						/* translators: %s: the offending prop name */
						__( 'Prop name "%s" is invalid: it must start with karmcp_ and continue with lowercase letters, digits and underscores. The props schema is shared with Elementor, so the prefix is what keeps an extension from shadowing someone else\'s prop.', 'karmcp' ),
						$name
					)
				);
			}
			if ( isset( $map[ $name ] ) ) {
				return new WP_Error(
					'spec_prop_duplicate',
					sprintf(
						/* translators: %s: the duplicated prop name */
						__( 'Prop "%s" is declared twice.', 'karmcp' ),
						$name
					)
				);
			}

			$type = isset( $prop['type'] ) ? (string) $prop['type'] : '';
			if ( ! self::has_type( $type ) ) {
				return new WP_Error(
					'spec_prop_type',
					sprintf(
						/* translators: 1: prop name, 2: comma-separated list of valid types */
						__( 'Prop "%1$s" has an unknown type. Supported: %2$s.', 'karmcp' ),
						$name,
						implode( ', ', array_keys( self::prop_types() ) )
					)
				);
			}

			if ( 'select' === $type ) {
				$checked = self::validate_options( $name, $prop );
				if ( is_wp_error( $checked ) ) {
					return $checked;
				}
			}

			if ( isset( $prop['default'] ) && ! is_scalar( $prop['default'] ) ) {
				return new WP_Error(
					'spec_prop_default',
					sprintf(
						/* translators: %s: prop name */
						__( 'Prop "%s" has a non-scalar default.', 'karmcp' ),
						$name
					)
				);
			}

			$map[ $name ] = $type;
		}

		return $map;
	}

	/**
	 * A select prop's options map.
	 *
	 * @param string $name Prop name.
	 * @param array  $prop Prop definition.
	 * @return true|WP_Error
	 */
	private static function validate_options( string $name, array $prop ) {
		$options = isset( $prop['options'] ) && is_array( $prop['options'] ) ? $prop['options'] : array();

		if ( empty( $options ) ) {
			return new WP_Error(
				'spec_select_options',
				sprintf(
					/* translators: %s: prop name */
					__( 'Select prop "%s" needs a non-empty "options" map of value => label.', 'karmcp' ),
					$name
				)
			);
		}
		if ( count( $options ) > self::MAX_OPTIONS ) {
			return new WP_Error(
				'spec_select_many',
				sprintf(
					/* translators: 1: prop name, 2: option limit */
					__( 'Select prop "%1$s" has more than %2$d options.', 'karmcp' ),
					$name,
					self::MAX_OPTIONS
				)
			);
		}

		foreach ( $options as $value => $label ) {
			if ( ! is_scalar( $label ) || ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', (string) $value ) ) {
				return new WP_Error(
					'spec_select_option',
					sprintf(
						/* translators: %s: prop name */
						__( 'Select prop "%s" has an option key that is not a simple slug, or a label that is not text.', 'karmcp' ),
						$name
					)
				);
			}
		}

		return true;
	}

	/**
	 * output: the rules that put something in the element's HTML.
	 *
	 * @param array                $spec  Spec.
	 * @param array<string,string> $props Declared props.
	 * @return true|WP_Error
	 */
	private static function validate_output( array $spec, array $props ) {
		$rules = isset( $spec['output'] ) && is_array( $spec['output'] ) ? $spec['output'] : array();

		if ( empty( $rules ) ) {
			return new WP_Error( 'spec_output', __( 'output is required: without a rule the extension stores a value and does nothing with it.', 'karmcp' ) );
		}
		if ( count( $rules ) > self::MAX_RULES ) {
			return new WP_Error(
				'spec_output_many',
				sprintf(
					/* translators: %d: rule limit */
					__( 'An extension may declare at most %d output rules.', 'karmcp' ),
					self::MAX_RULES
				)
			);
		}

		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				return new WP_Error(
					'spec_rule_shape',
					sprintf(
						/* translators: %d: position in the output array */
						__( 'Output rule #%d is not an object.', 'karmcp' ),
						(int) $index + 1
					)
				);
			}

			$checked = self::validate_condition( $rule, $props );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			if ( isset( $rule['class'] ) ) {
				// Validated here, emitted as a literal: a CSS class must never
				// come from a control's value.
				if ( ! is_string( $rule['class'] ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $rule['class'] ) ) {
					return new WP_Error(
						'spec_rule_class',
						__( 'A rule\'s "class" must be a single CSS class: letters, digits, hyphen and underscore, starting with a letter.', 'karmcp' )
					);
				}
			}

			$checked = self::validate_attributes( $rule, $props );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			if ( ! isset( $rule['class'] ) && empty( $rule['attributes'] ) ) {
				return new WP_Error(
					'spec_rule_empty',
					sprintf(
						/* translators: %d: position in the output array */
						__( 'Output rule #%d does nothing: give it a class, attributes, or both.', 'karmcp' ),
						(int) $index + 1
					)
				);
			}
		}

		return true;
	}

	/**
	 * A rule's `when` condition.
	 *
	 * @param array                $rule  Rule.
	 * @param array<string,string> $props Declared props.
	 * @return true|WP_Error
	 */
	private static function validate_condition( array $rule, array $props ) {
		if ( ! isset( $rule['when'] ) ) {
			return true;
		}

		if ( ! is_array( $rule['when'] ) ) {
			return new WP_Error( 'spec_condition_shape', __( 'A rule\'s "when" must be an object.', 'karmcp' ) );
		}

		$when = $rule['when'];
		$prop = isset( $when['prop'] ) ? (string) $when['prop'] : '';

		if ( ! isset( $props[ $prop ] ) ) {
			return new WP_Error(
				'spec_condition_prop',
				sprintf(
					/* translators: %s: prop name used in a condition */
					__( 'A condition references "%s", which is not one of the declared props.', 'karmcp' ),
					$prop
				)
			);
		}

		foreach ( array_keys( $when ) as $key ) {
			if ( ! in_array( $key, array( 'prop', 'equals', 'not' ), true ) ) {
				return new WP_Error(
					'spec_condition_key',
					sprintf(
						/* translators: %s: the offending key */
						__( '"%s" is not a supported condition. Use equals, not, or just prop for a truthy check.', 'karmcp' ),
						$key
					)
				);
			}
		}

		foreach ( array( 'equals', 'not' ) as $operator ) {
			if ( isset( $when[ $operator ] ) && ! is_scalar( $when[ $operator ] ) ) {
				return new WP_Error(
					'spec_condition_value',
					sprintf(
						/* translators: %s: operator name */
						__( 'The value of "%s" must be a scalar.', 'karmcp' ),
						$operator
					)
				);
			}
		}

		return true;
	}

	/**
	 * A rule's attributes: names, count, and the props they interpolate.
	 *
	 * @param array                $rule  Rule.
	 * @param array<string,string> $props Declared props.
	 * @return true|WP_Error
	 */
	private static function validate_attributes( array $rule, array $props ) {
		if ( ! isset( $rule['attributes'] ) ) {
			return true;
		}

		if ( ! is_array( $rule['attributes'] ) ) {
			return new WP_Error( 'spec_attributes_shape', __( 'A rule\'s "attributes" must be an object of name => value.', 'karmcp' ) );
		}
		if ( count( $rule['attributes'] ) > self::MAX_ATTRIBUTES ) {
			return new WP_Error(
				'spec_attributes_many',
				sprintf(
					/* translators: %d: attribute limit */
					__( 'A rule may write at most %d attributes.', 'karmcp' ),
					self::MAX_ATTRIBUTES
				)
			);
		}

		foreach ( $rule['attributes'] as $name => $value ) {
			$name = (string) $name;

			if ( ! preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $name ) || ! self::is_allowed_attribute( $name ) ) {
				return new WP_Error(
					'spec_attribute_name',
					sprintf(
						/* translators: 1: the offending attribute, 2: allowed prefixes */
						__( 'Attribute "%1$s" is not allowed. An extension may only write %2$s attributes — class, id, style, href and event handlers belong to the element, not to the extension.', 'karmcp' ),
						$name,
						implode( ', ', self::ATTRIBUTE_PREFIXES ) . '*'
					)
				);
			}

			if ( ! is_scalar( $value ) ) {
				return new WP_Error(
					'spec_attribute_value',
					sprintf(
						/* translators: %s: attribute name */
						__( 'The value of "%s" must be text, optionally containing {{prop}} placeholders.', 'karmcp' ),
						$name
					)
				);
			}

			foreach ( self::referenced_props( (string) $value ) as $referenced ) {
				if ( ! isset( $props[ $referenced ] ) ) {
					return new WP_Error(
						'spec_attribute_prop',
						sprintf(
							/* translators: 1: prop name, 2: attribute name */
							__( '"%1$s" (used in attribute "%2$s") is not one of the declared props.', 'karmcp' ),
							$referenced,
							$name
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * styles.critical / styles.deferred / scripts.
	 *
	 * @param array $spec Spec.
	 * @return true|WP_Error
	 */
	private static function validate_assets( array $spec ) {
		$styles = $spec['styles'] ?? array();

		if ( ! is_array( $styles ) && ! is_string( $styles ) ) {
			return new WP_Error( 'spec_styles_shape', __( 'styles must be a string, or an object with "critical" and "deferred".', 'karmcp' ) );
		}

		$critical = is_array( $styles ) ? (string) ( $styles['critical'] ?? '' ) : '';
		$deferred = is_array( $styles ) ? (string) ( $styles['deferred'] ?? '' ) : (string) $styles;

		if ( strlen( $critical ) > self::MAX_CRITICAL_CSS ) {
			return new WP_Error(
				'spec_critical_long',
				sprintf(
					/* translators: %d: byte limit */
					__( 'styles.critical is larger than %d bytes. It is inlined on every element that uses the effect, so it has to stay small — put the rest in styles.deferred.', 'karmcp' ),
					self::MAX_CRITICAL_CSS
				)
			);
		}

		foreach ( array( 'styles.critical' => $critical, 'styles.deferred' => $deferred, 'scripts' => (string) ( $spec['scripts'] ?? '' ) ) as $field => $source ) {
			if ( strlen( $source ) > self::MAX_ASSET ) {
				return new WP_Error(
					'spec_asset_long',
					sprintf(
						/* translators: 1: field name, 2: byte limit */
						__( '"%1$s" is larger than %2$d bytes.', 'karmcp' ),
						$field,
						self::MAX_ASSET
					)
				);
			}
			if ( '' !== $source && preg_match( '/<\?(php|=)?/i', $source ) ) {
				return new WP_Error(
					'spec_asset_php',
					sprintf(
						/* translators: %s: field name */
						__( '"%s" contains a PHP open tag. Assets are data, not code.', 'karmcp' ),
						$field
					)
				);
			}
		}

		// The critical block is printed inside a <style> tag, so it must not be
		// able to close it.
		if ( preg_match( '#</\s*style#i', $critical ) ) {
			return new WP_Error( 'spec_critical_tag', __( 'styles.critical contains a closing </style> tag.', 'karmcp' ) );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether an attribute name is one an extension may write.
	 *
	 * @since 1.13.0
	 *
	 * @param string $name Attribute name.
	 * @return bool
	 */
	public static function is_allowed_attribute( string $name ): bool {
		foreach ( self::ATTRIBUTE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The prop names an attribute value interpolates.
	 *
	 * @since 1.13.0
	 *
	 * @param string $value Attribute value.
	 * @return string[]
	 */
	public static function referenced_props( string $value ): array {
		$names = array();
		if ( preg_match_all( '/\{\{\s*(karmcp_[a-z0-9_]+)\s*\}\}/', $value, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				if ( ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}
		return $names;
	}
}
