<?php
/**
 * The content extractor's pure half — HTML in, digest out.
 *
 * This is the feedback loop the agent never had: it writes builder JSON and,
 * without this, has no way to learn that the section it just created renders
 * blank. So what these tests pin is mostly the *warnings*, and specifically the
 * distinctions that make them trustworthy — `alt=""` is a decision and not a
 * defect, a spacer with a background image is not an empty container, an icon
 * link with a labelled icon has an accessible name. A checker that cries wolf
 * on any of those gets ignored within a day, and then it may as well not exist.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-content-extractor.php';

class ContentExtractorTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** Every warning code the digest raised. */
	private function codes( array $digest ): array {
		return array_column( $digest['warnings'], 'code' );
	}

	private function warning( array $digest, string $code ): ?array {
		foreach ( $digest['warnings'] as $warning ) {
			if ( $code === $warning['code'] ) {
				return $warning;
			}
		}
		return null;
	}

	private function assertWarns( array $digest, string $code ): array {
		$warning = $this->warning( $digest, $code );
		$this->assertNotNull(
			$warning,
			sprintf( 'Expected warning "%s". Got: %s', $code, implode( ', ', $this->codes( $digest ) ) )
		);
		return $warning;
	}

	private function assertDoesNotWarn( array $digest, string $code ): void {
		$this->assertNull(
			$this->warning( $digest, $code ),
			sprintf( 'Did not expect warning "%s".', $code )
		);
	}

	// ---- the contract holds even with nothing to say -----------------------

	/**
	 * The digest shape is the tool contract. If an empty page returned a
	 * half-populated array, every consumer would need isset() everywhere.
	 */
	public function test_empty_input_still_returns_the_full_shape(): void {
		$digest = KarMCP_Content_Extractor::analyze( '' );

		foreach ( array( 'headings', 'images', 'links', 'forms', 'landmarks', 'empty_containers', 'text', 'counts', 'warnings' ) as $key ) {
			$this->assertArrayHasKey( $key, $digest );
		}
		$this->assertSame( array( 'empty_render' ), $this->codes( $digest ) );
	}

	public function test_a_clean_page_raises_nothing(): void {
		$html = '<main><h1>Servicios</h1><h2>Consultoría</h2>'
			. '<p>Trabajamos con equipos que necesitan resultados.</p>'
			. '<img src="/wp-content/uploads/team.jpg" alt="El equipo en la oficina">'
			. '<a href="/contacto">Hablemos</a></main>';

		$digest = KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full' ) );

		$this->assertSame( array(), $digest['warnings'] );
		$this->assertSame( 2, $digest['counts']['headings'] );
		$this->assertSame( 1, $digest['counts']['images'] );
		$this->assertSame( 1, $digest['counts']['links'] );
	}

	// ---- headings ----------------------------------------------------------

	public function test_heading_outline_is_returned_in_document_order(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<h1>Uno</h1><h2 id="dos">Dos</h2><h3>Tres</h3>' );

		$this->assertSame(
			array( 1, 2, 3 ),
			array_column( $digest['headings'], 'level' )
		);
		$this->assertSame( 'Dos', $digest['headings'][1]['text'] );
		$this->assertSame( 'dos', $digest['headings'][1]['id'] );
	}

	public function test_skipped_heading_level_is_flagged(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<h1>Uno</h1><h4>Cuatro</h4>' );

		$this->assertSame( array( 'h1 -> h4' ), $this->assertWarns( $digest, 'heading_skip' )['examples'] );
	}

	public function test_going_back_up_the_outline_is_not_a_skip(): void {
		// h1 > h2 > h3 > h2 is how every multi-section page is built.
		$digest = KarMCP_Content_Extractor::analyze( '<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2>' );

		$this->assertDoesNotWarn( $digest, 'heading_skip' );
	}

	public function test_more_than_one_h1_is_flagged_with_the_offenders(): void {
		$digest  = KarMCP_Content_Extractor::analyze( '<h1>Primero</h1><h1>Segundo</h1>' );
		$warning = $this->assertWarns( $digest, 'multiple_h1' );

		$this->assertSame( array( 'Primero', 'Segundo' ), $warning['examples'] );
	}

	/**
	 * The same page fragment is a real finding for a whole page and merely
	 * informational for builder content, where the theme usually renders the
	 * title. Grading it the same either way would train the agent to insert a
	 * second H1 into every template.
	 */
	public function test_missing_h1_severity_depends_on_scope(): void {
		$html = '<section><h2>Sin H1</h2></section>';

		$this->assertSame( 'warning', $this->assertWarns( KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full' ) ), 'no_h1' )['severity'] );
		$this->assertSame( 'info', $this->assertWarns( KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'content' ) ), 'no_h1' )['severity'] );
	}

	public function test_heading_with_no_text_is_flagged(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<h1>Título</h1><h2></h2>' );

		$this->assertWarns( $digest, 'empty_heading' );
	}

	// ---- images ------------------------------------------------------------

	/**
	 * The distinction the whole alt-text check rests on: a missing attribute is
	 * an oversight, `alt=""` is someone saying "this is decoration". Flagging
	 * the second is how accessibility tooling gets a bad name.
	 */
	public function test_empty_alt_is_decorative_and_not_a_finding(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<img src="/a.png" alt="">' );

		$this->assertDoesNotWarn( $digest, 'image_missing_alt' );
		$this->assertTrue( $digest['images'][0]['has_alt'] );
		$this->assertTrue( $digest['images'][0]['decorative'] );
	}

	public function test_missing_alt_attribute_is_flagged_with_the_source(): void {
		$digest  = KarMCP_Content_Extractor::analyze( '<img src="/uploads/hero.jpg">' );
		$warning = $this->assertWarns( $digest, 'image_missing_alt' );

		$this->assertSame( array( '/uploads/hero.jpg' ), $warning['examples'] );
	}

	public function test_image_with_no_source_at_all_is_an_error(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<img alt="Nada">' );

		$this->assertSame( 'error', $this->assertWarns( $digest, 'image_no_source' )['severity'] );
	}

	public function test_lazy_loaded_image_without_src_is_not_reported_as_sourceless(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<img data-src="/uploads/lazy.jpg" alt="Cargada luego">' );

		$this->assertDoesNotWarn( $digest, 'image_no_source' );
	}

	// ---- links -------------------------------------------------------------

	public function test_placeholder_href_is_flagged(): void {
		$digest  = KarMCP_Content_Extractor::analyze( '<a href="#">Comprar ahora</a><a href="/ok">Bien</a>' );
		$warning = $this->assertWarns( $digest, 'link_placeholder' );

		$this->assertSame( array( 'Comprar ahora' ), $warning['examples'] );
	}

	public function test_link_with_no_readable_label_is_flagged(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<a href="/x"><i class="icon-cart"></i></a>' );

		$this->assertWarns( $digest, 'link_no_label' );
	}

	public function test_icon_link_is_labelled_by_its_image_alt(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<a href="/x"><img src="/cart.svg" alt="Ver carrito"></a>' );

		$this->assertDoesNotWarn( $digest, 'link_no_label' );
		$this->assertSame( 'Ver carrito', $digest['links'][0]['text'] );
	}

	public function test_aria_label_counts_as_the_accessible_name(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<a href="/x" aria-label="Abrir el menú"></a>' );

		$this->assertDoesNotWarn( $digest, 'link_no_label' );
	}

	// ---- empty containers --------------------------------------------------

	/**
	 * The signature bug of a machine-built page: a column that exists in the
	 * builder JSON and renders as blank space.
	 */
	public function test_empty_container_is_reported_with_its_class(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<div class="e-con columna-vacia"></div>' );

		$this->assertWarns( $digest, 'empty_container' );
		$this->assertSame( 'columna-vacia', explode( ' ', $digest['empty_containers'][0]['class'] )[1] );
	}

	public function test_container_holding_only_an_image_is_not_empty(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<div class="media"><img src="/a.png" alt="A"></div>' );

		$this->assertSame( array(), $digest['empty_containers'] );
	}

	/**
	 * A spacer whose whole job is a background image has no text and no child
	 * elements, and is entirely correct. Flagging it would put a permanent false
	 * positive on most hero sections.
	 */
	public function test_container_with_a_background_image_is_not_empty(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<div class="hero" style="background-image:url(/hero.jpg);height:400px"></div>' );

		$this->assertSame( array(), $digest['empty_containers'] );
	}

	/**
	 * Nested empties are one problem, not four. Reporting each level turns a
	 * single missing widget into a wall of findings.
	 */
	public function test_nested_empty_containers_report_only_the_outermost(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<section class="fuera"><div class="dentro"><div class="mas-dentro"></div></div></section>' );

		$this->assertCount( 1, $digest['empty_containers'] );
		$this->assertSame( 'section', $digest['empty_containers'][0]['tag'] );
	}

	// ---- leftovers ---------------------------------------------------------

	public function test_unresolved_shortcode_in_visible_text_is_flagged(): void {
		$digest  = KarMCP_Content_Extractor::analyze( '<p>[contact-form-7 id="42"]</p>' );
		$warning = $this->assertWarns( $digest, 'unresolved_shortcode' );

		$this->assertSame( array( 'contact-form-7' ), $warning['examples'] );
	}

	/**
	 * Inline JavaScript is full of things that look like leftovers. Reading it
	 * as page copy would flag half the sites on the internet.
	 */
	public function test_script_contents_are_not_read_as_page_text(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<p>Hola</p><script>var t = "{{ token }}"; var s = "[gallery]";</script>' );

		$this->assertDoesNotWarn( $digest, 'unrendered_placeholder' );
		$this->assertDoesNotWarn( $digest, 'unresolved_shortcode' );
		$this->assertSame( 'Hola', $digest['text']['excerpt'] );
	}

	public function test_template_placeholder_left_in_the_copy_is_flagged(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<p>Hola {{ nombre }}, bienvenido</p>' );

		$this->assertWarns( $digest, 'unrendered_placeholder' );
	}

	public function test_filler_copy_is_reported_as_information_not_a_defect(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<p>Lorem ipsum dolor sit amet</p>' );

		$this->assertSame( 'info', $this->assertWarns( $digest, 'placeholder_text' )['severity'] );
	}

	// ---- forms -------------------------------------------------------------

	public function test_form_without_a_submit_control_is_an_error(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<form action="/enviar"><input type="text" name="n" aria-label="Nombre"></form>' );

		$this->assertSame( 'error', $this->assertWarns( $digest, 'form_no_submit' )['severity'] );
		$this->assertFalse( $digest['forms'][0]['has_submit'] );
	}

	public function test_unlabelled_form_field_is_flagged(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<form><input type="text" name="n"><button type="submit">Enviar</button></form>' );

		$this->assertWarns( $digest, 'form_unlabeled_fields' );
		$this->assertSame( 1, $digest['forms'][0]['unlabeled_fields'] );
	}

	public function test_label_for_and_placeholder_both_count_as_labelling(): void {
		$html = '<form><label for="n">Nombre</label><input type="text" id="n">'
			. '<input type="email" placeholder="Correo"><button type="submit">Enviar</button></form>';

		$digest = KarMCP_Content_Extractor::analyze( $html );

		$this->assertSame( 0, $digest['forms'][0]['unlabeled_fields'] );
	}

	public function test_hidden_fields_are_not_expected_to_be_labelled(): void {
		$html = '<form><input type="hidden" name="_nonce" value="x"><button type="submit">Enviar</button></form>';

		$digest = KarMCP_Content_Extractor::analyze( $html );

		$this->assertSame( 0, $digest['forms'][0]['unlabeled_fields'] );
	}

	// ---- ids, documents, text ----------------------------------------------

	public function test_duplicate_ids_are_flagged(): void {
		$digest  = KarMCP_Content_Extractor::analyze( '<div id="hero"></div><div id="hero"><p>x</p></div>' );
		$warning = $this->assertWarns( $digest, 'duplicate_id' );

		$this->assertSame( array( 'hero' ), $warning['examples'] );
	}

	public function test_document_level_metadata_is_read_from_a_full_page(): void {
		$html = '<html lang="es"><head><title>Inicio</title>'
			. '<meta name="description" content="Una descripción">'
			. '<link rel="canonical" href="https://ejemplo.test/"></head><body><h1>Hola</h1></body></html>';

		$digest = KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full' ) );

		$this->assertSame( 'Inicio', $digest['document']['title'] );
		$this->assertSame( 'Una descripción', $digest['document']['meta_description'] );
		$this->assertSame( 'https://ejemplo.test/', $digest['document']['canonical'] );
		$this->assertSame( 'es', $digest['document']['lang'] );
	}

	/**
	 * Open Graph is declared with `property`, but enough plugins emit it as
	 * `name` that reading only one of them misses real tags.
	 */
	public function test_og_image_is_read_from_either_attribute(): void {
		$property = KarMCP_Content_Extractor::analyze(
			'<html><head><meta property="og:image" content="https://ejemplo.test/a.jpg"></head><body><p>x</p></body></html>',
			array( 'scope' => 'full' )
		);
		$this->assertSame( 'https://ejemplo.test/a.jpg', $property['document']['og_image'] );

		$name = KarMCP_Content_Extractor::analyze(
			'<html><head><meta name="og:image" content="https://ejemplo.test/b.jpg"></head><body><p>x</p></body></html>',
			array( 'scope' => 'full' )
		);
		$this->assertSame( 'https://ejemplo.test/b.jpg', $name['document']['og_image'] );
	}

	/**
	 * Prose is the paragraphs, not everything a visitor can read. A real
	 * homepage scored 8/100 for readability off its own navigation menu, so
	 * this separation is the whole point.
	 */
	public function test_prose_excludes_navigation_headers_and_footers(): void {
		// A full document has to declare its own charset: libxml assumes
		// ISO-8859-1 otherwise and every accent comes back mangled. Only a
		// fragment gets one supplied by the extractor.
		$html = '<html><head><meta charset="utf-8"></head><body>'
			. '<header><p>Cabecera</p></header>'
			. '<nav><p>Servicios Portafolio Blog</p></nav>'
			. '<main><p>Este es el texto de verdad.</p><p>Y un segundo párrafo.</p></main>'
			. '<aside><p>Barra lateral</p></aside>'
			. '<footer><p>Pie de página</p></footer>'
			. '</body></html>';

		$digest = KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full', 'excerpt_chars' => 2000 ) );

		$this->assertSame( "Este es el texto de verdad.\nY un segundo párrafo.", $digest['text']['prose'] );
		$this->assertStringNotContainsString( 'Cabecera', $digest['text']['prose'] );
		$this->assertStringNotContainsString( 'Pie de página', $digest['text']['prose'] );
	}

	public function test_prose_counts_its_own_words_separately_from_visible_text(): void {
		// The whitespace between the blocks is deliberate: `textContent`
		// concatenates without a separator, so on minified markup the last word
		// of one block and the first of the next glue into one. Real pages are
		// indented; see the note on minified HTML in the roadmap.
		$html = '<html><body><nav><p>uno dos tres cuatro</p></nav> <main><p>cinco seis</p></main></body></html>';

		$digest = KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full', 'excerpt_chars' => 2000 ) );

		$this->assertSame( 6, $digest['text']['words'] );
		$this->assertSame( 2, $digest['text']['prose_words'] );
	}

	public function test_a_page_with_no_paragraphs_has_no_prose(): void {
		$digest = KarMCP_Content_Extractor::analyze(
			'<html><body><h2>Solo titulares</h2><div>y cajas</div></body></html>',
			array( 'scope' => 'full', 'excerpt_chars' => 2000 )
		);

		$this->assertSame( '', $digest['text']['prose'] );
		$this->assertSame( 0, $digest['text']['prose_words'] );
	}

	public function test_og_image_is_absent_when_the_page_declares_none(): void {
		$digest = KarMCP_Content_Extractor::analyze(
			'<html><head><title>x</title></head><body><p>x</p></body></html>',
			array( 'scope' => 'full' )
		);

		$this->assertArrayNotHasKey( 'og_image', $digest['document'] );
	}

	/**
	 * A fragment is loaded without a declared charset, and libxml then assumes
	 * ISO-8859-1. On a Spanish-language site that mangles roughly every other
	 * heading, and it does it silently.
	 */
	public function test_accented_text_survives_parsing(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<h1>Cómo diseñamos päginas</h1>' );

		$this->assertSame( 'Cómo diseñamos päginas', $digest['headings'][0]['text'] );
	}

	public function test_word_count_and_excerpt_come_from_visible_text(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<style>.a{color:red}</style><p>Una frase de cinco palabras</p>' );

		$this->assertSame( 5, $digest['text']['words'] );
		$this->assertSame( 'Una frase de cinco palabras', $digest['text']['excerpt'] );
	}

	public function test_excerpt_is_truncated_to_the_requested_length(): void {
		$digest = KarMCP_Content_Extractor::analyze(
			'<p>' . str_repeat( 'palabra ', 100 ) . '</p>',
			array( 'excerpt_chars' => 20 )
		);

		$this->assertSame( 21, mb_strlen( $digest['text']['excerpt'] ) ); // 20 + the ellipsis.
	}

	public function test_landmarks_are_counted(): void {
		$digest = KarMCP_Content_Extractor::analyze( '<header></header><main><section><p>x</p></section></main><footer><p>y</p></footer>' );

		$this->assertSame( 1, $digest['landmarks']['main'] );
		$this->assertSame( 1, $digest['landmarks']['section'] );
		$this->assertSame( 0, $digest['landmarks']['aside'] );
	}
	// ---- the access wall ---------------------------------------------------

	/**
	 * The worst result this tool can return is a confident one about a page it
	 * never saw. `scope: full` goes over a loopback request, a loopback carries
	 * no session, and a site behind an access wall answers it with its sign-in
	 * form and a 200 — so "rendered fine" is exactly what a caller checking
	 * their work would read, and it would be wrong.
	 */
	public function test_a_login_screen_under_full_scope_is_an_error(): void {
		$html = '<body class="login"><form action="/wp-login.php" method="post">'
			. '<label for="user_login">Usuario</label><input id="user_login" type="text">'
			. '<label for="user_pass">Contraseña</label><input id="user_pass" type="password">'
			. '<input type="submit" value="Acceder"></form></body>';

		$digest = KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full' ) );

		$warning = $this->assertWarns( $digest, 'access_wall' );
		$this->assertSame( 'error', $warning['severity'] );
		$this->assertTrue( $digest['access_wall'] );
	}

	/**
	 * Under `content` the markup was rendered directly, so a password field is
	 * just what the page holds. Worth saying, not worth calling an error.
	 */
	public function test_a_password_field_under_content_scope_is_only_a_note(): void {
		$html = '<form><label for="p">Clave</label><input id="p" type="password">'
			. '<input type="submit" value="Entrar"></form>';

		$digest = KarMCP_Content_Extractor::analyze( $html );

		$warning = $this->assertWarns( $digest, 'access_wall' );
		$this->assertSame( 'info', $warning['severity'] );
	}

	/**
	 * And it has to stay quiet on the pages this tool is actually pointed at,
	 * or it becomes one more warning nobody reads.
	 */
	public function test_an_ordinary_page_raises_no_access_wall(): void {
		$html = '<main><h1>Módulo 1</h1><p>Contenido del curso.</p>'
			. '<form><label for="q">Tu respuesta</label><input id="q" type="text">'
			. '<input type="submit" value="Enviar"></form></main>';

		$digest = KarMCP_Content_Extractor::analyze( $html, array( 'scope' => 'full' ) );

		$this->assertNotContains( 'access_wall', $this->codes( $digest ) );
		$this->assertFalse( $digest['access_wall'] );
	}
}
