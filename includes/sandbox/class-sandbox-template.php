<?php
/**
 * Sandbox template compiler — turns an AI-supplied `{{placeholder}}` template
 * into PHP source that echoes it.
 *
 * This is the seam that makes the whole sandbox builder defensible: the agent
 * supplies HTML with named placeholders, NEVER PHP. Everything that is not a
 * placeholder is emitted as a PHP string literal (via var_export), so template
 * text can never become executable code; every placeholder is replaced with an
 * expression the caller chose, which is where escaping is decided.
 *
 * Escaping is deliberately NOT decided here. The compiler asks its caller (a
 * platform generator — Elementor widget, Gutenberg block) for the PHP
 * expression behind each placeholder, so each platform escapes with the
 * function that fits its own value shape while the parsing, the literal
 * emission, and the control-flow rules stay in one tested place.
 *
 * Supported syntax:
 *
 *   {{name}}            value of control `name`, escaped for text context
 *   {{name|attr}}       same value, escaped for an HTML attribute
 *   {{name.url}}        a sub-property (url/alt/id/target/rel), escaped for it
 *   {{#if name}}…{{/if}} renders the block only when the value is non-empty
 *
 * @package KarMCP
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles a placeholder template into PHP `echo` statements.
 *
 * @since 1.12.0
 */
class KarMCP_Sandbox_Template {

	/** Hard ceiling on nested {{#if}} blocks, so a template cannot be a bomb. */
	const MAX_DEPTH = 8;

	/** Ceiling on template size, shared by every artifact kind. */
	const MAX_TEMPLATE = 20000;

	/** Modifiers a placeholder may carry. `raw` is deliberately absent. */
	const MODIFIERS = array( 'attr' );

	/**
	 * Sub-properties a placeholder may address, e.g. `{{image.url}}`. Which of
	 * them a given control type actually supports is the emitter's call.
	 *
	 * @var string[]
	 */
	const SUBPROPS = array( 'url', 'alt', 'id', 'target', 'rel' );

	/**
	 * Compiles a template to PHP source.
	 *
	 * The returned string is a sequence of statements (no opening tag), safe to
	 * splice into a generated render method.
	 *
	 * @since 1.12.0
	 *
	 * @param string   $template The raw template.
	 * @param string[] $controls Map of control name => control type. A
	 *                           placeholder naming anything else is an error.
	 * @param callable $emit     fn( string $name, string $type, string $subprop,
	 *                           string $modifier ): string|WP_Error — returns the
	 *                           PHP expression to echo (already escaped).
	 * @param callable $truthy   fn( string $name, string $type ): string — returns
	 *                           the PHP expression tested by `{{#if}}`.
	 * @param string   $indent   Indentation prefixed to every emitted line.
	 * @return string|WP_Error PHP source, or the first error found.
	 */
	public static function compile( string $template, array $controls, callable $emit, callable $truthy, string $indent = "\t\t" ) {
		$parts = preg_split( '/(\{\{[^{}]*\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'template_unparsable', __( 'The template could not be parsed.', 'karmcp' ) );
		}

		$out   = '';
		$depth = 0;
		$pad   = static function ( int $d ) use ( $indent ): string {
			return $indent . str_repeat( "\t", $d );
		};

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			// Literal text: emitted as a PHP string literal, never as code.
			if ( ! preg_match( '/^\{\{(.*)\}\}$/s', $part, $m ) ) {
				$out .= $pad( $depth ) . 'echo ' . var_export( $part, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- compile-time literal emission, not runtime output.
				continue;
			}

			$tag = trim( $m[1] );

			// {{/if}}
			if ( '/if' === $tag ) {
				if ( $depth < 1 ) {
					return new WP_Error( 'template_unbalanced', __( 'Template has a {{/if}} with no matching {{#if}}.', 'karmcp' ) );
				}
				--$depth;
				$out .= $pad( $depth ) . "}\n";
				continue;
			}

			// {{#if name}}
			if ( preg_match( '/^#if\s+([a-z][a-z0-9_]*)$/', $tag, $cond ) ) {
				$name = $cond[1];
				if ( ! isset( $controls[ $name ] ) ) {
					return self::unknown( $name );
				}
				if ( $depth >= self::MAX_DEPTH ) {
					return new WP_Error( 'template_too_deep', __( 'Template nests {{#if}} blocks too deeply.', 'karmcp' ) );
				}
				$out .= $pad( $depth ) . 'if ( ' . $truthy( $name, $controls[ $name ] ) . " ) {\n";
				++$depth;
				continue;
			}

			// {{name}} | {{name.sub}} | {{name|mod}} | {{name.sub|mod}}
			if ( ! preg_match( '/^([a-z][a-z0-9_]*)(?:\.([a-z]+))?(?:\|([a-z]+))?$/', $tag, $ref ) ) {
				return new WP_Error(
					'template_bad_placeholder',
					sprintf(
						/* translators: %s: the offending placeholder */
						__( 'Template placeholder "%s" is not valid syntax.', 'karmcp' ),
						$tag
					)
				);
			}

			$name     = $ref[1];
			$subprop  = $ref[2] ?? '';
			$modifier = $ref[3] ?? '';

			if ( ! isset( $controls[ $name ] ) ) {
				return self::unknown( $name );
			}
			if ( '' !== $subprop && ! in_array( $subprop, self::SUBPROPS, true ) ) {
				return new WP_Error(
					'template_bad_subprop',
					sprintf(
						/* translators: 1: sub-property, 2: control name */
						__( '"%1$s" is not a supported sub-property (on "%2$s").', 'karmcp' ),
						$subprop,
						$name
					)
				);
			}
			if ( '' !== $modifier && ! in_array( $modifier, self::MODIFIERS, true ) ) {
				return new WP_Error(
					'template_bad_modifier',
					sprintf(
						/* translators: %s: modifier name */
						__( '"%s" is not a supported placeholder modifier.', 'karmcp' ),
						$modifier
					)
				);
			}

			$expr = $emit( $name, $controls[ $name ], $subprop, $modifier );
			if ( is_wp_error( $expr ) ) {
				return $expr;
			}

			$out .= $pad( $depth ) . 'echo ' . $expr . ";\n";
		}

		if ( 0 !== $depth ) {
			return new WP_Error( 'template_unbalanced', __( 'Template has an unclosed {{#if}} block.', 'karmcp' ) );
		}

		return $out;
	}

