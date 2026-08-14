<?php
/**
 * add-contact-form — one call from "this page needs a contact form" to a
 * working, wired-up Elementor Pro form.
 *
 * The generic `add-pro-widget` can already place a `form` widget, but only if
 * the caller hand-writes the repeater rows and remembers that `required` is the
 * string "yes" and that `custom_id` is what names the submitted field. This tool
 * takes the field list a human would describe and produces those rows, so the
 * form that lands is one that actually delivers mail.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements `add-contact-form`.
 *
 * @since 1.1.0
 */
class KarMCP_Form_Widget_Abilities {

	/**
	 * The data access layer.
	 *
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * The element factory.
	 *
	 * @var KarMCP_Element_Factory
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param KarMCP_Data            $data    Data layer.
	 * @param KarMCP_Element_Factory $factory Element factory.
	 */
	public function __construct( KarMCP_Data $data, KarMCP_Element_Factory $factory ) {
		$this->data    = $data;
		$this->factory = $factory;
	}

	/**
	 * Whether Elementor Pro's Form widget is present.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public static function form_widget_available(): bool {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			return false;
		}
		return (bool) \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'form' );
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/add-contact-form' );
	}

	/**
	 * Write permission callback.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function check_edit_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the ability.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/add-contact-form',
			array(
				'label'               => __( 'Add Contact Form', 'karmcp' ),
				'description'         => __( 'Adds a working Elementor Pro form to a container from a plain field list, wiring the notification email, the reply-to address and the submit button for you. Each field is { type, label, name?, required?, placeholder?, options?, width? } with type one of text, email, textarea, url, tel, select, radio, checkbox, number, date, time, upload, acceptance, password, hidden. Prefer this over building a form widget by hand with add-pro-widget: the field repeater has two traps (required is the string "yes", and custom_id is what names the submitted value) that produce a form which looks right in the editor and emails you nothing. Needs Elementor Pro.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'         => array(
							'type'        => 'integer',
							'description' => __( 'The page/post ID to add the form to.', 'karmcp' ),
						),
						'parent_id'       => array(
							'type'        => 'string',
							'description' => __( 'The container element ID that will hold the form.', 'karmcp' ),
						),
						'position'        => array(
							'type'        => 'integer',
							'description' => __( 'Insert position inside the container. -1 appends (default).', 'karmcp' ),
						),
						'fields'          => array(
							'type'        => 'array',
							'description' => __( 'The form fields, in order.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'type'        => array(
										'type' => 'string',
										'enum' => KarMCP_Elementor_Form_Builder::FIELD_TYPES,
									),
									'label'       => array( 'type' => 'string' ),
									'name'        => array(
										'type'        => 'string',
										'description' => __( 'Submitted field name. Derived from the label when omitted.', 'karmcp' ),
									),
									'required'    => array( 'type' => 'boolean' ),
									'placeholder' => array( 'type' => 'string' ),
									'options'     => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'Choices for select, radio and checkbox fields.', 'karmcp' ),
									),
									'width'       => array(
										'type'        => 'string',
										'enum'        => KarMCP_Elementor_Form_Builder::WIDTHS,
										'description' => __( 'Column width in percent. Default 100.', 'karmcp' ),
									),
									'default'     => array( 'type' => 'string' ),
								),
							),
						),
						'form_name'       => array(
							'type'        => 'string',
							'description' => __( 'Internal name for the form, shown in Elementor submissions.', 'karmcp' ),
						),
						'submit_label'    => array(
							'type'        => 'string',
							'description' => __( 'Submit button text. Default "Send".', 'karmcp' ),
						),
						'recipient'       => array(
							'type'        => 'string',
							'description' => __( 'Where submissions are emailed. Defaults to the site admin address.', 'karmcp' ),
						),
						'subject'         => array(
							'type'        => 'string',
							'description' => __( 'Notification subject line.', 'karmcp' ),
						),
						'success_message' => array(
							'type'        => 'string',
							'description' => __( 'Message shown after a successful submission.', 'karmcp' ),
						),
						'redirect_to'     => array(
							'type'        => 'string',
							'description' => __( 'URL to send the visitor to after submitting. The email action is kept as well.', 'karmcp' ),
						),
					),
					'required'   => array( 'post_id', 'parent_id', 'fields' ),
				),
			)
		);
	}

	/**
	 * Execute callback.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( (string) ( $input['parent_id'] ?? '' ) );
		$position  = isset( $input['position'] ) ? (int) $input['position'] : -1;

		if ( ! $post_id || '' === $parent_id ) {
			return new WP_Error( 'missing_params', __( 'post_id and parent_id are required.', 'karmcp' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'karmcp' ), array( 'status' => 403 ) );
		}
		if ( ! self::form_widget_available() ) {
			return new WP_Error(
				'pro_required',
				__( 'The Form widget comes with Elementor Pro, which is not active on this site. Contact Form 7 is a free alternative: create the form with cf7-write create-form and place it with a shortcode widget.', 'karmcp' ),
				array( 'status' => 409 )
			);
		}

		$fields = ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) ? $input['fields'] : array();

		$recipient = isset( $input['recipient'] ) ? sanitize_email( (string) $input['recipient'] ) : '';
		if ( '' === $recipient ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		$settings = KarMCP_Elementor_Form_Builder::build_settings(
			$fields,
			array(
				'form_name'       => isset( $input['form_name'] ) ? sanitize_text_field( (string) $input['form_name'] ) : __( 'Contact form', 'karmcp' ),
				'submit_label'    => isset( $input['submit_label'] ) ? sanitize_text_field( (string) $input['submit_label'] ) : __( 'Send', 'karmcp' ),
				'recipient'       => $recipient,
				'subject'         => isset( $input['subject'] ) ? sanitize_text_field( (string) $input['subject'] ) : '',
				'from_name'       => (string) get_bloginfo( 'name' ),
				'success_message' => isset( $input['success_message'] ) ? sanitize_text_field( (string) $input['success_message'] ) : '',
				'redirect_to'     => isset( $input['redirect_to'] ) ? esc_url_raw( (string) $input['redirect_to'] ) : '',
			)
		);
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$widget = $this->factory->create_widget( 'form', $settings );

		if ( ! $this->data->insert_element( $page_data, $parent_id, $widget, $position ) ) {
			return new WP_Error( 'parent_not_found', __( 'Parent container not found.', 'karmcp' ), array( 'status' => 404 ) );
		}

		$saved = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'element_id'  => $widget['id'],
			'widget_type' => 'form',
			'fields'      => array_map(
				static function ( array $row ): array {
					return array(
						'name'     => $row['custom_id'],
						'type'     => $row['field_type'],
						'label'    => $row['field_label'],
						'required' => 'yes' === $row['required'],
					);
				},
				$settings['form_fields']
			),
			'recipient'   => $recipient,
			'next_step'   => __( 'Call render-page on this post to confirm the form renders with every field and a submit button.', 'karmcp' ),
		);
	}
}
