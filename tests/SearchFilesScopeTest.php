<?php
/**
 * search-files accepts a single file, not only a directory.
 *
 * Narrowing a search to one file is the obvious move once you know where to
 * look: checking a generated stylesheet for one rule, confirming which of two
 * files declares a control. It used to answer "Not a directory." — with no hint
 * that a directory was expected, and none that read-file exists for reading one
 * outright. The cost was a failed call plus a wider search to filter by eye,
 * which is exactly what it cost while auditing the widget catalog.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-filesystem-guard.php';
require_once __DIR__ . '/../includes/abilities/class-filesystem-abilities.php';

class SearchFilesScopeTest extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		karmcp_test_reset();

		// ABSPATH is the guard's root, so the fixture has to live under it.
		$this->root = rtrim( ABSPATH, '/' ) . '/karmcp-search-' . uniqid();
		mkdir( $this->root, 0777, true );

		file_put_contents( $this->root . '/one.php', "<?php\n\$needle = 'text_padding';\n" );
		file_put_contents( $this->root . '/two.php', "<?php\n\$other = 'text_padding';\n" );
		file_put_contents( $this->root . '/notes.txt', "text_padding lives here too\n" );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->root . '/*' ) as $f ) {
			unlink( $f );
		}
		rmdir( $this->root );
	}

	/**
	 * @param string $path  Relative path passed as the search scope.
	 * @param array  $extra Extra input.
	 * @return array|WP_Error
	 */
	private function search( string $path, array $extra = array() ) {
		$abilities = new KarMCP_Filesystem_Abilities();

		return $abilities->execute_search_files(
			array_merge( array( 'query' => 'text_padding', 'path' => $path ), $extra )
		);
	}

	/** @return string The fixture directory, relative to ABSPATH. */
	private function rel(): string {
		return basename( $this->root );
	}

	public function test_a_directory_searches_the_whole_tree(): void {
		$result = $this->search( $this->rel() );

		$this->assertCount( 3, $result['matches'] );
	}

	/**
	 * The regression: this used to be an error.
	 */
	public function test_a_single_file_searches_only_that_file(): void {
		$result = $this->search( $this->rel() . '/one.php' );

		$this->assertCount( 1, $result['matches'] );
		$this->assertStringEndsWith( 'one.php', $result['matches'][0]['file'] );
		$this->assertSame( 2, $result['matches'][0]['line'] );
	}

	/**
	 * A file the caller named outright is the choice; an extension filter meant
	 * for narrowing a sweep must not veto it. Otherwise the obvious call —
	 * "search this .txt, only .php files please" left over from a previous
	 * call — silently returns nothing.
	 */
	public function test_extensions_do_not_veto_an_explicitly_named_file(): void {
		$result = $this->search( $this->rel() . '/notes.txt', array( 'extensions' => array( 'php' ) ) );

		$this->assertCount( 1, $result['matches'] );
	}

	public function test_extensions_still_narrow_a_directory_sweep(): void {
		$result = $this->search( $this->rel(), array( 'extensions' => array( 'txt' ) ) );

		$this->assertCount( 1, $result['matches'] );
		$this->assertStringEndsWith( 'notes.txt', $result['matches'][0]['file'] );
	}

	/**
	 * And a path that is neither says so, and says what to pass instead.
	 */
	public function test_a_missing_path_explains_what_is_accepted(): void {
		$result = $this->search( $this->rel() . '/nope.php' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'path_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'single file', $result->get_error_message() );
	}
}
