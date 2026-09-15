<?php
/**
 * install-uploaded-zip, up to the moment WordPress's upgrader would take over.
 *
 * The upgrader itself needs a real WordPress and is not exercised here. What
 * is, is everything that decides whether it may run at all, and in what order:
 * confirmation and input shape first, capabilities before the file is touched,
 * then the file proven to be the one the caller meant — confined to uploads,
 * really a ZIP, matching the SHA-256 — and only then the archive inspected.
 * The order is part of the security: a hash checked after inspection, or a
 * capability checked after reading the file, would each leak something.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-zip-package-inspector.php';
require_once __DIR__ . '/../includes/class-package-guard.php';
require_once __DIR__ . '/../includes/abilities/class-plugin-abilities.php';

// Media and file-mod stubs the harness does not provide. Guarded, and reading
// fixtures of their own, so they cannot change what any other test sees.
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		return $GLOBALS['karmcp_zip_test']['files'][ (int) $attachment_id ] ?? false;
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $post = null ) {
		return $GLOBALS['karmcp_zip_test']['mimes'][ (int) $post ] ?? false;
	}
}
if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	function wp_get_upload_dir() {
		return array( 'basedir' => $GLOBALS['karmcp_zip_test']['uploads'] ?? '' );
	}
}
if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir() {
		return rtrim( $GLOBALS['karmcp_zip_test']['temp'] ?? sys_get_temp_dir(), '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	// Core's behaviour, minus stream wrappers: this suite runs on Windows too,
	// which is exactly where an unnormalised prefix check broke.
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );
		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}
		return $path;
	}
}

class InstallUploadedZipTest extends TestCase {

	private string $uploads = '';

	/** @var string[] */
	private array $created = array();

	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The zip extension is not available.' );
		}

		karmcp_test_reset();
		$GLOBALS['karmcp_test']['caps'] = array( 'install_plugins', 'install_themes', 'update_plugins', 'update_themes', 'activate_plugins' );

		$this->uploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'karmcp-uploads-' . bin2hex( random_bytes( 4 ) );
		$this->temp    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'karmcp-work-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploads );
		mkdir( $this->temp );

		$GLOBALS['karmcp_zip_test'] = array(
			'files'   => array(),
			'mimes'   => array(),
			'uploads' => $this->uploads,
			'temp'    => $this->temp,
		);
	}

	private string $temp = '';

	protected function tearDown(): void {
		foreach ( $this->created as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( array( $this->uploads, $this->temp ) as $dir ) {
			foreach ( (array) glob( $dir . DIRECTORY_SEPARATOR . '*' ) as $leftover ) {
				if ( is_file( (string) $leftover ) ) {
					unlink( (string) $leftover );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		unset( $GLOBALS['karmcp_zip_test'] );
	}

	/** Working copies still sitting in the temp folder. */
	private function leftover_copies(): array {
		return (array) glob( $this->temp . DIRECTORY_SEPARATOR . 'karmcp-zip-*' );
	}

	/**
	 * Registers a ZIP as Media Library attachment 42 and returns its SHA-256.
	 *
	 * @param array<string,string> $entries Entry name => contents.
	 * @param string               $dir     Where to put the file (defaults to uploads).
	 * @param string               $mime    Stored mime type.
	 * @return string SHA-256 of the archive.
	 */
	private function attach_zip( array $entries, string $dir = '', string $mime = 'application/zip' ): string {
		$path            = ( '' !== $dir ? $dir : $this->uploads ) . DIRECTORY_SEPARATOR . 'package-' . bin2hex( random_bytes( 3 ) ) . '.zip';
		$this->created[] = $path;

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();

		$GLOBALS['karmcp_test']['posts'][42]       = (object) array( 'ID' => 42, 'post_type' => 'attachment' );
		$GLOBALS['karmcp_zip_test']['files'][42] = $path;
		$GLOBALS['karmcp_zip_test']['mimes'][42] = $mime;

		return hash_file( 'sha256', $path );
	}

	private function plugin_zip(): array {
		return array( 'good/good.php' => "<?php\n/* Plugin Name: Good */" );
	}

	private function run_tool( array $input ) {
		return ( new KarMCP_Plugin_Abilities() )->execute_install_uploaded_zip(
			array_merge(
				array(
					'type'          => 'plugin',
					'attachment_id' => 42,
					'sha256'        => str_repeat( 'a', 64 ),
					'confirm'       => true,
				),
				$input
			)
		);
	}

	private function code( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	// ---------------------------------------------------------------------
	// Before anything is read
	// ---------------------------------------------------------------------

	public function test_nothing_happens_without_confirmation() {
		$this->assertSame( 'confirm_required', $this->code( $this->run_tool( array( 'confirm' => false ) ) ) );
	}

	public function test_a_malformed_hash_is_refused() {
		$this->assertSame( 'invalid_sha256', $this->code( $this->run_tool( array( 'sha256' => 'abc123' ) ) ) );
	}

	public function test_activation_is_plugins_only() {
		$this->assertSame(
			'activate_not_supported',
			$this->code( $this->run_tool( array( 'type' => 'theme', 'activate' => true ) ) )
		);
	}

	public function test_the_install_capability_is_checked_before_the_file_is_touched() {
		$GLOBALS['karmcp_test']['caps'] = array( 'install_themes' );

		// No attachment exists at all: reaching it would say attachment_not_found.
		$result = $this->run_tool( array() );

		$this->assertSame( 'missing_capability', $this->code( $result ) );
		$this->assertSame( 'install_plugins', $result->get_error_data()['required_capability'] );
	}

	public function test_overwriting_needs_the_update_capability_too() {
		$GLOBALS['karmcp_test']['caps'] = array( 'install_plugins' );

		$result = $this->run_tool( array( 'overwrite' => true ) );

		$this->assertSame( 'missing_capability', $this->code( $result ) );
		$this->assertSame( 'update_plugins', $result->get_error_data()['required_capability'] );
	}

	public function test_activating_needs_the_activate_capability_too() {
		$GLOBALS['karmcp_test']['caps'] = array( 'install_plugins' );

		$result = $this->run_tool( array( 'activate' => true ) );

		$this->assertSame( 'activate_plugins', $result->get_error_data()['required_capability'] );
	}

	// ---------------------------------------------------------------------
	// Proving the file is the one the caller meant
	// ---------------------------------------------------------------------

	public function test_a_missing_attachment_is_reported_as_such() {
		$this->assertSame( 'attachment_not_found', $this->code( $this->run_tool( array() ) ) );
	}

	public function test_a_file_outside_the_uploads_folder_is_refused() {
		$elsewhere = sys_get_temp_dir();
		$sha       = $this->attach_zip( $this->plugin_zip(), $elsewhere );

		$this->assertSame( 'attachment_outside_uploads', $this->code( $this->run_tool( array( 'sha256' => $sha ) ) ) );
	}

	public function test_an_attachment_that_is_not_a_zip_is_refused_even_if_labelled_as_one() {
		$path            = $this->uploads . DIRECTORY_SEPARATOR . 'fake.zip';
		$this->created[] = $path;
		file_put_contents( $path, '<?php system($_GET["c"]);' );

		$GLOBALS['karmcp_test']['posts'][42]       = (object) array( 'ID' => 42, 'post_type' => 'attachment' );
		$GLOBALS['karmcp_zip_test']['files'][42] = $path;
		$GLOBALS['karmcp_zip_test']['mimes'][42] = 'application/zip';

		$this->assertSame( 'not_a_zip', $this->code( $this->run_tool( array( 'sha256' => hash_file( 'sha256', $path ) ) ) ) );
	}

	public function test_a_zip_stored_under_another_mime_type_is_refused() {
		$sha = $this->attach_zip( $this->plugin_zip(), '', 'image/png' );

		$this->assertSame( 'not_a_zip', $this->code( $this->run_tool( array( 'sha256' => $sha ) ) ) );
	}

	public function test_a_file_that_does_not_match_the_hash_is_not_installed() {
		$this->attach_zip( $this->plugin_zip() );

		$result = $this->run_tool( array( 'sha256' => str_repeat( 'b', 64 ) ) );

		$this->assertSame( 'sha256_mismatch', $this->code( $result ) );
	}

	public function test_the_archive_is_inspected_only_after_the_hash_matched() {
		// A hostile archive with a WRONG hash must fail on the hash, never
		// reach the inspector: the hash is what says this is the caller's file.
		$this->attach_zip( array( 'evil/evil.php' => "<?php\n/* Plugin Name: Evil */", 'evil/../../x.php' => '<?php' ) );

		$this->assertSame( 'sha256_mismatch', $this->code( $this->run_tool( array( 'sha256' => str_repeat( 'c', 64 ) ) ) ) );
	}

	public function test_a_matching_hash_on_a_hostile_archive_still_stops_at_inspection() {
		$sha = $this->attach_zip( array( 'evil/evil.php' => "<?php\n/* Plugin Name: Evil */", 'evil/../../x.php' => '<?php' ) );

		$this->assertSame( 'zip_unsafe_path', $this->code( $this->run_tool( array( 'sha256' => $sha ) ) ) );
	}

	public function test_the_hash_is_compared_case_insensitively() {
		$sha = $this->attach_zip( array( 'evil/evil.php' => "<?php\n/* Plugin Name: Evil */", 'evil/../../x.php' => '<?php' ) );

		// Upper-case hex from a client is the same hash; it gets past the hash
		// check and is stopped by the inspector, which proves it matched.
		$this->assertSame( 'zip_unsafe_path', $this->code( $this->run_tool( array( 'sha256' => strtoupper( $sha ) ) ) ) );
	}

	// ---------------------------------------------------------------------
	// The working copy: what is checked is what gets installed
	// ---------------------------------------------------------------------

	public function test_the_file_installed_is_a_private_copy_not_the_upload_itself() {
		$sha    = $this->attach_zip( $this->plugin_zip() );
		$method = new ReflectionMethod( KarMCP_Plugin_Abilities::class, 'verified_upload' );
		$method->setAccessible( true );

		$zip = $method->invoke( null, 42, $sha );

		$this->assertIsArray( $zip );
		$this->assertNotSame(
			wp_normalize_path( (string) realpath( $GLOBALS['karmcp_zip_test']['files'][42] ) ),
			wp_normalize_path( $zip['path'] ),
			'Installing from the attachment path would let anything that replaces it after the hash check be installed instead.'
		);
		$this->assertSame( $sha, $zip['sha256'] );

		// Swap the upload for something else, the way a race would.
		file_put_contents( $GLOBALS['karmcp_zip_test']['files'][42], 'replaced after verification' );
		$this->assertSame( $sha, hash_file( 'sha256', $zip['path'] ), 'The copy is untouched by what happens to the upload.' );

		unlink( $zip['path'] );
	}

	public function test_the_working_copy_is_removed_when_the_install_is_refused() {
		$sha = $this->attach_zip( array( 'evil/evil.php' => "<?php\n/* Plugin Name: Evil */", 'evil/../../x.php' => '<?php' ) );

		$this->assertSame( 'zip_unsafe_path', $this->code( $this->run_tool( array( 'sha256' => $sha ) ) ) );
		$this->assertSame( array(), $this->leftover_copies(), 'A refused install must not leave a copy of the archive in temp.' );
	}

	public function test_the_working_copy_is_removed_when_the_hash_does_not_match() {
		$this->attach_zip( $this->plugin_zip() );

		$this->assertSame( 'sha256_mismatch', $this->code( $this->run_tool( array( 'sha256' => str_repeat( 'd', 64 ) ) ) ) );
		$this->assertSame( array(), $this->leftover_copies() );
	}

	// ---------------------------------------------------------------------
	// Into the destination: overwrite, protection, identity
	// ---------------------------------------------------------------------

	private function refusal( string $type, bool $dir_exists, ?array $existing, string $incoming_name, bool $overwrite ): ?string {
		$result = KarMCP_Plugin_Abilities::overwrite_refusal(
			$type,
			$dir_exists,
			$existing,
			array(
				'slug'    => 'pkg',
				'name'    => $incoming_name,
				'version' => '2.0',
			),
			$overwrite
		);

		return null === $result ? null : $result->get_error_code();
	}

	public function test_a_fresh_destination_is_always_fine() {
		$this->assertNull( $this->refusal( 'plugin', false, null, 'Anything', false ) );
	}

	public function test_an_existing_package_needs_overwrite() {
		$this->assertSame(
			'destination_exists',
			$this->refusal( 'plugin', true, array( 'name' => 'Pkg', 'version' => '1.0', 'file' => 'pkg/pkg.php' ), 'Pkg', false )
		);
	}

	public function test_an_unidentifiable_folder_is_never_overwritten() {
		$this->assertSame( 'destination_unidentified', $this->refusal( 'plugin', true, null, 'Pkg', true ) );
	}

	public function test_protected_plugins_are_refused_even_when_the_names_match() {
		// The case worth refusing is exactly the one with matching names: a ZIP
		// can declare "Elementor", and update-plugin refuses these too.
		$this->assertSame(
			'protected_plugin',
			$this->refusal( 'plugin', true, array( 'name' => 'Elementor', 'version' => '4.2.3', 'file' => 'elementor/elementor.php' ), 'Elementor', true )
		);
		$this->assertSame(
			'protected_plugin',
			$this->refusal( 'plugin', true, array( 'name' => 'Elementor Pro', 'version' => '4.2.2', 'file' => 'elementor-pro/elementor-pro.php' ), 'Elementor Pro', true )
		);
	}

	public function test_an_ordinary_plugin_with_the_same_name_may_be_replaced() {
		$this->assertNull(
			$this->refusal( 'plugin', true, array( 'name' => 'JetEngine', 'version' => '3.8.14', 'file' => 'jet-engine/jet-engine.php' ), 'JetEngine', true )
		);
	}

	public function test_a_package_declaring_another_name_is_refused() {
		$this->assertSame(
			'identity_mismatch',
			$this->refusal( 'plugin', true, array( 'name' => 'JetEngine', 'version' => '3.8.14', 'file' => 'jet-engine/jet-engine.php' ), 'Something Else', true )
		);
	}

	public function test_a_theme_with_the_same_name_may_be_replaced() {
		$this->assertNull( $this->refusal( 'theme', true, array( 'name' => 'Hello Elementor', 'version' => '3.4', 'file' => '' ), 'Hello Elementor', true ) );
	}

	// ---------------------------------------------------------------------
	// Identity for overwrites
	// ---------------------------------------------------------------------

	public function test_the_same_name_is_the_same_package() {
		$this->assertTrue( KarMCP_Plugin_Abilities::same_package_name( 'KarMCP', 'KarMCP' ) );
		$this->assertTrue( KarMCP_Plugin_Abilities::same_package_name( 'Elementor Pro', '  elementor pro ' ) );
	}

	public function test_a_different_name_is_not_the_same_package() {
		$this->assertFalse( KarMCP_Plugin_Abilities::same_package_name( 'Elementor', 'Totally Elementor' ) );
		$this->assertFalse( KarMCP_Plugin_Abilities::same_package_name( 'Elementor', 'Elementor Pro' ) );
	}

	public function test_an_installed_package_with_no_name_matches_nothing() {
		$this->assertFalse( KarMCP_Plugin_Abilities::same_package_name( '', '' ) );
		$this->assertFalse( KarMCP_Plugin_Abilities::same_package_name( '   ', 'Anything' ) );
	}
}