	/**
	 * Collects the control names a template references, without compiling it.
	 * Used by the validators to report every unknown name at once.
	 *
	 * @since 1.12.0
	 *
	 * @param string $template The raw template.
	 * @return string[] Unique control names, in order of first appearance.
	 */
	public static function referenced_names( string $template ): array {
		$names = array();
		if ( preg_match_all( '/\{\{\s*(?:#if\s+)?([a-z][a-z0-9_]*)/', $template, $m ) ) {
			foreach ( $m[1] as $name ) {
				if ( ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/**
	 * The markup rules every sandbox template obeys, whatever it compiles to.
	 *
	 * These are not paranoia about the agent: they keep the compiled artifact
	 * honest. PHP tags would mean the template stopped being data; a `<script>`
	 * or an inline `on…=` handler would smuggle behaviour past the escaping the
	 * control types promise, and both have a declared home in `scripts`.
	 *
	 * @since 1.12.0
	 *
	 * @param string $markup The template source.
	 * @param string $field  Field name, for the error message.
	 * @return true|WP_Error
	 */
	public static function check_markup( string $markup, string $field = 'template' ) {
		if ( strlen( $markup ) > self::MAX_TEMPLATE ) {
			return new WP_Error(
				'template_long',
				sprintf(
					/* translators: 1: field name, 2: byte limit */
					__( '"%1$s" is larger than %2$d bytes.', 'karmcp' ),
					$field,
					self::MAX_TEMPLATE
				)
			);
		}
		if ( preg_match( '/<\?(php|=)?/i', $markup ) ) {
			return new WP_Error(
				'template_php',
				sprintf(
					/* translators: %s: field name */
					__( '"%s" contains a PHP open tag. Templates are data, not code — the plugin compiles the PHP.', 'karmcp' ),
					$field
				)
			);
		}
		if ( preg_match( '/<\s*script\b/i', $markup ) ) {
			return new WP_Error(
				'template_script',
				sprintf(
					/* translators: %s: field name */
					__( '"%s" contains a <script> tag. Put behaviour in "scripts", which is served as a separate file.', 'karmcp' ),
					$field
				)
			);
		}
		if ( preg_match( '/\son[a-z]+\s*=/i', $markup ) ) {
			return new WP_Error(
				'template_event_handler',
				sprintf(
					/* translators: %s: field name */
					__( '"%s" contains an inline event handler attribute. Put behaviour in "scripts" and bind it there.', 'karmcp' ),
					$field
				)
			);
		}
		if ( preg_match( '/javascript\s*:/i', $markup ) ) {
			return new WP_Error(
				'template_js_url',
				sprintf(
					/* translators: %s: field name */
					__( '"%s" contains a javascript: URL.', 'karmcp' ),
					$field
				)
			);
		}
		return true;
	}

	/**
	 * The "unknown control" error, shared by both call sites.
	 *
	 * @param string $name Control name.
	 * @return WP_Error
	 */
	private static function unknown( string $name ): WP_Error {
		return new WP_Error(
			'template_unknown_control',
			sprintf(
				/* translators: %s: control name used in the template */
				__( 'Template references "%s", which is not one of the declared controls.', 'karmcp' ),
				$name
			)
		);
	}
}
