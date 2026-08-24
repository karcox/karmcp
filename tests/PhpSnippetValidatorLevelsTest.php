<?php
/**
 * Three levels, and the reason there are three.
 *
 * The human approval step is the real safety boundary for snippets — an agent
 * can only ever create a draft. So anything that trains the reviewer to skim
 * attacks the boundary itself, and a validator that reported `$_POST`, `exit`
 * and a closure as WARNINGS did exactly that: a correct snippet arrived looking
 * like a problem, and after enough of those nobody reads the list.
 *
 * The headline test is therefore not about any single rule. It takes a snippet
 * of the kind actually written on these sites and asserts it comes back clean.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-php-snippet-validator.php';

class PhpSnippetValidatorLevelsTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/**
	 * @param string $code Snippet source.
	 * @return array Validation result.
	 */
	private function check( string $code ): array {
		return KarMCP_PHP_Snippet_Validator::validate( $code );
	}

	/**
	 * @param string $code Snippet source.
	 * @return string[] Severities, in order.
	 */
	private function severities( string $code ): array {
		return array_column( $this->check( $code )['findings'], 'severity' );
	}

	// -----------------------------------------------------------------
	// The point of the change
	// -----------------------------------------------------------------

	/**
	 * An ordinary, correct snippet: a shortcode and a redirect guard. Every
	 * construct in it was a WARNING before — the superglobal, the exit, and the
	 * two closures — so this arrived flagged four times over with nothing wrong.
	 */
	public function test_an_ordinary_snippet_raises_nothing_to_act_on(): void {
		$code = <<<'PHP'
add_shortcode( 'karcos_year', function () {
	return esc_html( gmdate( 'Y' ) );
} );

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['legacy'] ) ) {
		return;
	}
	wp_safe_redirect( home_url( '/' ) );
	exit;
} );
PHP;
		$result = $this->check( $code );

		$this->assertTrue( $result['valid'] );
		$this->assertTrue( $result['safe'] );
		$this->assertSame( 0, $result['counts']['critical'] );
		$this->assertSame( 0, $result['counts']['warning'] );
		$this->assertStringContainsString( 'Safe to activate', $result['verdict'] );
		$this->assertStringContainsString( 'ordinary in working code', $result['verdict'] );
	}

	public function test_a_closure_is_not_a_redeclaration_risk(): void {
		$this->assertSame( array(), $this->severities( 'add_action( "init", function () { return 1; } );' ) );
	}

	public function test_a_named_function_is_still_worth_a_note(): void {
		$this->assertSame( array( 'note' ), $this->severities( 'function karcos_helper() { return 1; }' ) );
	}

	// -----------------------------------------------------------------
	// What each level means
	// -----------------------------------------------------------------

	public function test_reading_request_input_is_a_note(): void {
		$this->assertSame( array( 'note' ), $this->severities( '$id = (int) $_GET["id"];' ) );
	}

	public function test_stopping_after_a_redirect_is_a_note(): void {
		$this->assertSame( array( 'note' ), $this->severities( 'wp_safe_redirect( "/" ); exit;' ) );
	}

	public function test_firing_a_hook_is_a_note(): void {
		$this->assertSame( array( 'note' ), $this->severities( 'do_action( "karcos_ready" );' ) );
	}

	public function test_writing_a_site_option_is_a_warning(): void {
		$this->assertSame( array( 'warning' ), $this->severities( 'update_option( "karcos_flag", 1 );' ) );
	}

	public function test_sending_mail_is_a_warning(): void {
		$this->assertSame( array( 'warning' ), $this->severities( 'wp_mail( $to, $subject, $body );' ) );
	}

	public function test_running_a_shell_command_is_critical(): void {
		$result = $this->check( 'system( "ls" );' );

		$this->assertFalse( $result['safe'] );
		$this->assertSame( 1, $result['counts']['critical'] );
	}

	// -----------------------------------------------------------------
	// Callbacks: the bypass, and the noise it used to cost
	// -----------------------------------------------------------------

	/**
	 * A string callback naming a dangerous function IS that call, spelled so the
	 * direct-call scan cannot see it. It used to be a mere warning, which means
	 * it did not block — the one finding that mattered, waved through.
	 */
	public function test_a_dangerous_string_callback_now_blocks(): void {
		$result = $this->check( 'array_map( "system", $commands );' );

		$this->assertFalse( $result['safe'] );
		$this->assertSame( 1, $result['counts']['critical'] );
	}

	public function test_an_ordinary_string_callback_is_a_note(): void {
		$this->assertSame( array( 'note' ), $this->severities( 'array_map( "trim", $values );' ) );
	}

	public function test_a_closure_callback_is_a_note(): void {
		$this->assertSame( array( 'note' ), $this->severities( 'usort( $rows, function ( $a, $b ) { return $a <=> $b; } );' ) );
	}

	// -----------------------------------------------------------------
	// The verdict
	// -----------------------------------------------------------------

	public function test_a_clean_snippet_says_so(): void {
		$this->assertSame( 'Safe to activate. Nothing flagged.', $this->check( 'return 1;' )['verdict'] );
	}

	public function test_the_verdict_counts_notes(): void {
		$this->assertSame(
			'Safe to activate. 2 notes, all ordinary in working code.',
			$this->check( '$a = $_GET["a"]; do_action( "x" );' )['verdict']
		);
	}

	/**
	 * One note, not "1 notes" — the plural form has to survive the count.
	 */
	public function test_a_single_note_reads_as_singular(): void {
		$this->assertStringContainsString( '1 note,', $this->check( '$a = $_GET["a"];' )['verdict'] );
	}

	public function test_a_warning_asks_the_reviewer_to_read_it(): void {
		$verdict = $this->check( 'update_option( "k", 1 ); $a = $_GET["a"];' )['verdict'];

		$this->assertStringContainsString( 'read the warnings first', $verdict );
		$this->assertStringContainsString( '1 warning', $verdict );
		$this->assertStringContainsString( '1 note', $verdict );
	}

	public function test_a_blocked_snippet_says_it_cannot_be_activated(): void {
		$this->assertStringContainsString( 'Cannot be activated', $this->check( 'eval( $x );' )['verdict'] );
	}

	public function test_a_snippet_that_does_not_parse_says_so(): void {
		$result = $this->check( 'function ( {' );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'Does not parse', $result['verdict'] );
	}

	public function test_an_empty_snippet_does_not_parse(): void {
		$this->assertFalse( $this->check( '   ' )['valid'] );
	}

	/**
	 * The counts drive both the verdict and the admin colouring, so they have to
	 * agree with the findings they came from.
	 */
	public function test_the_counts_match_the_findings(): void {
		$result = $this->check( 'eval( $x ); update_option( "k", 1 ); $a = $_GET["a"];' );
		$tally  = array_count_values( array_column( $result['findings'], 'severity' ) );

		$this->assertSame( $tally['critical'] ?? 0, $result['counts']['critical'] );
		$this->assertSame( $tally['warning'] ?? 0, $result['counts']['warning'] );
		$this->assertSame( $tally['note'] ?? 0, $result['counts']['note'] );
	}
}
