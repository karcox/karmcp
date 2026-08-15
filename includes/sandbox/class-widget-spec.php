<?php
/**
 * Widget spec — the contract between an AI agent and the widget compiler.
 *
 * A spec is plain structured data: metadata, a list of typed controls, an HTML
 * template with `{{placeholder}}` references, and optional CSS/JS. The agent
 * never writes PHP; `KarMCP_Widget_Generator` compiles the spec into a
 * `Widget_Base` subclass and decides every escape from the control's declared
 * type. This class owns the vocabulary (which control types exist) and the
 * validation, so the generator, the validator tool, and `list-control-types`
 * all read from one source.
 *
 * Specs carry `spec_version`. The stored spec is the regenerable source of
 * truth for a widget's PHP, so the format has to be able to change without
 * orphaning what people already saved — a version they carry from day one is
 * what makes that migration possible later.
 *
 * Everything here is pure: no database, no filesystem, no Elementor. That is
 * what lets `validate-widget-spec` dry-run the real compiler, and what lets the
 * test suite cover it without WordPress.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vocabulary and validation for widget specs.
 *
 * @since 1.12.0
 */
class KarMCP_Widget_Spec {

	/** Current spec format. Bump only with a migration in the store. */
	const SPEC_VERSION = 1;

	/** Ceilings. Generous for real widgets, closed for abuse. */
	const MAX_CONTROLS = 40;
	const MAX_OPTIONS  = 60;
	const MAX_TITLE    = 120;
	const MAX_TEMPLATE = KarMCP_Sandbox_Template::MAX_TEMPLATE;
	const MAX_ASSET    = 40000;

	/** The two control sections a widget spec may place a control in. */
	const SECTIONS = array( 'content', 'style' );

	/**
	 * Control names the compiled widget reserves for itself or that Elementor
	 * already means something by. A spec using one is rejected rather than
	 * silently shadowed.
	 *
	 * @var string[]
	 */
	const RESERVED = array( 'settings', 'id', 'type', 'elements', 'widgettype', 'classes', 'karmcp' );

