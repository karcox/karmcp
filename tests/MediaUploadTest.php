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
 * Since 1.33.0 the decode happens inside a stream filter on the way to disk
 * (the decoded file never exists as a string — that shape is what hosts'
 * malware scanners flag), so validity is settled by normalize_upload_payload()
 * up front and the round-trip tests read the file back. The filter skips
 * invalid bytes silently, which is why the refusals pinned here matter: they
 * are the only thing standing between a garbage payload and a corrupt upload.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-filename-guard.php';
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

	/**
	 * The check runs on the name BEFORE sanitize_file_name(): core defuses the
	 * inner extension by renaming it to "php_", so a check placed after would
	 * pass everything and this refusal would be dead code on a real site.
	 */
	public function test_an_executable_extension_hidden_inside_the_name_is_refused(): void {
		foreach ( array( 'photo.php.jpg', 'photo.PHP.jpg', 'photo.phtml.png', 'shell.phar.gif' ) as $name ) {
			$err = $this->call( 'resolve_upload_filename', $name );
			$this->assertInstanceOf( WP_Error::class, $err, $name );
			$this->assertSame( 'executable_filename', $err->get_error_code(), $name );
		}
	}

	public function test_the_executable_refusal_names_the_offending_extension(): void {
		$err = $this->call( 'resolve_upload_filename', 'photo.php.jpg' );
		$this->assertStringContainsString( '.php', $err->get_error_message() );
	}

	public function test_harmless_extra_dots_in_a_filename_still_pass(): void {
		$this->assertSame( 'mi.foto.bonita.jpg', $this->call( 'resolve_upload_filename', 'mi.foto.bonita.jpg' ) );
	}

	/**
	 * The 1.33.0 list included 'pl', which refused legitimate names carrying
	 * the Poland ccTLD as a segment. The segment-level rules live in
	 * FilenameGuardTest; this pins the end-to-end outcome through the tool.
	 */
	public function test_a_country_code_segment_is_not_refused(): void {
		$this->assertSame( 'krakow.pl.jpg', $this->call( 'resolve_upload_filename', 'krakow.pl.jpg' ) );
	}

	// -----------------------------------------------------------------
	// normalize_upload_payload() + write_decoded_payload()
	// -----------------------------------------------------------------

	/**
	 * Runs a payload through the same two steps as the executor: validate,
	 * then stream-decode into a real temp file, and returns the file's bytes.
	 *
	 * @param mixed $raw The `data` input value.
	 * @return string|WP_Error
	 */
	private function decode_via_file( $raw ) {
		$payload = $this->call( 'normalize_upload_payload', $raw );
		if ( $payload instanceof WP_Error ) {
			return $payload;
		}

		$tmp_file = tempnam( sys_get_temp_dir(), 'karmcp-test' );
		$this->assertNotFalse( $tmp_file );
		try {
			$written = $this->call( 'write_decoded_payload', $payload, $tmp_file );
			if ( $written instanceof WP_Error ) {
				return $written;
			}
			return (string) file_get_contents( $tmp_file );
		} finally {
			unlink( $tmp_file );
		}
	}

	public function test_plain_base64_round_trips(): void {
		$bytes = "\x89PNG\r\n\x1a\n" . 'not really a png';
		$this->assertSame( $bytes, $this->decode_via_file( base64_encode( $bytes ) ) );
	}

	public function test_a_data_uri_prefix_is_stripped(): void {
		$payload = 'data:image/png;base64,' . base64_encode( 'abc' );
		$this->assertSame( 'abc', $this->decode_via_file( $payload ) );
	}

	public function test_line_wrapped_base64_is_accepted(): void {
		$bytes = str_repeat( 'wrapped-payload', 20 );
		$this->assertSame(
			$bytes,
			$this->decode_via_file( chunk_split( base64_encode( $bytes ), 76, "\r\n" ) )
		);
	}

	/**
	 * base64_decode() in strict mode tolerated missing "=" padding, so the
	 * validator must keep accepting it — some clients trim it.
	 */
	public function test_unpadded_base64_is_accepted(): void {
		$this->assertSame( 'abcde', $this->decode_via_file( rtrim( base64_encode( 'abcde' ), '=' ) ) );
	}

	/**
	 * Strict mode also accepted non-canonical-but-complete padding shapes;
	 * they must keep round-tripping ("YWJ=" is remainder-3 data plus one pad).
	 */
	public function test_correctly_padded_quanta_round_trip(): void {
		$this->assertSame( 'a', $this->decode_via_file( 'YQ==' ) );
		$this->assertSame( 'ab', $this->decode_via_file( 'YWI=' ) );
	}

	/**
	 * The 1.33.0 regression this pins: a stray "=" after a complete quantum
	 * ("YWJj=") passed validation, the re-pad step inflated it to "YWJj====",
	 * and the failure surfaced as temp_write_failed ("check the temp
	 * directory is writable") plus a PHP warning from the stream filter — the
	 * agent then debugs a nonexistent disk problem. Strict base64_decode()
	 * refused all of these as invalid_base64, and so must the validator:
	 * a payload that carries "=" must be a complete multiple of 4.
	 */
	public function test_a_stray_pad_after_a_complete_quantum_is_invalid_base64(): void {
		foreach ( array( 'YWJj=', 'YWJj==', 'YWJ==', 'YQ=' ) as $payload ) {
			$err = $this->call( 'normalize_upload_payload', $payload );
			$this->assertInstanceOf( WP_Error::class, $err, $payload );
			$this->assertSame( 'invalid_base64', $err->get_error_code(), $payload );
		}
	}

	/**
	 * The chunked write hands the filter 1 MiB of encoded input at a time, so a
	 * payload spanning several chunks pins that no bytes are lost or doubled at
	 * the seams.
	 */
	public function test_a_multi_chunk_payload_round_trips(): void {
		$bytes  = random_bytes( 1024 );
		$bytes  = str_repeat( $bytes, 3 * 1024 ); // 3 MiB decoded, 4 MiB encoded.
		$result = $this->decode_via_file( base64_encode( $bytes ) );
		$this->assertSame( strlen( $bytes ), strlen( $result ) );
		$this->assertSame( hash( 'sha256', $bytes ), hash( 'sha256', $result ) );
	}

	public function test_a_non_base64_payload_is_refused(): void {
		// The stream filter would swallow these silently — the validator is the
		// only thing that turns them into an error instead of a corrupt upload.
		foreach ( array( '/home/user/photo.jpg', '{"data":"x"}', 'YWJj=extra', 'x' ) as $payload ) {
			$err = $this->call( 'normalize_upload_payload', $payload );
			$this->assertInstanceOf( WP_Error::class, $err, $payload );
			$this->assertSame( 'invalid_base64', $err->get_error_code(), $payload );
		}
	}

	public function test_an_empty_payload_is_refused(): void {
		foreach ( array( '', '   ', 'data:image/png;base64,', null, 42 ) as $payload ) {
			$err = $this->call( 'normalize_upload_payload', $payload );
			$this->assertInstanceOf( WP_Error::class, $err );
			$this->assertSame( 'missing_params', $err->get_error_code() );
		}
	}

	public function test_a_payload_over_the_upload_limit_is_refused(): void {
		$GLOBALS['karmcp_test']['max_upload'] = 1024;

		$err = $this->call( 'normalize_upload_payload', base64_encode( str_repeat( 'x', 4096 ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'file_too_large', $err->get_error_code() );
		// The limit is named so an agent resizes instead of retrying the same bytes.
		$this->assertStringContainsString( 'MB', $err->get_error_message() );
	}

	public function test_a_payload_within_the_upload_limit_passes(): void {
		$GLOBALS['karmcp_test']['max_upload'] = 4096;

		$bytes = str_repeat( 'x', 1024 );
		$this->assertSame( $bytes, $this->decode_via_file( base64_encode( $bytes ) ) );
	}

	public function test_an_unknown_upload_limit_does_not_refuse_anything(): void {
		$GLOBALS['karmcp_test']['max_upload'] = 0;

		$bytes = str_repeat( 'x', 200000 );
		$this->assertSame( $bytes, $this->decode_via_file( base64_encode( $bytes ) ) );
	}

	/**
	 * The size ceiling still counts DECODED bytes, exactly: 1024 encoded chars
	 * decode to 768 bytes, which must pass a 768-byte limit and fail a 767 one.
	 */
	public function test_the_size_estimate_is_exact_not_padded(): void {
		$bytes = str_repeat( 'x', 768 );

		$GLOBALS['karmcp_test']['max_upload'] = 768;
		$this->assertSame( $bytes, $this->decode_via_file( base64_encode( $bytes ) ) );

		$GLOBALS['karmcp_test']['max_upload'] = 767;
		$err = $this->call( 'normalize_upload_payload', base64_encode( $bytes ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'file_too_large', $err->get_error_code() );
	}
}
