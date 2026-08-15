<?php
/**
 * Block spec — the contract between an AI agent and the block compiler.
 *
 * The Gutenberg counterpart of `KarMCP_Widget_Spec`, and deliberately the same
 * shape: metadata, typed attributes, an HTML template with `{{placeholder}}`
 * references, and optional CSS/JS. An agent that has learned to describe a
 * widget can describe a block without learning a second language, and the
 * markup safety rules are literally the same code.
 *
 * Where it differs is what a type means downstream: a widget control becomes an
 * Elementor control, a block attribute becomes an entry in block.json plus an
 * editor control rendered by the shared sandbox-blocks script — which is why
 * each type here also declares its JSON type and default.
 *
 * Pure: no database, no filesystem, no WordPress.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vocabulary and validation for block specs.
 *
 * @since 1.12.0
 */
class KarMCP_Block_Spec {

	/** Current spec format. Bump only with a migration in the store. */
	const SPEC_VERSION = 1;

	const MAX_ATTRIBUTES = 40;
	const MAX_OPTIONS    = 60;
	const MAX_TITLE      = 120;
	const MAX_ASSET      = 40000;

	/**
	 * Attribute names the generated render reserves, or that would collide with
	 * what WordPress puts in `$attributes` for a block with supports.
	 *
	 * @var string[]
	 */
	const RESERVED = array( 'lock', 'metadata', 'anchor', 'classname', 'style', 'align', 'attributes', 'karmcp' );

