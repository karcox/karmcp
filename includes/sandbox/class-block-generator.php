<?php
/**
 * Block generator — compiles a validated spec into a Gutenberg block.
 *
 * Produces the two files the block store writes to the sandbox: a `block.json`
 * carrying the metadata and attribute schema, and a `render.php` that echoes
 * the template with every value escaped by its declared type. Same discipline
 * as the widget generator: spec data becomes PHP literals, never PHP code, and
 * escaping is decided here rather than by whoever wrote the spec.
 *
 * The block is server-rendered on purpose. A save-side JavaScript
 * implementation would mean compiling and shipping a script per block, and
 * would put markup in post content that no longer matches the spec the moment
 * it changes. Rendering on the server keeps one source of truth and lets the
 * editor preview the same PHP the visitor gets, through ServerSideRender.
 *
 * Pure: arrays in, strings out. No database, no filesystem, no WordPress.
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles block specs into block.json + render.php.
 *
 * @since 1.12.0
 */
class KarMCP_Block_Generator {

	/** Inserter category the generated blocks are filed under. */
	const CATEGORY = 'karmcp-custom';

	/** Fallback inserter icon when the spec does not name a valid one. */
	const DEFAULT_ICON = 'block-default';

	/** The variable the render file reads its attributes from. */
	const ATTR_VAR = '$karmcp_attributes';

