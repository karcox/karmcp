<?php
/**
 * The archive inspector that stands between an uploaded ZIP and the upgrader.
 *
 * Installing from an upload installs code nobody reviewed on the way in, so
 * each guard here is exercised against a real archive built to break it — a
 * path that climbs out of the package, a symbolic link, files spread at the
 * root, a package that does not say what it is, and an entry that expands to
 * fill the disk. A guard proven only against a well-formed ZIP is a guard that
 * was never tested.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-zip-package-inspector.php';

class ZipPackageInspectorTest extends TestCase {

	/** @var string[] Archives created by a test, removed afterwards. */
	private array $created = array();

	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The zip extension is not available.' );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->created as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Builds a ZIP from name => contents, with optional per-entry tweaks.
	 *
	 * @param array<string,string> $entries  Entry name => contents.
	 * @param callable|null        $finalize Receives the open ZipArchive before close.
	 * @return string Path to the archive.
	 */
	private function zip( array $entries, ?callable $finalize = null ): string {
		$path            = tempnam( sys_get_temp_dir(), 'karmcp-zip-' );
		$this->created[] = $path;

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		if ( $finalize ) {
			$finalize( $zip );
		}
		$zip->close();

		return $path;
	}

	private function plugin_main( string $name = 'Good Plugin' ): string {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Version: 2.3.1\n * Requires PHP: 8.1\n * Requires at least: 6.9\n */\n";
	}

	private function code( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the archive to be refused.' );
		return $result->get_error_code();
	}

	// ---------------------------------------------------------------------
	// What a good package looks like
	// ---------------------------------------------------------------------

	public function test_a_well_formed_plugin_is_described() {
		$result = KarMCP_Zip_Package_Inspector::inspect(
			$this->zip(
				array(
					'good-plugin/good-plugin.php' => $this->plugin_main(),
					'good-plugin/includes/a.php'  => '<?php // helper',
					'good-plugin/readme.txt'      => 'readme',
				)
			),
			'plugin'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'good-plugin', $result['slug'] );
		$this->assertSame( 'Good Plugin', $result['name'] );
		$this->assertSame( '2.3.1', $result['version'] );
		$this->assertSame( '8.1', $result['requires_php'] );
		$this->assertSame( '6.9', $result['requires_wp'] );
		$this->assertSame( 'good-plugin/good-plugin.php', $result['main_file'] );
		$this->assertSame( 3, $result['entries'] );
	}

	public function test_a_plugin_whose_main_file_is_not_named_after_the_folder_is_still_found() {
		$result = KarMCP_Zip_Package_Inspector::inspect(
			$this->zip(
				array(
					'karmcp/uninstall.php' => '<?php // no header here',
					'karmcp/karmcp.php'    => $this->plugin_main( 'KarMCP' ),
				)
			),
			'plugin'
		);

		$this->assertSame( 'KarMCP', $result['name'] );
		$this->assertSame( 'karmcp/karmcp.php', $result['main_file'] );
	}

	public function test_a_well_formed_theme_is_described() {
		$result = KarMCP_Zip_Package_Inspector::inspect(
			$this->zip(
				array(
					'my-theme/style.css' => "/*\nTheme Name: My Theme\nVersion: 1.0\n*/",
					'my-theme/index.php' => '<?php',
				)
			),
			'theme'
		);

		$this->assertSame( 'my-theme', $result['slug'] );
		$this->assertSame( 'My Theme', $result['name'] );
		$this->assertSame( '1.0', $result['version'] );
	}

	// ---------------------------------------------------------------------
	// Escaping the package folder
	// ---------------------------------------------------------------------

	public function test_an_entry_that_climbs_out_of_the_package_is_refused() {
		$this->assertSame(
			'zip_unsafe_path',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip(
						array(
							'evil/evil.php'          => $this->plugin_main(),
							'evil/../../wp-config.php' => '<?php // overwrite',
						)
					),
					'plugin'
				)
			)
		);
	}

	/**
	 * @dataProvider unsafe_paths
	 */
	public function test_unsafe_entry_paths_are_recognised( string $path ) {
		$this->assertFalse( KarMCP_Zip_Package_Inspector::path_is_safe( $path ) );
	}

	public static function unsafe_paths(): array {
		return array(
			'parent segment'        => array( 'pkg/../escape.php' ),
			'leading parent'        => array( '../escape.php' ),
			'absolute'              => array( '/etc/cron.d/job' ),
			'backslash separator'   => array( 'pkg\\..\\escape.php' ),
			'windows drive'         => array( 'C:/Windows/evil.dll' ),
			'stream wrapper'        => array( 'phar://pkg/evil' ),
			'embedded NUL'          => array( "pkg/evil.php\0.txt" ),
			'empty'                 => array( '' ),
		);
	}

	public function test_ordinary_paths_are_safe() {
		foreach ( array( 'pkg/file.php', 'pkg/assets/app.min.js', 'pkg/dir/', 'pkg/..hidden-but-fine', 'pkg/a..b.php' ) as $path ) {
			$this->assertTrue( KarMCP_Zip_Package_Inspector::path_is_safe( $path ), $path );
		}
	}

	public function test_a_symbolic_link_entry_is_refused() {
		$path = $this->zip(
			array(
				'linky/linky.php' => $this->plugin_main(),
				'linky/secret'    => '/etc/passwd',
			),
			static function ( ZipArchive $zip ) {
				$zip->setExternalAttributesName( 'linky/secret', ZipArchive::OPSYS_UNIX, ( 0120777 ) << 16 );
			}
		);

		$this->assertSame( 'zip_symlink', $this->code( KarMCP_Zip_Package_Inspector::inspect( $path, 'plugin' ) ) );
	}

	public function test_a_regular_unix_file_is_not_mistaken_for_a_link() {
		$this->assertFalse( KarMCP_Zip_Package_Inspector::is_symlink_entry( ZipArchive::OPSYS_UNIX, ( 0100644 ) << 16 ) );
		$this->assertFalse( KarMCP_Zip_Package_Inspector::is_symlink_entry( ZipArchive::OPSYS_UNIX, ( 0040755 ) << 16 ) );
		$this->assertTrue( KarMCP_Zip_Package_Inspector::is_symlink_entry( ZipArchive::OPSYS_UNIX, ( 0120777 ) << 16 ) );
	}

	// ---------------------------------------------------------------------
	// One folder, named sensibly
	// ---------------------------------------------------------------------

	public function test_a_file_at_the_archive_root_is_refused() {
		$this->assertSame(
			'zip_no_folder',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip( array( 'loose-plugin.php' => $this->plugin_main() ) ),
					'plugin'
				)
			)
		);
	}

	public function test_two_top_level_folders_are_refused() {
		$this->assertSame(
			'zip_no_folder',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip(
						array(
							'first/first.php'   => $this->plugin_main(),
							'second/second.php' => '<?php',
						)
					),
					'plugin'
				)
			)
		);
	}

	public function test_a_plugin_zipped_on_a_mac_is_accepted() {
		// macOS adds __MACOSX/ beside the package; WordPress skips it on
		// extraction, so it must not count as a second top-level folder.
		$result = KarMCP_Zip_Package_Inspector::inspect(
			$this->zip(
				array(
					'good-plugin/good-plugin.php'          => $this->plugin_main(),
					'__MACOSX/good-plugin/._good-plugin.php' => "\0\5\x16\7resource-fork",
				)
			),
			'plugin'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'good-plugin', $result['slug'] );
	}

	public function test_an_archive_holding_only_mac_metadata_is_refused() {
		$this->assertSame(
			'zip_no_folder',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip( array( '__MACOSX/x/._x.php' => 'fork' ) ),
					'plugin'
				)
			)
		);
	}

	public function test_mac_metadata_is_still_path_checked() {
		// Skipped by WordPress, but not by the guards: a traversal hidden there
		// is refused like any other.
		$this->assertSame(
			'zip_unsafe_path',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip(
						array(
							'good-plugin/good-plugin.php' => $this->plugin_main(),
							'__MACOSX/../../escape.php'   => '<?php',
						)
					),
					'plugin'
				)
			)
		);
	}

	public function test_folder_names_that_are_not_package_names_are_refused() {
		foreach ( array( '.hidden', '..', 'has space', '-leading-dash', str_repeat( 'a', 101 ) ) as $slug ) {
			$this->assertFalse( KarMCP_Zip_Package_Inspector::slug_is_valid( $slug ), $slug );
		}
		foreach ( array( 'karmcp', 'elementor-pro', 'My_Plugin.v2', 'a' ) as $slug ) {
			$this->assertTrue( KarMCP_Zip_Package_Inspector::slug_is_valid( $slug ), $slug );
		}
	}

	// ---------------------------------------------------------------------
	// Packages that do not say what they are
	// ---------------------------------------------------------------------

	public function test_a_plugin_with_no_header_is_refused() {
		$this->assertSame(
			'zip_not_a_plugin',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip( array( 'anon/anon.php' => "<?php\n// just code" ) ),
					'plugin'
				)
			)
		);
	}

	public function test_a_header_buried_below_the_top_level_does_not_count() {
		// WordPress only reads direct children of the plugin folder.
		$this->assertSame(
			'zip_not_a_plugin',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip( array( 'deep/lib/main.php' => $this->plugin_main() ) ),
					'plugin'
				)
			)
		);
	}

	public function test_a_theme_without_style_css_is_refused() {
		$this->assertSame(
			'zip_not_a_theme',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip( array( 'theme-x/index.php' => '<?php' ) ),
					'theme'
				)
			)
		);
	}

	public function test_a_plugin_uploaded_as_a_theme_is_refused() {
		$this->assertSame(
			'zip_not_a_theme',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip( array( 'good-plugin/good-plugin.php' => $this->plugin_main() ) ),
					'theme'
				)
			)
		);
	}

	// ---------------------------------------------------------------------
	// Archives built to fill the disk
	// ---------------------------------------------------------------------

	public function test_too_many_entries_are_refused() {
		$entries = array( 'many/many.php' => $this->plugin_main() );
		for ( $i = 0; $i < 5; $i++ ) {
			$entries[ "many/f{$i}.txt" ] = 'x';
		}

		$this->assertSame(
			'zip_too_many_entries',
			$this->code( KarMCP_Zip_Package_Inspector::inspect( $this->zip( $entries ), 'plugin', array( 'max_entries' => 3 ) ) )
		);
	}

	public function test_an_archive_that_expands_past_the_limit_is_refused() {
		$this->assertSame(
			'zip_too_large',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip(
						array(
							'big/big.php'  => $this->plugin_main(),
							'big/data.bin' => random_bytes( 4096 ),
						)
					),
					'plugin',
					array( 'max_uncompressed' => 2048 )
				)
			)
		);
	}

	public function test_an_entry_that_expands_far_beyond_its_compressed_size_is_refused() {
		// Two megabytes of zeros deflate to a few kilobytes.
		$this->assertSame(
			'zip_ratio',
			$this->code(
				KarMCP_Zip_Package_Inspector::inspect(
					$this->zip(
						array(
							'bomb/bomb.php'  => $this->plugin_main(),
							'bomb/zeros.bin' => str_repeat( "\0", 2 * 1048576 ),
						)
					),
					'plugin'
				)
			)
		);
	}

	public function test_an_incompressible_large_file_is_not_mistaken_for_a_bomb() {
		$result = KarMCP_Zip_Package_Inspector::inspect(
			$this->zip(
				array(
					'media/media.php'  => $this->plugin_main(),
					'media/video.bin'  => random_bytes( 1100000 ),
				)
			),
			'plugin'
		);

		$this->assertIsArray( $result );
	}

	// ---------------------------------------------------------------------
	// Not an archive at all, and header parsing
	// ---------------------------------------------------------------------

	public function test_a_file_that_is_not_a_zip_is_refused() {
		$path            = tempnam( sys_get_temp_dir(), 'karmcp-notzip-' );
		$this->created[] = $path;
		file_put_contents( $path, 'this is not a zip' );

		$this->assertSame( 'zip_unreadable', $this->code( KarMCP_Zip_Package_Inspector::inspect( $path, 'plugin' ) ) );
	}

	public function test_an_unknown_package_type_is_refused() {
		$this->assertSame(
			'invalid_type',
			$this->code( KarMCP_Zip_Package_Inspector::inspect( $this->zip( array( 'x/x.php' => $this->plugin_main() ) ), 'mu-plugin' ) )
		);
	}

	public function test_headers_are_read_the_way_wordpress_reads_them() {
		$header = KarMCP_Zip_Package_Inspector::parse_header(
			"<?php\n/*\n * Plugin Name:   Spaced Out   \n * Version: 1.2 */\n",
			array(
				'name'    => 'Plugin Name',
				'version' => 'Version',
				'missing' => 'Requires PHP',
			)
		);

		$this->assertSame( 'Spaced Out', $header['name'] );
		$this->assertSame( '1.2', $header['version'], 'A closing comment marker is not part of the value.' );
		$this->assertSame( '', $header['missing'] );
	}
}
