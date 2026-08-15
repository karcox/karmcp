<?php
/**
 * The template compiler — the seam that keeps agent-supplied markup as data.
 *
 * These pin the property the whole sandbox builder rests on: text that is not a
 * placeholder is emitted as a PHP string literal, so a template can never turn
 * into code, no matter what it contains. The rest is balance and vocabulary:
 * an unclosed conditional or an undeclared name has to fail loudly at compile
 * time, because the alternative is a widget that fatals on a visitor's page.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';

class SandboxTemplateTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/** Controls available to most tests. */
	private function controls(): array {
		return array(
			'quote'  => 'text',
			'body'   => 'wysiwyg',
			'shown'  => 'switcher',
			'image'  => 'media',
		);
	}

	/** A trivial emitter: the expression records what it was asked for. */
	private function emit(): callable {
		return static function ( string $name, string $type, string $subprop, string $modifier ) {
			$suffix = '' !== $subprop ? '.' . $subprop : '';
			$suffix .= '' !== $modifier ? '|' . $modifier : '';
			return "EXPR('{$name}{$suffix}',{$type})";
		};
	}

	private function truthy(): callable {
		return static function ( string $name, string $type ) {
			return "TRUTHY('{$name}')";
		};
	}

	private function compile( string $template, ?array $controls = null ) {
		return KarMCP_Sandbox_Template::compile(
			$template,
			$controls ?? $this->controls(),
			$this->emit(),
			$this->truthy(),
			''
		);
	}

	public function test_literal_text_is_emitted_as_a_php_string_literal(): void {
		$php = $this->compile( '<p>Hello</p>' );
		$this->assertSame( "echo '<p>Hello</p>';\n", $php );
	}

	/**
	 * The one that matters: markup that looks like PHP is still just text.
	 *
	 * Asserted by running the compiled output — if the template had become
	 * code, this would print "pwned" instead of the tag itself.
	 */
	public function test_php_looking_markup_stays_a_literal(): void {
		$php = $this->compile( '<?php echo "pwned"; ?>' );

		ob_start();
		eval( $php ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- executing the compiler's own output is the assertion.
		$printed = ob_get_clean();

		$this->assertSame( '<?php echo "pwned"; ?>', $printed );
	}

	/** Same property for a `<script>` payload: it comes out as visible text. */
	public function test_script_markup_stays_a_literal(): void {
		$php = $this->compile( '<script>alert(1)</script>' );

		ob_start();
		eval( $php ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- executing the compiler's own output is the assertion.
		$printed = ob_get_clean();

		$this->assertSame( '<script>alert(1)</script>', $printed );
	}

	/** Quotes in the template must not break out of the emitted literal. */
	public function test_quotes_and_backslashes_are_escaped_in_literals(): void {
		$php = $this->compile( "it's a \\ backslash" );
		$this->assertSame( "echo 'it\\'s a \\\\ backslash';\n", $php );
	}

	public function test_placeholder_becomes_the_emitters_expression(): void {
		$php = $this->compile( '<p>{{quote}}</p>' );
		$this->assertSame(
			"echo '<p>';\necho EXPR('quote',text);\necho '</p>';\n",
			$php
		);
	}

	public function test_modifier_and_subproperty_reach_the_emitter(): void {
		$php = $this->compile( '{{quote|attr}}{{image.url}}' );
		$this->assertStringContainsString( "EXPR('quote|attr',text)", $php );
		$this->assertStringContainsString( "EXPR('image.url',media)", $php );
	}

	public function test_conditional_wraps_the_block(): void {
		$php = $this->compile( '{{#if shown}}<b>{{quote}}</b>{{/if}}' );
		$this->assertStringContainsString( "if ( TRUTHY('shown') ) {", $php );
		$this->assertStringContainsString( "}\n", $php );
		$this->assertStringContainsString( "EXPR('quote',text)", $php );
	}

	public function test_unclosed_conditional_is_rejected(): void {
		$result = $this->compile( '{{#if shown}}<b>x</b>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_unbalanced', $result->get_error_code() );
	}

	public function test_stray_closing_conditional_is_rejected(): void {
		$result = $this->compile( 'x{{/if}}' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_unbalanced', $result->get_error_code() );
	}

	public function test_unknown_control_is_rejected(): void {
		$result = $this->compile( '{{nope}}' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_unknown_control', $result->get_error_code() );
	}

	public function test_unknown_control_in_conditional_is_rejected(): void {
		$result = $this->compile( '{{#if nope}}x{{/if}}' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_unknown_control', $result->get_error_code() );
	}

	public function test_unknown_modifier_is_rejected(): void {
		$result = $this->compile( '{{quote|raw}}' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_bad_modifier', $result->get_error_code() );
	}

	public function test_unknown_subproperty_is_rejected(): void {
		$result = $this->compile( '{{image.exec}}' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_bad_subprop', $result->get_error_code() );
	}

	public function test_malformed_placeholder_is_rejected(): void {
		$result = $this->compile( '{{ quote() }}' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_bad_placeholder', $result->get_error_code() );
	}

	public function test_nesting_beyond_the_limit_is_rejected(): void {
		$template = str_repeat( '{{#if shown}}', KarMCP_Sandbox_Template::MAX_DEPTH + 1 )
			. 'x'
			. str_repeat( '{{/if}}', KarMCP_Sandbox_Template::MAX_DEPTH + 1 );

		$result = $this->compile( $template );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_too_deep', $result->get_error_code() );
	}

	public function test_referenced_names_collects_values_and_conditionals(): void {
		$names = KarMCP_Sandbox_Template::referenced_names( '{{#if shown}}{{quote}}{{image.url}}{{quote}}{{/if}}' );
		$this->assertSame( array( 'shown', 'quote', 'image' ), $names );
	}

	/** An emitter's error is the compiler's error — it must not be swallowed. */
	public function test_emitter_error_propagates(): void {
		$result = KarMCP_Sandbox_Template::compile(
			'{{quote}}',
			$this->controls(),
			static function () {
				return new WP_Error( 'emitter_said_no', 'nope' );
			},
			$this->truthy(),
			''
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'emitter_said_no', $result->get_error_code() );
	}
}
