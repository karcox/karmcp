<?php
/**
 * Contact Form 7 integration (free) — two dispatcher tools (cf7-read /
 * cf7-write) over the WPCF7_ContactForm API.
 *
 * Verified against Contact Form 7 6.1.6:
 *  - WPCF7_ContactForm::find( $args ) → WPCF7_ContactForm[] (post_type wpcf7_contact_form).
 *  - WPCF7_ContactForm::get_instance( int $id ) → WPCF7_ContactForm|null.
 *  - ->id() / ->name() (slug) / ->title().
 *  - ->scan_form_tags() → WPCF7_FormTag[] (props: name, type, basetype, values,
 *    labels; is_required() = trailing "*").
 *  - ->prop('mail'|'mail_2'|'messages'|'additional_settings'|'form').
 *  - ->set_properties( array ) + ->save().
 *  - mail array keys: active, subject, sender, recipient, body,
 *    additional_headers, attachments, use_html, exclude_blank.
 *  - caps: wpcf7_read_contact_forms (= edit_posts), wpcf7_edit_contact_forms
 *    (= publish_pages).
 *
 * CF7 stores no submissions, so there are no entry operations.
 *
 * @package KarMCP
 * @since   3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.5.0
 */
class KarMCP_CF7_Integration extends KarMCP_Form_Integration {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'cf7';
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return 'Contact Form 7';
	}

	/**
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'WPCF7_ContactForm' ) || defined( 'WPCF7_VERSION' );
	}

	/**
	 * @return array<string,array>
	 */
	protected function operations(): array {
		$can_read  = static function (): bool {
			return current_user_can( 'wpcf7_read_contact_forms' );
		};
		$can_write = static function (): bool {
			return current_user_can( 'wpcf7_edit_contact_forms' );
		};

		return array(
			'list-forms'           => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_list_forms' ),
				'perm' => $can_read,
				'desc' => 'List all Contact Form 7 forms (id, title, slug, field count).',
			),
			'get-form'             => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_form' ),
				'perm' => $can_read,
				'desc' => 'Get one form by { form_id }: fields, mail templates, messages, and settings.',
			),
			'list-notifications'   => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_list_notifications' ),
				'perm' => $can_read,
				'desc' => 'List a form\'s mail templates (mail, mail_2) by { form_id }.',
			),
			'get-settings'         => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_settings' ),
				'perm' => $can_read,
				'desc' => 'Get a form\'s messages and additional settings by { form_id }.',
			),
			'list-entries'         => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_list_entries' ),
				'perm' => $can_read,
				'desc' => 'List stored submissions: { form_id?, limit?, offset?, include_spam? }. CF7 itself stores nothing, so this needs the Flamingo plugin (same author); without it the operation says so.',
			),
			'create-form'          => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_create_form' ),
				'perm' => $can_write,
				'desc' => 'Create a form from a field spec: { title, fields: [ { name?, type, label?, required?, placeholder?, options?, default? } ], recipient?, subject?, submit_label? }. Types: text, email, tel, url, number, date, textarea, select, checkbox, radio, acceptance, file, hidden. Generates the form body and a mail template that reports every field, and returns the shortcode to embed.',
			),
			'update-form'          => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_form' ),
				'perm' => $can_write,
				'desc' => 'Rebuild an existing form from a field spec: { form_id, fields, title?, recipient?, subject?, submit_label?, keep_mail? }. Replaces the whole body, so send the complete field list. keep_mail:true leaves the mail template untouched.',
			),
			'update-notification'  => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_notification' ),
				'perm' => $can_write,
				'desc' => 'Update a mail template: { form_id, notification: "mail"|"mail_2", mail: { subject?, sender?, recipient?, body?, additional_headers?, attachments?, use_html?, active? } }. Only provided keys change.',
			),
			'update-messages'      => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_messages' ),
				'perm' => $can_write,
				'desc' => 'Update validation/response messages: { form_id, messages: { key: value, ... } }. Only provided keys change.',
			),
			'update-form-settings' => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_form_settings' ),
				'perm' => $can_write,
				'desc' => 'Replace the Additional Settings block: { form_id, additional_settings: string }.',
			),
		);
	}

	/**
	 * Load a form or return a WP_Error.
	 *
	 * @param array $args Operation arguments.
	 * @return \WPCF7_ContactForm|WP_Error
	 */
	private function form( array $args ) {
		$id = isset( $args['form_id'] ) ? absint( $args['form_id'] ) : 0;
		if ( ! $id ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: form_id.', 'karmcp' ), array( 'status' => 400 ) );
		}
		$form = \WPCF7_ContactForm::get_instance( $id );
		if ( ! $form ) {
			return new WP_Error(
				'form_not_found',
				sprintf(
					/* translators: %d: form id */
					__( 'No Contact Form 7 form with id %d.', 'karmcp' ),
					$id
				),
				array( 'status' => 404 )
			);
		}
		return $form;
	}

	/**
	 * @param array $args Unused.
	 * @return array
	 */
	public function op_list_forms( array $args ): array {
		$out = array();
		foreach ( \WPCF7_ContactForm::find( array() ) as $form ) {
			$out[] = array(
				'id'          => $form->id(),
				'title'       => $form->title(),
				'slug'        => $form->name(),
				'field_count' => count( $this->fields( $form ) ),
			);
		}
		return array( 'forms' => $out );
	}

	/**
	 * @param array $args { form_id }.
	 * @return array|WP_Error
	 */
	public function op_get_form( array $args ) {
		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array(
			'id'                  => $form->id(),
			'title'               => $form->title(),
			'slug'                => $form->name(),
			'fields'              => $this->fields( $form ),
			'mail'                => $form->prop( 'mail' ),
			'mail_2'              => $form->prop( 'mail_2' ),
			'messages'            => $form->prop( 'messages' ),
			'additional_settings' => $form->prop( 'additional_settings' ),
		);
	}

	/**
	 * @param array $args { form_id }.
	 * @return array|WP_Error
	 */
	public function op_list_notifications( array $args ) {
		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array(
			'notifications' => array(
				array( 'id' => 'mail', 'mail' => $form->prop( 'mail' ) ),
				array( 'id' => 'mail_2', 'mail' => $form->prop( 'mail_2' ) ),
			),
		);
	}

	/**
	 * @param array $args { form_id }.
	 * @return array|WP_Error
	 */
	public function op_get_settings( array $args ) {
		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array(
			'messages'            => $form->prop( 'messages' ),
			'additional_settings' => $form->prop( 'additional_settings' ),
		);
	}

	/**
	 * @param array $args { form_id, notification, mail }.
	 * @return array|WP_Error
	 */
	public function op_update_notification( array $args ) {
		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$which = isset( $args['notification'] ) ? (string) $args['notification'] : 'mail';
		if ( ! in_array( $which, array( 'mail', 'mail_2' ), true ) ) {
			return new WP_Error( 'invalid_argument', __( 'notification must be "mail" or "mail_2".', 'karmcp' ), array( 'status' => 400 ) );
		}
		$patch = ( isset( $args['mail'] ) && is_array( $args['mail'] ) ) ? $args['mail'] : array();
		if ( ! $patch ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: mail (object of fields to change).', 'karmcp' ), array( 'status' => 400 ) );
		}
		$current = $form->prop( $which );
		$current = is_array( $current ) ? $current : array();
		$merged  = array_merge( $current, $patch );
		$form->set_properties( array( $which => $merged ) );
		$form->save();
		return array( 'updated' => true, 'notification' => $which, 'mail' => \WPCF7_ContactForm::get_instance( $form->id() )->prop( $which ) );
	}

	/**
	 * @param array $args { form_id, messages }.
	 * @return array|WP_Error
	 */
	public function op_update_messages( array $args ) {
		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$patch = ( isset( $args['messages'] ) && is_array( $args['messages'] ) ) ? $args['messages'] : array();
		if ( ! $patch ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: messages (object of message keys to change).', 'karmcp' ), array( 'status' => 400 ) );
		}
		$current = $form->prop( 'messages' );
		$current = is_array( $current ) ? $current : array();
		$merged  = array_merge( $current, array_map( 'strval', $patch ) );
		$form->set_properties( array( 'messages' => $merged ) );
		$form->save();
		return array( 'updated' => true, 'messages' => \WPCF7_ContactForm::get_instance( $form->id() )->prop( 'messages' ) );
	}

	/**
	 * @param array $args { form_id, additional_settings }.
	 * @return array|WP_Error
	 */
	public function op_update_form_settings( array $args ) {
		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		if ( ! isset( $args['additional_settings'] ) || ! is_string( $args['additional_settings'] ) ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: additional_settings (string).', 'karmcp' ), array( 'status' => 400 ) );
		}
		$form->set_properties( array( 'additional_settings' => (string) $args['additional_settings'] ) );
		$form->save();
		return array( 'updated' => true, 'additional_settings' => \WPCF7_ContactForm::get_instance( $form->id() )->prop( 'additional_settings' ) );
	}

	/**
	 * Creates a form from a field spec.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args { title, fields, recipient?, subject?, submit_label? }.
	 * @return array|WP_Error
	 */
	public function op_create_form( array $args ) {
		$api = $this->assert_authoring_api();
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$title = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: title.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$built = $this->build( $args );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$form = \WPCF7_ContactForm::get_template( array( 'title' => $title ) );
		if ( ! $form ) {
			return new WP_Error( 'create_failed', __( 'Contact Form 7 refused to create the form.', 'karmcp' ), array( 'status' => 500 ) );
		}

		$form->set_properties(
			array(
				'form' => $built['body'],
				'mail' => $built['mail'],
			)
		);

		$id = (int) $form->save();
		if ( $id <= 0 ) {
			return new WP_Error( 'create_failed', __( 'Saving the form returned no id.', 'karmcp' ), array( 'status' => 500 ) );
		}

		$saved = \WPCF7_ContactForm::get_instance( $id );

		return array(
			'created'   => true,
			'form_id'   => $id,
			'title'     => $title,
			'shortcode' => $this->shortcode( $saved, $id, $title ),
			'fields'    => $saved ? $this->fields( $saved ) : array(),
			'recipient' => $built['mail']['recipient'],
		);
	}

	/**
	 * Rebuilds an existing form's body from a field spec.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args { form_id, fields, title?, recipient?, subject?, submit_label?, keep_mail? }.
	 * @return array|WP_Error
	 */
	public function op_update_form( array $args ) {
		$api = $this->assert_authoring_api();
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$form = $this->form( $args );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$built = $this->build( $args );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$props = array( 'form' => $built['body'] );
		if ( empty( $args['keep_mail'] ) ) {
			// Merge over the existing template so a hand-edited subject or an
			// extra recipient header is not silently discarded by a field change.
			$current       = $form->prop( 'mail' );
			$props['mail'] = array_merge( is_array( $current ) ? $current : array(), $built['mail'] );
		}
		if ( isset( $args['title'] ) && '' !== (string) $args['title'] ) {
			$props['title'] = sanitize_text_field( (string) $args['title'] );
		}

		$form->set_properties( $props );
		$form->save();

		$saved = \WPCF7_ContactForm::get_instance( $form->id() );

		return array(
			'updated'   => true,
			'form_id'   => $form->id(),
			'shortcode' => $this->shortcode( $saved, $form->id(), $saved ? $saved->title() : '' ),
			'fields'    => $saved ? $this->fields( $saved ) : array(),
			'mail_kept' => ! empty( $args['keep_mail'] ),
		);
	}

	/**
	 * Lists stored submissions.
	 *
	 * Contact Form 7 deliberately stores nothing — it emails and forgets. Its
	 * companion plugin Flamingo is what persists submissions, so that is what we
	 * read. When it is absent the honest answer is to say so and name the fix,
	 * not to return an empty list that reads as "no one has written to you".
	 *
	 * @since 1.1.0
	 *
	 * @param array $args { form_id?, limit?, offset?, include_spam? }.
	 * @return array|WP_Error
	 */
	public function op_list_entries( array $args ) {
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) || ! method_exists( 'Flamingo_Inbound_Message', 'find' ) ) {
			return new WP_Error(
				'entries_unavailable',
				__( 'Contact Form 7 does not store submissions; it sends them by email and keeps nothing. Install the Flamingo plugin (by the same author) to have them saved, and this operation will read them.', 'karmcp' ),
				array( 'status' => 409 )
			);
		}

		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 20;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$query = array(
			'posts_per_page' => $limit,
			'offset'         => $offset,
		);

		// Flamingo files messages by "channel", which is the form's slug.
		if ( ! empty( $args['form_id'] ) ) {
			$form = $this->form( $args );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$query['channel'] = $form->name();
		}

		$messages = \Flamingo_Inbound_Message::find( $query );
		$entries  = array();

		foreach ( (array) $messages as $message ) {
			$id     = isset( $message->id ) ? (int) $message->id : 0;
			$is_spam = ! empty( $message->spam );
			if ( $is_spam && empty( $args['include_spam'] ) ) {
				continue;
			}

			$entries[] = array(
				'id'      => $id,
				'channel' => isset( $message->channel ) ? (string) $message->channel : '',
				'subject' => isset( $message->subject ) ? (string) $message->subject : '',
				'from'    => isset( $message->from ) ? (string) $message->from : '',
				'date'    => $id ? (string) get_post_field( 'post_date', $id ) : '',
				'spam'    => $is_spam,
				'fields'  => isset( $message->fields ) && is_array( $message->fields ) ? $message->fields : array(),
			);
		}

		return array(
			'entries' => $entries,
			'count'   => count( $entries ),
			'source'  => 'flamingo',
		);
	}

	/**
	 * Builds the body and mail template shared by create and update.
	 *
	 * @param array $args Operation arguments.
	 * @return array|WP_Error { body, mail }
	 */
	private function build( array $args ) {
		if ( ! isset( $args['fields'] ) || ! is_array( $args['fields'] ) ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: fields (array of field objects).', 'karmcp' ), array( 'status' => 400 ) );
		}

		$body = KarMCP_CF7_Form_Builder::build_body(
			$args['fields'],
			array(
				'submit_label'    => isset( $args['submit_label'] ) ? (string) $args['submit_label'] : __( 'Send', 'karmcp' ),
				'required_marker' => isset( $args['required_marker'] ) ? (string) $args['required_marker'] : ' *',
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$recipient = isset( $args['recipient'] ) ? sanitize_email( (string) $args['recipient'] ) : '';
		if ( '' === $recipient ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		$mail = KarMCP_CF7_Form_Builder::build_mail(
			$args['fields'],
			array(
				'recipient' => $recipient,
				'sender'    => $this->default_sender(),
				'site_name' => (string) get_bloginfo( 'name' ),
				'subject'   => isset( $args['subject'] ) ? sanitize_text_field( (string) $args['subject'] ) : '',
			)
		);
		if ( is_wp_error( $mail ) ) {
			return $mail;
		}
		if ( '' === $mail['subject'] ) {
			$mail['subject'] = sprintf(
				/* translators: %s: site name. */
				__( '%s — new form submission', 'karmcp' ),
				(string) get_bloginfo( 'name' )
			);
		}

		return array(
			'body' => $body,
			'mail' => $mail,
		);
	}

	/**
	 * The From address CF7 will accept: the site's own domain, matching what
	 * WordPress uses for its own mail. A From header on someone else's domain is
	 * what gets a site's mail marked as spam.
	 *
	 * @return string
	 */
	private function default_sender(): string {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );

		return sprintf( '%s <wordpress@%s>', (string) get_bloginfo( 'name' ), $host );
	}

	/**
	 * The embed shortcode for a form.
	 *
	 * CF7 5.8 moved the shortcode from the numeric id to a per-form hash, so the
	 * form's own shortcode() method is the only source that stays right across
	 * versions. The literal is a last resort for older installs.
	 *
	 * @param \WPCF7_ContactForm|null $form  Saved form.
	 * @param int                     $id    Form id.
	 * @param string                  $title Form title.
	 * @return string
	 */
	private function shortcode( $form, int $id, string $title ): string {
		if ( $form && method_exists( $form, 'shortcode' ) ) {
			$shortcode = (string) $form->shortcode();
			if ( '' !== $shortcode ) {
				return $shortcode;
			}
		}
		return sprintf( '[contact-form-7 id="%d" title="%s"]', $id, $title );
	}

	/**
	 * Guards the authoring path against a CF7 whose API has moved.
	 *
	 * Creating a form goes through methods this build cannot verify at runtime
	 * on every install. Failing loudly here beats saving a half-formed post: a
	 * broken CF7 form does not error, it prints its own tags on the page.
	 *
	 * @return true|WP_Error
	 */
	private function assert_authoring_api() {
		if ( ! class_exists( 'WPCF7_ContactForm' )
			|| ! method_exists( 'WPCF7_ContactForm', 'get_template' )
			|| ! method_exists( 'WPCF7_ContactForm', 'get_instance' ) ) {
			return new WP_Error(
				'api_unavailable',
				__( 'This version of Contact Form 7 does not expose the form-authoring API this tool needs. Update Contact Form 7, or build the form in its editor.', 'karmcp' ),
				array( 'status' => 409 )
			);
		}
		if ( ! class_exists( 'KarMCP_CF7_Form_Builder' ) ) {
			return new WP_Error( 'unavailable', __( 'The form builder is not available on this install.', 'karmcp' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Extract a compact field list from a form's tags (skips nameless tags like
	 * submit).
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return array<int,array>
	 */
	private function fields( $form ): array {
		$out = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( '' === (string) $tag->name ) {
				continue;
			}
			$field = array(
				'name'     => $tag->name,
				'type'     => $tag->basetype,
				'required' => $tag->is_required(),
			);
			if ( ! empty( $tag->values ) ) {
				$field['values'] = array_values( (array) $tag->values );
			}
			if ( ! empty( $tag->labels ) ) {
				$field['labels'] = array_values( (array) $tag->labels );
			}
			$out[] = $field;
		}
		return $out;
	}
}
