<?php
/**
 * The Elementor Pro Form widget settings builder.
 *
 * Elementor accepts any key you hand it without complaining, so a wrong control
 * name is not an error — it is a form that looks finished in the editor and
 * delivers nothing. Two of those traps have their own tests here because they
 * are the ones that cost real leads: `required` is the string "yes" (a boolean
 * silently makes the field optional), and `custom_id` is what names the
 * submitted value (without it the notification email arrives blank).
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/forms/class-elementor-form-builder.php';

class ElementorFormBuilderTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** Builds with predictable row ids so assertions can be exact. */
	private function build( array $fields, array $args = array() ): array {
		$counter = 0;
		$args   += array(
			'id_generator' => static function () use ( &$counter ): string {
				++$counter;
				return sprintf( 'row%04d', $counter );
			},
		);

		$settings = KarMCP_Elementor_Form_Builder::build_settings( $fields, $args );
		$this->assertIsArray( $settings, 'Expected the settings to build.' );
		return $settings;
	}

	private function assertRefused( array $fields, string $expected_code ): void {
		$result = KarMCP_Elementor_Form_Builder::build_settings( $fields );
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the spec to be refused.' );
		$this->assertSame( $expected_code, $result->get_error_code() );
	}

	// ---- the two traps -----------------------------------------------------

	/**
	 * `required` is a string control. A boolean true is not read as truthy
	 * anywhere in the widget, so the field just stops being required — and the
	 * form still submits, which is why nobody catches it.
	 */
	public function test_required_is_the_string_yes_and_optional_is_the_empty_string(): void {
		$settings = $this->build(
			array(
				array( 'type' => 'text', 'label' => 'Nombre', 'required' => true ),
				array( 'type' => 'text', 'label' => 'Empresa' ),
			)
		);

		$this->assertSame( 'yes', $settings['form_fields'][0]['required'] );
		$this->assertSame( '', $settings['form_fields'][1]['required'] );
	}

	/**
	 * custom_id is the name the value is submitted under, and what
	 * `[field id="…"]` resolves against. The editor generates it in JavaScript,
	 * so anything built through the API has to set it explicitly.
	 */
	public function test_every_row_gets_a_custom_id_and_a_row_id(): void {
		$settings = $this->build(
			array( array( 'type' => 'email', 'label' => 'Correo electrónico' ) )
		);

		$this->assertSame( 'correo_electronico', $settings['form_fields'][0]['custom_id'] );
		$this->assertSame( 'row0001', $settings['form_fields'][0]['_id'] );
	}

	public function test_row_ids_are_unique_per_field(): void {
		$settings = $this->build(
			array(
				array( 'type' => 'text', 'label' => 'A' ),
				array( 'type' => 'text', 'label' => 'B' ),
				array( 'type' => 'text', 'label' => 'C' ),
			)
		);

		$ids = array_column( $settings['form_fields'], '_id' );
		$this->assertSame( $ids, array_unique( $ids ) );
	}

	// ---- field shapes ------------------------------------------------------

	public function test_choice_options_are_newline_separated(): void {
		$settings = $this->build(
			array(
				array(
					'type'    => 'select',
					'label'   => 'Asunto',
					'options' => array( 'Presupuesto', 'Soporte', 'Otro' ),
				),
			)
		);

		$this->assertSame( "Presupuesto\nSoporte\nOtro", $settings['form_fields'][0]['field_options'] );
	}

	public function test_acceptance_field_carries_its_consent_text(): void {
		$settings = $this->build(
			array( array( 'type' => 'acceptance', 'label' => 'Acepto la política de privacidad' ) )
		);

		$this->assertSame( 'Acepto la política de privacidad', $settings['form_fields'][0]['acceptance_text'] );
	}

	public function test_hidden_field_carries_its_value(): void {
		$settings = $this->build(
			array( array( 'type' => 'hidden', 'name' => 'origen', 'default' => 'landing-verano' ) )
		);

		$this->assertSame( 'landing-verano', $settings['form_fields'][0]['field_value'] );
	}

	/**
	 * An unrecognised width produces no CSS class at all, so the field silently
	 * renders full width. Falling back to a known value keeps the layout honest.
	 */
	public function test_unknown_width_falls_back_to_full(): void {
		$settings = $this->build(
			array(
				array( 'type' => 'text', 'label' => 'A', 'width' => '50' ),
				array( 'type' => 'text', 'label' => 'B', 'width' => '37' ),
			)
		);

		$this->assertSame( '50', $settings['form_fields'][0]['width'] );
		$this->assertSame( '100', $settings['form_fields'][1]['width'] );
	}

	public function test_placeholder_is_only_written_when_given(): void {
		$settings = $this->build(
			array(
				array( 'type' => 'text', 'label' => 'A', 'placeholder' => 'Escribe aquí' ),
				array( 'type' => 'text', 'label' => 'B' ),
			)
		);

		$this->assertSame( 'Escribe aquí', $settings['form_fields'][0]['placeholder'] );
		$this->assertArrayNotHasKey( 'placeholder', $settings['form_fields'][1] );
	}

	// ---- submit actions ----------------------------------------------------

	public function test_email_action_is_wired_by_default(): void {
		$settings = $this->build(
			array( array( 'type' => 'text', 'label' => 'Nombre' ) ),
			array( 'recipient' => 'ventas@ejemplo.test', 'subject' => 'Nuevo aviso' )
		);

		$this->assertSame( array( 'email' ), $settings['submit_actions'] );
		$this->assertSame( 'ventas@ejemplo.test', $settings['email_to'] );
		$this->assertSame( 'Nuevo aviso', $settings['email_subject'] );
	}

	public function test_first_email_field_becomes_the_reply_to(): void {
		$settings = $this->build(
			array(
				array( 'type' => 'text', 'label' => 'Nombre' ),
				array( 'type' => 'email', 'label' => 'Correo' ),
				array( 'type' => 'email', 'label' => 'Correo alternativo' ),
			)
		);

		$this->assertSame( '[field id="correo"]', $settings['email_reply_to'] );
	}

	public function test_form_without_an_email_field_has_no_reply_to(): void {
		$settings = $this->build( array( array( 'type' => 'tel', 'label' => 'Teléfono' ) ) );

		$this->assertArrayNotHasKey( 'email_reply_to', $settings );
	}

	/**
	 * Replacing the email action with the redirect would send the visitor to the
	 * thank-you page and throw the submission away — the worst possible failure,
	 * because from the outside it looks like it worked.
	 */
	public function test_redirect_is_added_to_the_email_action_not_swapped_for_it(): void {
		$settings = $this->build(
			array( array( 'type' => 'text', 'label' => 'Nombre' ) ),
			array( 'redirect_to' => 'https://ejemplo.test/gracias' )
		);

		$this->assertSame( array( 'email', 'redirect' ), $settings['submit_actions'] );
		$this->assertSame( 'https://ejemplo.test/gracias', $settings['redirect_to'] );
	}

	// ---- refusals ----------------------------------------------------------

	public function test_unsupported_field_type_is_refused(): void {
		$this->assertRefused( array( array( 'type' => 'signature', 'label' => 'Firma' ) ), 'invalid_argument' );
	}

	public function test_choice_field_without_options_is_refused(): void {
		$this->assertRefused( array( array( 'type' => 'radio', 'label' => 'Plan' ) ), 'missing_argument' );
	}

	public function test_duplicate_field_names_are_refused(): void {
		$this->assertRefused(
			array(
				array( 'type' => 'text', 'name' => 'nombre' ),
				array( 'type' => 'text', 'label' => 'Nombre' ),
			),
			'invalid_argument'
		);
	}

	public function test_a_form_with_no_fields_is_refused(): void {
		$this->assertRefused( array(), 'missing_argument' );
	}
}
