<?php
/**
 * The widget compiler, tested by running what it compiles.
 *
 * The admin screen tells site owners that these widgets are "PHP compiled by
 * this plugin from an AI-supplied spec" and that "output is escaped by control
 * type". That promise is only worth what these tests prove, so most of them
 * execute the generated class against hostile settings and assert on what it
 * actually printed — not on the source it produced.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/elementor.php';
require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';
require_once __DIR__ . '/../includes/sandbox/class-widget-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-widget-generator.php';

class WidgetGeneratorTest extends TestCase {

	/** Keeps every eval'd class name unique across the run. */
	private static int $seq = 0;

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/**
	 * Compiles a spec and loads the class, returning an instance.
	 *
	 * @param array $spec Widget spec.
	 * @return \Elementor\Widget_Base
	 */
	private function build( array $spec ): \Elementor\Widget_Base {
		$class = 'KarMCP_Widget_Test_' . ( ++self::$seq );
		$php   = KarMCP_Widget_Generator::generate( $spec, $class, 'karmcp_custom_test_' . self::$seq );

		$this->assertIsString( $php, is_wp_error( $php ) ? $php->get_error_message() : 'expected source' );

		eval( preg_replace( '/^<\?php/', '', $php, 1 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- running the compiler's output IS the test.

		$this->assertTrue( class_exists( $class ), 'The generated class did not load.' );

		return new $class();
	}

	private function spec( array $controls, string $template, array $meta = array() ): array {
		return array(
			'spec_version' => 1,
			'meta'         => array_merge( array( 'title' => 'Test Widget' ), $meta ),
			'controls'     => $controls,
			'template'     => $template,
		);
	}

	// -------------------------------------------------------------------------
	// The escaping promise
	// -------------------------------------------------------------------------

	public function test_text_control_output_is_html_escaped(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'quote', 'type' => 'text', 'label' => 'Quote' ) ),
				'<p>{{quote}}</p>'
			)
		);

		$html = $widget->karmcp_test_render( array( 'quote' => '<script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_attr_modifier_escapes_for_an_attribute(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'label', 'type' => 'text' ) ),
				'<div title="{{label|attr}}">x</div>'
			)
		);

		$html = $widget->karmcp_test_render( array( 'label' => '" onmouseover="steal()' ) );

		$this->assertStringNotContainsString( 'onmouseover="steal()', $html );
		$this->assertStringContainsString( '&quot;', $html );
	}

	public function test_select_value_used_as_a_class_cannot_break_out(): void {
		$widget = $this->build(
			$this->spec(
				array(
					array(
						'name'    => 'size',
						'type'    => 'select',
						'options' => array( 'sm' => 'Small', 'lg' => 'Large' ),
					),
				),
				'<div class="card is-{{size|attr}}">x</div>'
			)
		);

		$html = $widget->karmcp_test_render( array( 'size' => '"><img src=x onerror=alert(1)>' ) );

		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_wysiwyg_is_the_only_type_that_keeps_markup_and_still_drops_scripts(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'body', 'type' => 'wysiwyg' ) ),
				'<div class="body">{{body}}</div>'
			)
		);

		$html = $widget->karmcp_test_render(
			array( 'body' => '<p>Kept <strong>bold</strong></p><script>alert(1)</script>' )
		);

		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_number_control_cannot_carry_markup(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'count', 'type' => 'number' ) ),
				'<span>{{count}}</span>'
			)
		);

		$this->assertSame( '<span>7</span>', $widget->karmcp_test_render( array( 'count' => '7' ) ) );
		$this->assertSame( '<span>0</span>', $widget->karmcp_test_render( array( 'count' => '<script>alert(1)</script>' ) ) );
	}

	public function test_url_control_rejects_a_javascript_scheme(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'link', 'type' => 'url' ) ),
				'<a href="{{link.url}}">go</a>'
			)
		);

		$html = $widget->karmcp_test_render( array( 'link' => array( 'url' => 'javascript:alert(1)' ) ) );

		$this->assertSame( '<a href="">go</a>', $html );
	}

	public function test_url_subproperties_emit_safe_attribute_values(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'link', 'type' => 'url' ) ),
				'<a href="{{link.url}}" target="{{link.target}}" rel="{{link.rel}}">go</a>'
			)
		);

		$html = $widget->karmcp_test_render(
			array(
				'link' => array(
					'url'         => 'https://example.com/a',
					'is_external' => 'on',
					'nofollow'    => 'on',
				),
			)
		);

		$this->assertSame( '<a href="https://example.com/a" target="_blank" rel="nofollow">go</a>', $html );
	}

	public function test_media_alt_comes_from_the_library_and_is_escaped(): void {
		$GLOBALS['karmcp_test']['post_meta'][ 42 ] = array(
			'_wp_attachment_image_alt' => array( 'A "quoted" cat' ),
		);

		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'image', 'type' => 'media' ) ),
				'<img src="{{image.url}}" alt="{{image.alt}}" />'
			)
		);

		$html = $widget->karmcp_test_render(
			array( 'image' => array( 'id' => 42, 'url' => 'https://example.com/cat.jpg' ) )
		);

		$this->assertStringContainsString( 'src="https://example.com/cat.jpg"', $html );
		$this->assertStringContainsString( 'alt="A &quot;quoted&quot; cat"', $html );
	}

	public function test_missing_settings_render_empty_instead_of_fataling(): void {
		$widget = $this->build(
			$this->spec(
				array(
					array( 'name' => 'quote', 'type' => 'text' ),
					array( 'name' => 'image', 'type' => 'media' ),
					array( 'name' => 'link', 'type' => 'url' ),
				),
				'<p>{{quote}}</p><img src="{{image.url}}" /><a href="{{link.url}}">x</a>'
			)
		);

		// A widget saved before these controls existed has nothing stored.
		$html = $widget->karmcp_test_render( array() );

		$this->assertSame( '<p></p><img src="" /><a href="">x</a>', $html );
	}

	// -------------------------------------------------------------------------
	// Conditionals
	// -------------------------------------------------------------------------

	public function test_switcher_conditional_gates_the_block(): void {
		$widget = $this->build(
			$this->spec(
				array(
					array( 'name' => 'show_badge', 'type' => 'switcher' ),
					array( 'name' => 'badge', 'type' => 'text' ),
				),
				'{{#if show_badge}}<span class="badge">{{badge}}</span>{{/if}}'
			)
		);

		$on = $widget->karmcp_test_render( array( 'show_badge' => 'yes', 'badge' => 'New' ) );
		$this->assertSame( '<span class="badge">New</span>', $on );

		$off = $widget->karmcp_test_render( array( 'show_badge' => '', 'badge' => 'New' ) );
		$this->assertSame( '', $off );
	}

	public function test_media_conditional_uses_the_url(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'image', 'type' => 'media' ) ),
				'{{#if image}}<img src="{{image.url}}" />{{/if}}'
			)
		);

		$this->assertSame( '', $widget->karmcp_test_render( array( 'image' => array( 'id' => 0, 'url' => '' ) ) ) );
		$this->assertSame(
			'<img src="https://example.com/a.png" />',
			$widget->karmcp_test_render( array( 'image' => array( 'id' => 5, 'url' => 'https://example.com/a.png' ) ) )
		);
	}

	// -------------------------------------------------------------------------
	// Identity and controls
	// -------------------------------------------------------------------------

	public function test_widget_identity_comes_from_the_spec(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'quote', 'type' => 'text' ) ),
				'<p>{{quote}}</p>',
				array( 'title' => 'Testimonial Card', 'icon' => 'eicon-testimonial', 'keywords' => array( 'quote', 'review' ) )
			)
		);

		$this->assertSame( 'Testimonial Card', $widget->get_title() );
		$this->assertSame( 'eicon-testimonial', $widget->get_icon() );
		$this->assertSame( array( 'quote', 'review' ), $widget->get_keywords() );
		$this->assertSame( array( KarMCP_Widget_Generator::CATEGORY ), $widget->get_categories() );
	}

	public function test_a_hostile_icon_value_falls_back_to_the_default(): void {
		$widget = $this->build(
			$this->spec(
				array( array( 'name' => 'quote', 'type' => 'text' ) ),
				'<p>{{quote}}</p>',
				array( 'icon' => "eicon'; system('rm -rf /'); //" )
			)
		);

		$this->assertSame( KarMCP_Widget_Generator::DEFAULT_ICON, $widget->get_icon() );
	}

	public function test_controls_are_registered_in_balanced_sections(): void {
		$widget = $this->build(
			$this->spec(
				array(
					array( 'name' => 'quote', 'type' => 'text', 'label' => 'Quote', 'default' => 'Hi' ),
					array( 'name' => 'accent', 'type' => 'color', 'label' => 'Accent', 'section' => 'style' ),
				),
				'<p style="color:{{accent}}">{{quote}}</p>'
			)
		);

		// get_stack() throws on an unbalanced section, as Elementor would break.
		$stack = $widget->get_stack();

		$this->assertArrayHasKey( 'quote', $stack['controls'] );
		$this->assertArrayHasKey( 'accent', $stack['controls'] );
		$this->assertSame( 'Hi', $stack['controls']['quote']['default'] );
		$this->assertSame( \Elementor\Controls_Manager::COLOR, $stack['controls']['accent']['type'] );
		$this->assertSame( \Elementor\Controls_Manager::TAB_STYLE, $widget->karmcp_test_sections['karmcp_section_style']['tab'] );
	}

	public function test_asset_handles_are_declared_only_when_present(): void {
		$spec  = $this->spec( array( array( 'name' => 'quote', 'type' => 'text' ) ), '<p>{{quote}}</p>' );
		$class = 'KarMCP_Widget_Assets_' . ( ++self::$seq );

		$php = KarMCP_Widget_Generator::generate(
			$spec,
			$class,
			'karmcp_custom_assets',
			array( 'style_handle' => 'karmcp-widget-9-style', 'script_handle' => '' )
		);

		$this->assertStringContainsString( 'get_style_depends', $php );
		$this->assertStringNotContainsString( 'get_script_depends', $php );
	}

	// -------------------------------------------------------------------------
	// Refusals
	// -------------------------------------------------------------------------

	public function test_an_invalid_spec_is_refused_before_any_code_is_produced(): void {
		$result = KarMCP_Widget_Generator::generate(
			$this->spec( array(), '<p><?php echo 1; ?></p>' ),
			'KarMCP_Widget_Bad',
			'karmcp_custom_bad'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_php', $result->get_error_code() );
	}

	public function test_attr_modifier_on_a_type_that_cannot_use_it_is_refused(): void {
		$result = KarMCP_Widget_Generator::generate(
			$this->spec(
				array( array( 'name' => 'body', 'type' => 'wysiwyg' ) ),
				'<div title="{{body|attr}}">x</div>'
			),
			'KarMCP_Widget_BadMod',
			'karmcp_custom_badmod'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_bad_modifier', $result->get_error_code() );
	}

	public function test_subproperty_on_a_type_that_lacks_it_is_refused(): void {
		$result = KarMCP_Widget_Generator::generate(
			$this->spec(
				array( array( 'name' => 'quote', 'type' => 'text' ) ),
				'<p>{{quote.url}}</p>'
			),
			'KarMCP_Widget_BadSub',
			'karmcp_custom_badsub'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_bad_subprop', $result->get_error_code() );
	}

	public function test_an_invalid_class_name_is_refused(): void {
		$result = KarMCP_Widget_Generator::generate(
			$this->spec( array( array( 'name' => 'quote', 'type' => 'text' ) ), '<p>{{quote}}</p>' ),
			'9 Bad Class',
			'karmcp_custom_x'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'generator_class_name', $result->get_error_code() );
	}
}
