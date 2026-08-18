<?php
/**
 * Duplicating a post, and the meta that must not travel with it.
 *
 * The whole point of this primitive is that it copies *protected* meta, which
 * every other write path deliberately refuses. That makes the exclusion list
 * the safety-critical part: a copied `_edit_lock` makes the new post look like
 * someone else has it open, a copied `_sku` makes the product unsaveable, and a
 * copied `_elementor_css` makes the copy render with the original's styles until
 * something invalidates a cache nobody knows about.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-post-duplicator.php';

class PostDuplicatorTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
		karmcp_test_reset_hooks();

		$GLOBALS['karmcp_test']['posts'][7] = new WP_Post(
			array(
				'ID'           => 7,
				'post_title'   => 'Página de servicios',
				'post_name'    => 'servicios',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Contenido',
			)
		);
		$GLOBALS['karmcp_test']['post_meta'][7] = array(
			'_elementor_data'      => array( '[{"id":"abc1234"}]' ),
			'_elementor_edit_mode' => array( 'builder' ),
			'_jet_popup_settings'  => array( array( 'trigger' => 'scroll' ) ),
			'_edit_lock'           => array( '1699999999:3' ),
			'_sku'                 => array( 'CAM-001' ),
			'_elementor_css'       => array( 'cached' ),
			'_oembed_abc'          => array( '<iframe>' ),
		);
		$GLOBALS['karmcp_test']['taxonomies_for_type']['page'] = array( 'category' );
		$GLOBALS['karmcp_test']['object_terms'][7]['category'] = array( 3, 9 );
	}

	private function duplicate( array $args = array() ): int {
		$id = KarMCP_Post_Duplicator::duplicate( 7, $args );
		$this->assertIsInt( $id, 'Expected a new post id.' );
		return $id;
	}

	// ---- the exclusion list ------------------------------------------------

	/**
	 * @dataProvider skipped_keys
	 */
	public function test_meta_that_must_not_travel( string $key ): void {
		$this->assertTrue( KarMCP_Post_Duplicator::should_skip_meta( $key ) );
	}

	public static function skipped_keys(): array {
		return array(
			array( '_edit_lock' ),
			array( '_edit_last' ),
			array( '_wp_old_slug' ),
			array( '_elementor_css' ),
			array( '_sku' ),
			array( '_oembed_a1b2c3' ),
		);
	}

	/**
	 * @dataProvider copied_keys
	 */
	public function test_meta_that_must_travel( string $key ): void {
		$this->assertFalse( KarMCP_Post_Duplicator::should_skip_meta( $key ) );
	}

	public static function copied_keys(): array {
		return array(
			array( '_elementor_data' ),
			array( '_elementor_edit_mode' ),
			array( '_jet_popup_settings' ),
			// Sharing a featured image between a post and its copy is correct.
			array( '_thumbnail_id' ),
			array( 'precio' ),
		);
	}

	// ---- the copy ----------------------------------------------------------

	/**
	 * The reason the tool exists: the protected meta a plugin CPT keeps its
	 * whole configuration in has to arrive, or the copy is an empty shell of
	 * the right post type — which is exactly what create-post already gave us.
	 */
	public function test_protected_plugin_meta_is_copied(): void {
		$new_id = $this->duplicate();

		$this->assertSame( '[{"id":"abc1234"}]', get_post_meta( $new_id, '_elementor_data', true ) );
		$this->assertSame( array( 'trigger' => 'scroll' ), get_post_meta( $new_id, '_jet_popup_settings', true ) );
	}

	public function test_excluded_meta_does_not_reach_the_copy(): void {
		$new_id = $this->duplicate();

		foreach ( array( '_edit_lock', '_sku', '_elementor_css', '_oembed_abc' ) as $key ) {
			$this->assertSame( array(), get_post_meta( $new_id, $key ), $key . ' should not have been copied.' );
		}
	}

	/**
	 * get_post_meta() in list mode hands back serialized strings. Writing them
	 * straight through would store the array as a literal string, and the copy
	 * would read as configured while being unusable.
	 */
	public function test_array_meta_survives_as_an_array(): void {
		$new_id = $this->duplicate();

		$this->assertIsArray( get_post_meta( $new_id, '_jet_popup_settings', true ) );
	}

	public function test_terms_are_copied(): void {
		$new_id = $this->duplicate();

		$this->assertSame( array( 3, 9 ), $GLOBALS['karmcp_test']['object_terms'][ $new_id ]['category'] );
	}

	public function test_copying_meta_can_be_switched_off(): void {
		$new_id = $this->duplicate( array( 'copy_meta' => false ) );

		$this->assertSame( array(), get_post_meta( $new_id, '_elementor_data' ) );
	}

	/**
	 * A copy that goes straight live publishes duplicate content under a
	 * near-identical slug. Draft is recoverable; published is not.
	 */
	public function test_the_copy_is_a_draft_unless_asked_otherwise(): void {
		$this->assertSame( 'draft', $GLOBALS['karmcp_test']['posts'][ $this->duplicate() ]->post_status );
		$this->assertSame( 'publish', $GLOBALS['karmcp_test']['posts'][ $this->duplicate( array( 'status' => 'publish' ) ) ]->post_status );
	}

	public function test_the_title_is_marked_as_a_copy_by_default(): void {
		$new_id = $this->duplicate();

		$this->assertSame( 'Página de servicios (copy)', $GLOBALS['karmcp_test']['posts'][ $new_id ]->post_title );
	}

	public function test_an_explicit_title_wins(): void {
		$new_id = $this->duplicate( array( 'title' => 'Services page' ) );

		$this->assertSame( 'Services page', $GLOBALS['karmcp_test']['posts'][ $new_id ]->post_title );
	}

	public function test_the_copy_keeps_the_source_post_type(): void {
		$this->assertSame( 'page', $GLOBALS['karmcp_test']['posts'][ $this->duplicate() ]->post_type );
	}

	public function test_a_missing_source_is_an_error(): void {
		$this->assertInstanceOf( WP_Error::class, KarMCP_Post_Duplicator::duplicate( 4242 ) );
	}

	/**
	 * Integrations need somewhere to copy what lives outside meta and terms.
	 */
	public function test_a_hook_fires_with_both_ids(): void {
		$seen = array();
		add_action(
			'karmcp_post_duplicated',
			static function ( $new_id, $source_id ) use ( &$seen ) {
				$seen = array( $new_id, $source_id );
			},
			10,
			2
		);

		$new_id = $this->duplicate();

		$this->assertSame( array( $new_id, 7 ), $seen );
	}
	// ---- the copy has to be readable ---------------------------------------

	/**
	 * The regression this class exists to never repeat.
	 *
	 * `add_post_meta()` unslashes whatever it is given, because the metadata API
	 * is written for values arriving slashed from a form post. A value read
	 * straight out of the database is not slashed, so passing it through
	 * unchanged loses every backslash in it. For most meta that is invisible.
	 * For `_elementor_data` it is fatal: the JSON is full of \/ and \uXXXX
	 * escapes, and a copy without them does not decode. Every tool then reads
	 * the copy as an empty page, and the first write on top of it makes that
	 * emptiness permanent.
	 *
	 * Measured on a real course page before the fix: 38,632 bytes of valid JSON
	 * in, 37,529 out, all 1,103 backslashes gone.
	 */
	public function test_elementor_data_still_decodes_after_the_copy(): void {
		$json = wp_json_encode(
			array(
				array(
					'id'       => 'abc1234',
					'settings' => array(
						'link'  => array( 'url' => 'https://ejemplo.test/módulo-1/' ),
						'title' => 'Introducción — «acentos» y "comillas"',
					),
				),
			)
		);

		$this->assertIsArray( json_decode( $json, true ), 'The fixture itself must be valid JSON.' );

		$GLOBALS['karmcp_test']['post_meta'][7]['_elementor_data'] = array( $json );

		$new_id = $this->duplicate();
		$copied = $GLOBALS['karmcp_test']['post_meta'][ $new_id ]['_elementor_data'][0];

		$this->assertIsArray(
			json_decode( $copied, true ),
			'The copied Elementor data must still decode as JSON.'
		);
		$this->assertSame( $json, $copied, 'The copy must be byte-for-byte identical to the source.' );
	}

	/**
	 * The same guarantee stated the way the bug presented: the copy lost bytes.
	 */
	public function test_the_copy_keeps_every_backslash(): void {
		$escaped = '[{"id":"abc1234","url":"https:\/\/ejemplo.test\/a","t":"\u00e1rea"}]';

		$GLOBALS['karmcp_test']['post_meta'][7]['_elementor_data'] = array( $escaped );

		$new_id = $this->duplicate();
		$copied = $GLOBALS['karmcp_test']['post_meta'][ $new_id ]['_elementor_data'][0];

		$this->assertSame(
			substr_count( $escaped, '\\' ),
			substr_count( $copied, '\\' ),
			'No backslash may be lost in the copy.'
		);
		$this->assertSame( strlen( $escaped ), strlen( $copied ) );
	}
}
