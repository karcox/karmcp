<?php
/**
 * Agent Skills — the discovery index and the get-skill lookup.
 *
 * What is pinned here is the economics as much as the correctness: the index
 * carries names and summaries, never bodies. A regression that starts shipping
 * bodies would not fail anything visibly — it would just quietly tax every
 * connection to every site, which is exactly the kind of bug nobody notices.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/skills/class-skill-store.php';
require_once __DIR__ . '/../includes/skills/class-skill-catalog.php';

class SkillCatalogTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
		karmcp_test_reset_hooks();
	}

	/** Build a skill post the way the store reads it back. */
	private function skill( string $name, string $title, string $summary, string $body = '' ): WP_Post {
		return new WP_Post(
			array(
				'post_name'    => $name,
				'post_title'   => $title,
				'post_excerpt' => $summary,
				'post_content' => $body,
			)
		);
	}

	// ---- the index ----------------------------------------------------------

	public function test_no_skills_means_no_block(): void {
		$this->assertSame( '', KarMCP_Skill_Catalog::block( array() ) );
	}

	/**
	 * With nothing published the seam must come back untouched — an agent should
	 * not pay for a "## Skills" heading over an empty list.
	 */
	public function test_inject_leaves_the_seam_alone_when_there_are_no_skills(): void {
		$this->assertSame( '', KarMCP_Skill_Catalog::inject( '' ) );
		$this->assertSame( '## Something', KarMCP_Skill_Catalog::inject( '## Something' ) );
	}

	public function test_block_lists_name_title_and_summary(): void {
		$block = KarMCP_Skill_Catalog::block(
			array(
				array( 'name' => 'landing-pages', 'title' => 'Landing pages', 'summary' => 'Structure and tone for a landing.' ),
				array( 'name' => 'publishing', 'title' => 'Publishing', 'summary' => 'What to check before going live.' ),
			)
		);

		$this->assertStringContainsString( '## Skills', $block );
		$this->assertStringContainsString( '`landing-pages`', $block );
		$this->assertStringContainsString( 'Structure and tone for a landing.', $block );
		$this->assertStringContainsString( '`publishing`', $block );
		$this->assertStringContainsString( 'get-skill', $block, 'The agent must be told how to fetch a body.' );
	}

	/**
	 * The index is the cheap half of the deal. If a body ever leaks into it, the
	 * whole reason for having two seams is gone.
	 */
	public function test_block_never_carries_a_body(): void {
		$block = KarMCP_Skill_Catalog::block(
			array(
				array(
					'name'    => 'landing-pages',
					'title'   => 'Landing pages',
					'summary' => 'Structure and tone.',
					'body'    => 'SECRET_BODY_MARKER: the full instructions live here.',
				),
			)
		);

		$this->assertStringNotContainsString( 'SECRET_BODY_MARKER', $block );
	}

	public function test_block_survives_a_skill_with_no_summary(): void {
		$block = KarMCP_Skill_Catalog::block( array( array( 'name' => 'bare', 'title' => 'Bare', 'summary' => '' ) ) );
		$this->assertStringContainsString( '`bare`', $block );
		$this->assertStringContainsString( 'Bare', $block );
	}

	// ---- the store's two shapes ---------------------------------------------

	public function test_summarize_omits_the_body_and_expand_includes_it(): void {
		$skill = $this->skill( 'publishing', 'Publishing', 'What to check.', 'The long body.' );

		$summary = KarMCP_Skill_Store::summarize( $skill );
		$this->assertSame( array( 'name', 'title', 'summary' ), array_keys( $summary ) );
		$this->assertArrayNotHasKey( 'body', $summary );

		$full = KarMCP_Skill_Store::expand( $skill );
		$this->assertSame( 'The long body.', $full['body'] );
		$this->assertSame( 'publishing', $full['name'] );
	}

	// ---- the seam is wired --------------------------------------------------

	public function test_init_registers_the_discovery_filter(): void {
		KarMCP_Skill_Catalog::init();
		$this->assertArrayHasKey( 'karmcp_discovery_skills', $GLOBALS['karmcp_test_hooks'] );
	}

	/** Publish skills into the fixture so the store can read them back. */
	private function publish( WP_Post ...$skills ): void {
		$GLOBALS['karmcp_test']['cpt_posts'][ KarMCP_Skill_Store::POST_TYPE ] = $skills;
	}

	// ---- end to end, through the store --------------------------------------

	public function test_index_reads_published_skills(): void {
		$this->publish(
			$this->skill( 'landing-pages', 'Landing pages', 'Structure and tone.', 'Body one.' ),
			$this->skill( 'publishing', 'Publishing', 'Pre-flight checks.', 'Body two.' )
		);

		$index = KarMCP_Skill_Catalog::index();
		$this->assertCount( 2, $index );
		$this->assertSame( 'landing-pages', $index[0]['name'] );
		$this->assertArrayNotHasKey( 'body', $index[0] );
	}

	/** Skills append to whatever an earlier listener produced, never replace it. */
	public function test_inject_appends_without_clobbering(): void {
		$this->publish( $this->skill( 'publishing', 'Publishing', 'Pre-flight checks.', 'Body.' ) );

		$appended = KarMCP_Skill_Catalog::inject( '## Earlier' );
		$this->assertStringStartsWith( '## Earlier', $appended );
		$this->assertStringContainsString( '`publishing`', $appended );
		$this->assertStringNotContainsString( 'Body.', $appended );
	}

	public function test_find_resolves_by_machine_name(): void {
		$this->publish( $this->skill( 'publishing', 'Publishing', 'Pre-flight checks.', 'The body.' ) );

		$found = KarMCP_Skill_Store::find( 'publishing' );
		$this->assertInstanceOf( WP_Post::class, $found );
		$this->assertSame( 'The body.', KarMCP_Skill_Store::expand( $found )['body'] );

		$this->assertNull( KarMCP_Skill_Store::find( 'does-not-exist' ) );
		$this->assertNull( KarMCP_Skill_Store::find( '' ) );
	}

	/** A name typed with capitals or spaces still resolves. */
	public function test_find_normalises_the_requested_name(): void {
		$this->publish( $this->skill( 'landing-pages', 'Landing pages', 'Summary.' ) );
		$this->assertInstanceOf( WP_Post::class, KarMCP_Skill_Store::find( 'Landing Pages' ) );
	}
}
