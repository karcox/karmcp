<?php
/**
 * Builds a Contact Form 7 form body, and its mail template, from a field spec.
 *
 * CF7 stores a form as a block of markup with its own tag syntax mixed in. That
 * is pleasant to write by hand and miserable to generate: the tag grammar is
 * positional, quoting is unforgiving, and a malformed tag does not error — it
 * renders as literal text on the page, which is exactly the failure an agent
 * cannot see. So the generation lives here, on its own, with no WordPress
 * underneath it and a test suite over the grammar.
 *
 * Verified against the Contact Form 7 6.x tag syntax:
 *   [type* name option "value" "value"]  — the trailing `*` marks required,
 *   free-text options are quoted, and pipe-separated values (`"Label|value"`)
 *   split display from submitted value in select/checkbox/radio.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field spec in, CF7 markup out.
 *
 * @since 1.1.0
 */
class KarMCP_CF7_Form_Builder {

	/**
	 * Field types this builder emits, mapped to whether they take a list of
	 * options. Anything outside this map is refused rather than passed through:
	 * CF7 renders an unknown tag as plain text, so a typo would ship a form with
	 * the literal string "[emial your-email]" printed on the page.
	 */
	const TYPES = array(
		'text'       => false,
		'email'      => false,
		'tel'        => false,
		'url'        => false,
		'number'     => false,
		'date'       => false,
		'textarea'   => false,
		'select'     => true,
		'checkbox'   => true,
		'radio'      => true,
		'acceptance' => false,
		'file'       => false,
		'hidden'     => false,
	);

