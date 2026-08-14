<?php
/**
 * The Contact Form 7 body generator.
 *
 * CF7's tag grammar has a nasty property: it does not fail. A malformed tag is
 * not an error, it is text — the form renders with "[emial your-email]" printed
 * on the page, and the site owner finds out when nobody has written in for a
 * month. Nothing downstream can catch that, so the grammar gets pinned here:
 * the required star, quoting, the option list, and the refusals that stop a bad
 * spec from ever reaching a saved form.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/forms/class-cf7-form-builder.php';

class Cf7FormBuilderTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function build( array $fields, array $args = array() ): string {
		$body = KarMCP_CF7_Form_Builder::build_body( $fields, $args );
		$this->assertIsString( $body, 'Expected the body to build.' );
		return $body;
	}

	private function assertRefused( array $fields, string $expected_code = '' ): WP_Error {
		$result = KarMCP_CF7_Form_Builder::build_body( $fields );
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the spec to be refused.' );
		if ( '' !== $expected_code ) {
			$this->assertSame( $expected_code, $result->get_error_code() );
		}
		return $result;
	}

	// ---- the tag grammar ---------------------------------------------------

	public function test_optional_and_required_fields_differ_by_the_star(): void {
		$body = $this->build(
			array(
				array( 'name' => 'nombre', 'type' => 'text', 'label' => 'Nombre', 'required' => true ),
				array( 'name' => 'empresa', 'type' => 'text', 'label' => 'Empresa' ),
			)
		);

		$this->assertStringContainsString( '[text* nombre]', $body );
		$this->assertStringContainsString( '[text empresa]', $body );
	}

	public function test_placeholder_is_emitted_as_a_quoted_option(): void {
		$body = $this->build(
			array( array( 'name' => 'email', 'type' => 'email', 'required' => true, 'placeholder' => 'tu@correo.com' ) )
		);

		$this->assertStringContainsString( '[email* email placeholder "tu@correo.com"]', $body );
	}

	public function test_choice_fields_list_their_options(): void {
		$body = $this->build(
			array(
				array(
					'name'    => 'asunto',
					'type'    => 'select',
					'label'   => 'Asunto',
					'options' => array( 'Presupuesto', 'Soporte' ),
				),
			)
		);

		$this->assertStringContainsString( '[select asunto "Presupuesto" "Soporte"]', $body );
	}

	/**
	 * The grammar has no escape for a quote, so a quote inside an option would
	 * close it early and shift every later value by one position — silently
	 * producing a working form with the wrong choices.
	 */
	public function test_quotes_inside_an_option_are_removed_not_escaped(): void {
		$body = $this->build(
			array(
				array(
					'name'    => 'plan',
					'type'    => 'radio',
					'options' => array( 'El plan "grande"', 'Básico' ),
				),
			)
		);

		$this->assertStringContainsString( '[radio plan "El plan grande" "Básico"]', $body );
		$this->assertSame( 2, substr_count( $body, '"El plan grande"' ) + substr_count( $body, '"Básico"' ) );
	}

	public function test_acceptance_wraps_its_consent_text(): void {
		$body = $this->build(
			array( array( 'name' => 'privacidad', 'type' => 'acceptance', 'label' => 'Acepto la política de privacidad' ) )
		);

		$this->assertStringContainsString( '[acceptance privacidad] Acepto la política de privacidad [/acceptance]', $body );
	}

	public function test_hidden_field_is_not_labelled_or_starred(): void {
		$body = $this->build(
			array( array( 'name' => 'origen', 'type' => 'hidden', 'required' => true, 'default' => 'landing' ) )
		);

		$this->assertStringContainsString( '[hidden origen', $body );
		$this->assertStringNotContainsString( '[hidden* origen', $body );
		$this->assertStringNotContainsString( '<label>', $body );
	}

	public function test_a_submit_button_is_always_appended(): void {
		$body = $this->build(
			array( array( 'name' => 'nombre', 'type' => 'text' ) ),
			array( 'submit_label' => 'Enviar consulta' )
		);

		$this->assertStringContainsString( '[submit "Enviar consulta"]', $body );
	}

	public function test_label_text_is_escaped(): void {
		$body = $this->build(
			array( array( 'name' => 'nota', 'type' => 'text', 'label' => 'Precio <b>final</b> & IVA' ) )
		);

		$this->assertStringContainsString( 'Precio &lt;b&gt;final&lt;/b&gt; &amp; IVA', $body );
		$this->assertStringNotContainsString( '<b>final</b>', $body );
	}

	// ---- names -------------------------------------------------------------

	public function test_name_is_derived_from_the_label_when_absent(): void {
		$body = $this->build(
			array( array( 'type' => 'text', 'label' => 'Nombre completo' ) )
		);

		$this->assertStringContainsString( '[text nombre-completo]', $body );
	}

	/**
	 * A Spanish label run through a naive slug would come back as a row of
	 * hyphens, and CF7 keys the submitted values by this name.
	 */
	public function test_accents_and_enye_fold_to_ascii_in_names(): void {
		$this->assertSame( 'telefono', KarMCP_CF7_Form_Builder::tag_name( 'Teléfono' ) );
		$this->assertSame( 'ano-de-nacimiento', KarMCP_CF7_Form_Builder::tag_name( 'Año de nacimiento' ) );
	}

	/**
	 * CF7's parser breaks on a name starting with a digit, and the failure shows
	 * up as a form that will not save rather than as a message about the name.
	 */
	public function test_name_starting_with_a_digit_is_prefixed(): void {
		$this->assertSame( 'field-2024-plan', KarMCP_CF7_Form_Builder::tag_name( '2024 plan' ) );
	}

	// ---- refusals ----------------------------------------------------------

	public function test_unknown_field_type_is_refused_with_the_supported_list(): void {
		$error = $this->assertRefused(
			array( array( 'name' => 'correo', 'type' => 'emial' ) ),
			'invalid_argument'
		);

		$this->assertStringContainsString( 'email', $error->get_error_message() );
	}

	public function test_choice_field_without_options_is_refused(): void {
		$this->assertRefused(
			array( array( 'name' => 'asunto', 'type' => 'select' ) ),
			'missing_argument'
		);
	}

	/**
	 * Two fields sharing a name is not a cosmetic problem: CF7 keys submitted
	 * values by name, so one of them silently never arrives in the email.
	 */
	public function test_duplicate_field_names_are_refused(): void {
		$this->assertRefused(
			array(
				array( 'name' => 'nombre', 'type' => 'text' ),
				array( 'label' => 'Nombre', 'type' => 'text' ),
			),
			'invalid_argument'
		);
	}

	public function test_a_form_with_no_fields_is_refused(): void {
		$this->assertRefused( array(), 'missing_argument' );
	}

	public function test_field_without_a_usable_name_is_refused(): void {
		$this->assertRefused(
			array( array( 'type' => 'text', 'label' => '—' ) ),
			'missing_argument'
		);
	}

	// ---- the mail template -------------------------------------------------

	/**
	 * CF7's stock template lists its own demo field names. Ship that with a
	 * generated form and every notification arrives with unresolved placeholders
	 * where the answers should be — the form looks like it works and the content
	 * is lost.
	 */
	public function test_mail_body_reports_every_mailable_field(): void {
		$mail = KarMCP_CF7_Form_Builder::build_mail(
			array(
				array( 'name' => 'nombre', 'type' => 'text', 'label' => 'Nombre' ),
				array( 'name' => 'correo', 'type' => 'email', 'label' => 'Correo' ),
				array( 'name' => 'mensaje', 'type' => 'textarea', 'label' => 'Mensaje' ),
			),
			array( 'recipient' => 'ventas@ejemplo.test' )
		);

		$this->assertStringContainsString( 'Nombre: [nombre]', $mail['body'] );
		$this->assertStringContainsString( 'Correo: [correo]', $mail['body'] );
		$this->assertStringContainsString( 'Mensaje: [mensaje]', $mail['body'] );
		$this->assertSame( 'ventas@ejemplo.test', $mail['recipient'] );
	}

	/**
	 * Putting the submitter's address in From gets the site's mail marked as
	 * spam, or refused outright by CF7's own header check. Reply-To is where it
	 * belongs, and it is what makes the notification answerable.
	 */
	public function test_submitter_email_becomes_reply_to_not_the_sender(): void {
		$mail = KarMCP_CF7_Form_Builder::build_mail(
			array(
				array( 'name' => 'correo', 'type' => 'email', 'label' => 'Correo' ),
			),
			array( 'recipient' => 'hola@ejemplo.test', 'sender' => 'Sitio <wordpress@ejemplo.test>' )
		);

		$this->assertSame( 'Reply-To: [correo]', $mail['additional_headers'] );
		$this->assertSame( 'Sitio <wordpress@ejemplo.test>', $mail['sender'] );
	}

	public function test_file_fields_are_attached_not_inlined(): void {
		$mail = KarMCP_CF7_Form_Builder::build_mail(
			array(
				array( 'name' => 'cv', 'type' => 'file', 'label' => 'Currículum' ),
				array( 'name' => 'nombre', 'type' => 'text', 'label' => 'Nombre' ),
			)
		);

		$this->assertSame( '[cv]', $mail['attachments'] );
		$this->assertStringNotContainsString( '[cv]', $mail['body'] );
	}

	public function test_mail_refuses_the_same_specs_the_body_does(): void {
		$this->assertInstanceOf(
			WP_Error::class,
			KarMCP_CF7_Form_Builder::build_mail( array( array( 'name' => 'x', 'type' => 'nope' ) ) )
		);
	}
}
