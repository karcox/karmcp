<?php
/**
 * JSON Schema compatibility layer for the tool input/output schemas.
 *
 * Different MCP clients accept different JSON Schema dialects, so every ability
 * schema is normalized here before it reaches the Abilities API:
 *   - sanitize(): strips empty enum values and keeps empty `properties` as `{}`
 *     (Gemini / Antigravity reject those).
 *   - strictify(): optional, opt-in OpenAI strict function-calling form — every
 *     property required, optionals nullable, additionalProperties:false (CrewAI
 *     and other OpenAI-compatible stacks require it; it would break Gemini, so
 *     it's gated behind the `karmcp_strict_schemas` option/filter).
 *
 * `register_ability()` is the single entry point all ability classes use to
 * register a tool; the global `karmcp_register_ability()` shim (defined at
 * the bottom of this file) forwards to it so call sites stay terse.
 *
 * @package KarMCP
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema normalization + ability registration.
 *
 * @since 2.1.0
 */
class KarMCP_Schema_Compat {

	const STRICT_OPTION = 'karmcp_strict_schemas';

	/**
	 * Registers an ability with normalized (and optionally strict) schemas.
	 *
	 * @since 2.1.0 (extracted from karmcp_register_ability, since 1.4.3)
	 *
	 * @param string $name The ability name.
	 * @param array  $args The ability arguments.
	 * @return mixed The result of wp_register_ability().
	 */
	public static function register_ability( string $name, array $args ) {
		$strict = self::use_strict_schemas();

		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			$args['input_schema'] = self::sanitize( $args['input_schema'] );
			if ( $strict ) {
				$args['input_schema'] = self::strictify( $args['input_schema'] );
			}
		}
		if ( isset( $args['output_schema'] ) && is_array( $args['output_schema'] ) ) {
			$args['output_schema'] = self::sanitize( $args['output_schema'] );
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$readonly = ! empty( $args['meta']['annotations']['readonly'] );
			$args['execute_callback'] = self::wrap_execute_callback( $args['execute_callback'], $name, $readonly );
		}

