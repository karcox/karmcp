<?php
/**
 * KarMCP_Themer_Extended — granular Themer matchers.
 *
 * The matcher map is a pure value, so these tests call the callbacks directly
 * against normalized contexts. No WordPress, no filter registry.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/themer/class-themer-matcher-registry.php';
require_once dirname( __DIR__ ) . '/includes/themer/class-themer-context.php';
require_once dirname( __DIR__ ) . '/includes/themer/class-themer-conditions.php';
require_once dirname( __DIR__ ) . '/includes/themer/class-themer-resolver.php';
require_once dirname( __DIR__ ) . '/includes/themer/class-themer-extended.php';

final class ThemerExtendedTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset_hooks();
	}

	/**
	 * Run one matcher against a context.
	 *
	 * @param string $object Rule object string, e.g. "post:42".
	 * @param array  $parts  Partial context.
	 * @return bool
	 */
	private function matchRule( string $object, array $parts ): bool {
		$key      = explode( ':', $object )[0];
		$matchers = KarMCP_Themer_Extended::matchers();
		$this->assertArrayHasKey( $key, $matchers, "matcher '$key' is not registered" );
		$ctx = KarMCP_Themer_Context::from_parts( $parts );
		return (bool) call_user_func( $matchers[ $key ]['callback'], array( 'object' => $object ), $ctx );
	}

	// ---------------------------------------------------------------- post

	public function test_post_matches_only_that_entry(): void {
		$this->assertTrue( $this->matchRule( 'post:42', array( 'is_singular' => true, 'post_id' => 42 ) ) );
		$this->assertFalse( $this->matchRule( 'post:42', array( 'is_singular' => true, 'post_id' => 43 ) ) );
	}

	public function test_post_does_not_match_an_archive(): void {
		// Same numeric id, but not a singular request.
		$this->assertFalse( $this->matchRule( 'post:42', array( 'is_archive' => true, 'post_id' => 42 ) ) );
	}

	public function test_post_rejects_a_missing_or_zero_id(): void {
		$this->assertFalse( $this->matchRule( 'post:', array( 'is_singular' => true, 'post_id' => 0 ) ) );
		$this->assertFalse( $this->matchRule( 'post:0', array( 'is_singular' => true, 'post_id' => 0 ) ) );
	}

	// ------------------------------------------------------------- in-term

	public function test_in_term_matches_a_post_filed_under_the_term(): void {
		$ctx = array(
			'is_singular' => true,
			'post_id'     => 7,
			'term_ids'    => array( 'category' => array( 3, 9 ) ),
		);
		$this->assertTrue( $this->matchRule( 'in-term:category:9', $ctx ) );
		$this->assertFalse( $this->matchRule( 'in-term:category:11', $ctx ) );
	}

	public function test_in_term_is_taxonomy_scoped(): void {
		// Term id 9 exists, but under a different taxonomy.
		$ctx = array(
			'is_singular' => true,
			'term_ids'    => array( 'post_tag' => array( 9 ) ),
		);
		$this->assertFalse( $this->matchRule( 'in-term:category:9', $ctx ) );
	}

	public function test_in_term_needs_both_parameters(): void {
		$ctx = array( 'is_singular' => true, 'term_ids' => array( 'category' => array( 9 ) ) );
		$this->assertFalse( $this->matchRule( 'in-term:category', $ctx ) );
		$this->assertFalse( $this->matchRule( 'in-term', $ctx ) );
	}

	// -------------------------------------------------------------- author

	public function test_author_matches_a_singular_by_that_author(): void {
		$this->assertTrue( $this->matchRule( 'author:5', array( 'is_singular' => true, 'author_id' => 5 ) ) );
		$this->assertFalse( $this->matchRule( 'author:5', array( 'is_singular' => true, 'author_id' => 6 ) ) );
	}

	public function test_author_and_author_archive_are_distinct(): void {
		$singular = array( 'is_singular' => true, 'author_id' => 5 );
		$archive  = array( 'is_author' => true, 'author_id' => 5 );

		// "By author" targets the entry; "author archive" targets the listing.
		$this->assertTrue( $this->matchRule( 'author:5', $singular ) );
		$this->assertFalse( $this->matchRule( 'author:5', $archive ) );

		$this->assertTrue( $this->matchRule( 'author-archive:5', $archive ) );
		$this->assertFalse( $this->matchRule( 'author-archive:5', $singular ) );
	}

	// ---------------------------------------------------------------- term

	public function test_term_matches_the_queried_term_archive(): void {
		$ctx = array( 'is_archive' => true, 'queried_taxonomy' => 'category', 'queried_term_id' => 4 );
		$this->assertTrue( $this->matchRule( 'term:category:4', $ctx ) );
		$this->assertFalse( $this->matchRule( 'term:category:5', $ctx ) );
		$this->assertFalse( $this->matchRule( 'term:post_tag:4', $ctx ) );
	}

	// ---------------------------------------------------------------- date

	public function test_date_matches_only_date_archives(): void {
		$this->assertTrue( $this->matchRule( 'date', array( 'is_date' => true ) ) );
		$this->assertFalse( $this->matchRule( 'date', array( 'is_archive' => true ) ) );
	}

	// -------------------------------------------------------- specificity

	public function test_granular_matchers_outrank_every_broad_one(): void {
		// The free tier tops out at 20 (front-page / post-type / …). Anything that
		// targets a specific object must beat that, or "site-wide except this page"
		// would resolve to the site-wide template.
		$matchers = KarMCP_Themer_Extended::matchers();
		foreach ( array( 'post', 'in-term', 'author', 'term', 'author-archive' ) as $key ) {
			$this->assertGreaterThan(
				20,
				$matchers[ $key ]['specificity'],
				"matcher '$key' must outrank the broad selectors"
			);
		}
	}

	public function test_post_is_the_most_specific_matcher(): void {
		$matchers = KarMCP_Themer_Extended::matchers();
		$scores   = array_map( static fn( $m ) => (int) $m['specificity'], $matchers );
		$this->assertSame( max( $scores ), (int) $matchers['post']['specificity'] );
	}

	// ----------------------------------------------------------- selectors

	public function test_every_matcher_is_allowed_through_save_validation(): void {
		// A matcher without a matching selector resolves at render time but is
		// silently dropped on save — the easiest way to ship a broken condition.
		$selectors = KarMCP_Themer_Extended::filter_selectors( array() );
		foreach ( array_keys( KarMCP_Themer_Extended::matchers() ) as $key ) {
			$this->assertContains( $key, $selectors, "selector '$key' is not saveable" );
		}
	}

	public function test_filter_selectors_preserves_the_broad_set(): void {
		$base   = array( 'entire-site', 'all-singular', 'post-type' );
		$result = KarMCP_Themer_Extended::filter_selectors( $base );
		foreach ( $base as $key ) {
			$this->assertContains( $key, $result );
		}
		$this->assertSame( array_values( array_unique( $result ) ), $result, 'no duplicates' );
	}

	public function test_registering_post_flips_the_builder_into_granular_mode(): void {
		// KarMCP_Themer_Metabox::is_pro() probes for exactly this key.
		$this->assertContains( 'post', KarMCP_Themer_Extended::filter_selectors( array() ) );
	}

	// ---------------------------------------------------------------- quota

	public function test_quota_is_lifted_for_every_type(): void {
		foreach ( array( 'header', 'footer', 'single', 'archive', 'search', '404' ) as $type ) {
			$this->assertSame( PHP_INT_MAX, KarMCP_Themer_Extended::filter_quota( 1, $type ) );
		}
	}

	// --------------------------------------------- end-to-end via evaluate()

	public function test_exclude_beats_a_matching_include(): void {
		// "Site-wide header, except on page 42" — the headline use case. Goes
		// through the real filter so it covers the registry wiring too.
		add_filter( 'karmcp_themer_matchers', array( 'KarMCP_Themer_Extended', 'filter_matchers' ) );
		$registry = KarMCP_Themer_Matcher_Registry::fresh();

		$conditions = array(
			'include' => array( array( 'object' => 'entire-site' ) ),
			'exclude' => array( array( 'object' => 'post:42' ) ),
		);

		$on_42    = KarMCP_Themer_Context::from_parts( array( 'is_singular' => true, 'post_id' => 42 ) );
		$on_other = KarMCP_Themer_Context::from_parts( array( 'is_singular' => true, 'post_id' => 7 ) );

		$this->assertNull( KarMCP_Themer_Conditions::evaluate( $conditions, $on_42, $registry ) );
		$this->assertNotNull( KarMCP_Themer_Conditions::evaluate( $conditions, $on_other, $registry ) );
	}

	// ------------------------------------------------------------- priority

	public function test_ranker_reads_the_stored_priority(): void {
		// The default ranker returns 0 for every row, which makes a saved priority
		// a no-op. This is the wire that makes it real.
		$ranker = KarMCP_Themer_Extended::filter_rank( static fn( array $row ): int => 0 );

		$this->assertSame( 7, $ranker( array( 'id' => 1, 'priority' => 7 ) ) );
		$this->assertSame( 0, $ranker( array( 'id' => 2 ) ), 'a row with no priority ranks 0' );
		$this->assertSame( -3, $ranker( array( 'id' => 3, 'priority' => -3 ) ) );
	}

	public function test_specificity_beats_priority(): void {
		// A granular rule must win even when a broad template asks for priority.
		add_filter( 'karmcp_themer_matchers', array( 'KarMCP_Themer_Extended', 'filter_matchers' ) );
		$registry = KarMCP_Themer_Matcher_Registry::fresh();
		$ranker   = KarMCP_Themer_Extended::filter_rank( static fn( array $row ): int => 0 );

		$index = array(
			'header' => array(
				array( 'id' => 10, 'include' => array( array( 'object' => 'entire-site' ) ), 'exclude' => array(), 'priority' => 99 ),
				array( 'id' => 20, 'include' => array( array( 'object' => 'post:42' ) ), 'exclude' => array(), 'priority' => 0 ),
			),
		);
		$ctx = KarMCP_Themer_Context::from_parts( array( 'is_singular' => true, 'post_id' => 42 ) );

		$slots = KarMCP_Themer_Resolver::resolve( $index, $ctx, $registry, $ranker );
		$this->assertSame( 20, $slots['header'] );
	}

	public function test_priority_breaks_a_specificity_tie(): void {
		add_filter( 'karmcp_themer_matchers', array( 'KarMCP_Themer_Extended', 'filter_matchers' ) );
		$registry = KarMCP_Themer_Matcher_Registry::fresh();
		$ranker   = KarMCP_Themer_Extended::filter_rank( static fn( array $row ): int => 0 );

		// Same selector, so identical specificity — priority decides.
		$index = array(
			'header' => array(
				array( 'id' => 10, 'include' => array( array( 'object' => 'entire-site' ) ), 'exclude' => array(), 'priority' => 1 ),
				array( 'id' => 20, 'include' => array( array( 'object' => 'entire-site' ) ), 'exclude' => array(), 'priority' => 5 ),
			),
		);
		$ctx = KarMCP_Themer_Context::from_parts( array( 'is_singular' => true, 'post_id' => 1 ) );

		$slots = KarMCP_Themer_Resolver::resolve( $index, $ctx, $registry, $ranker );
		$this->assertSame( 20, $slots['header'], 'the higher priority wins the tie' );
	}
}
