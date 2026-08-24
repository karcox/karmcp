<?php
/**
 * The two halves of an `html-v3` value have to agree.
 *
 * Such a value stores the same text twice: as markup in `content`, and as a node
 * tree in `children` that the editor's rich-text control renders from. Writing
 * the markup beside an empty tree produced an element that rendered perfectly on
 * the front end, reported success, and opened EMPTY in the editor. Nothing in
 * the saved page looked wrong, which is why it could go unnoticed across a whole
 * site (upstream #121).
 *
 * These tests assert on the RELATIONSHIP between the two halves rather than on
 * exact ids, because the ids are generated and the bug was never about their
 * value — it was about one half being built and the other left blank.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-atomic-props.php';

class AtomicRichTextTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/**
	 * @param string $text Rich-text markup.
	 * @return array The html-v3 inner value.
	 */
	private function value( string $text ): array {
		return KarMCP_Atomic_Props::html( $text )['value'];
	}

	// -----------------------------------------------------------------
	// The regression
	// -----------------------------------------------------------------

	public function test_an_inline_tag_produces_a_matching_child_node(): void {
		$value = $this->value( 'Hello <b>world</b>' );

		$this->assertCount( 1, $value['children'] );
		$this->assertSame( 'b', $value['children'][0]['type'] );
		$this->assertSame( 'world', $value['children'][0]['content'] );
	}

	/**
	 * The id the parser invents has to reach BOTH halves, or the editor cannot
	 * match a node to the markup it came from.
	 */
	public function test_the_generated_id_is_written_into_the_markup_too(): void {
		$value = $this->value( 'Hello <b>world</b>' );
		$id    = $value['children'][0]['id'];

		$this->assertNotSame( '', $id );
		$this->assertStringContainsString( $id, $value['content']['value'] );
	}

	public function test_an_id_the_author_already_set_is_kept(): void {
		$value = $this->value( 'Hi <strong id="mine">there</strong>' );

		$this->assertSame( 'mine', $value['children'][0]['id'] );
	}

	public function test_nested_formatting_is_preserved(): void {
		$value = $this->value( '<strong>bold <em>and italic</em></strong>' );

		$this->assertCount( 1, $value['children'] );
		$this->assertSame( 'strong', $value['children'][0]['type'] );
		$this->assertCount( 1, $value['children'][0]['children'] );
		$this->assertSame( 'em', $value['children'][0]['children'][0]['type'] );
	}

	/**
	 * A block wrapper is not a rich-text node, so it contributes what is inside it
	 * and nothing of its own.
	 */
	public function test_a_block_wrapper_contributes_its_inline_descendants(): void {
		$value = $this->value( '<div><a href="/x">link</a></div>' );

		$this->assertCount( 1, $value['children'] );
		$this->assertSame( 'a', $value['children'][0]['type'] );
	}

	public function test_several_siblings_are_all_recorded(): void {
		$value = $this->value( '<b>one</b> and <i>two</i>' );

		$this->assertSame(
			array( 'b', 'i' ),
			array_column( $value['children'], 'type' )
		);
	}

	// -----------------------------------------------------------------
	// Text that has no structure keeps its exact bytes
	// -----------------------------------------------------------------

	public function test_plain_text_is_left_exactly_as_it_was(): void {
		$value = $this->value( 'Just text' );

		$this->assertSame( 'Just text', $value['content']['value'] );
		$this->assertSame( array(), $value['children'] );
	}

	public function test_an_empty_string_stays_empty(): void {
		$value = $this->value( '' );

		$this->assertSame( '', $value['content']['value'] );
		$this->assertSame( array(), $value['children'] );
	}

	/**
	 * libxml reads its input as ISO-8859-1, so a round-trip that does not encode
	 * first turns every accent into mojibake. Spanish copy is the common case
	 * here, so it is the case that gets the test.
	 */
	public function test_accented_text_survives_the_dom_round_trip(): void {
		$value = $this->value( 'Introducción a la <b>programación</b>' );

		$this->assertStringContainsString( 'Introducción', $value['content']['value'] );
		$this->assertStringContainsString( 'programación', $value['content']['value'] );
		$this->assertSame( 'programación', $value['children'][0]['content'] );
	}

	public function test_an_emoji_survives_the_dom_round_trip(): void {
		$value = $this->value( 'Vamos <b>allá</b> 🚀' );

		$this->assertStringContainsString( '🚀', $value['content']['value'] );
	}

	// -----------------------------------------------------------------
	// The other two doors into the same value
	// -----------------------------------------------------------------

	/**
	 * The second door: a caller passing the inner shape as a bare array. It has
	 * to derive the tree exactly like html() does, or the same value written the
	 * other way is damaged in the same way.
	 *
	 * @return array The html-v3 candidate's inner value.
	 */
	private function candidate_value( array $inner ): array {
		$candidates = AtomicPropsProbe::candidates( new stdClass(), $inner );
		foreach ( $candidates as $candidate ) {
			if ( 'html-v3' === ( $candidate['$$type'] ?? '' ) ) {
				return $candidate['value'];
			}
		}
		$this->fail( 'No html-v3 candidate was produced.' );
	}

	public function test_the_array_form_derives_the_tree_too(): void {
		$value = $this->candidate_value( array( 'content' => 'Hello <b>world</b>' ) );

		$this->assertCount( 1, $value['children'] );
		$this->assertSame( 'b', $value['children'][0]['type'] );
	}

	/**
	 * A caller that supplies its own tree keeps it. Deriving one anyway would
	 * silently replace a tree that was built on purpose.
	 */
	public function test_a_caller_supplied_tree_is_kept(): void {
		$own   = array( array( 'id' => 'keep-me', 'type' => 'b' ) );
		$value = $this->candidate_value(
			array(
				'content'  => 'Hello <b>world</b>',
				'children' => $own,
			)
		);

		$this->assertSame( $own, $value['children'] );
	}
}

/**
 * Reaches the protected candidate builder. The html-v3 branch under test sits in
 * that method's fallback section, which runs for any prop object.
 */
class AtomicPropsProbe extends KarMCP_Atomic_Props {

	/**
	 * @param object $prop  Any prop type.
	 * @param mixed  $value Raw value.
	 * @return array
	 */
	public static function candidates( $prop, $value ): array {
		return self::candidates_for( $prop, $value );
	}
}