	/**
	 * The supported control types.
	 *
	 * `elementor` is the Controls_Manager constant the generator emits.
	 * `subprops` lists the `{{name.sub}}` accessors the type answers to.
	 * `attr` says whether `{{name|attr}}` is meaningful for it.
	 *
	 * @since 1.12.0
	 *
	 * @return array<string, array>
	 */
	public static function control_types(): array {
		return array(
			'text'     => array(
				'label'       => __( 'Text', 'karmcp' ),
				'elementor'   => 'TEXT',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'Single-line text. Escaped with esc_html (esc_attr with |attr).', 'karmcp' ),
			),
			'textarea' => array(
				'label'       => __( 'Textarea', 'karmcp' ),
				'elementor'   => 'TEXTAREA',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'Multi-line plain text. Escaped with esc_html; line breaks are preserved with nl2br.', 'karmcp' ),
			),
			'wysiwyg'  => array(
				'label'       => __( 'Rich text', 'karmcp' ),
				'elementor'   => 'WYSIWYG',
				'subprops'    => array(),
				'attr'        => false,
				'description' => __( 'Rich text from the editor. Filtered with wp_kses_post — the only type that may emit markup.', 'karmcp' ),
			),
			'number'   => array(
				'label'       => __( 'Number', 'karmcp' ),
				'elementor'   => 'NUMBER',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'A number. Cast before output, so it can never carry markup.', 'karmcp' ),
			),
			'select'   => array(
				'label'       => __( 'Select', 'karmcp' ),
				'elementor'   => 'SELECT',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'One of a fixed set of options. Requires "options". Escaped with esc_html (esc_attr with |attr).', 'karmcp' ),
			),
			'switcher' => array(
				'label'       => __( 'Toggle', 'karmcp' ),
				'elementor'   => 'SWITCHER',
				'subprops'    => array(),
				'attr'        => false,
				'description' => __( 'On/off. Intended for {{#if name}} blocks rather than direct output.', 'karmcp' ),
			),
			'color'    => array(
				'label'       => __( 'Color', 'karmcp' ),
				'elementor'   => 'COLOR',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'A color value. Always escaped with esc_attr — use it inside a style attribute.', 'karmcp' ),
			),
			'url'      => array(
				'label'       => __( 'Link', 'karmcp' ),
				'elementor'   => 'URL',
				'subprops'    => array( 'url', 'target', 'rel' ),
				'attr'        => false,
				'description' => __( 'A link. {{name.url}} is esc_url; {{name.target}} and {{name.rel}} emit the safe attribute values Elementor recorded.', 'karmcp' ),
			),
			'media'    => array(
				'label'       => __( 'Image', 'karmcp' ),
				'elementor'   => 'MEDIA',
				'subprops'    => array( 'url', 'alt', 'id' ),
				'attr'        => false,
				'description' => __( 'An image from the Media Library. {{name.url}} is esc_url, {{name.alt}} is the library alt text, {{name.id}} is an integer.', 'karmcp' ),
			),
			'icon'     => array(
				'label'       => __( 'Icon', 'karmcp' ),
				'elementor'   => 'ICONS',
				'subprops'    => array(),
				'attr'        => false,
				'description' => __( 'An icon. Rendered through Elementor\'s own icon manager, which emits the markup.', 'karmcp' ),
			),
		);
	}

	/**
	 * Whether a control type exists.
	 *
	 * @since 1.12.0
	 *
	 * @param string $type Control type.
	 * @return bool
	 */
	public static function has_type( string $type ): bool {
		return isset( self::control_types()[ $type ] );
	}

	/**
	 * The payload behind `list-control-types`: the vocabulary plus the template
	 * syntax, so an agent can build a valid spec without guessing.
	 *
	 * @since 1.12.0
	 *
	 * @return array
	 */
	public static function describe(): array {
		$types = array();
		foreach ( self::control_types() as $type => $def ) {
			$types[] = array(
				'type'        => $type,
				'label'       => $def['label'],
				'description' => $def['description'],
				'subprops'    => $def['subprops'],
				'supports_attr_modifier' => $def['attr'],
			);
		}

		return array(
			'spec_version'    => self::SPEC_VERSION,
			'control_types'   => $types,
			'sections'        => self::SECTIONS,
			'template_syntax' => array(
				'value'       => '{{name}}',
				'attribute'   => '{{name|attr}}',
				'subproperty' => '{{name.url}}',
				'conditional' => '{{#if name}} … {{/if}}',
				'notes'       => array(
					__( 'The template is HTML. Everything that is not a placeholder is emitted verbatim as text — it can never become PHP.', 'karmcp' ),
					__( 'Escaping is chosen by the control type, not by you. There is no raw output modifier.', 'karmcp' ),
					__( 'Inline <script> tags and inline event handler attributes (onclick=…) are rejected: put behaviour in "scripts".', 'karmcp' ),
				),
			),
			'limits'          => array(
				'controls' => self::MAX_CONTROLS,
				'options'  => self::MAX_OPTIONS,
				'template' => self::MAX_TEMPLATE,
				'asset'    => self::MAX_ASSET,
			),
		);
	}

	/**
	 * Validates a spec.
	 *
	 * @since 1.12.0
	 *
	 * @param array $spec The spec to check.
	 * @return true|WP_Error
	 */
	public static function validate( array $spec ) {
		// ----- spec_version -----
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

		// ----- meta -----
		$meta = isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();
		$title = isset( $meta['title'] ) ? trim( (string) $meta['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'spec_title', __( 'meta.title is required — it is the widget name a person sees in the panel.', 'karmcp' ) );
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
		if ( isset( $meta['keywords'] ) && ! is_array( $meta['keywords'] ) ) {
			return new WP_Error( 'spec_keywords', __( 'meta.keywords must be an array of strings.', 'karmcp' ) );
		}

		// ----- controls -----
		$controls = isset( $spec['controls'] ) && is_array( $spec['controls'] ) ? $spec['controls'] : array();
		if ( count( $controls ) > self::MAX_CONTROLS ) {
			return new WP_Error(
				'spec_controls_many',
				sprintf(
					/* translators: %d: control limit */
					__( 'A widget may declare at most %d controls.', 'karmcp' ),
					self::MAX_CONTROLS
				)
			);
		}

		$seen = array();
		foreach ( $controls as $index => $control ) {
			if ( ! is_array( $control ) ) {
				return new WP_Error(
					'spec_control_shape',
					sprintf(
						/* translators: %d: position in the controls array */
						__( 'Control #%d is not an object.', 'karmcp' ),
						(int) $index + 1
					)
				);
			}

			$name = isset( $control['name'] ) ? (string) $control['name'] : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $name ) ) {
				return new WP_Error(
					'spec_control_name',
					sprintf(
						/* translators: %s: the offending control name */
						__( 'Control name "%s" is invalid: use lowercase letters, digits and underscores, starting with a letter (max 32).', 'karmcp' ),
						$name
					)
				);
			}
			if ( in_array( $name, self::RESERVED, true ) ) {
				return new WP_Error(
					'spec_control_reserved',
					sprintf(
						/* translators: %s: the reserved control name */
						__( 'Control name "%s" is reserved.', 'karmcp' ),
						$name
					)
				);
			}
			if ( isset( $seen[ $name ] ) ) {
				return new WP_Error(
					'spec_control_duplicate',
					sprintf(
						/* translators: %s: the duplicated control name */
						__( 'Control "%s" is declared twice.', 'karmcp' ),
						$name
					)
				);
			}
			$seen[ $name ] = true;

			$type = isset( $control['type'] ) ? (string) $control['type'] : '';
			if ( ! self::has_type( $type ) ) {
				return new WP_Error(
					'spec_control_type',
					sprintf(
						/* translators: 1: control name, 2: comma-separated list of valid types */
						__( 'Control "%1$s" has an unknown type. Supported: %2$s.', 'karmcp' ),
						$name,
						implode( ', ', array_keys( self::control_types() ) )
					)
				);
			}

			if ( isset( $control['section'] ) && ! in_array( (string) $control['section'], self::SECTIONS, true ) ) {
				return new WP_Error(
					'spec_control_section',
					sprintf(
						/* translators: %s: control name */
						__( 'Control "%s" has an unknown section (use "content" or "style").', 'karmcp' ),
						$name
					)
				);
			}

			if ( 'select' === $type ) {
				$options = isset( $control['options'] ) && is_array( $control['options'] ) ? $control['options'] : array();
				if ( empty( $options ) ) {
					return new WP_Error(
						'spec_select_options',
						sprintf(
							/* translators: %s: control name */
							__( 'Select control "%s" needs a non-empty "options" map of value => label.', 'karmcp' ),
							$name
						)
					);
				}
				if ( count( $options ) > self::MAX_OPTIONS ) {
					return new WP_Error(
						'spec_select_many',
						sprintf(
							/* translators: 1: control name, 2: option limit */
							__( 'Select control "%1$s" has more than %2$d options.', 'karmcp' ),
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
								/* translators: %s: control name */
								__( 'Select control "%s" has an option key that is not a simple slug, or a label that is not text.', 'karmcp' ),
								$name
							)
						);
					}
				}
			}

			if ( isset( $control['default'] ) && ! is_scalar( $control['default'] ) ) {
				return new WP_Error(
					'spec_control_default',
					sprintf(
						/* translators: %s: control name */
						__( 'Control "%s" has a non-scalar default.', 'karmcp' ),
						$name
					)
				);
			}
		}

		// ----- template -----
		$template = isset( $spec['template'] ) ? (string) $spec['template'] : '';
		if ( '' === trim( $template ) ) {
			return new WP_Error( 'spec_template', __( 'template is required — it is what the widget renders.', 'karmcp' ) );
		}
		$checked = self::check_markup( $template, 'template' );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$declared = array();
		foreach ( $controls as $control ) {
			$declared[ (string) $control['name'] ] = (string) $control['type'];
		}
		foreach ( KarMCP_Sandbox_Template::referenced_names( $template ) as $referenced ) {
			if ( ! isset( $declared[ $referenced ] ) ) {
				return new WP_Error(
					'spec_template_unknown',
					sprintf(
						/* translators: %s: control name used in the template */
						__( 'The template references "%s", which is not one of the declared controls.', 'karmcp' ),
						$referenced
					)
				);
			}
		}

		// ----- assets -----
		foreach ( array( 'styles', 'scripts' ) as $asset ) {
			if ( ! isset( $spec[ $asset ] ) ) {
				continue;
			}
			if ( ! is_string( $spec[ $asset ] ) ) {
				return new WP_Error(
					'spec_asset_type',
					sprintf(
						/* translators: %s: "styles" or "scripts" */
						__( '"%s" must be a string.', 'karmcp' ),
						$asset
					)
				);
			}
			if ( strlen( $spec[ $asset ] ) > self::MAX_ASSET ) {
				return new WP_Error(
					'spec_asset_long',
					sprintf(
						/* translators: 1: "styles" or "scripts", 2: byte limit */
						__( '"%1$s" is larger than %2$d bytes.', 'karmcp' ),
						$asset,
						self::MAX_ASSET
					)
				);
			}
			if ( preg_match( '/<\?(php|=)?/i', $spec[ $asset ] ) ) {
				return new WP_Error(
					'spec_asset_php',
					sprintf(
						/* translators: %s: "styles" or "scripts" */
						__( '"%s" contains a PHP open tag. Assets are served as static files.', 'karmcp' ),
						$asset
					)
				);
			}
		}

		return true;
	}

	/**
	 * Markup rules. Delegates to the shared implementation so a widget template
	 * and a block template can never drift into different safety rules.
	 *
	 * @since 1.12.0
	 *
	 * @param string $markup The template source.
	 * @param string $field  Field name, for the error message.
	 * @return true|WP_Error
	 */
	public static function check_markup( string $markup, string $field = 'template' ) {
		return KarMCP_Sandbox_Template::check_markup( $markup, $field );
	}
}
