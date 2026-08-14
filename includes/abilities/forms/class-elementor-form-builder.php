<?php
/**
 * Builds the settings payload for an Elementor Pro Form widget.
 *
 * The Form widget's settings are a repeater of field rows plus a flat set of
 * submit-action keys, and writing them by hand is where forms quietly break.
 * Two traps in particular, both of which produce a form that looks perfect in
 * the editor:
 *
 * 1. **`custom_id` is the submitted field name.** Elementor's editor fills it in
 *    from JavaScript, so a form built through the API without it submits fields
 *    with no usable name, and `[field id="…"]` in the notification resolves to
 *    nothing. The email arrives empty.
 * 2. **`required` is the string `"yes"`, not a boolean.** A `true` is not
 *    falsy-checked anywhere; the field simply stops being required, and nobody
 *    notices until the leads arrive with no phone number.
 *
 * Control names here are the ones carried in `includes/widgets/catalog-pro.php`,
 * which is this repo's verified list for the widget.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field spec in, Form widget settings out.
 *
 * @since 1.1.0
 */
class KarMCP_Elementor_Form_Builder {

	/**
	 * Field types the Form widget accepts.
	 */
	const FIELD_TYPES = array( 'text', 'email', 'textarea', 'url', 'tel', 'select', 'radio', 'checkbox', 'number', 'date', 'time', 'upload', 'acceptance', 'password', 'hidden' );

	/**
	 * Types whose choices come from the newline-separated `field_options`.
	 */
	const OPTION_TYPES = array( 'select', 'radio', 'checkbox' );

	/**
	 * Column widths the widget's own control offers. Anything else is dropped
	 * rather than written: an unknown width produces no CSS class at all, so the
	 * field silently falls back to full width.
	 */
	const WIDTHS = array( '100', '80', '75', '66', '50', '33', '25', '20' );

	/**
	 * Builds the widget settings.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Field specs: { name?, type, label?, required?,
	 *                      placeholder?, options?, width?, default? }.
	 * @param array $args   {
	 *     Optional.
	 *
	 *     @type string $form_name    Internal form name. Default 'Form'.
	 *     @type string $submit_label Button text. Default 'Send'.
	 *     @type string $recipient    Notification recipient.
	 *     @type string $subject      Notification subject.
	 *     @type string $from_name    Notification From name.
	 *     @type string $redirect_to  URL to send the visitor to after submitting.
	 *     @type string $success_message Confirmation shown in place of the form.
	 *     @type callable $id_generator Returns a repeater row id. Injected so the
	 *                                  output is reproducible under test.
	 * }
	 * @return array|WP_Error Settings for the `form` widget, or the first failure.
	 */
	public static function build_settings( array $fields, array $args = array() ) {
		$normalized = self::normalize_fields( $fields );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$generator = ( isset( $args['id_generator'] ) && is_callable( $args['id_generator'] ) )
			? $args['id_generator']
			: static function (): string {
				return class_exists( 'KarMCP_Id_Generator' )
					? KarMCP_Id_Generator::generate()
					: substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
			};

		$rows     = array();
		$reply_to = '';

		foreach ( $normalized as $field ) {
			$row = array(
				'_id'         => (string) call_user_func( $generator ),
				'custom_id'   => $field['name'],
				'field_type'  => $field['type'],
				'field_label' => $field['label'],
				'required'    => $field['required'] ? 'yes' : '',
				'width'       => $field['width'],
			);

			if ( '' !== $field['placeholder'] ) {
				$row['placeholder'] = $field['placeholder'];
			}
			if ( in_array( $field['type'], self::OPTION_TYPES, true ) ) {
				// One choice per line: that is how the control stores them.
				$row['field_options'] = implode( "\n", $field['options'] );
			}
			if ( 'acceptance' === $field['type'] ) {
				$row['acceptance_text'] = $field['label'];
			}
			if ( 'hidden' === $field['type'] && '' !== $field['default'] ) {
				$row['field_value'] = $field['default'];
			}

			$rows[] = $row;

			if ( 'email' === $field['type'] && '' === $reply_to ) {
				$reply_to = sprintf( '[field id="%s"]', $field['name'] );
			}
		}

		$settings = array(
			'form_name'      => isset( $args['form_name'] ) ? (string) $args['form_name'] : 'Form',
			'form_fields'    => $rows,
			'button_text'    => isset( $args['submit_label'] ) ? (string) $args['submit_label'] : 'Send',
			'submit_actions' => array( 'email' ),
		);

		if ( ! empty( $args['recipient'] ) ) {
			$settings['email_to'] = (string) $args['recipient'];
		}
		if ( ! empty( $args['subject'] ) ) {
			$settings['email_subject'] = (string) $args['subject'];
		}
		if ( ! empty( $args['from_name'] ) ) {
			$settings['email_from_name'] = (string) $args['from_name'];
		}
		if ( '' !== $reply_to ) {
			$settings['email_reply_to'] = $reply_to;
		}
		if ( ! empty( $args['success_message'] ) ) {
			$settings['success_message'] = (string) $args['success_message'];
		}

		// Redirect is a second action, not a replacement: dropping "email" here
		// would send the visitor onward and lose the submission entirely.
		if ( ! empty( $args['redirect_to'] ) ) {
			$settings['submit_actions'][] = 'redirect';
			$settings['redirect_to']      = (string) $args['redirect_to'];
		}

		return $settings;
	}

	/**
	 * Validates and fills in the field specs.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Raw specs.
	 * @return array|WP_Error
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
			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: 1: given type, 2: comma-separated list of supported types. */
						__( 'Unsupported field type "%1$s". Supported types: %2$s.', 'karmcp' ),
						$type,
						implode( ', ', self::FIELD_TYPES )
					),
					array( 'status' => 400 )
				);
			}

			$label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';
			$name  = self::field_id( isset( $field['name'] ) && '' !== (string) $field['name'] ? (string) $field['name'] : $label );

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
						__( 'Duplicate field name "%s". Elementor keys submitted values by it, so two fields cannot share one.', 'karmcp' ),
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

			if ( in_array( $type, self::OPTION_TYPES, true ) && empty( $options ) ) {
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

			$width = isset( $field['width'] ) ? (string) $field['width'] : '100';
			if ( ! in_array( $width, self::WIDTHS, true ) ) {
				$width = '100';
			}

			$out[] = array(
				'name'        => $name,
				'type'        => $type,
				'label'       => '' !== $label ? $label : $name,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => isset( $field['placeholder'] ) ? trim( (string) $field['placeholder'] ) : '',
				'options'     => $options,
				'width'       => $width,
				'default'     => isset( $field['default'] ) ? trim( (string) $field['default'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * Turns a label or a loose name into an Elementor field id.
	 *
	 * The id ends up in the submitted payload and in `[field id="…"]` shortcodes,
	 * so it stays lowercase ASCII with no spaces.
	 *
	 * @since 1.1.0
	 *
	 * @param string $raw Raw name or label.
	 * @return string
	 */
	public static function field_id( string $raw ): string {
		$name = strtolower( trim( $raw ) );
		$name = strtr(
			$name,
			array(
				'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
				'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
				'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
				'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
				'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
				'ñ' => 'n', 'ç' => 'c',
			)
		);
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name );

		return trim( (string) $name, '_' );
	}
}
