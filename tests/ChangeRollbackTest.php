<?php
/**
 * The change history: what an undo removes, what it refuses, and whether it
 * can tell when it did not work.
 *
 * Three failures shaped this file, all of the same kind — an undo that reported
 * success over a site that was not back where it started:
 *
 * - Undoing create-page left an empty page. The initial content save recorded
 *   itself as an "edit", and that edit was the only entry; its undo restored
 *   the empty tree.
 * - Page settings, custom CSS included, were never recorded at all.
 * - Every restore trusted its write. A delete WordPress declined, a meta value
 *   that did not take, a restore under a different id — all marked undone.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-change-log.php';
require_once __DIR__ . '/../includes/class-change-recorder.php';
require_once __DIR__ . '/../includes/class-change-blobs.php';
require_once __DIR__ . '/../includes/class-elementor-data.php';
require_once __DIR__ . '/../includes/class-element-factory.php';
require_once __DIR__ . '/../includes/abilities/class-page-abilities.php';

// Stubs the harness does not provide. Guarded; the three media stubs are the
// same definitions InstallUploadedZipTest declares and read the same fixture,
// so whichever file loads first, both see the behaviour they expect.
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		return $GLOBALS['karmcp_zip_test']['files'][ (int) $attachment_id ] ?? false;
	}
}
if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	function wp_get_upload_dir() {
		return array( 'basedir' => $GLOBALS['karmcp_zip_test']['uploads'] ?? '' );
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );
		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}
		return $path;
	}
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
}
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		return isset( $GLOBALS['karmcp_test']['post_meta'][ (int) $object_id ][ (string) $meta_key ] );
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['karmcp_test']['options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id ) {
		$values = $GLOBALS['karmcp_test']['post_meta'][ (int) $attachment_id ]['_wp_attachment_metadata'] ?? array();
		return $values ? reset( $values ) : false;
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	// A superset of NavMenuAbilitiesTest's stub: it also clears nav fixtures and
	// returns the same values, so that suite behaves the same whichever loads first.
	// ['undeletable'][ id ] makes WordPress decline, the way a filter can.
	function wp_delete_post( $id, $force = false ) {
		$id = (int) $id;
		if ( ! empty( $GLOBALS['karmcp_test']['undeletable'][ $id ] ) ) {
			return false;
		}
		$existed = isset( $GLOBALS['karmcp_nav']['items'][ $id ] ) || isset( $GLOBALS['karmcp_test']['posts'][ $id ] );
		unset( $GLOBALS['karmcp_nav']['items'][ $id ], $GLOBALS['karmcp_nav']['item_menu'][ $id ], $GLOBALS['karmcp_test']['posts'][ $id ] );
		$GLOBALS['karmcp_test']['deleted_posts'][] = $id;
		return $existed ? new WP_Post( array( 'ID' => $id ) ) : false;
	}
}
if ( ! function_exists( 'wp_delete_attachment' ) ) {
	// Deletes the post and only the files listed in ['wp_deletes'][ id ] — so a
	// test can have WordPress leave a generated size behind, as it does on
	// Windows when the separators in two paths disagree.
	function wp_delete_attachment( $id, $force = false ) {
		$id = (int) $id;
		if ( ! empty( $GLOBALS['karmcp_test']['undeletable'][ $id ] ) ) {
			return false;
		}
		foreach ( (array) ( $GLOBALS['karmcp_test']['wp_deletes'][ $id ] ?? array() ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		unset( $GLOBALS['karmcp_test']['posts'][ $id ] );
		return new WP_Post( array( 'ID' => $id ) );
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( $id ) {
		return $GLOBALS['karmcp_test']['untrash_result'] ?? new WP_Post( array( 'ID' => (int) $id ) );
	}
}

/**
 * A data layer that behaves like the real one where it matters here: saving
 * page data records an Elementor edit, as KarMCP_Data::save_page_data() does.
 */
class KarMCP_Test_Recording_Data extends KarMCP_Data {

