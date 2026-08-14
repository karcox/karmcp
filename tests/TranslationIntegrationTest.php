<?php
/**
 * The shared multilingual layer, exercised through a fake adapter.
 *
 * Polylang and WPML are not installed here, and that is the point: the
 * orchestration is what breaks, not the plugin calls. Two failures in
 * particular are silent and expensive — a translation created as its own group
 * instead of joining the existing one (so a three-language page splits into two
 * pairs that each know half the story), and a language code the site has not
 * configured (accepted by both plugins, and the post then exists in a language
 * with no switcher entry and no URL, visible nowhere).
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/trait-operation-dispatcher.php';
require_once __DIR__ . '/../includes/class-post-duplicator.php';
require_once __DIR__ . '/../includes/abilities/i18n/class-translation-integration.php';

/**
 * In-memory adapter: languages and groups live in the fixture.
 */
class KarMCP_Fake_Translation_Integration extends KarMCP_Translation_Integration {

	public function id(): string {
		return 'faketrans';
	}

	public function label(): string {
		return 'Fake Translations';
	}

	public function is_active(): bool {
		return true;
	}

	protected function languages(): array {
		return $GLOBALS['karmcp_test']['languages'];
	}

	protected function language_of( int $post_id ): string {
		return (string) ( $GLOBALS['karmcp_test']['post_language'][ $post_id ] ?? '' );
	}

	protected function translations_of( int $post_id ): array {
		foreach ( $GLOBALS['karmcp_test']['groups'] as $group ) {
			if ( in_array( $post_id, $group, true ) ) {
				return $group;
			}
		}
		$code = $this->language_of( $post_id );
		return '' !== $code ? array( $code => $post_id ) : array();
	}

	protected function assign_language( int $post_id, string $code ) {
		$GLOBALS['karmcp_test']['post_language'][ $post_id ] = $code;
		return true;
	}

	protected function link_group( array $map ) {
		$GLOBALS['karmcp_test']['groups'] = array_values(
			array_filter(
				$GLOBALS['karmcp_test']['groups'],
				static function ( $group ) use ( $map ) {
					return empty( array_intersect( $group, $map ) );
				}
			)
		);
		$GLOBALS['karmcp_test']['groups'][] = $map;
		return true;
	}
}

class TranslationIntegrationTest extends TestCase {

	/** @var KarMCP_Fake_Translation_Integration */
	private $i18n;

	protected function setUp(): void {
		karmcp_test_reset();
		karmcp_test_reset_hooks();

		$GLOBALS['karmcp_test']['languages'] = array(
			array( 'code' => 'es', 'name' => 'Español', 'default' => true ),
			array( 'code' => 'en', 'name' => 'English', 'default' => false ),
			array( 'code' => 'fr', 'name' => 'Français', 'default' => false ),
		);
		$GLOBALS['karmcp_test']['groups']        = array();
		$GLOBALS['karmcp_test']['post_language'] = array( 5 => 'es' );

		$GLOBALS['karmcp_test']['posts'][5] = new WP_Post(
			array(
				'ID'          => 5,
				'post_title'  => 'Servicios',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$GLOBALS['karmcp_test']['post_status'][5] = 'publish';
		$GLOBALS['karmcp_test']['post_meta'][5]   = array( '_elementor_data' => array( '[]' ) );

		$this->i18n = new KarMCP_Fake_Translation_Integration();
	}

	private function write( string $operation, array $args = array() ) {
		return $this->i18n->run_write(
			array(
				'operation' => $operation,
				'arguments' => $args,
			)
		);
	}

	private function read( string $operation, array $args = array() ) {
		return $this->i18n->run_read(
			array(
				'operation' => $operation,
				'arguments' => $args,
			)
		);
	}

	// ---- discovery ---------------------------------------------------------

	public function test_tool_names_follow_the_house_pattern(): void {
		$this->assertSame( array( 'karmcp/faketrans-read', 'karmcp/faketrans-write' ), $this->i18n->get_ability_names() );
	}

	public function test_read_catalog_lists_only_read_operations(): void {
		$names = array_column( $this->read( '' )['operations'], 'operation' );

		$this->assertEqualsCanonicalizing( array( 'list-languages', 'get-translation-status' ), $names );
	}

	public function test_write_catalog_lists_only_write_operations(): void {
		$names = array_column( $this->write( '' )['operations'], 'operation' );

		$this->assertEqualsCanonicalizing( array( 'create-translation', 'set-post-language', 'link-translations' ), $names );
	}

	public function test_list_languages_marks_the_default(): void {
		$out = $this->read( 'list-languages' );

		$this->assertSame( 3, $out['count'] );
		$this->assertSame( 'es', $out['default'] );
	}

	// ---- status ------------------------------------------------------------

	public function test_translation_status_reports_what_is_missing(): void {
		$out = $this->read( 'get-translation-status', array( 'post_id' => 5 ) );

		$this->assertSame( 'es', $out['language'] );
		$this->assertSame( array( 'en', 'fr' ), $out['missing'] );
		$this->assertCount( 1, $out['translations'] );
		$this->assertTrue( $out['translations'][0]['is_self'] );
	}

	// ---- creating a translation --------------------------------------------

	public function test_create_translation_duplicates_assigns_and_links(): void {
		$out = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );

		$this->assertTrue( $out['created'] );
		$this->assertSame( 'en', $out['language'] );
		$this->assertSame( 5, $out['source'] );
		$this->assertSame( 'en', $GLOBALS['karmcp_test']['post_language'][ $out['post_id'] ] );
		$this->assertSame(
			array( 'es' => 5, 'en' => $out['post_id'] ),
			$GLOBALS['karmcp_test']['groups'][0]
		);
	}

	/**
	 * The copy carries the source's layout, which is the whole reason to
	 * duplicate rather than create an empty page: a translator edits text, not
	 * a blank canvas.
	 */
	public function test_the_translation_carries_the_layout(): void {
		$out = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );

		$this->assertSame( '[]', get_post_meta( $out['post_id'], '_elementor_data', true ) );
	}