	/**
	 * The supported attribute types.
	 *
	 * `json` is the block.json attribute type, `blank` the default value used
	 * when the spec does not give one, `control` the editor control the shared
	 * script renders, `subprops` the `{{name.sub}}` accessors the type answers
	 * to, and `attr` whether `{{name|attr}}` applies.
	 *
	 * @since 1.12.0
	 *
	 * @return array<string, array>
	 */
	public static function attribute_types(): array {
		return array(
			'text'     => array(
				'label'       => __( 'Text', 'karmcp' ),
				'json'        => 'string',
				'blank'       => '',
				'control'     => 'text',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'Single-line text. Escaped with esc_html (esc_attr with |attr).', 'karmcp' ),
			),
			'textarea' => array(
				'label'       => __( 'Textarea', 'karmcp' ),
				'json'        => 'string',
				'blank'       => '',
				'control'     => 'textarea',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'Multi-line plain text. Escaped with esc_html; line breaks preserved with nl2br.', 'karmcp' ),
			),
			'richtext' => array(
				'label'       => __( 'Rich text', 'karmcp' ),
				'json'        => 'string',
				'blank'       => '',
				'control'     => 'textarea',
				'subprops'    => array(),
				'attr'        => false,
				'description' => __( 'Text that may contain basic markup. Filtered with wp_kses_post — the only type that may emit tags.', 'karmcp' ),
			),
			'number'   => array(
				'label'       => __( 'Number', 'karmcp' ),
				'json'        => 'number',
				'blank'       => 0,
				'control'     => 'number',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'A number. Cast before output, so it can never carry markup.', 'karmcp' ),
			),
			'select'   => array(
				'label'       => __( 'Select', 'karmcp' ),
				'json'        => 'string',
				'blank'       => '',
				'control'     => 'select',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'One of a fixed set of options. Requires "options".', 'karmcp' ),
			),
			'toggle'   => array(
				'label'       => __( 'Toggle', 'karmcp' ),
				'json'        => 'boolean',
				'blank'       => false,
				'control'     => 'toggle',
				'subprops'    => array(),
				'attr'        => false,
				'description' => __( 'On/off. Intended for {{#if name}} blocks rather than direct output.', 'karmcp' ),
			),
			'color'    => array(
				'label'       => __( 'Color', 'karmcp' ),
				'json'        => 'string',
				'blank'       => '',
				'control'     => 'color',
				'subprops'    => array(),
				'attr'        => true,
				'description' => __( 'A color value. Always escaped with esc_attr — use it inside a style attribute.', 'karmcp' ),
			),
			'url'      => array(
				'label'       => __( 'Link', 'karmcp' ),
				'json'        => 'string',
				'blank'       => '',
				'control'     => 'url',
				'subprops'    => array(),
				'attr'        => false,
				'description' => __( 'A URL, escaped with esc_url.', 'karmcp' ),
			),
			'image'    => array(
				'label'       => __( 'Image', 'karmcp' ),
				'json'        => 'object',
				'blank'       => array(),
				'control'     => 'image',
				'subprops'    => array( 'url', 'alt', 'id' ),
				'attr'        => false,
				'description' => __( 'An image from the Media Library. {{name.url}} is esc_url, {{name.alt}} is the library alt text, {{name.id}} an integer.', 'karmcp' ),
			),
		);
	}

	/**
	 * Whether an attribute type exists.
	 *
	 * @since 1.12.0
	 *
	 * @param string $type Attribute type.
	 * @return bool
	 */
	public static function has_type( string $type ): bool {
		return isset( self::attribute_types()[ $type ] );
	}

	/**
	 * The payload behind `list-block-control-types`.
	 *
	 * @since 1.12.0
	 *
	 * @return array
	 */
	public static function describe(): array {
		$types = array();
		foreach ( self::attribute_types() as $type => $def ) {
			$types[] = array(
				'type'                   => $type,
				'label'                  => $def['label'],
				'description'            => $def['description'],
				'json_type'              => $def['json'],
				'subprops'               => $def['subprops'],
				'supports_attr_modifier' => $def['attr'],
			);
		}

		return array(
			'spec_version'    => self::SPEC_VERSION,
			'attribute_types' => $types,
			'template_syntax' => array(
				'value'       => '{{name}}',
				'attribute'   => '{{name|attr}}',
				'subproperty' => '{{name.url}}',
				'conditional' => '{{#if name}} … {{/if}}',
				'notes'       => array(
					__( 'The template is HTML. Everything that is not a placeholder is emitted verbatim as text — it can never become PHP.', 'karmcp' ),
					__( 'Escaping is chosen by the attribute type, not by you. There is no raw output modifier.', 'karmcp' ),
					__( 'The block is server-rendered: the editor shows a live preview of the same PHP the front end runs.', 'karmcp' ),
					__( 'Inline <script> tags and inline event handler attributes (onclick=…) are rejected: put behaviour in "scripts".', 'karmcp' ),
				),
			),
			'limits'          => array(
				'attributes' => self::MAX_ATTRIBUTES,
				'options'    => self::MAX_OPTIONS,
				'template'   => KarMCP_Sandbox_Template::MAX_TEMPLATE,
				'asset'      => self::MAX_ASSET,
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

		$meta  = isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();
		$title = isset( $meta['title'] ) ? trim( (string) $meta['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'spec_title', __( 'meta.title is required — it is the block name a person sees in the inserter.', 'karmcp' ) );
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

		$attributes = isset( $spec['attributes'] ) && is_array( $spec['attributes'] ) ? $spec['attributes'] : array();
		if ( count( $attributes ) > self::MAX_ATTRIBUTES ) {
			return new WP_Error(
				'spec_attributes_many',
				sprintf(
					/* translators: %d: attribute limit */
					__( 'A block may declare at most %d attributes.', 'karmcp' ),
					self::MAX_ATTRIBUTES
				)
			);
		}

		$seen = array();
		foreach ( $attributes as $index => $attribute ) {
			if ( ! is_array( $attribute ) ) {
				return new WP_Error(
					'spec_attribute_shape',
					sprintf(
						/* translators: %d: position in the attributes array */
						__( 'Attribute #%d is not an object.', 'karmcp' ),
						(int) $index + 1
					)
				);
			}

			$name = isset( $attribute['name'] ) ? (string) $attribute['name'] : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $name ) ) {
				return new WP_Error(
					'spec_attribute_name',
					sprintf(
						/* translators: %s: the offending attribute name */
						__( 'Attribute name "%s" is invalid: use lowercase letters, digits and underscores, starting with a letter (max 32).', 'karmcp' ),
						$name
					)
				);
			}
			if ( in_array( $name, self::RESERVED, true ) ) {
				return new WP_Error(
					'spec_attribute_reserved',
					sprintf(
						/* translators: %s: the reserved attribute name */
						__( 'Attribute name "%s" is reserved by WordPress.', 'karmcp' ),
						$name
					)
				);
			}
			if ( isset( $seen[ $name ] ) ) {
				return new WP_Error(
					'spec_attribute_duplicate',
					sprintf(
						/* translators: %s: the duplicated attribute name */
						__( 'Attribute "%s" is declared twice.', 'karmcp' ),
						$name
					)
				);
			}
			$seen[ $name ] = true;

			$type = isset( $attribute['type'] ) ? (string) $attribute['type'] : '';
			if ( ! self::has_type( $type ) ) {
				return new WP_Error(
					'spec_attribute_type',
					sprintf(
						/* translators: 1: attribute name, 2: comma-separated list of valid types */
						__( 'Attribute "%1$s" has an unknown type. Supported: %2$s.', 'karmcp' ),
						$name,
						implode( ', ', array_keys( self::attribute_types() ) )
					)
				);
			}

			if ( 'select' === $type ) {
				$options = isset( $attribute['options'] ) && is_array( $attribute['options'] ) ? $attribute['options'] : array();
				if ( empty( $options ) ) {
					return new WP_Error(
						'spec_select_options',
						sprintf(
							/* translators: %s: attribute name */
							__( 'Select attribute "%s" needs a non-empty "options" map of value => label.', 'karmcp' ),
							$name
						)
					);
				}
				if ( count( $options ) > self::MAX_OPTIONS ) {
					return new WP_Error(
						'spec_select_many',
						sprintf(
							/* translators: 1: attribute name, 2: option limit */
							__( 'Select attribute "%1$s" has more than %2$d options.', 'karmcp' ),
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
								/* translators: %s: attribute name */
								__( 'Select attribute "%s" has an option key that is not a simple slug, or a label that is not text.', 'karmcp' ),
								$name
							)
						);
					}
				}
			}

			if ( isset( $attribute['default'] ) && ! is_scalar( $attribute['default'] ) ) {
				return new WP_Error(
					'spec_attribute_default',
					sprintf(
						/* translators: %s: attribute name */
						__( 'Attribute "%s" has a non-scalar default.', 'karmcp' ),
						$name
					)
				);
			}
		}

		$template = isset( $spec['template'] ) ? (string) $spec['template'] : '';
		if ( '' === trim( $template ) ) {
			return new WP_Error( 'spec_template', __( 'template is required — it is what the block renders.', 'karmcp' ) );
		}
		$checked = KarMCP_Sandbox_Template::check_markup( $template );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$declared = array();
		foreach ( $attributes as $attribute ) {
			$declared[ (string) $attribute['name'] ] = (string) $attribute['type'];
		}
		foreach ( KarMCP_Sandbox_Template::referenced_names( $template ) as $referenced ) {
			if ( ! isset( $declared[ $referenced ] ) ) {
				return new WP_Error(
					'spec_template_unknown',
					sprintf(
						/* translators: %s: attribute name used in the template */
						__( 'The template references "%s", which is not one of the declared attributes.', 'karmcp' ),
						$referenced
					)
				);
			}
		}

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
}
