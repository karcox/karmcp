<?php
/**
 * Public unit tests for KarMCP_Redirect_Abilities — the DB-free surface:
 * ability registration (names + category) and the suggestion queue. CRUD
 * execution + find-broken-links classification are covered by dedicated tests
 * and the live smoke.
 *
 * @package KarMCP
 */

class RedirectAbilitiesTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	public function test_register_declares_all_slugs_with_category() {
		$g = new KarMCP_Redirect_Abilities();
		$g->register();
		$names = $g->get_ability_names();
		$this->assertContains( 'karmcp/list-redirects', $names );
		$this->assertContains( 'karmcp/create-redirect', $names );
		$this->assertContains( 'karmcp/update-redirect', $names );
		$this->assertContains( 'karmcp/delete-redirect', $names );
		$this->assertContains( 'karmcp/find-broken-links', $names );
		// Every registered ability MUST carry category => karmcp.
		foreach ( $names as $n ) {
			$this->assertSame( 'karmcp', $GLOBALS['karmcp_test']['abilities'][ $n ]['category'] ?? null, "$n missing category" );
		}
	}

	public function test_delete_redirect_is_badged_destructive() {
		$g = new KarMCP_Redirect_Abilities();
		$g->register();
		$meta = $GLOBALS['karmcp_test']['abilities']['karmcp/delete-redirect']['meta']['annotations'] ?? array();
		$this->assertTrue( $meta['destructive'] ?? false );
	}

	public function test_push_suggestion_appends_and_dedupes() {
		KarMCP_Redirect_Abilities::push_suggestion( '/old-a', 'post-deleted', 5 );
		KarMCP_Redirect_Abilities::push_suggestion( '/old-b', 'slug-changed', 6 );
		$q = get_option( 'karmcp_redirect_suggestions', array() );
		$this->assertCount( 2, $q );
		// Re-pushing /old-a de-dupes (newest wins) → still 2, /old-a last.
		KarMCP_Redirect_Abilities::push_suggestion( '/old-a', 'post-deleted', 5 );
		$q = get_option( 'karmcp_redirect_suggestions', array() );
		$this->assertCount( 2, $q );
		$this->assertSame( '/old-a', $q[ count( $q ) - 1 ]['old_path'] );
	}

	public function test_push_suggestion_caps_queue() {
		for ( $i = 0; $i < 60; $i++ ) {
			KarMCP_Redirect_Abilities::push_suggestion( '/p' . $i, 'post-deleted', $i );
		}
		$q = get_option( 'karmcp_redirect_suggestions', array() );
		$this->assertCount( 50, $q );
		// FIFO: the oldest were dropped, the newest kept.
		$this->assertSame( '/p59', $q[ count( $q ) - 1 ]['old_path'] );
	}

	public function test_push_suggestion_ignores_root_and_empty() {
		KarMCP_Redirect_Abilities::push_suggestion( '/', 'post-deleted', 1 );
		KarMCP_Redirect_Abilities::push_suggestion( '', 'post-deleted', 2 );
		$this->assertSame( array(), get_option( 'karmcp_redirect_suggestions', array() ) );
	}

	public function test_classify_href_dead_redirected_ok_external() {
		$g = new KarMCP_Redirect_Abilities();

		// External host → external (ignored).
		$this->assertSame( 'external', $g->classify_href( 'https://other.example/x', array() )['kind'] );
		// Anchor / mailto → external.
		$this->assertSame( 'external', $g->classify_href( '#top', array() )['kind'] );

		// Path that matches an enabled redirect source → redirected.
		$this->assertSame( 'redirected', $g->classify_href( '/go-here', array( '/go-here' ) )['kind'] );

		// Internal path with no post → dead.
		$this->assertSame( 'dead', $g->classify_href( '/missing', array() )['kind'] );

		// Internal path → trashed post → dead.
		$GLOBALS['karmcp_test']['url_to_postid']['/gone'] = 77;
		$GLOBALS['karmcp_test']['post_status'][77]        = 'trash';
		$this->assertSame( 'dead', $g->classify_href( '/gone', array() )['kind'] );

		// Internal path → published post → ok.
		$GLOBALS['karmcp_test']['url_to_postid']['/live'] = 88;
		$GLOBALS['karmcp_test']['post_status'][88]        = 'publish';
		$this->assertSame( 'ok', $g->classify_href( 'http://example.test/live', array() )['kind'] );
	}
}