	public function test_the_translation_starts_as_a_draft(): void {
		$out = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );

		$this->assertSame( 'draft', $out['status'] );
	}

	/**
	 * The failure this whole file exists for. Adding a third language must
	 * extend the group, not start a second one: two pairs each know half the
	 * site, and the language switcher shows different options depending on
	 * which page you are standing on.
	 */
	public function test_a_third_language_joins_the_existing_group(): void {
		$english = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );
		$french  = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'fr' ) );

		$this->assertCount( 1, $GLOBALS['karmcp_test']['groups'] );
		$this->assertSame(
			array( 'es' => 5, 'en' => $english['post_id'], 'fr' => $french['post_id'] ),
			$GLOBALS['karmcp_test']['groups'][0]
		);
	}

	public function test_a_second_translation_into_the_same_language_is_refused(): void {
		$this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );
		$again = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'translation_exists', $again->get_error_code() );
	}

	// ---- languages the site does not have ----------------------------------

	/**
	 * Both plugins accept an unconfigured code and store it. The post then lives
	 * in a language with no switcher entry and no URL: it exists and is
	 * reachable from nowhere.
	 */
	public function test_an_unconfigured_language_is_refused_and_lists_the_real_ones(): void {
		$out = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'de' ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'unknown_language', $out->get_error_code() );
		$this->assertStringContainsString( 'es, en, fr', $out->get_error_message() );
	}

	public function test_a_missing_language_argument_is_refused(): void {
		$out = $this->write( 'create-translation', array( 'post_id' => 5 ) );

		$this->assertSame( 'missing_argument', $out->get_error_code() );
	}

	public function test_a_missing_source_post_is_refused(): void {
		$out = $this->write( 'create-translation', array( 'post_id' => 999, 'language' => 'en' ) );

		$this->assertSame( 'not_found', $out->get_error_code() );
	}

	// ---- linking by hand ---------------------------------------------------

	public function test_link_translations_assigns_languages_and_groups(): void {
		$GLOBALS['karmcp_test']['posts'][8]        = new WP_Post( array( 'ID' => 8, 'post_type' => 'page' ) );
		$GLOBALS['karmcp_test']['post_status'][8]  = 'publish';

		$out = $this->write( 'link-translations', array( 'translations' => array( 'es' => 5, 'en' => 8 ) ) );

		$this->assertTrue( $out['linked'] );
		$this->assertSame( 'en', $GLOBALS['karmcp_test']['post_language'][8] );
		$this->assertSame( array( 'es' => 5, 'en' => 8 ), $GLOBALS['karmcp_test']['groups'][0] );
	}

	public function test_linking_needs_at_least_two_posts(): void {
		$out = $this->write( 'link-translations', array( 'translations' => array( 'es' => 5 ) ) );

		$this->assertSame( 'invalid_argument', $out->get_error_code() );
	}

	// ---- permissions -------------------------------------------------------

	public function test_an_unknown_operation_is_named_in_the_error(): void {
		$out = $this->write( 'translate-everything' );

		$this->assertSame( 'unknown_operation', $out->get_error_code() );
	}

	public function test_a_user_who_cannot_edit_the_post_is_refused(): void {
		$GLOBALS['karmcp_test']['post_caps'][5] = false;

		$out = $this->write( 'create-translation', array( 'post_id' => 5, 'language' => 'en' ) );

		$this->assertSame( 'forbidden', $out->get_error_code() );
	}
}