		return wp_register_ability( $name, $args );
	}

	/**
	 * Wraps a tool's execute callback so its return value is always a shape the
	 * MCP `structuredContent` field accepts.
	 *
	 * The MCP schema types `structuredContent` as `{ [key: string]: unknown }`,
	 * a JSON object. The adapter passes a tool's return value straight through
	 * to that field, so a tool returning a JSON *list* produces a response that
	 * strict clients reject with a dictionary-validation error.
	 *
	 * This bites any tool that forwards another API's payload verbatim. The
	 * WooCommerce dispatcher returns `WP_REST_Response::get_data()` as-is, and
	 * plenty of `wc/v3` routes answer with a top-level array (product lists,
	 * order lists, `reports/products/totals`, and so on).
	 *
	 * Fixing it here rather than in the vendored adapter means it survives
	 * `composer update`, and it covers every ability rather than the one route
	 * someone happened to hit. Reported against 3.6.0 with `woo-read`,
	 * `report-products-totals`.
	 *
	 * @since 3.6.1
	 *
	 * For non-read-only abilities the wrapper also runs the `karmcp_before_write`
	 * veto filter first: a listener (the Pro Memory Enforcer) can return a WP_Error
	 * to block the write — this is how approved `block`-severity project-memory
	 * guardrails are actually enforced. The default (no listener) allows the write.
	 *
	 * @param callable $callback The ability's execute callback.
	 * @param string   $name     Ability name (e.g. karmcp/delete-media).
	 * @param bool     $readonly Whether the ability is read-only (no write veto).
	 * @return callable
	 */
	protected static function wrap_execute_callback( callable $callback, string $name = '', bool $readonly = false ): callable {
		return static function () use ( $callback, $name, $readonly ) {
			$call_args = func_get_args();
			if ( ! $readonly && function_exists( 'apply_filters' ) ) {
				$input = ( isset( $call_args[0] ) && is_array( $call_args[0] ) ) ? $call_args[0] : array();
				/**
				 * Veto a write before it runs. Return a WP_Error to block it (the
				 * Pro Memory Enforcer hooks this to honor approved `block`-severity
				 * project-memory guardrails). Default null = allow.
				 *
				 * @param null|WP_Error $veto  Null to allow; WP_Error to block.
				 * @param string        $name  Ability name.
				 * @param array         $input The ability input.
				 */
				$veto = apply_filters( 'karmcp_before_write', null, $name, $input );
				if ( is_wp_error( $veto ) ) {
					return $veto;
				}
			}
			try {
				return self::normalize_result( $callback( ...$call_args ) );
			} catch ( \Throwable $karmcp_thrown ) {
				return self::error_from_throwable( $karmcp_thrown, $name );
			}
		};
	}

	/**
	 * Turn a Throwable escaping a tool into a WP_Error that names its origin.
	 *
	 * WordPress core already catches whatever an ability callback throws
	 * (`WP_Ability::invoke_callback()`), but it keeps only `getMessage()` and
	 * wraps it in "Ability %s callback threw an exception: %s". When a third
	 * party throws a bare string, that is all the client ever sees.
	 *
	 * The case that prompted this: saving kit settings goes through Elementor's
	 * `Page\Manager::ajax_before_save_settings()`, which throws `Access denied.`
	 * when the user fails `edit_post` on the kit. The literal exists nowhere in
	 * this plugin, so the report is not just unhelpful, it points away from the
	 * actual code. Catching first — our wrapper runs inside core's try — keeps
	 * the class, the file and the line.
	 *
	 * Catching \Throwable rather than \Exception is deliberate: a TypeError out
	 * of a widget schema is exactly as opaque, and here it becomes a tool error
	 * instead of a 500 with an empty body.
	 *
	 * @since 1.2.1
	 *
	 * @param \Throwable $thrown The caught throwable.
	 * @param string     $name   Ability name (e.g. karmcp/update-global-colors).
	 * @return \WP_Error
	 */
	public static function error_from_throwable( \Throwable $thrown, string $name = '' ) {
		$origin = self::relative_path( $thrown->getFile() ) . ':' . $thrown->getLine();

		$data = array(
			'tool'      => $name,
			'exception' => get_class( $thrown ),
			'origin'    => $origin,
			'code'      => $thrown->getCode(),
		);

		$previous = $thrown->getPrevious();
		if ( $previous instanceof \Throwable ) {
			$data['previous'] = sprintf(
				'%s: %s @ %s:%d',
				get_class( $previous ),
				$previous->getMessage(),
				self::relative_path( $previous->getFile() ),
				$previous->getLine()
			);
		}

		return new \WP_Error(
			'unhandled_exception',
			sprintf(
				/* translators: 1: exception class, 2: ability name, 3: file:line, 4: exception message */
				__( '%1$s escaped %2$s at %3$s: %4$s', 'karmcp' ),
				get_class( $thrown ),
				'' !== $name ? $name : __( 'the tool', 'karmcp' ),
				$origin,
				$thrown->getMessage()
			),
			$data
		);
	}

	/**
	 * A file path relative to the WordPress root.
	 *
	 * The absolute path is noise to an MCP client, and it discloses the server's
	 * directory layout to it. Relative is also what `read-file` accepts, so the
	 * origin of an error can be opened without editing the path by hand.
	 *
	 * @since 1.2.1
	 *
	 * @param string $file Absolute file path.
	 * @return string
	 */
	private static function relative_path( string $file ): string {
		if ( function_exists( 'wp_normalize_path' ) && defined( 'ABSPATH' ) ) {
			$file = wp_normalize_path( $file );
			$root = wp_normalize_path( ABSPATH );
			if ( '' !== $root && 0 === strpos( $file, $root ) ) {
				return substr( $file, strlen( $root ) );
			}
		}
		return basename( $file );
	}

	/**
	 * Coerces a tool result into a JSON object, leaving anything already
	 * object-shaped untouched.
	 *
	 * Associative arrays and objects pass through unchanged, which is what the
	 * overwhelming majority of abilities return. Lists, scalars and null are
	 * wrapped in a `data` key. `WP_Error` is returned untouched so the adapter's
	 * error handling still sees it.
	 *
	 * Note that PHP cannot distinguish an empty list from an empty map, so
	 * `array()` is wrapped too. That is deliberate: unwrapped it serialises to
	 * `[]`, which is exactly the invalid shape this guards against.
	 *
	 * @since 3.6.1
	 *
	 * @param mixed $result The raw ability result.
	 * @return mixed
	 */
	public static function normalize_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Already a JSON object: a string-keyed array, or any object that is not
		// an error. Leave the shape the ability intended.
		if ( is_array( $result ) && ! array_is_list( $result ) ) {
			return $result;
		}
		if ( is_object( $result ) ) {
			return $result;
		}

		return array( 'data' => $result );
	}

	/**
	 * Whether to emit OpenAI-strict-compatible tool schemas. OFF by default;
	 * opt-in via the Connection-tab toggle or the `karmcp_strict_schemas`
	 * filter. (GitHub #42)
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function use_strict_schemas(): bool {
		$enabled = '1' === (string) get_option( self::STRICT_OPTION, '0' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- STRICT_OPTION is the literal 'karmcp_strict_schemas'; the sniff cannot resolve the constant.
		return (bool) apply_filters( self::STRICT_OPTION, $enabled );
	}

	/**
	 * Recursively removes empty strings from enum arrays and keeps empty
	 * `properties` as a JSON object. (Gemini / Antigravity compatibility, #21)
	 *
	 * @since 2.1.0 (extracted from karmcp_sanitize_schema, since 1.4.3)
	 *
	 * @param array $schema A JSON Schema array.
	 * @return array
	 */
	public static function sanitize( array $schema ): array {
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			$schema['enum'] = array_values(
				array_filter(
					$schema['enum'],
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);
			if ( empty( $schema['enum'] ) ) {
				unset( $schema['enum'] );
			}
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			if ( empty( $schema['properties'] ) ) {
				$schema['properties'] = new \stdClass();
			} else {
				foreach ( $schema['properties'] as $key => $prop ) {
					if ( is_array( $prop ) ) {
						$schema['properties'][ $key ] = self::sanitize( $prop );
					}
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::sanitize( $schema['items'] );
		}

		foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $keyword ) {
			if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) ) {
				foreach ( $schema[ $keyword ] as $i => $sub ) {
					if ( is_array( $sub ) ) {
						$schema[ $keyword ][ $i ] = self::sanitize( $sub );
					}
				}
			}
		}

		return $schema;
	}

	/**
	 * Rewrites a JSON Schema to satisfy OpenAI strict function-calling: every
	 * property required, originally-optional properties nullable, and objects
	 * with declared properties get additionalProperties:false. Free-form objects
	 * (no declared properties) are left untouched. (GitHub #42)
	 *
	 * @since 2.1.0 (extracted from karmcp_strictify_schema)
	 *
	 * @param array $schema A JSON Schema array.
	 * @return array
	 */
	public static function strictify( array $schema ): array {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && ! empty( $schema['properties'] ) ) {
			$required = ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) ? $schema['required'] : array();
			$keys     = array();

			foreach ( $schema['properties'] as $key => $prop ) {
				$keys[] = $key;
				if ( ! is_array( $prop ) ) {
					continue;
				}
				if ( ! in_array( $key, $required, true ) ) {
					$prop = self::make_nullable( $prop );
				}
				$schema['properties'][ $key ] = self::strictify( $prop );
			}

			$schema['required']             = array_values( $keys );
			$schema['additionalProperties'] = false;
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::strictify( $schema['items'] );
		}

		foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $keyword ) {
			if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) ) {
				foreach ( $schema[ $keyword ] as $i => $sub ) {
					if ( is_array( $sub ) ) {
						$schema[ $keyword ][ $i ] = self::strictify( $sub );
					}
				}
			}
		}

		return $schema;
	}

	/**
	 * Makes a single property schema nullable (so strict mode can list it in
	 * `required` while still allowing it to be omitted as null).
	 *
	 * @since 2.1.0 (extracted from karmcp_make_schema_nullable)
	 *
	 * @param array $prop A property schema.
	 * @return array
	 */
	public static function make_nullable( array $prop ): array {
		if ( isset( $prop['type'] ) ) {
			if ( is_string( $prop['type'] ) && 'null' !== $prop['type'] ) {
				$prop['type'] = array( $prop['type'], 'null' );
			} elseif ( is_array( $prop['type'] ) && ! in_array( 'null', $prop['type'], true ) ) {
				$prop['type'][] = 'null';
			}
		}
		if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) && ! in_array( null, $prop['enum'], true ) ) {
			$prop['enum'][] = null;
		}
		return $prop;
	}
}

if ( ! function_exists( 'karmcp_register_ability' ) ) {
	/**
	 * Back-compat global shim: the public ability-registration entry point used
	 * by every ability class. Forwards to KarMCP_Schema_Compat::register_ability().
	 *
	 * @since 1.4.3
	 *
	 * @param string $name The ability name.
	 * @param array  $args The ability arguments.
	 * @return mixed
	 */
	function karmcp_register_ability( string $name, array $args ) {
		return KarMCP_Schema_Compat::register_ability( $name, $args );
	}
}
