<?php
/**
 * A skill too long to return is returned as an outline.
 *
 * The site's manual is 90 KB and grows with every fix it documents, so
 * get-skill stopped being able to answer at all: not a truncation, a failed
 * call. The reader then dumps the JSON to a file, pulls the headings out with a
 * regex and slices it by character offsets before reading a word — and every
 * agent that starts work pays that, because the skill is required reading
 * before touching anything.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/skills/class-skill-outline.php';

class SkillOutlineTest extends TestCase {

	/**
	 * Shaped like the real one: numbered chapters, numbered subsections, and a
	 * heading with no number at all.
	 *
	 * @return string
	 */
	private function body(): string {
		return implode(
			"\n",
			array(
				'# Montar un curso',
				'Intro paragraph.',
				'',
				'## Las cuatro reglas que no se rompen',
				'Rule one.',
				'',
				'## 1. Anatomia del item',
				'Chapter opening.',
				'',
				'### 1.1 Identidad JetPopup',
				'Identity detail.',
				'',
				'### 1.5 El bloque de navegacion',
				'Nav detail.',
				'',
				'## 2. La portada',
				'Cover detail.',
			)
		) . "\n";
	}

	public function test_the_outline_lists_every_heading_with_an_id_and_a_size(): void {
		$outline = KarMCP_Skill_Outline::outline( $this->body() );

		$this->assertSame(
			array( 'las-cuatro-reglas-que-no-se-rompen', '1', '1.1', '1.5', '2' ),
			array_column( $outline, 'id' )
		);
		$this->assertGreaterThan( 0, $outline[0]['chars'] );
	}

	/**
	 * The number is the id because a manual cross-references itself that way
	 * ("see 1.5"), so it is what a reader tries first.
	 */
	public function test_a_numbered_section_is_addressed_by_its_number(): void {
		$found = KarMCP_Skill_Outline::section( $this->body(), '1.5' );

		$this->assertNotNull( $found );
		$this->assertStringContainsString( 'Nav detail.', $found['text'] );
		$this->assertStringNotContainsString( 'Cover detail.', $found['text'] );
	}

	/**
	 * Asking for a chapter has to bring its subsections, or "read section 1"
	 * returns one paragraph and the reader concludes the manual is empty.
	 */
	public function test_a_chapter_includes_its_subsections(): void {
		$found = KarMCP_Skill_Outline::section( $this->body(), '1' );

		$this->assertStringContainsString( 'Identity detail.', $found['text'] );
		$this->assertStringContainsString( 'Nav detail.', $found['text'] );
		// And stops at the next chapter.
		$this->assertStringNotContainsString( 'Cover detail.', $found['text'] );
	}

	/**
	 * Whatever the reader copies out of the outline should work: the id, the
	 * whole title, or the start of it.
	 */
	public function test_a_section_can_be_named_by_title_or_by_its_start(): void {
		$byTitle  = KarMCP_Skill_Outline::section( $this->body(), '1.5 El bloque de navegacion' );
		$byPrefix = KarMCP_Skill_Outline::section( $this->body(), 'Las cuatro reglas' );

		$this->assertSame( '1.5', $byTitle['id'] );
		$this->assertStringContainsString( 'Rule one.', $byPrefix['text'] );
	}

	public function test_an_unnumbered_heading_gets_a_slug(): void {
		$found = KarMCP_Skill_Outline::section( $this->body(), 'las-cuatro-reglas-que-no-se-rompen' );

		$this->assertNotNull( $found );
		$this->assertStringContainsString( 'Rule one.', $found['text'] );
	}

	public function test_an_unknown_section_is_null_rather_than_a_guess(): void {
		$this->assertNull( KarMCP_Skill_Outline::section( $this->body(), '9.9' ) );
		$this->assertNull( KarMCP_Skill_Outline::section( $this->body(), '' ) );
	}

	/**
	 * A body with no headings at all must not come back as an empty outline —
	 * that would read as "this skill is blank" when it is merely unstructured.
	 */
	public function test_a_body_without_headings_yields_an_empty_outline(): void {
		$this->assertSame( array(), KarMCP_Skill_Outline::outline( "Just prose.\nNo headings here.\n" ) );
	}

	/**
	 * Real manuals do not number as tidily as the regex assumes: this site's has
	 * "4. Bloques con solucion" and "4 bis. Multimedia" as separate chapters, and
	 * both reduce to "4". Found on the first real call, where the outline listed
	 * two sections with the same id — and the second was unreachable, since every
	 * request landed on the first.
	 */
	public function test_two_headings_that_reduce_to_the_same_number_get_distinct_ids(): void {
		$body = "## 4. Bloques con solucion
First chapter.

## 4 bis. Multimedia
Second chapter.
";

		$ids = array_column( KarMCP_Skill_Outline::outline( $body ), 'id' );

		$this->assertCount( 2, array_unique( $ids ), 'Both chapters must be addressable.' );
		$this->assertSame( '4', $ids[0], 'The first claimant keeps the plain number.' );
	}

	/**
	 * And the one that had to give up its number is still reachable — by the id
	 * it was given, and by its title.
	 */
	public function test_the_displaced_section_is_reachable_both_ways(): void {
		$body = "## 4. Bloques con solucion
First chapter.

## 4 bis. Multimedia
Second chapter.
";

		$ids   = array_column( KarMCP_Skill_Outline::outline( $body ), 'id' );
		$byId  = KarMCP_Skill_Outline::section( $body, $ids[1] );
		$byTtl = KarMCP_Skill_Outline::section( $body, '4 bis. Multimedia' );

		$this->assertStringContainsString( 'Second chapter.', $byId['text'] );
		$this->assertStringContainsString( 'Second chapter.', $byTtl['text'] );
	}
}
