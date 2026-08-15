<?php
/**
 * The block spec contract.
 *
 * Same boundary as the widget spec, on the Gutenberg side: an agent describes a
 * block, it never supplies code. These pin the markup rules (shared with the
 * widget template on purpose, so the two can never drift into different safety
 * rules), the closed attribute vocabulary, and the reserved names WordPress
 * itself puts in a block's attributes.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sandbox/class-sandbox-template.php';
require_once __DIR__ . '/../includes/sandbox/class-widget-spec.php';
require_once __DIR__ . '/../includes/sandbox/class-block-spec.php';

class BlockSpecTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function spec( array $overrides = array() ): array {
		return array_merge(
			array(
				'spec_version' => 1,
				'meta'         => array( 'title' => 'Callout' ),
				'attributes'   => array(
					array( 'name' => 'heading', 'type' => 'text', 'label' => 'Heading' ),
				),
				'template'     => '<h2>{{heading}}</h2>',
			),
			$overrides
		);
	}

	private function assertRejected( array $spec, string $code ): void {
		$result = KarMCP_Block_Spec::validate( $spec );
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the spec to be rejected.' );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_a_minimal_spec_is_valid(): void {
		$this->assertTrue( KarMCP_Block_Spec::validate( $this->spec() ) );
	}

	public function test_title_is_required(): void {
		$this->assertRejected( $this->spec( array( 'meta' => array( 'title' => '' ) ) ), 'spec_title' );
	}

	public function test_future_spec_version_is_rejected(): void {
		$this->assertRejected(
			$this->spec( array( 'spec_version' => KarMCP_Block_Spec::SPEC_VERSION + 1 ) ),
			'spec_version'
		);
	}

	public function test_template_with_php_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<p><?php echo 1; ?></p>' ) ), 'template_php' );
	}

	public function test_template_with_script_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<script>x()</script>' ) ), 'template_script' );
	}

	public function test_template_with_inline_handler_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<div onmouseover="x()">y</div>' ) ), 'template_event_handler' );
	}

	public function test_template_referencing_an_undeclared_attribute_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'template' => '<p>{{nope}}</p>' ) ), 'spec_template_unknown' );
	}

	public function test_unknown_attribute_type_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'attributes' => array( array( 'name' => 'heading', 'type' => 'php' ) ),
				)
			),
			'spec_attribute_type'
		);
	}

	/** WordPress puts these in $attributes itself when supports are on. */
	public function test_reserved_attribute_name_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'attributes' => array( array( 'name' => 'align', 'type' => 'text' ) ),
					'template'   => '<p>x</p>',
				)
			),
			'spec_attribute_reserved'
		);
	}

	public function test_duplicate_attribute_name_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'attributes' => array(
						array( 'name' => 'heading', 'type' => 'text' ),
						array( 'name' => 'heading', 'type' => 'number' ),
					),
				)
			),
			'spec_attribute_duplicate'
		);
	}

	public function test_select_without_options_is_rejected(): void {
		$this->assertRejected(
			$this->spec(
				array(
					'attributes' => array( array( 'name' => 'tone', 'type' => 'select' ) ),
					'template'   => '<p>{{tone}}</p>',
				)
			),
			'spec_select_options'
		);
	}

	public function test_too_many_attributes_is_rejected(): void {
		$attributes = array();
		for ( $i = 0; $i <= KarMCP_Block_Spec::MAX_ATTRIBUTES; $i++ ) {
			$attributes[] = array( 'name' => 'a' . $i, 'type' => 'text' );
		}
		$this->assertRejected(
			$this->spec( array( 'attributes' => $attributes, 'template' => '<p>x</p>' ) ),
			'spec_attributes_many'
		);
	}

	public function test_styles_with_php_tag_is_rejected(): void {
		$this->assertRejected( $this->spec( array( 'styles' => '.a{}<?php' ) ), 'spec_asset_php' );
	}

	public function test_describe_covers_every_type(): void {
		$described = KarMCP_Block_Spec::describe();

		$this->assertSame( KarMCP_Block_Spec::SPEC_VERSION, $described['spec_version'] );
		$this->assertCount( count( KarMCP_Block_Spec::attribute_types() ), $described['attribute_types'] );
		$this->assertNotContains( 'raw', KarMCP_Sandbox_Template::MODIFIERS );
	}

	/**
	 * The two specs are different vocabularies but one safety model. If the
	 * markup rules ever diverge, one of the two builders is the weak one.
	 */
	public function test_block_and_widget_share_the_same_markup_rules(): void {
		$hostile = '<div onclick="x()">y</div>';

		$block  = KarMCP_Sandbox_Template::check_markup( $hostile );
		$widget = KarMCP_Widget_Spec::check_markup( $hostile );

		$this->assertInstanceOf( WP_Error::class, $block );
		$this->assertInstanceOf( WP_Error::class, $widget );
		$this->assertSame( $block->get_error_code(), $widget->get_error_code() );
	}
}