	/**
	 * Compiles a spec into its sandbox files.
	 *
	 * @since 1.12.0
	 *
	 * @param array  $spec       The block spec (validated here, again).
	 * @param string $block_name Fully-qualified block name, e.g. karmcp/custom-12.
	 * @return array|WP_Error { 'block.json': string, 'render.php': string }
	 */
	public static function generate( array $spec, string $block_name ) {
		$valid = KarMCP_Block_Spec::validate( $spec );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $block_name ) ) {
			return new WP_Error( 'generator_block_name', __( 'The generated block name is not a valid block type name (namespace/slug).', 'karmcp' ) );
		}

		$attributes = self::index_attributes( $spec );
		$types      = array();
		foreach ( $attributes as $name => $attribute ) {
			$types[ $name ] = $attribute['type'];
		}

		$body = KarMCP_Sandbox_Template::compile(
			(string) $spec['template'],
			$types,
			array( __CLASS__, 'emit' ),
			array( __CLASS__, 'truthy' ),
			"\t"
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$json = self::block_json( $spec, $block_name, $attributes );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		return array(
			'block.json' => $json,
			'render.php' => self::render_file( $body ),
		);
	}

	// -------------------------------------------------------------------------
	// Expression emitters — where escaping is decided
	// -------------------------------------------------------------------------

	/**
	 * Returns the PHP expression behind one placeholder.
	 *
	 * @since 1.12.0
	 *
	 * @param string $name     Attribute name.
	 * @param string $type     Attribute type.
	 * @param string $subprop  Requested sub-property, or ''.
	 * @param string $modifier Requested modifier, or ''.
	 * @return string|WP_Error
	 */
	public static function emit( string $name, string $type, string $subprop, string $modifier ) {
		$definition = KarMCP_Block_Spec::attribute_types()[ $type ] ?? null;
		if ( null === $definition ) {
			return new WP_Error( 'generator_unknown_type', __( 'Unknown attribute type in template.', 'karmcp' ) );
		}
		if ( '' !== $subprop && ! in_array( $subprop, $definition['subprops'], true ) ) {
			return new WP_Error(
				'generator_bad_subprop',
				sprintf(
					/* translators: 1: sub-property, 2: attribute name, 3: attribute type */
					__( '"%1$s" is not available on "%2$s" (a %3$s attribute).', 'karmcp' ),
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
					/* translators: 1: attribute name, 2: attribute type */
					__( '"%1$s" is a %2$s attribute, which is already escaped for its context — the |attr modifier does not apply.', 'karmcp' ),
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
			case 'toggle':
				return ( $attr ? 'esc_attr' : 'esc_html' ) . '( (string) ' . $raw . ' )';

			case 'textarea':
				return $attr
					? 'esc_attr( (string) ' . $raw . ' )'
					: 'nl2br( esc_html( (string) ' . $raw . ' ) )';

			case 'richtext':
				return 'wp_kses_post( (string) ' . $raw . ' )';

			case 'number':
				return ( $attr ? 'esc_attr' : 'esc_html' ) . '( (string) ( 0 + (float) ' . $raw . ' ) )';

			case 'color':
				return 'esc_attr( (string) ' . $raw . ' )';

			case 'url':
				return 'esc_url( (string) ' . $raw . ' )';

			case 'image':
				if ( 'alt' === $subprop ) {
					return 'esc_attr( (string) get_post_meta( (int) ' . self::accessor( $name, 'id', '0' ) . ', \'_wp_attachment_image_alt\', true ) )';
				}
				if ( 'id' === $subprop ) {
					return '(int) ' . self::accessor( $name, 'id', '0' );
				}
				return 'esc_url( (string) ' . self::accessor( $name, 'url' ) . ' )';
		}

		return new WP_Error( 'generator_unhandled_type', __( 'This attribute type cannot be rendered directly.', 'karmcp' ) );
	}

	/**
	 * Returns the PHP expression tested by `{{#if name}}`.
	 *
	 * @since 1.12.0
	 *
	 * @param string $name Attribute name.
	 * @param string $type Attribute type.
	 * @return string
	 */
	public static function truthy( string $name, string $type ): string {
		switch ( $type ) {
			case 'toggle':
				return '! empty( ' . self::accessor( $name, '', 'false' ) . ' )';
			case 'image':
				return '! empty( ' . self::accessor( $name, 'url' ) . ' )';
			case 'number':
				return '0 + (float) ' . self::accessor( $name, '', '0' ) . ' !== 0.0';
			default:
				return "'' !== trim( (string) " . self::accessor( $name ) . ' )';
		}
	}

	/**
	 * The `$karmcp_attributes` accessor for an attribute.
	 *
	 * @param string $name     Attribute name (already validated).
	 * @param string $key      Optional sub-key of an object-valued attribute.
	 * @param string $fallback PHP literal used when the value is absent.
	 * @return string
	 */
	private static function accessor( string $name, string $key = '', string $fallback = "''" ): string {
		$path = self::ATTR_VAR . '[' . var_export( $name, true ) . ']'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal.
		if ( '' !== $key ) {
			$path .= '[' . var_export( $key, true ) . ']'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal.
		}
		return '( ' . $path . ' ?? ' . $fallback . ' )';
	}

	// -------------------------------------------------------------------------
	// File emission
	// -------------------------------------------------------------------------

	/**
	 * Builds block.json.
	 *
	 * @param array  $spec       Validated spec.
	 * @param string $block_name Block type name.
	 * @param array  $attributes Indexed attributes.
	 * @return string|WP_Error
	 */
	private static function block_json( array $spec, string $block_name, array $attributes ) {
		$meta = isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();

		$icon = isset( $meta['icon'] ) ? (string) $meta['icon'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $icon ) ) {
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

		$schema = self::attribute_schema( $attributes );

		$json = array(
			'$schema'     => 'https://schemas.wp.org/trunk/block.json',
			'apiVersion'  => 3,
			'name'        => $block_name,
			'title'       => trim( (string) ( $meta['title'] ?? $block_name ) ),
			'category'    => self::CATEGORY,
			'icon'        => $icon,
			'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
			'keywords'    => $keywords,
			'textdomain'  => 'karmcp',
			'supports'    => array(
				'html'   => false,
				'anchor' => true,
				'align'  => array( 'wide', 'full' ),
			),
			'attributes'  => (object) $schema,
		);

		$encoded = wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'generator_json', __( 'The block metadata could not be encoded.', 'karmcp' ) );
		}

		return $encoded . "\n";
	}

	/**
	 * Builds render.php.
	 *
	 * The file echoes rather than returns: it is included inside an output
	 * buffer, which is also how WordPress treats a block's own render file, so
	 * a returned string would silently render nothing.
	 *
	 * @param string $body Compiled template statements.
	 * @return string
	 */
	private static function render_file( string $body ): string {
		return "<?php\n"
			. "/**\n"
			. " * GENERATED FILE — do not edit.\n"
			. " *\n"
			. " * Compiled by KarMCP from a block spec. The spec is the source of truth;\n"
			. " * the next update to the block overwrites everything here.\n"
			. " *\n"
			. " * Echoes its output — it is included inside an output buffer, so a return\n"
			. " * value would be discarded.\n"
			. " *\n"
			. " * @package KarMCP\n"
			. " */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n"
			. '// $attributes is provided by the loader; $karmcp_wrapper carries the class' . "\n"
			. "// and style attributes WordPress generates from the block's supports.\n"
			. self::ATTR_VAR . " = ( isset( \$attributes ) && is_array( \$attributes ) ) ? \$attributes : array();\n"
			. "\$karmcp_wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';\n\n"
			. "echo '<div' . ( '' !== \$karmcp_wrapper ? ' ' . \$karmcp_wrapper : '' ) . '>';\n\n"
			. $body
			. "\necho '</div>';\n";
	}

	// -------------------------------------------------------------------------
	// Editor payload
	// -------------------------------------------------------------------------

	/**
	 * The descriptor the shared editor script turns into an inspector panel.
	 *
	 * Kept here, beside the type table it mirrors, so the controls a person
	 * sees and the escaping the render applies can never describe different
	 * sets of attributes.
	 *
	 * @since 1.12.0
	 *
	 * @param array  $spec       Validated spec.
	 * @param string $block_name Block type name.
	 * @return array
	 */
	public static function editor_payload( array $spec, string $block_name ): array {
		$controls   = array();
		$attributes = self::index_attributes( $spec );

		foreach ( $attributes as $name => $attribute ) {
			$definition = KarMCP_Block_Spec::attribute_types()[ $attribute['type'] ] ?? null;
			if ( null === $definition ) {
				continue;
			}

			$control = array(
				'name'    => $name,
				'control' => $definition['control'],
				'label'   => isset( $attribute['label'] ) && '' !== trim( (string) $attribute['label'] )
					? trim( (string) $attribute['label'] )
					: $name,
			);

			if ( 'select' === $attribute['type'] ) {
				$options = array();
				foreach ( (array) $attribute['options'] as $value => $label ) {
					$options[] = array(
						'value' => (string) $value,
						'label' => (string) $label,
					);
				}
				$control['options'] = $options;
			}

			$controls[] = $control;
		}

		$meta = isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();
		$icon = isset( $meta['icon'] ) ? (string) $meta['icon'] : '';

		return array(
			'name'       => $block_name,
			'title'      => trim( (string) ( $meta['title'] ?? $block_name ) ),
			'icon'       => preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $icon ) ? $icon : self::DEFAULT_ICON,
			'category'   => self::CATEGORY,
			// Repeated from block.json on purpose: the editor registration does
			// not have to wait on, or agree with, whatever the REST metadata
			// endpoint returns for a block living outside a plugin.
			'attributes' => (object) self::attribute_schema( $attributes ),
			'controls'   => $controls,
		);
	}

	/**
	 * The block.json `attributes` object: JSON type and default per attribute.
	 *
	 * Shared by the metadata file and the editor payload, so the schema the
	 * editor writes into a post and the schema the server validates it against
	 * are the same object by construction.
	 *
	 * @param array $attributes Indexed attributes.
	 * @return array<string, array>
	 */
	private static function attribute_schema( array $attributes ): array {
		$schema = array();

		foreach ( $attributes as $name => $attribute ) {
			$definition = KarMCP_Block_Spec::attribute_types()[ $attribute['type'] ] ?? null;
			if ( null === $definition ) {
				continue;
			}

			$default = $definition['blank'];
			if ( isset( $attribute['default'] ) && is_scalar( $attribute['default'] ) && 'object' !== $definition['json'] ) {
				switch ( $definition['json'] ) {
					case 'number':
						$default = 0 + (float) $attribute['default'];
						break;
					case 'boolean':
						$default = (bool) $attribute['default'];
						break;
					default:
						$default = (string) $attribute['default'];
				}
			}

			$schema[ $name ] = array(
				'type'    => $definition['json'],
				'default' => $default,
			);
		}

		return $schema;
	}

	/**
	 * Indexes the spec's attribute list by name.
	 *
	 * @param array $spec Validated spec.
	 * @return array<string, array>
	 */
	private static function index_attributes( array $spec ): array {
		$out = array();
		foreach ( (array) ( $spec['attributes'] ?? array() ) as $attribute ) {
			$out[ (string) $attribute['name'] ] = $attribute;
		}
		return $out;
	}
}
