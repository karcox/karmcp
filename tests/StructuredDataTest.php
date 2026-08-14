<?php
/**
 * Schema.org JSON-LD: the builder and the way it refuses.
 *
 * Structured data has no visible failure. An invalid node is not rejected with
 * an error, it is ignored — the rich result never appears, the page looks
 * identical, and the only symptom is search traffic that never arrives. That
 * makes validation at write time the only place the mistake can be caught, so
 * these tests are mostly about what does not get stored.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-structured-data.php';

class StructuredDataTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function build( string $type, array $data ): array {
		$node = KarMCP_Structured_Data::build( $type, $data );
		$this->assertIsArray( $node, 'Expected the node to build.' );
		return $node;
	}

	private function assertRefused( string $type, array $data, string $expected_code ): WP_Error {
		$node = KarMCP_Structured_Data::build( $type, $data );
		$this->assertInstanceOf( WP_Error::class, $node, 'Expected the node to be refused.' );
		$this->assertSame( $expected_code, $node->get_error_code() );
		return $node;
	}

	// ---- the envelope ------------------------------------------------------

	public function test_every_node_carries_the_context_and_type(): void {
		$node = $this->build( 'Organization', array( 'name' => 'Femxa' ) );

		$this->assertSame( 'https://schema.org', $node['@context'] );
		$this->assertSame( 'Organization', $node['@type'] );
		$this->assertSame( 'Femxa', $node['name'] );
	}

	public function test_unknown_properties_pass_through_untouched(): void {
		$node = $this->build(
			'Organization',
			array( 'name' => 'Femxa', 'sameAs' => array( 'https://linkedin.test/femxa' ), 'foundingDate' => '1998' )
		);

		$this->assertSame( array( 'https://linkedin.test/femxa' ), $node['sameAs'] );
		$this->assertSame( '1998', $node['foundingDate'] );
	}

	public function test_a_node_missing_its_required_property_is_refused(): void {
		$this->assertRefused( 'LocalBusiness', array( 'name' => 'Bar Paco' ), 'missing_property' );
	}

	/**
	 * A near-miss type is the likeliest mistake, and the least discoverable:
	 * schema.org has thousands of types and no error when you invent one.
	 */
	public function test_a_misspelled_type_is_refused_with_a_suggestion(): void {
		$error = $this->assertRefused( 'Organisation', array( 'name' => 'X' ), 'unsupported_type' );

		$this->assertStringContainsString( 'Organization', $error->get_error_message() );
	}

	public function test_an_unrelated_type_is_refused_with_the_supported_list(): void {
		$error = $this->assertRefused( 'SoftwareApplication', array( 'name' => 'X' ), 'unsupported_type' );

		$this->assertStringContainsString( 'BreadcrumbList', $error->get_error_message() );
	}

	// ---- the shapes worth making easy --------------------------------------

	/**
	 * The nested Question/Answer structure is where hand-written FAQ markup
	 * goes wrong, and a malformed one costs the FAQ rich result entirely.
	 */
	public function test_faqs_expand_into_the_nested_question_structure(): void {
		$node = $this->build(
			'FAQPage',
			array(
				'faqs' => array(
					array( 'question' => '¿Hacéis envíos?', 'answer' => 'Sí, a toda España.' ),
					array( 'question' => '¿Cuánto tarda?', 'answer' => 'De 24 a 48 horas.' ),
				),
			)
		);

		$this->assertCount( 2, $node['mainEntity'] );
		$this->assertSame( 'Question', $node['mainEntity'][0]['@type'] );
		$this->assertSame( '¿Hacéis envíos?', $node['mainEntity'][0]['name'] );
		$this->assertSame( 'Answer', $node['mainEntity'][0]['acceptedAnswer']['@type'] );
		$this->assertSame( 'Sí, a toda España.', $node['mainEntity'][0]['acceptedAnswer']['text'] );
		$this->assertArrayNotHasKey( 'faqs', $node );
	}

	public function test_a_question_without_an_answer_is_refused(): void {
		$this->assertRefused(
			'FAQPage',
			array( 'faqs' => array( array( 'question' => '¿Y esto?', 'answer' => '' ) ) ),
			'invalid_argument'
		);
	}

	public function test_the_raw_shape_is_still_accepted(): void {
		$node = $this->build(
			'FAQPage',
			array(
				'mainEntity' => array(
					array(
						'@type'          => 'Question',
						'name'           => 'A',
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'B' ),
					),
				),
			)
		);

		$this->assertCount( 1, $node['mainEntity'] );
	}

	/**
	 * Breadcrumb positions are 1-based and must be contiguous. Numbering them
	 * by hand is the single most common way this node is wrong.
	 */
	public function test_breadcrumb_positions_are_numbered_automatically(): void {
		$node = $this->build(
			'BreadcrumbList',
			array(
				'items' => array(
					array( 'name' => 'Inicio', 'url' => 'https://ejemplo.test/' ),
					array( 'name' => 'Servicios', 'url' => 'https://ejemplo.test/servicios' ),
					array( 'name' => 'Consultoría' ),
				),
			)
		);

		$this->assertSame( array( 1, 2, 3 ), array_column( $node['itemListElement'], 'position' ) );
		$this->assertSame( 'https://ejemplo.test/servicios', $node['itemListElement'][1]['item'] );
		// The current page is the last crumb and carries no link.
		$this->assertArrayNotHasKey( 'item', $node['itemListElement'][2] );
	}

	public function test_a_breadcrumb_without_a_name_is_refused(): void {
		$this->assertRefused( 'BreadcrumbList', array( 'items' => array( array( 'url' => '/x' ) ) ), 'invalid_argument' );
	}

	// ---- product offers ----------------------------------------------------

	public function test_a_product_price_becomes_a_proper_offer(): void {
		$node = $this->build(
			'Product',
			array( 'name' => 'Camiseta', 'price' => '19.90', 'priceCurrency' => 'EUR', 'availability' => 'instock' )
		);

		$this->assertSame( 'Offer', $node['offers']['@type'] );
		$this->assertSame( '19.90', $node['offers']['price'] );
		$this->assertSame( 'EUR', $node['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $node['offers']['availability'] );
		$this->assertArrayNotHasKey( 'price', $node );
	}

	/**
	 * A price with no currency is not an offer. Google drops the node rather
	 * than assuming the site's currency.
	 */
	public function test_a_price_without_a_currency_is_refused(): void {
		$this->assertRefused( 'Product', array( 'name' => 'X', 'price' => '19.90' ), 'missing_property' );
	}

	/**
	 * @dataProvider availabilities
	 */
	public function test_availability_words_become_schema_urls( string $given, string $expected ): void {
		$node = $this->build( 'Product', array( 'name' => 'X', 'price' => '1', 'priceCurrency' => 'EUR', 'availability' => $given ) );

		$this->assertSame( $expected, $node['offers']['availability'] );
	}

	public static function availabilities(): array {
		return array(
			array( 'instock', 'https://schema.org/InStock' ),
			array( 'in stock', 'https://schema.org/InStock' ),
			array( 'out_of_stock', 'https://schema.org/OutOfStock' ),
			array( 'onbackorder', 'https://schema.org/BackOrder' ),
			array( 'https://schema.org/PreOrder', 'https://schema.org/PreOrder' ),
		);
	}

	// ---- rendering ---------------------------------------------------------

	public function test_a_single_node_renders_as_one_object(): void {
		$html = KarMCP_Structured_Data::render( array( $this->build( 'Person', array( 'name' => 'Ada' ) ) ) );

		$this->assertStringStartsWith( '<script type="application/ld+json">', $html );
		$this->assertStringContainsString( '"@type":"Person"', $html );
		$this->assertStringNotContainsString( '[{', $html );
	}

	public function test_several_nodes_render_as_an_array(): void {
		$html = KarMCP_Structured_Data::render(
			array(
				$this->build( 'Person', array( 'name' => 'Ada' ) ),
				$this->build( 'Organization', array( 'name' => 'Femxa' ) ),
			)
		);

		$this->assertStringContainsString( '[{', $html );
	}

	/**
	 * A closing script tag inside a value would end the block early and spill
	 * the rest of the payload into the page as markup — an injection vector
	 * wherever the text came from a form or an import.
	 */
	public function test_markup_inside_a_value_cannot_break_out_of_the_script_tag(): void {
		$html = KarMCP_Structured_Data::render(
			array( $this->build( 'Organization', array( 'name' => 'Evil</script><img src=x onerror=alert(1)>' ) ) )
		);

		$this->assertStringNotContainsString( '</script><img', $html );
		$this->assertStringContainsString( '<', $html );
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
	}

	public function test_nothing_renders_for_an_empty_node_list(): void {
		$this->assertSame( '', KarMCP_Structured_Data::render( array() ) );
	}

	public function test_accents_survive_rendering_unescaped(): void {
		$html = KarMCP_Structured_Data::render( array( $this->build( 'Organization', array( 'name' => 'Diseño Gráfico' ) ) ) );

		$this->assertStringContainsString( 'Diseño Gráfico', $html );
	}
}
