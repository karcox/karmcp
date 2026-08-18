<?php
/**
 * Writing a skill through the API instead of through the editor.
 *
 * The operation that carries this tool is `edit`: a skill of any real size
 * cannot be re-sent whole in a tool call, so replacing the whole body is exactly
 * the operation that stops working at the size where you need it. What these
 * tests pin is therefore the search-and-replace contract — and above all the
 * refusal to guess, because an edit applied to the wrong one of two identical
 * strings is a silent corruption of the document every agent on the site reads.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/skills/class-skill-store.php';
require_once dirname( __DIR__ ) . '/includes/abilities/trait-operation-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/abilities/class-skill-write-abilities.php';

class SkillWriteTest extends TestCase {

	/** @var KarMCP_Skill_Write_Abilities */
	private $tool;

	protected function setUp(): void {
		karmcp_test_reset();

		$GLOBALS['karmcp_test']['posts'][55] = new WP_Post(
			array(
				'ID'           => 55,
				'post_title'   => 'Montar un curso',
				'post_name'    => 'montar-curso',
				'post_type'    => 'karmcp_skill',
				'post_status'  => 'publish',
				'post_content' => "# Guia\n\nUna linea con <p> dentro.\n\n## Seccion\n\nRepetido. Repetido.\n",
			)
		);

		// find() resolves a name through get_posts(), which the harness serves
		// from cpt_posts; get_post() reads from posts. Same object in both, so a
		// write through either path is visible to the other.
		$GLOBALS['karmcp_test']['cpt_posts']['karmcp_skill'] = array( $GLOBALS['karmcp_test']['posts'][55] );

		$this->tool = new KarMCP_Skill_Write_Abilities();
	}

	private function body(): string {
		return $GLOBALS['karmcp_test']['posts'][55]->post_content;
	}

	// ---- the uniqueness contract -------------------------------------------

	public function test_a_unique_string_is_replaced(): void {
		$out = $this->tool->op_edit(
			array(
				'post_id'    => 55,
				'old_string' => '## Seccion',
				'new_string' => '## Seccion nueva',
			)
		);

		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( 1, $out['replaced'] );
		$this->assertStringContainsString( '## Seccion nueva', $this->body() );
	}

	/**
	 * Two matches means the caller is thinking of one of them and cannot say
	 * which. Guessing here would corrupt the document quietly, so it refuses.
	 */
	public function test_an_ambiguous_string_is_refused(): void {
		$before = $this->body();

		$out = $this->tool->op_edit(
			array(
				'post_id'    => 55,
				'old_string' => 'Repetido.',
				'new_string' => 'Cambiado.',
			)
		);

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'not_unique', $out->get_error_code() );
		$this->assertStringContainsString( '2 times', $out->get_error_message() );
		$this->assertSame( $before, $this->body(), 'Nothing may be written when the edit is refused.' );
	}

	public function test_replace_all_takes_every_occurrence(): void {
		$out = $this->tool->op_edit(
			array(
				'post_id'     => 55,
				'old_string'  => 'Repetido.',
				'new_string'  => 'Cambiado.',
				'replace_all' => true,
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 2, $out['replaced'] );
		$this->assertStringNotContainsString( 'Repetido.', $this->body() );
	}

	public function test_a_string_that_is_not_there_is_an_error(): void {
		$out = $this->tool->op_edit(
			array(
				'post_id'    => 55,
				'old_string' => 'no existe en el cuerpo',
				'new_string' => 'x',
			)
		);

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'not_found', $out->get_error_code() );
	}

	public function test_identical_strings_are_refused(): void {
		$out = $this->tool->op_edit(
			array(
				'post_id'    => 55,
				'old_string' => 'igual',
				'new_string' => 'igual',
			)
		);

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'no_change', $out->get_error_code() );
	}

	// ---- the reason the tool exists ----------------------------------------

	/**
	 * The repair case, stated as a test: undoing one level of escaping across a
	 * document. This is what could not be done by hand, because the editor put
	 * the escaping back on every save.
	 */
	public function test_a_whole_document_can_be_unescaped_in_one_call(): void {
		$GLOBALS['karmcp_test']['posts'][55]->post_content = 'Usa &amp;lt;p&amp;gt; y &amp;lt;div&amp;gt; en el ejemplo.';

		$out = $this->tool->op_edit(
			array(
				'post_id'     => 55,
				'old_string'  => '&amp;lt;',
				'new_string'  => '&lt;',
				'replace_all' => true,
			)
		);

		$this->assertSame( 2, $out['replaced'] );
		$this->assertStringNotContainsString( '&amp;lt;', $this->body() );
		$this->assertStringContainsString( '&lt;p', $this->body() );
	}

	/** Angle brackets and backslashes survive the round trip untouched. */
	public function test_markdown_is_stored_verbatim(): void {
		$markdown = "Ejemplo: `<p>` y una ruta C:\\ruta\\archivo y \\u00e1 literal.";

		$out = $this->tool->op_edit(
			array(
				'post_id'    => 55,
				'old_string' => '# Guia',
				'new_string' => $markdown,
			)
		);

		$this->assertIsArray( $out );
		$this->assertStringContainsString( $markdown, $this->body() );
	}

	// ---- resolving the target ----------------------------------------------

	public function test_a_skill_can_be_addressed_by_name(): void {
		$out = $this->tool->op_edit(
			array(
				'name'       => 'montar-curso',
				'old_string' => '## Seccion',
				'new_string' => '## Otra',
			)
		);

		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( 'montar-curso', $out['name'] );
		$this->assertSame( 55, $out['post_id'] );
	}

	public function test_an_unknown_name_is_an_error(): void {
		$out = $this->tool->op_edit(
			array(
				'name'       => 'no-existe',
				'old_string' => 'x',
				'new_string' => 'y',
			)
		);

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'not_found', $out->get_error_code() );
	}

	public function test_a_post_of_another_type_is_not_a_skill(): void {
		$GLOBALS['karmcp_test']['posts'][77] = new WP_Post(
			array(
				'ID'        => 77,
				'post_type' => 'page',
				'post_name' => 'una-pagina',
			)
		);

		$out = $this->tool->op_edit(
			array(
				'post_id'    => 77,
				'old_string' => 'x',
				'new_string' => 'y',
			)
		);

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'not_found', $out->get_error_code() );
	}

	// ---- update ------------------------------------------------------------

	/**
	 * Replacing a body wholesale overwrites work nobody necessarily re-read
	 * first, so it has to be asked for twice.
	 */
	public function test_replacing_the_body_needs_confirmation(): void {
		$before = $this->body();

		$out = $this->tool->op_update(
			array(
				'post_id' => 55,
				'body'    => 'todo nuevo',
			)
		);

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'confirmation_required', $out->get_error_code() );
		$this->assertSame( $before, $this->body() );
	}

	public function test_replacing_the_body_with_confirmation_goes_through(): void {
		$out = $this->tool->op_update(
			array(
				'post_id' => 55,
				'body'    => 'todo nuevo',
				'confirm' => true,
			)
		);

		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( 'todo nuevo', $this->body() );
	}

	/** Changing a summary must not need confirmation, and must not touch the body. */
	public function test_updating_the_summary_leaves_the_body_alone(): void {
		$before = $this->body();

		$out = $this->tool->op_update(
			array(
				'post_id' => 55,
				'summary' => 'Un resumen nuevo',
			)
		);

		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( $before, $this->body() );
	}

	public function test_an_update_with_no_fields_is_refused(): void {
		$out = $this->tool->op_update( array( 'post_id' => 55 ) );

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'nothing_to_do', $out->get_error_code() );
	}

	// ---- create ------------------------------------------------------------

	public function test_creating_needs_a_machine_name(): void {
		$out = $this->tool->op_create( array( 'title' => 'Sin nombre' ) );

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'missing_name', $out->get_error_code() );
	}

	public function test_creating_a_name_that_exists_is_refused(): void {
		$out = $this->tool->op_create( array( 'name' => 'montar-curso' ) );

		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'already_exists', $out->get_error_code() );
	}
}