	/** @var \WP_Error|null */
	public $fail_with = null;

	public function save_page_data( int $post_id, array $data ) {
		if ( $this->fail_with ) {
			return $this->fail_with;
		}
		$before = get_post_meta( $post_id, '_elementor_data', true );
		update_post_meta( $post_id, '_elementor_data', wp_json_encode( $data ) );
		KarMCP_Change_Recorder::record_elementor( $post_id, is_string( $before ) && '' !== $before ? (array) json_decode( $before, true ) : array(), 'Edited Elementor page #' . $post_id );
		return true;
	}
}

class ChangeRollbackTest extends TestCase {

	private string $uploads = '';

	protected function setUp(): void {
		karmcp_test_reset();
		KarMCP_Change_Log::$suppress = false;

		$this->uploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'karmcp-hist-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploads );
		$GLOBALS['karmcp_zip_test'] = array(
			'files'   => array(),
			'uploads' => $this->uploads,
		);
	}

	protected function tearDown(): void {
		KarMCP_Change_Log::$suppress = false;
		$this->remove_tree( $this->uploads );
		unset( $GLOBALS['karmcp_zip_test'] );
	}

	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? $this->remove_tree( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/** @return array[] */
	private function ledger(): array {
		return KarMCP_Change_Log::all();
	}

	private function add_post( int $id, array $props = array() ): void {
		$GLOBALS['karmcp_test']['posts'][ $id ] = new WP_Post( array_merge( array( 'ID' => $id, 'post_title' => 'Post ' . $id, 'post_type' => 'page', 'post_status' => 'draft' ), $props ) );
	}

	private function create_page( array $input = array(), ?KarMCP_Test_Recording_Data $data = null ) {
		$abilities = new KarMCP_Page_Abilities( $data ?? new KarMCP_Test_Recording_Data(), new KarMCP_Element_Factory() );
		return $abilities->execute_create_page( array_merge( array( 'title' => 'Landing' ), $input ) );
	}

	// ---------------------------------------------------------------------
	// create-page: one creation entry, and undo removes the page
	// ---------------------------------------------------------------------

	public function test_create_page_records_one_creation_and_returns_its_id() {
		$out = $this->create_page( array( 'content' => array( array( 'id' => 'a1b2c3d', 'elType' => 'container' ) ) ) );

		$this->assertIsArray( $out );
		$entries = $this->ledger();
		$this->assertCount( 1, $entries, 'The initial content save must not add an "edit" entry of its own.' );
		$this->assertSame( 'post-create', $entries[0]['rollback']['type'] );
		$this->assertSame( 'create-page', $entries[0]['action'] );
		$this->assertSame( $entries[0]['id'], $out['change_id'] );
	}

	public function test_undoing_create_page_removes_the_page_instead_of_emptying_it() {
		$out = $this->create_page( array( 'content' => array( array( 'id' => 'a1b2c3d', 'elType' => 'container' ) ) ) );

		$result = KarMCP_Change_Log::rollback( $out['change_id'] );

		$this->assertIsArray( $result );
		$this->assertNull( get_post( $out['post_id'] ), 'Undoing a creation deletes the page; an empty page left behind is the bug this fixes.' );
	}

	public function test_create_page_leaves_the_callers_recording_state_as_it_found_it() {
		$this->create_page();
		$this->assertFalse( KarMCP_Change_Log::$suppress );

		KarMCP_Change_Log::$suppress = true;
		$this->create_page();
		$this->assertTrue( KarMCP_Change_Log::$suppress, 'An operation already running unrecorded must stay unrecorded.' );
	}

	public function test_a_failed_initial_save_does_not_leave_an_unrecorded_page_behind() {
		$data            = new KarMCP_Test_Recording_Data();
		$data->fail_with = new WP_Error( 'save_failed', 'Nope.' );

		$out = $this->create_page( array(), $data );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( array(), $this->ledger() );
		$this->assertSame( array(), array_keys( $GLOBALS['karmcp_test']['posts'] ), 'Nothing recorded it, so nothing could undo it.' );
	}

	// ---------------------------------------------------------------------
	// The creation guard
	// ---------------------------------------------------------------------

	public function test_undoing_a_creation_that_was_edited_since_is_a_conflict() {
		$out = $this->create_page();
		$GLOBALS['karmcp_test']['posts'][ $out['post_id'] ]->post_title = 'Renamed by someone';

		$result = KarMCP_Change_Log::rollback( $out['change_id'] );

		$this->assertSame( 'conflict', $result->get_error_code() );
		$this->assertNotNull( get_post( $out['post_id'] ), 'A conflict must leave the page alone.' );
	}

	public function test_elementor_content_added_after_creation_counts_as_an_edit() {
		$out = $this->create_page();
		update_post_meta( $out['post_id'], '_elementor_data', '[{"id":"later01","elType":"container"}]' );

		$this->assertSame( 'conflict', KarMCP_Change_Log::rollback( $out['change_id'] )->get_error_code() );
	}

	public function test_force_deletes_an_edited_creation() {
		$out = $this->create_page();
		$GLOBALS['karmcp_test']['posts'][ $out['post_id'] ]->post_title = 'Renamed';

		$this->assertIsArray( KarMCP_Change_Log::rollback( $out['change_id'], true ) );
		$this->assertNull( get_post( $out['post_id'] ) );
	}

	public function test_a_draft_whose_date_moved_can_still_be_undone() {
		$out = $this->create_page();
		$GLOBALS['karmcp_test']['posts'][ $out['post_id'] ]->post_date = '2026-09-21 10:00:00';

		$this->assertIsArray( KarMCP_Change_Log::rollback( $out['change_id'] ), 'WordPress keeps moving an undated draft\'s date; that is not an edit.' );
	}

	public function test_a_creation_recorded_without_a_guard_needs_force() {
		$this->add_post( 501 );
		$id = KarMCP_Change_Log::record( array( 'domain' => 'content', 'action' => 'create-post', 'rollback' => array( 'type' => 'post-create', 'post_id' => 501 ) ) );

		$this->assertSame( 'unguarded_creation', KarMCP_Change_Log::rollback( $id )->get_error_code() );
		$this->assertNotNull( get_post( 501 ) );

		$this->assertIsArray( KarMCP_Change_Log::rollback( $id, true ) );
		$this->assertNull( get_post( 501 ) );
	}

	public function test_a_delete_wordpress_declines_is_not_marked_undone() {
		$out = $this->create_page();
		$GLOBALS['karmcp_test']['undeletable'][ $out['post_id'] ] = true;

		$result = KarMCP_Change_Log::rollback( $out['change_id'] );

		$this->assertSame( 'rollback_failed', $result->get_error_code() );
		$this->assertEmpty( KarMCP_Change_Log::get( $out['change_id'] )['rolled_back'], 'The entry stays undoable, because nothing was undone.' );
	}

	// ---------------------------------------------------------------------
	// Uploaded media
	// ---------------------------------------------------------------------

	/** @return string[] [ main, size ] */
	private function add_attachment( int $id ): array {
		$dir  = $this->uploads . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '09';
		wp_mkdir_p( $dir );
		$main = $dir . DIRECTORY_SEPARATOR . 'photo.jpg';
		$size = $dir . DIRECTORY_SEPARATOR . 'photo-300x200.jpg';
		file_put_contents( $main, 'main-bytes' );
		file_put_contents( $size, 'size-bytes' );

		$this->add_post( $id, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$GLOBALS['karmcp_zip_test']['files'][ $id ]                                 = $main;
		$GLOBALS['karmcp_test']['post_meta'][ $id ]['_wp_attachment_metadata'] = array( array( 'sizes' => array( 'medium' => array( 'file' => 'photo-300x200.jpg' ) ) ) );
		return array( $main, $size );
	}

	public function test_undoing_an_upload_removes_every_file_even_the_ones_wordpress_left() {
		list( $main, $size ) = $this->add_attachment( 700 );
		$id                  = KarMCP_Change_Recorder::record_post_create( 700, 'Uploaded photo.jpg', '', 'upload-media', 'media' );

		// WordPress deletes the main file and leaves the generated size behind.
		$GLOBALS['karmcp_test']['wp_deletes'][700] = array( $main );

		$this->assertIsArray( KarMCP_Change_Log::rollback( $id ) );
		$this->assertFileDoesNotExist( $main );
		$this->assertFileDoesNotExist( $size, 'A size WordPress left behind is removed here, not reported as a clean undo.' );
	}

	public function test_a_file_that_cannot_be_removed_is_reported() {
		list( $main ) = $this->add_attachment( 701 );
		$outside      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'karmcp-outside-' . bin2hex( random_bytes( 4 ) ) . '.jpg';
		file_put_contents( $outside, 'x' );
		$id = KarMCP_Change_Recorder::record_post_create( 701, 'Uploaded', '', 'upload-media', 'media' );

		// Pretend the recorded list also named a file outside uploads, which is
		// never deleted from here.
		$log                                           = KarMCP_Change_Log::all();
		$log[0]['rollback']['files'][]                 = $outside;
		$GLOBALS['karmcp_test']['options'][ KarMCP_Change_Log::OPTION ] = $log;
		$GLOBALS['karmcp_test']['wp_deletes'][701]     = array( $main );

		$result = KarMCP_Change_Log::rollback( $id );
		unlink( $outside );

		$this->assertSame( 'rollback_incomplete', $result->get_error_code() );
		$this->assertStringContainsString( basename( $outside ), $result->get_error_message() );
	}

	public function test_replacing_the_image_bytes_after_upload_is_a_conflict() {
		list( $main ) = $this->add_attachment( 702 );
		$id           = KarMCP_Change_Recorder::record_post_create( 702, 'Uploaded', '', 'upload-media', 'media' );
		file_put_contents( $main, 'edited-bytes' );

		$this->assertSame( 'conflict', KarMCP_Change_Log::rollback( $id )->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// Page settings and meta
	// ---------------------------------------------------------------------

	private function record_page_settings( int $post_id, $before ): void {
		$method = new ReflectionMethod( KarMCP_Data::class, 'record_page_settings' );
		$method->setAccessible( true );
		$method->invoke( new KarMCP_Data(), $post_id, $before );
	}

	public function test_page_settings_on_a_page_that_had_none_are_deleted_on_undo() {
		$this->add_post( 800 );
		$before = KarMCP_Change_Recorder::meta_before( 'post', 800, '_elementor_page_settings' );
		update_post_meta( 800, '_elementor_page_settings', array( 'custom_css' => 'selector { color: red; }' ) );
		$this->record_page_settings( 800, $before );

		$entries = $this->ledger();
		$this->assertCount( 1, $entries );
		$this->assertIsArray( KarMCP_Change_Log::rollback( $entries[0]['id'] ) );
		$this->assertFalse( metadata_exists( 'post', 800, '_elementor_page_settings' ) );
	}

	public function test_undo_restores_custom_css_with_its_backslashes() {
		$this->add_post( 801 );
		$css = array( 'custom_css' => 'selector::before { content: "\\201C"; }' );
		// Seeded raw: through update_post_meta() the fixture itself would lose the backslash.
		$GLOBALS['karmcp_test']['post_meta'][801]['_elementor_page_settings'] = array( $css );
		$before = KarMCP_Change_Recorder::meta_before( 'post', 801, '_elementor_page_settings' );
		update_post_meta( 801, '_elementor_page_settings', array( 'custom_css' => 'selector { color: blue; }' ) );
		$this->record_page_settings( 801, $before );

		$this->assertIsArray( KarMCP_Change_Log::rollback( $this->ledger()[0]['id'] ) );
		$this->assertSame( $css, get_post_meta( 801, '_elementor_page_settings', true ), 'The metadata API unslashes on the way in; an unslashed restore eats the backslash.' );
	}

	public function test_a_save_that_changed_nothing_is_not_recorded() {
		$this->add_post( 802 );
		update_post_meta( 802, '_elementor_page_settings', array( 'padding' => '10' ) );
		$before = KarMCP_Change_Recorder::meta_before( 'post', 802, '_elementor_page_settings' );
		$this->record_page_settings( 802, $before );

		$this->assertSame( array(), $this->ledger() );
	}

	public function test_undoing_page_settings_drops_the_generated_css() {
		$this->add_post( 803 );
		$before = KarMCP_Change_Recorder::meta_before( 'post', 803, '_elementor_page_settings' );
		update_post_meta( 803, '_elementor_page_settings', array( 'padding' => '10' ) );
		$this->record_page_settings( 803, $before );
		update_post_meta( 803, '_elementor_css', array( 'status' => 'file' ) );

		KarMCP_Change_Log::rollback( $this->ledger()[0]['id'] );

		$this->assertFalse( metadata_exists( 'post', 803, '_elementor_css' ), 'The stylesheet was built from the settings being undone.' );
	}

	public function test_an_empty_prior_value_is_written_back_not_deleted() {
		$this->add_post( 804 );
		update_post_meta( 804, '_karmcp_note', '' );
		$id = KarMCP_Change_Recorder::record_meta( 'post', 804, array( '_karmcp_note' => KarMCP_Change_Recorder::meta_before( 'post', 804, '_karmcp_note' ) ), 'Edited' );
		update_post_meta( 804, '_karmcp_note', 'filled' );

		KarMCP_Change_Log::rollback( $id, true );

		$this->assertTrue( metadata_exists( 'post', 804, '_karmcp_note' ) );
		$this->assertSame( '', get_post_meta( 804, '_karmcp_note', true ) );
	}

	public function test_entries_written_before_the_marker_keep_their_old_meaning() {
		$this->add_post( 805 );
		update_post_meta( 805, '_karmcp_note', 'filled' );
		$id = KarMCP_Change_Log::record( array(
			'domain'   => 'globals',
			'action'   => 'update-globals',
			'rollback' => array( 'type' => 'meta-before-image', 'object' => 'post', 'id' => 805, 'before' => array( '_karmcp_note' => '' ) ),
		) );

		$this->assertIsArray( KarMCP_Change_Log::rollback( $id ) );
		$this->assertFalse( metadata_exists( 'post', 805, '_karmcp_note' ), 'In an old entry an empty value meant "was not set".' );
	}

	// ---------------------------------------------------------------------
	// Restores that cannot be done safely
	// ---------------------------------------------------------------------

	private function record_attachment_delete( array $snapshot ): string {
		return KarMCP_Change_Log::record( array(
			'domain'   => 'media',
			'action'   => 'delete-media',
			'rollback' => array( 'type' => 'attachment-delete', 'att_id' => (int) ( $snapshot['post']['ID'] ?? 0 ), 'snapshot' => $snapshot ),
		) );
	}

	public function test_a_restore_is_refused_when_its_id_now_belongs_to_something_else() {
		$trashed = $this->uploads . DIRECTORY_SEPARATOR . 'trashed.jpg';
		file_put_contents( $trashed, 'x' );
		$this->add_post( 900, array( 'post_title' => 'Someone else' ) );
		$id = $this->record_attachment_delete( array(
			'post'  => array( 'ID' => 900, 'post_type' => 'attachment', 'post_title' => 'Photo' ),
			'meta'  => array( '_wp_attached_file' => array( '2026/09/photo.jpg' ) ),
			'files' => array( array( 'orig' => $this->uploads . DIRECTORY_SEPARATOR . 'photo.jpg', 'trashed' => $trashed ) ),
		) );

		$result = KarMCP_Change_Log::rollback( $id, true );

		$this->assertSame( 'rollback_refused', $result->get_error_code(), 'Refused even with force: every reference to #900 would land on the wrong item.' );
		$this->assertSame( 'Someone else', get_post( 900 )->post_title );
		$this->assertFileDoesNotExist( $this->uploads . DIRECTORY_SEPARATOR . 'photo.jpg' );
	}

	public function test_an_attachment_deleted_without_a_file_copy_is_not_restored() {
		$id = $this->record_attachment_delete( array(
			'post'  => array( 'ID' => 901, 'post_type' => 'attachment' ),
			'meta'  => array( '_wp_attached_file' => array( '2026/09/photo.jpg' ) ),
			'files' => array(),
		) );

		$this->assertSame( 'rollback_refused', KarMCP_Change_Log::rollback( $id, true )->get_error_code() );
		$this->assertNull( get_post( 901 ) );
	}

	public function test_an_attachment_whose_saved_copy_vanished_is_not_restored() {
		$id = $this->record_attachment_delete( array(
			'post'  => array( 'ID' => 902, 'post_type' => 'attachment' ),
			'meta'  => array( '_wp_attached_file' => array( 'photo.jpg' ) ),
			'files' => array( array( 'orig' => $this->uploads . DIRECTORY_SEPARATOR . 'photo.jpg', 'trashed' => $this->uploads . DIRECTORY_SEPARATOR . 'gone.jpg' ) ),
		) );

		$this->assertSame( 'rollback_refused', KarMCP_Change_Log::rollback( $id )->get_error_code() );
	}

	public function test_a_deleted_attachment_comes_back_under_its_id_with_its_file() {
		$trashed = $this->uploads . DIRECTORY_SEPARATOR . 'trashed.jpg';
		$orig    = $this->uploads . DIRECTORY_SEPARATOR . 'restored' . DIRECTORY_SEPARATOR . 'photo.jpg';
		file_put_contents( $trashed, 'photo-bytes' );
		$GLOBALS['karmcp_test']['next_post_id'] = 902; // The stub hands out 903 next.
		$id = $this->record_attachment_delete( array(
			'post'  => array( 'ID' => 903, 'post_type' => 'attachment', 'post_title' => 'Photo' ),
			'meta'  => array( '_wp_attached_file' => array( 'restored/photo.jpg' ) ),
			'files' => array( array( 'orig' => $orig, 'trashed' => $trashed ) ),
		) );

		$this->assertIsArray( KarMCP_Change_Log::rollback( $id ) );
		$this->assertSame( 'Photo', get_post( 903 )->post_title );
		$this->assertStringEqualsFile( $orig, 'photo-bytes' );
	}

	public function test_a_restore_that_lands_under_a_new_id_is_undone_and_reported() {
		$trashed = $this->uploads . DIRECTORY_SEPARATOR . 'trashed.jpg';
		$orig    = $this->uploads . DIRECTORY_SEPARATOR . 'photo.jpg';
		file_put_contents( $trashed, 'photo-bytes' );
		$GLOBALS['karmcp_test']['next_post_id'] = 2000; // The stub ignores import_id.
		$id = $this->record_attachment_delete( array(
			'post'  => array( 'ID' => 904, 'post_type' => 'attachment' ),
			'meta'  => array( '_wp_attached_file' => array( 'photo.jpg' ) ),
			'files' => array( array( 'orig' => $orig, 'trashed' => $trashed ) ),
		) );

		$result = KarMCP_Change_Log::rollback( $id );

		$this->assertSame( 'rollback_failed', $result->get_error_code() );
		$this->assertNull( get_post( 2001 ), 'The stray copy is removed again.' );
		$this->assertFileDoesNotExist( $orig, 'And so is the file it put back.' );
	}

	private function fake_wpdb( int $affected, int $count ) {
		return new class( $affected, $count ) {
			public $last_error = '';
			public $queries    = array();
			private $affected;
			private $count;
			public function __construct( $affected, $count ) {
				$this->affected = $affected;
				$this->count    = $count;
			}
			public function update( $table, $row, $where ) {
				return $this->affected;
			}
			public function prepare( $sql, ...$args ) {
				$this->queries[] = array( $sql, $args );
				return $sql;
			}
			public function get_var( $sql ) {
				return (string) $this->count;
			}
		};
	}

	private function record_db_update(): string {
		return KarMCP_Change_Log::record( array(
			'domain'   => 'database',
			'action'   => 'update-rows',
			'rollback' => array( 'type' => 'db-before-image', 'table' => 'wp_things', 'op' => 'update', 'key_cols' => array( 'id' ), 'before_rows' => array( array( 'id' => 7, 'name' => 'old' ) ) ),
		) );
	}

	public function test_restoring_a_row_that_is_gone_is_not_a_restore() {
		$saved          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->fake_wpdb( 0, 0 );
		$result          = KarMCP_Change_Log::rollback( $this->record_db_update() );
		$GLOBALS['wpdb'] = $saved;

		$this->assertSame( 'rollback_failed', $result->get_error_code(), 'Zero rows updated because the row was deleted used to read as success.' );
	}

	public function test_a_row_already_at_its_prior_values_counts_as_restored() {
		$saved          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->fake_wpdb( 0, 1 );
		$result          = KarMCP_Change_Log::rollback( $this->record_db_update() );
		$GLOBALS['wpdb'] = $saved;

		$this->assertIsArray( $result );
	}

	public function test_a_trash_wordpress_would_not_undo_is_reported() {
		$GLOBALS['karmcp_test']['untrash_result'] = false;
		$id = KarMCP_Change_Log::record( array( 'domain' => 'content', 'action' => 'delete-post', 'rollback' => array( 'type' => 'post-restore', 'mode' => 'untrash', 'post_id' => 55 ) ) );

		$this->assertSame( 'rollback_failed', KarMCP_Change_Log::rollback( $id )->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// The machinery
	// ---------------------------------------------------------------------

	public function test_a_rollback_restores_the_outer_recording_state() {
		$this->add_post( 950 );
		$id = KarMCP_Change_Recorder::record_post_create( 950, 'Created' );

		KarMCP_Change_Log::$suppress = true;
		KarMCP_Change_Log::rollback( $id );

		$this->assertTrue( KarMCP_Change_Log::$suppress, 'A rollback run inside an unrecorded operation used to switch recording back on halfway through it.' );
	}

	public function test_the_compensating_entry_is_recorded_and_the_original_marked() {
		$this->add_post( 951 );
		$id = KarMCP_Change_Recorder::record_post_create( 951, 'Created' );

		$out = KarMCP_Change_Log::rollback( $id );

		$this->assertTrue( KarMCP_Change_Log::get( $id )['rolled_back'] );
		$this->assertSame( 'rollback', KarMCP_Change_Log::get( $out['compensating'] )['action'] );
		$this->assertSame( 'already_rolled_back', KarMCP_Change_Log::rollback( $id )->get_error_code() );
	}

	public function test_marking_an_unknown_entry_reports_failure() {
		$this->assertFalse( KarMCP_Change_Log::mark_rolled_back( 'nope' ) );
	}

	/**
	 * @dataProvider value_pairs
	 */
	public function test_values_read_back_compare_the_way_the_database_returns_them( $actual, $expected, bool $same ) {
		$this->assertSame( $same, KarMCP_Change_Log::same_value( $actual, $expected ) );
	}

	public static function value_pairs(): array {
		return array(
			'int stored, string read' => array( '5', 5, true ),
			'bool true'               => array( '1', true, true ),
			'bool false'              => array( '', false, true ),
			'different strings'       => array( 'a', 'b', false ),
			'equal arrays'            => array( array( 'a' => 1 ), array( 'a' => 1 ), true ),
			'arrays that differ'      => array( array( 'a' => 1 ), array( 'a' => 2 ), false ),
			'array versus string'     => array( 'Array', array( 'a' ), false ),
		);
	}
}
