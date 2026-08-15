<?php
/**
 * upload-media: what it accepts before it writes anything.
 *
 * The tool takes a file as base64 from the client, so the two things worth
 * pinning are the ones that run before a byte reaches disk: who is allowed to
 * upload (and, when the upload is attached to a post, whether they may edit
 * *that* post), and what the payload decoder refuses.
 *
 * The size ceiling is checked from the ENCODED length first — base64 costs 4
 * bytes per 3, so refusing early avoids holding the decoded copy alongside the
 * payload. That estimate is the behaviour under test, not an implementation
 * detail: it is what keeps an oversized upload from becoming a memory fatal
 * instead of an error message.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/class-media-library-abilities.php';

class MediaUploadTest extends TestCase {

	/** @var KarMCP_Media_Library_Abilities */
	private $abilities;

	protected function setUp(): void {
		// Resets 'max_upload' and 'allowed_ext' with everything else; the stubs
		// that read them (wp_max_upload_size, wp_check_filetype, size_format,
		// sanitize_file_name) live in bootstrap.php, because sanitize_file_name
		// is also reached from content-abilities and a stub declared here would
		// apply or not depending on test-file load order.
		karmcp_test_reset();

		// The constructor only type-hints KarMCP_Data to hand it to the read
		// tools; nothing under test touches it, and instantiating it would drag
		// in the Elementor data layer.
		$this->abilities = ( new ReflectionClass( KarMCP_Media_Library_Abilities::class ) )
			->newInstanceWithoutConstructor();
	}

	/**
	 * Calls one of the private payload helpers.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		// No setAccessible(): reflection has reached private methods without it
		// since PHP 8.1, and the call is deprecated as of 8.5.
		$ref = new ReflectionMethod( KarMCP_Media_Library_Abilities::class, $method );
		return $ref->invokeArgs( $this->abilities, $args );
	}

	// -----------------------------------------------------------------
	// check_upload_permission()
	// -----------------------------------------------------------------

	/**
	 * Registers post 42 so the existence check passes and the capability is what
	 * decides. Without it the callback answers post_not_found, correctly.
	 */
	private function given_post_42_exists(): void {
		$GLOBALS['karmcp_test']['posts'][42] = (object) array(
			'ID'        => 42,
			'post_type' => 'page',
		);
	}

	public function test_upload_requires_the_upload_files_capability(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' );

		$err = $this->abilities->check_upload_permission( array() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_capability', $err->get_error_code() );
		$this->assertSame( 'upload_files', $err->get_error_data()['required_capability'] );
	}

	public function test_upload_files_alone_is_enough_when_nothing_is_attached(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts', 'upload_files' );
		$this->assertTrue( $this->abilities->check_upload_permission( array() ) );
		$this->assertTrue( $this->abilities->check_upload_permission( null ) );
	}

	public function test_attaching_to_a_post_the_user_cannot_edit_is_refused(): void {
		$GLOBALS['karmcp_test']['caps']      = array( 'edit_posts', 'upload_files' );
		$GLOBALS['karmcp_test']['post_caps'] = array( 42 => false );
		$this->given_post_42_exists();

		$err = $this->abilities->check_upload_permission( array( 'post_id' => 42 ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'cannot_edit_post', $err->get_error_code() );
		$this->assertSame( 'page', $err->get_error_data()['post_type'] );
	}

	public function test_attaching_to_a_post_the_user_can_edit_is_allowed(): void {
		$GLOBALS['karmcp_test']['caps']      = array( 'edit_posts', 'upload_files' );
		$GLOBALS['karmcp_test']['post_caps'] = array( 42 => true );
		$this->given_post_42_exists();

		$this->assertTrue( $this->abilities->check_upload_permission( array( 'post_id' => 42 ) ) );
	}

	public function test_edit_rights_on_the_parent_do_not_substitute_for_upload_files(): void {
		$GLOBALS['karmcp_test']['caps']      = array( 'edit_posts' );
		$GLOBALS['karmcp_test']['post_caps'] = array( 42 => true );
		$this->given_post_42_exists();

		$err = $this->abilities->check_upload_permission( array( 'post_id' => 42 ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_capability', $err->get_error_code() );
	}

	/**
	 * Found by running it against a real site. map_meta_cap() resolves edit_post
	 * against a post that is not there to do_not_allow, so checking the
	 * capability before existence answered a bare "Permission denied" for what
	 * was really a mistyped id — and made the post_not_found branch in the
	 * executor unreachable. Existence is resolved first.
	 */
	public function test_a_parent_that_does_not_exist_says_so_instead_of_denying_permission(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts', 'upload_files' );

		$err = $this->abilities->check_upload_permission( array( 'post_id' => 999999999 ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'post_not_found', $err->get_error_code() );
		$this->assertSame( 999999999, $err->get_error_data()['post_id'] );
		$this->assertStringNotContainsStringIgnoringCase( 'permission', $err->get_error_message() );
	}

	// -----------------------------------------------------------------
	// resolve_upload_filename()
	// -----------------------------------------------------------------

	public function test_a_plain_filename_survives_intact(): void {
		$this->assertSame( 'team-photo.jpg', $this->call( 'resolve_upload_filename', 'team-photo.jpg' ) );
	}

	public function test_a_posix_path_is_reduced_to_its_basename(): void {
		$this->assertSame( 'photo.jpg', $this->call( 'resolve_upload_filename', '/home/me/pics/photo.jpg' ) );
	}

	/**
	 * The bug this guards: basename() only knows the separator of the platform it
	 * runs on, so on a Linux server a Windows client's path arrives as one long
	 * "name" and sanitize_file_name() flattens it to "CUsersmephoto.jpg" — a
	 * successful upload under a garbage name, which is worse than an error.
	 */
	public function test_a_windows_path_is_reduced_to_its_basename(): void {
		$this->assertSame( 'photo.jpg', $this->call( 'resolve_upload_filename', 'C:\\Users\\me\\Pictures\\photo.jpg' ) );
	}

	public function test_a_traversal_attempt_cannot_escape_the_basename(): void {
		$this->assertSame( 'photo.jpg', $this->call( 'resolve_upload_filename', '../../../../etc/photo.jpg' ) );
	}

	public function test_an_empty_filename_is_refused(): void {
		foreach ( array( '', '   ', '/', '../', null ) as $name ) {
			$err = $this->call( 'resolve_upload_filename', $name );
			$this->assertInstanceOf( WP_Error::class, $err, var_export( $name, true ) );
			$this->assertSame( 'missing_params', $err->get_error_code(), var_export( $name, true ) );
		}
	}

	public function test_a_filename_without_an_extension_is_refused(): void {
		$err = $this->call( 'resolve_upload_filename', 'photo' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_extension', $err->get_error_code() );
	}

	public function test_a_type_the_site_does_not_accept_is_refused(): void {
		foreach ( array( 'payload.php', 'installer.exe', 'logo.svg' ) as $name ) {
			$err = $this->call( 'resolve_upload_filename', $name );
			$this->assertInstanceOf( WP_Error::class, $err, $name );
			$this->assertSame( 'disallowed_file_type', $err->get_error_code(), $name );
		}
	}

	public function test_svg_is_accepted_once_the_site_allows_it(): void {
		// What the SVG Support module does to get_allowed_mime_types().
		$GLOBALS['karmcp_test']['allowed_ext'] = array( 'svg' => 'image/svg+xml' );

		$this->assertSame( 'logo.svg', $this->call( 'resolve_upload_filename', 'logo.svg' ) );
	}

	public function test_the_rejection_names_the_extension_it_rejected(): void {
		$err = $this->call( 'resolve_upload_filename', 'payload.PhP' );
		$this->assertStringContainsString( '.php', $err->get_error_message() );
	}

	// -----------------------------------------------------------------
	// decode_upload_payload()
	// -----------------------------------------------------------------

	public function test_plain_base64_round_trips(): void {
		$bytes = "\x89PNG\r\n\x1a\n" . 'not really a png';
		$this->assertSame( $bytes, $this->call( 'decode_upload_payload', base64_encode( $bytes ) ) );
	}

	public function test_a_data_uri_prefix_is_stripped(): void {
		$payload = 'data:image/png;base64,' . base64_encode( 'abc' );
		$this->assertSame( 'abc', $this->call( 'decode_upload_payload', $payload ) );
	}

	public function test_line_wrapped_base64_is_accepted(): void {
		$bytes = str_repeat( 'wrapped-payload', 20 );
		$this->assertSame(
			$bytes,
			$this->call( 'decode_upload_payload', chunk_split( base64_encode( $bytes ), 76, "\r\n" ) )
		);
	}

	public function test_a_non_base64_payload_is_refused(): void {
		$err = $this->call( 'decode_upload_payload', '/home/user/photo.jpg' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_base64', $err->get_error_code() );
	}

	public function test_an_empty_payload_is_refused(): void {
		foreach ( array( '', '   ', 'data:image/png;base64,', null, 42 ) as $payload ) {
			$err = $this->call( 'decode_upload_payload', $payload );
			$this->assertInstanceOf( WP_Error::class, $err );
			$this->assertSame( 'missing_params', $err->get_error_code() );
		}
	}

	public function test_a_payload_over_the_upload_limit_is_refused(): void {
		$GLOBALS['karmcp_test']['max_upload'] = 1024;

		$err = $this->call( 'decode_upload_payload', base64_encode( str_repeat( 'x', 4096 ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'file_too_large', $err->get_error_code() );
		// The limit is named so an agent resizes instead of retrying the same bytes.
		$this->assertStringContainsString( 'MB', $err->get_error_message() );
	}

	public function test_a_payload_within_the_upload_limit_passes(): void {
		$GLOBALS['karmcp_test']['max_upload'] = 4096;

		$bytes = str_repeat( 'x', 1024 );
		$this->assertSame( $bytes, $this->call( 'decode_upload_payload', base64_encode( $bytes ) ) );
	}

	public function test_an_unknown_upload_limit_does_not_refuse_anything(): void {
		$GLOBALS['karmcp_test']['max_upload'] = 0;

		$bytes = str_repeat( 'x', 200000 );
		$this->assertSame( $bytes, $this->call( 'decode_upload_payload', base64_encode( $bytes ) ) );
	}
}
