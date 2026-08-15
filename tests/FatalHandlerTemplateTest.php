<?php
/**
 * The fatal-error handler drop-in, before it ever reaches a site.
 *
 * This one is unusual and worth explaining. The drop-in is generated from a
 * heredoc, and it runs *on shutdown after a fatal* — the one context where a
 * mistake cannot be reported, because the thing that would report it is what
 * just died. A broken drop-in is a fatal on every single request with nothing
 * in the output to say why.
 *
 * So the test compiles it. `php -l` on the generated source is the only way to
 * know a stray interpolation or an unescaped `$` has not turned the file into a
 * parse error, and no amount of reading catches that reliably.
 *
 * The rest pins the guard rails that keep auto-deactivation from being worse
 * than the bug it answers.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security/class-fatal-handler-template.php';

class FatalHandlerTemplateTest extends TestCase {

	/** @var string */
	private static $source;

	public static function setUpBeforeClass(): void {
		self::$source = KarMCP_Fatal_Handler_Template::source();
	}

	/**
	 * The one that matters. A parse error here is a site that answers nothing,
	 * on every request, with no clue as to the cause.
	 */
	public function test_the_generated_dropin_is_valid_php(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'karmcp-dropin' ) . '.php';
		file_put_contents( $tmp, self::$source );

		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $status );
		unlink( $tmp );

		$this->assertSame( 0, $status, "El drop-in generado no compila:\n" . implode( "\n", $output ) );
	}

	public function test_it_carries_the_marker_that_identifies_it_as_ours(): void {
		$this->assertStringContainsString( KarMCP_Fatal_Handler_Template::MARKER, self::$source );
	}

	public function test_it_extends_the_core_handler_rather_than_replacing_it(): void {
		// Subclassing keeps WordPress's own behaviour — the recovery email, the
		// front-end message — and only adds recording on top.
		$this->assertStringContainsString( 'extends WP_Fatal_Error_Handler', self::$source );
		$this->assertStringContainsString( 'parent::handle()', self::$source );
	}

	/**
	 * Whatever else goes wrong, the handler must not become the failure. It runs
	 * with memory possibly exhausted and WordPress half-loaded.
	 */
	public function test_its_own_work_is_wrapped_so_it_can_never_be_the_failure(): void {
		$this->assertStringContainsString( 'catch ( \\Throwable', self::$source );
	}

	public function test_it_depends_on_nothing_from_the_plugin(): void {
		// Neither the autoloader nor any plugin class is reliably available at
		// the moment this runs. Checked as *usage*, not as the word: the file's
		// own header says "no autoloader", and a test that failed on the comment
		// explaining the rule would be worse than no test.
		foreach ( array( 'vendor/autoload', 'autoload_packages', 'KARMCP_DIR', 'KarMCP_Security_', 'KarMCP_Fatal_Handler_Template' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, self::$source, $forbidden );
		}

		// The only require it may make is of a WordPress core file, by absolute
		// path from ABSPATH.
		preg_match_all( '/require(?:_once)?\s+([^;]+);/', self::$source, $requires );
		foreach ( (array) ( $requires[1] ?? array() ) as $target ) {
			$this->assertStringContainsString( 'ABSPATH', $target, trim( $target ) );
		}
	}

	// -----------------------------------------------------------------
	// The guard rails on auto-deactivation
	// -----------------------------------------------------------------

	/**
	 * Deactivating on the first fatal would let one transient error take a shop
	 * offline — a different outage, not a fix. It has to be a repeat.
	 */
	public function test_deactivation_needs_repeated_fatals_in_a_window(): void {
		$this->assertStringContainsString( 'strikes', self::$source );
		$this->assertStringContainsString( 'window', self::$source );
		$this->assertStringContainsString( '$hits < $strikes', self::$source );
	}

	public function test_auto_pause_is_off_unless_switched_on(): void {
		$this->assertStringContainsString( "empty( \$config['auto_pause'] )", self::$source );
	}

	/** The plugin must never be able to deactivate itself and lose the way back. */
	public function test_karmcp_can_never_deactivate_itself(): void {
		$this->assertStringContainsString( "'karmcp/karmcp.php' === \$plugin", self::$source );
	}

	public function test_a_protected_list_is_honoured(): void {
		$this->assertStringContainsString( 'in_array( $plugin, $protected, true )', self::$source );
	}

	public function test_the_log_is_bounded(): void {
		// Unbounded, this option grows until it is the site's biggest row.
		$this->assertStringContainsString( 'self::KEEP', self::$source );
		$this->assertStringContainsString( 'array_slice', self::$source );
	}

	public function test_paths_are_stored_relative_to_the_wordpress_root(): void {
		// Absolute paths leak the server layout to every client that reads the log.
		$this->assertStringContainsString( 'karmcp_relative', self::$source );
	}

	/**
	 * Measured on a real site: the log filled with `ini_set()` warnings, an
	 * "undefined property" notice and a deprecation — none of them fatal. That
	 * was noise in the log and a genuine hazard in the auto-pause counter, which
	 * would have deactivated Elementor Pro over a notice. error_get_last()
	 * returns the last error of ANY severity, so the type has to be checked.
	 */
	public function test_only_genuinely_fatal_error_types_are_recorded(): void {
		$this->assertStringContainsString( 'FATAL_TYPES', self::$source );
		$this->assertStringContainsString( "in_array( (int) ( \$error['type'] ?? 0 ), self::FATAL_TYPES, true )", self::$source );

		// E_WARNING, E_NOTICE and E_DEPRECATED must not be in the list, or the
		// filter is decorative.
		preg_match( '/const FATAL_TYPES = array\(([^)]*)\)/', self::$source, $m );
		$list = $m[1] ?? '';
		$this->assertNotSame( '', $list );
		foreach ( array( 'E_WARNING', 'E_NOTICE', 'E_DEPRECATED', 'E_USER_WARNING', 'E_USER_NOTICE', 'E_USER_DEPRECATED', 'E_STRICT' ) as $benign ) {
			$this->assertStringNotContainsString( $benign, $list, $benign . ' must not count as a fatal.' );
		}
		foreach ( array( 'E_ERROR', 'E_PARSE', 'E_COMPILE_ERROR' ) as $fatal ) {
			$this->assertStringContainsString( $fatal, $list, $fatal );
		}
	}
}