	/**
	 * Types whose value belongs in the notification email. `file` is excluded
	 * because CF7 handles uploads through the attachments key instead.
	 */
	const MAILABLE = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'checkbox', 'radio', 'hidden' );

	/**
	 * Builds the form body.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields List of field specs: { name, type, label?, required?,
	 *                      placeholder?, options?, default? }.
	 * @param array $args   {
	 *     Optional.
	 *
	 *     @type string $submit_label    Submit button text. Default 'Send'.
	 *     @type string $required_marker Appended to the label of a required
	 *                                   field. Default ' *'.
	 * }
	 * @return string|WP_Error The form markup, or the first validation failure.
	 */
	public static function build_body( array $fields, array $args = array() ) {
		$normalized = self::normalize_fields( $fields );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$submit_label    = isset( $args['submit_label'] ) ? (string) $args['submit_label'] : 'Send';
		$required_marker = isset( $args['required_marker'] ) ? (string) $args['required_marker'] : ' *';

		$lines = array();

		foreach ( $normalized as $field ) {
			if ( 'hidden' === $field['type'] ) {
				// A hidden field has nothing to label, and wrapping it in a
				// paragraph would leave an empty line in the rendered form.
				$lines[] = self::tag( $field );
				continue;
			}

			if ( 'acceptance' === $field['type'] ) {
				// Consent text belongs inside the tag pair in CF7 5.0+, so the
				// checkbox and the wording it consents to cannot drift apart.
				$lines[] = sprintf(
					'<p>[acceptance %s] %s [/acceptance]</p>',
					$field['name'],
					self::html( $field['label'] )
				);
				continue;
			}

			$label = self::html( $field['label'] );
			if ( $field['required'] && '' !== $required_marker ) {
				$label .= self::html( $required_marker );
			}

			$lines[] = sprintf(
				"<p><label> %s<br />\n    %s </label></p>",
				$label,
				self::tag( $field )
			);
		}

		$lines[] = sprintf( '<p>[submit "%s"]</p>', self::quoted( $submit_label ) );

		return implode( "\n\n", $lines );
	}

	/**
	 * Builds a mail template that actually reports the fields.
	 *
	 * CF7's own default template lists a fixed set of field names, so a form
	 * built with any other names emails a body full of unresolved placeholders.
	 * This walks the real spec instead.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Field specs (raw or normalized).
	 * @param array $args   {
	 *     Optional.
	 *
	 *     @type string $recipient   Where the mail goes.
	 *     @type string $sender      From header.
	 *     @type string $subject     Subject line.
	 *     @type string $site_name   Site name used in the default subject/sender.
	 *     @type string $intro       First line of the body.
	 * }
	 * @return array|WP_Error CF7 mail array, or the first validation failure.
	 */
	public static function build_mail( array $fields, array $args = array() ) {
		$normalized = self::normalize_fields( $fields );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$site_name = isset( $args['site_name'] ) ? (string) $args['site_name'] : '';
		$recipient = isset( $args['recipient'] ) ? (string) $args['recipient'] : '';
		$subject   = isset( $args['subject'] ) ? (string) $args['subject'] : trim( $site_name . ' — new form submission' );
		$intro     = isset( $args['intro'] ) ? (string) $args['intro'] : 'A new submission came in:';

		// CF7 rejects a From header the site does not own, so the default sender
		// is the site itself and the submitter's address goes in Reply-To.
		$sender    = isset( $args['sender'] ) ? (string) $args['sender'] : '';
		$reply_to  = '';
		foreach ( $normalized as $field ) {
			if ( 'email' === $field['type'] && '' === $reply_to ) {
				$reply_to = '[' . $field['name'] . ']';
			}
		}

		$body = array( $intro, '' );
		foreach ( $normalized as $field ) {
			if ( ! in_array( $field['type'], self::MAILABLE, true ) ) {
				continue;
			}
			$body[] = sprintf( '%s: [%s]', $field['label'], $field['name'] );
		}

		$attachments = array();
		foreach ( $normalized as $field ) {
			if ( 'file' === $field['type'] ) {
				$attachments[] = '[' . $field['name'] . ']';
			}
		}

		return array(
			'active'             => true,
			'subject'            => $subject,
			'sender'             => $sender,
			'recipient'          => $recipient,
			'body'               => implode( "\n", $body ) . "\n",
			'additional_headers' => '' !== $reply_to ? 'Reply-To: ' . $reply_to : '',
			'attachments'        => implode( "\n", $attachments ),
			'use_html'           => false,
			'exclude_blank'      => false,
		);
	}

	/**
	 * Validates and fills in a list of field specs.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Raw specs.
	 * @return array|WP_Error Normalized specs, or the first failure.
	 */
	public static function normalize_fields( array $fields ) {
		if ( empty( $fields ) ) {
			return new WP_Error(
				'missing_argument',
				__( 'A form needs at least one field.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}

		$out  = array();
		$seen = array();

		foreach ( array_values( $fields ) as $index => $field ) {
			if ( ! is_array( $field ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: %d: field position. */
						__( 'Field %d is not an object.', 'karmcp' ),
						$index + 1
					),
					array( 'status' => 400 )
				);
			}

			$type = isset( $field['type'] ) ? strtolower( trim( (string) $field['type'] ) ) : 'text';
			if ( ! isset( self::TYPES[ $type ] ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given type, 2: comma-separated list of supported types. */
						__( 'Unsupported field type "%1$s". Supported types: %2$s.', 'karmcp' ),
						$type,
						implode( ', ', array_keys( self::TYPES ) )
					),
					array( 'status' => 400 )
				);
			}

			$label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';
			$name  = self::tag_name( isset( $field['name'] ) && '' !== (string) $field['name'] ? (string) $field['name'] : $label );

			if ( '' === $name ) {
				return new WP_Error(
					'missing_argument',
					sprintf(
						/* translators: %d: field position. */
						__( 'Field %d needs a name or a label to derive one from.', 'karmcp' ),
						$index + 1
					),
					array( 'status' => 400 )
				);
			}

			if ( isset( $seen[ $name ] ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: %s: field name. */
						__( 'Duplicate field name "%s". CF7 keys submitted values by name, so two fields cannot share one.', 'karmcp' ),
						$name
					),
					array( 'status' => 400 )
				);
			}
			$seen[ $name ] = true;

			$options = array();
			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $option ) {
					$option = trim( (string) $option );
					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
			}

			if ( self::TYPES[ $type ] && empty( $options ) ) {
				return new WP_Error(
					'missing_argument',
					sprintf(
						/* translators: 1: field name, 2: field type. */
						__( 'Field "%1$s" is a %2$s and needs an options list.', 'karmcp' ),
						$name,
						$type
					),
					array( 'status' => 400 )
				);
			}

			$out[] = array(
				'name'        => $name,
				'type'        => $type,
				'label'       => '' !== $label ? $label : $name,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => isset( $field['placeholder'] ) ? trim( (string) $field['placeholder'] ) : '',
				'options'     => $options,
				'default'     => isset( $field['default'] ) ? trim( (string) $field['default'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * Renders one normalized field as a CF7 tag.
	 *
	 * @param array $field Normalized field.
	 * @return string
	 */
	private static function tag( array $field ): string {
		$type = $field['type'] . ( $field['required'] && 'hidden' !== $field['type'] ? '*' : '' );
		$parts = array( $type, $field['name'] );

		if ( '' !== $field['placeholder'] && ! self::TYPES[ $field['type'] ] ) {
			$parts[] = 'placeholder';
			$parts[] = '"' . self::quoted( $field['placeholder'] ) . '"';
		}

		if ( '' !== $field['default'] && ! self::TYPES[ $field['type'] ] ) {
			$parts[] = 'default:' . rawurlencode( $field['default'] );
		}

		foreach ( $field['options'] as $option ) {
			$parts[] = '"' . self::quoted( $option ) . '"';
		}

		return '[' . implode( ' ', $parts ) . ']';
	}

	/**
	 * Turns a label or a loose name into a CF7-legal tag name.
	 *
	 * CF7 names allow letters, digits, underscore and hyphen, and a name that
	 * starts with a digit breaks its own parser, so a prefix goes on in that
	 * case rather than silently producing a form that will not save.
	 *
	 * @since 1.1.0
	 *
	 * @param string $raw Raw name or label.
	 * @return string
	 */
	public static function tag_name( string $raw ): string {
		$name = strtolower( trim( $raw ) );
		$name = self::deaccent( $name );
		$name = preg_replace( '/[^a-z0-9_-]+/', '-', $name );
		$name = trim( (string) $name, '-_' );

		if ( '' === $name ) {
			return '';
		}
		if ( ctype_digit( $name[0] ) ) {
			$name = 'field-' . $name;
		}

		return $name;
	}

	/**
	 * Folds accented Latin characters down to ASCII so a Spanish label produces
	 * a usable field name instead of a string of hyphens.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function deaccent( string $text ): string {
		$map = array(
			'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
			'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
			'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
			'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
			'ñ' => 'n', 'ç' => 'c',
		);
		return strtr( $text, $map );
	}

	/**
	 * Makes a value safe to sit inside a double-quoted CF7 option.
	 *
	 * The tag grammar has no escape sequence for a quote, so the only correct
	 * move is to remove it. A stray quote would end the option early and shift
	 * every value after it by one position.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quoted( string $value ): string {
		return trim( str_replace( array( '"', '[', ']' ), '', $value ) );
	}

	/**
	 * Escapes text going into the form's markup.
	 *
	 * Deliberately `htmlspecialchars` and not `esc_html()`: this class is pure so
	 * the grammar can be tested without WordPress, and for this input the two are
	 * the same transformation.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
