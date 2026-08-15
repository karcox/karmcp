<?php
/**
 * The block compiler, tested by running what it compiles.
 *
 * Like the widget generator tests, most of these execute the generated
 * render.php against hostile attributes and assert on what it printed. The
 * block.json assertions cover the other half of the contract: the attribute
 * schema the editor writes against has to match the types the render escapes
 * by, or a value arrives in a shape the render never expected.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';
require_once __DIR__ . '/../includes/sandbox/class-block-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-block-generator.php';

class BlockGeneratorTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function spec( array $attributes, string $template, array $meta = array() ): array {
		return array(
			'spec_version' => 1,
			'meta'         => array_merge( array( 'title' => 'Test Block' ), $meta ),
			'attributes'   => $attributes,
			'template'     => $template,
		);
	}

	/** Compiles, then runs the render file with the given attributes. */
	private function render( array $spec, array $attributes ): string {
		$compiled = KarMCP_Block_Generator::generate( $spec, 'karmcp/custom-1' );
		$this->assertIsArray( $compiled, is_wp_error( $compiled ) ? $compiled->get_error_message() : 'expected files' );

		$code = preg_replace( '/^<\?php/', '', $compiled['render.php'], 1 );

		ob_start();
		( static function () use ( $code, $attributes ) {
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- running the compiler's output IS the test.
		} )();

		return (string) ob_get_clean();
	}

	private function block_json( array $spec ): array {
		$compiled = KarMCP_Block_Generator::generate( $spec, 'karmcp/custom-1' );
		$this->assertIsArray( $compiled );

		return json_decode( $compiled['block.json'], true );
	}

	// -------------------------------------------------------------------------
	// The escaping promise
	// -------------------------------------------------------------------------

	public function test_text_attribute_output_is_html_escaped(): void {
		$html = $this->render(
			$this->spec( array( array( 'name' => 'heading', 'type' => 'text' ) ), '<h2>{{heading}}</h2>' ),
			array( 'heading' => '<script>alert(1)</script>' )
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_attr_modifier_escapes_for_an_attribute(): void {
		$html = $this->render(
			$this->spec(
				array(
					array(
						'name'    => 'tone',
						'type'    => 'select',
						'options' => array( 'calm' => 'Calm', 'loud' => 'Loud' ),
					),
				),
				'<div class="is-{{tone|attr}}">x</div>'
			),
			array( 'tone' => '"><img src=x onerror=alert(1)>' )
		);

		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_richtext_keeps_markup_but_drops_scripts(): void {
		$html = $this->render(
			$this->spec( array( array( 'name' => 'body', 'type' => 'richtext' ) ), '<div>{{body}}</div>' ),
			array( 'body' => '<p>Kept <em>italic</em></p><script>alert(1)</script>' )
		);

		$this->assertStringContainsString( '<em>italic</em>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_url_attribute_rejects_a_javascript_scheme(): void {
		$html = $this->render(
			$this->spec( array( array( 'name' => 'link', 'type' => 'url' ) ), '<a href="{{link}}">go</a>' ),
			array( 'link' => 'javascript:alert(1)' )
		);

		$this->assertStringContainsString( 'href=""', $html );
	}

	public function test_number_attribute_cannot_carry_markup(): void {
		$html = $this->render(
			$this->spec( array( array( 'name' => 'count', 'type' => 'number' ) ), '<span>{{count}}</span>' ),
			array( 'count' => '<b>9</b>' )
		);

		$this->assertStringContainsString( '<span>0</span>', $html );
	}

	public function test_image_alt_comes_from_the_library_and_is_escaped(): void {
		$GLOBALS['karmcp_test']['post_meta'][ 7 ] = array(
			'_wp_attachment_image_alt' => array( 'A "quoted" dog' ),
		);

		$html = $this->render(
			$this->spec( array( array( 'name' => 'photo', 'type' => 'image' ) ), '<img src="{{photo.url}}" alt="{{photo.alt}}" />' ),
			array( 'photo' => array( 'id' => 7, 'url' => 'https://example.com/dog.jpg' ) )
		);

		$this->assertStringContainsString( 'src="https://example.com/dog.jpg"', $html );
		$this->assertStringContainsString( 'alt="A &quot;quoted&quot; dog"', $html );
	}

	public function test_missing_attributes_render_empty_instead_of_fataling(): void {
		$html = $this->render(
			$this->spec(
				array(
					array( 'name' => 'heading', 'type' => 'text' ),
					array( 'name' => 'photo', 'type' => 'image' ),
				),
				'<h2>{{heading}}</h2><img src="{{photo.url}}" />'
			),
			array()
		);

		$this->assertSame( '<div><h2></h2><img src="" /></div>', $html );
	}

	public function test_toggle_conditional_gates_the_block(): void {
		$spec = $this->spec(
			array(
				array( 'name' => 'show_note', 'type' => 'toggle' ),
				array( 'name' => 'note', 'type' => 'text' ),
			),
			'{{#if show_note}}<p class="note">{{note}}</p>{{/if}}'
		);

		$this->assertSame(
			'<div><p class="note">Hello</p></div>',
			$this->render( $spec, array( 'show_note' => true, 'note' => 'Hello' ) )
		);
		$this->assertSame(
			'<div></div>',
			$this->render( $spec, array( 'show_note' => false, 'note' => 'Hello' ) )
		);
	}

	// -------------------------------------------------------------------------
	// block.json
	// -------------------------------------------------------------------------

	public function test_block_json_carries_the_identity_and_attribute_schema(): void {
		$json = $this->block_json(
			$this->spec(
				array(
					array( 'name' => 'heading', 'type' => 'text', 'default' => 'Hi' ),
					array( 'name' => 'count', 'type' => 'number', 'default' => 3 ),
					array( 'name' => 'show_note', 'type' => 'toggle' ),
					array( 'name' => 'photo', 'type' => 'image' ),
				),
				'<h2>{{heading}}</h2><span>{{count}}</span>{{#if show_note}}<i>{{photo.url}}</i>{{/if}}',
				array( 'title' => 'Callout', 'icon' => 'format-quote' )
			)
		);

		$this->assertSame( 3, $json['apiVersion'] );
		$this->assertSame( 'karmcp/custom-1', $json['name'] );
		$this->assertSame( 'Callout', $json['title'] );
		$this->assertSame( 'format-quote', $json['icon'] );
		$this->assertSame( KarMCP_Block_Generator::CATEGORY, $json['category'] );

		$this->assertSame( 'string', $json['attributes']['heading']['type'] );
		$this->assertSame( 'Hi', $json['attributes']['heading']['default'] );
		$this->assertSame( 'number', $json['attributes']['count']['type'] );
		// JSON has one number type: 3.0 round-trips as 3, which is what the
		// editor will read back.
		$this->assertEqualsWithDelta( 3, $json['attributes']['count']['default'], 0.0001 );
		$this->assertSame( 'boolean', $json['attributes']['show_note']['type'] );
		$this->assertFalse( $json['attributes']['show_note']['default'] );
		$this->assertSame( 'object', $json['attributes']['photo']['type'] );
	}

	public function test_a_hostile_icon_falls_back_to_the_default(): void {
		$json = $this->block_json(
			$this->spec(
				array( array( 'name' => 'heading', 'type' => 'text' ) ),
				'<h2>{{heading}}</h2>',
				array( 'icon' => '"><script>alert(1)</script>' )
			)
		);

		$this->assertSame( KarMCP_Block_Generator::DEFAULT_ICON, $json['icon'] );
	}

	public function test_generated_render_file_echoes_rather_than_returns(): void {
		$compiled = KarMCP_Block_Generator::generate(
			$this->spec( array( array( 'name' => 'heading', 'type' => 'text' ) ), '<h2>{{heading}}</h2>' ),
			'karmcp/custom-1'
		);

		// WordPress wraps a block render in its own buffer, so a return value
		// would be silently discarded.
		$this->assertStringContainsString( 'echo ', $compiled['render.php'] );
		$this->assertDoesNotMatchRegularExpression( '/^\s*return /m', $compiled['render.php'] );
	}

	// -------------------------------------------------------------------------
	// Editor payload
	// -------------------------------------------------------------------------

	public function test_editor_payload_describes_every_attribute_once(): void {
		$spec = $this->spec(
			array(
				array( 'name' => 'heading', 'type' => 'text', 'label' => 'Heading' ),
				array(
					'name'    => 'tone',
					'type'    => 'select',
					'options' => array( 'calm' => 'Calm', 'loud' => 'Loud' ),
				),
			),
			'<h2 class="is-{{tone|attr}}">{{heading}}</h2>'
		);

		$payload = KarMCP_Block_Generator::editor_payload( $spec, 'karmcp/custom-1' );

		$this->assertSame( 'karmcp/custom-1', $payload['name'] );
		$this->assertCount( 2, $payload['controls'] );
		$this->assertSame( 'text', $payload['controls'][0]['control'] );
		$this->assertSame( 'select', $payload['controls'][1]['control'] );
		$this->assertSame(
			array( array( 'value' => 'calm', 'label' => 'Calm' ), array( 'value' => 'loud', 'label' => 'Loud' ) ),
			$payload['controls'][1]['options']
		);

		// The editor writes attributes against this schema; it must be the same
		// one block.json declares, or values arrive in unexpected shapes.
		$this->assertSame(
			$this->block_json( $spec )['attributes'],
			json_decode( wp_json_encode( $payload['attributes'] ), true )
		);
	}

	// -------------------------------------------------------------------------
	// Refusals
	// -------------------------------------------------------------------------

	public function test_an_invalid_block_name_is_refused(): void {
		$result = KarMCP_Block_Generator::generate(
			$this->spec( array( array( 'name' => 'heading', 'type' => 'text' ) ), '<h2>{{heading}}</h2>' ),
			'NotABlockName'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_block_name', $result->get_error_code() );
	}

	public function test_subproperty_on_a_type_that_lacks_it_is_refused(): void {
		$result = KarMCP_Block_Generator::generate(
			$this->spec( array( array( 'name' => 'heading', 'type' => 'text' ) ), '<h2>{{heading.url}}</h2>' ),
			'karmcp/custom-1'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_bad_subprop', $result->get_error_code() );
	}

	public function test_attr_modifier_on_richtext_is_refused(): void {
		$result = KarMCP_Block_Generator::generate(
			$this->spec( array( array( 'name' => 'body', 'type' => 'richtext' ) ), '<div title="{{body|attr}}">x</div>' ),
			'karmcp/custom-1'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_bad_modifier', $result->get_error_code() );
	}
}
