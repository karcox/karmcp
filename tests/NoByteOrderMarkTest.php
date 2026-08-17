<?php
/**
 * No file in the plugin may start with a UTF-8 byte order mark.
 *
 * This is not style. PHP emits everything outside `<?php … ?>` verbatim, and a
 * BOM is three bytes outside it — so a single BOM'd file that WordPress loads
 * prepends `EF BB BF` to EVERY response the site produces. JSON stops parsing
 * ("expected value at line 1 column 1"), headers are already sent by the time
 * an endpoint tries to set its own, and the MCP server becomes unreachable
 * while the site itself still looks perfectly healthy.
 *
 * It happened: 1.16.3 shipped with a BOM on karmcp.php, added by bumping the
 * version with PowerShell's `Set-Content -Encoding utf8`, which on Windows
 * PowerShell 5.1 means "UTF-8 WITH BOM". Nothing caught it — not the suite, not
 * PHPCS, not PHPStan, not `php -l` — because the file is still valid PHP. Only
 * the live site failed, and it failed in a way that pointed nowhere near the
 * cause.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class NoByteOrderMarkTest extends TestCase {

	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Every file the plugin ships or loads, minus third-party trees.
	 *
	 * @return string[]
	 */
	private function plugin_files(): array {
		$root  = realpath( __DIR__ . '/..' );
		$files = array();

		foreach ( array( 'karmcp.php', 'readme.txt' ) as $name ) {
			$files[] = $root . '/' . $name;
		}

		foreach ( array( 'includes', 'tests' ) as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );
			foreach ( $it as $file ) {
				if ( in_array( strtolower( $file->getExtension() ), array( 'php', 'txt', 'js', 'css' ), true ) ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	public function test_no_file_starts_with_a_byte_order_mark(): void {
		$offenders = array();

		foreach ( $this->plugin_files() as $path ) {
			$handle = fopen( $path, 'rb' );
			if ( false === $handle ) {
				continue;
			}
			$head = (string) fread( $handle, 3 );
			fclose( $handle );

			if ( self::BOM === $head ) {
				$offenders[] = str_replace( realpath( __DIR__ . '/..' ), '', $path );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These files start with a UTF-8 BOM, which PHP will emit before every response:\n  "
				. implode( "\n  ", $offenders )
				. "\n\nStrip it (tail -c +4), and do not rewrite files with PowerShell's"
				. " Set-Content -Encoding utf8 — on Windows PowerShell 5.1 that WRITES a BOM."
		);
	}

	/**
	 * The plugin's entry point is the file that matters most — it is loaded on
	 * every single request — so it gets its own assertion with its own message.
	 */
	public function test_the_plugin_entry_point_opens_with_a_php_tag(): void {
		$src = (string) file_get_contents( __DIR__ . '/../karmcp.php' );
		$this->assertStringStartsWith(
			'<?php',
			$src,
			'karmcp.php must start with <?php and nothing else. Anything before it — a BOM, a blank line — is'
				. ' output, and output on every request breaks JSON responses and header() calls site-wide.'
		);
	}
}
