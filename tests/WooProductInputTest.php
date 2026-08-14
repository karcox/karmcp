<?php
/**
 * Product input validation, before anything reaches WooCommerce.
 *
 * The price parser carries most of the weight here. WooCommerce stores prices
 * as strings and does not check them, so "19,90" — which is how most of the
 * world and every Spanish-speaking agent writes a price — is stored verbatim
 * and later read as 19. The product goes on sale ninety cents cheap and there
 * is no error anywhere. The rest of the file pins the refusals that stop a
 * plausible-looking product from being unsellable.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/woo/class-woo-product-input.php';

class WooProductInputTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function normalize( array $input, bool $partial = false ): array {
		$out = KarMCP_Woo_Product_Input::normalize( $input, $partial );
		$this->assertIsArray( $out, 'Expected the input to normalize.' );
		return $out;
	}

	private function assertRefused( array $input, string $expected_code, bool $partial = false ): WP_Error {
		$out = KarMCP_Woo_Product_Input::normalize( $input, $partial );
		$this->assertInstanceOf( WP_Error::class, $out, 'Expected the input to be refused.' );
		$this->assertSame( $expected_code, $out->get_error_code() );
		return $out;
	}

	// ---- prices ------------------------------------------------------------

	/**
	 * @dataProvider prices
	 */
	public function test_prices_are_read_the_way_a_human_wrote_them( $given, ?float $expected ): void {
		$this->assertSame( $expected, KarMCP_Woo_Product_Input::parse_price( $given ) );
	}

	public static function prices(): array {
		return array(
			'plain float'            => array( 19.9, 19.9 ),
			'plain int'              => array( 20, 20.0 ),
			'dot decimal'            => array( '19.90', 19.9 ),
			'spanish comma decimal'  => array( '19,90', 19.9 ),
			'spanish full'           => array( '1.299,00', 1299.0 ),
			'english full'           => array( '1,299.00', 1299.0 ),
			'euro symbol'            => array( '€19,90', 19.9 ),
			'trailing currency'      => array( '19,90 €', 19.9 ),
			'spaces'                 => array( '  49.99  ', 49.99 ),
			'grouped thousands only' => array( '1,299', 1299.0 ),
			'millions grouped'       => array( '1,299,000', 1299000.0 ),
			'three decimals'         => array( '0,125', 0.125 ),
			'zero'                   => array( '0', 0.0 ),
			'not a number'           => array( 'gratis', null ),
			'empty'                  => array( '', null ),
		);
	}

	public function test_price_is_stored_in_the_format_woocommerce_expects(): void {
		$fields = $this->normalize( array( 'name' => 'Camiseta', 'regular_price' => '1.299,50' ) );

		$this->assertSame( '1299.5', $fields['regular_price'] );
	}

	/**
	 * WooCommerce accepts a sale price above the regular one and then never
	 * applies it: the product shows a sale badge and sells at full price.
	 */
	public function test_sale_price_above_regular_price_is_refused(): void {
		$error = $this->assertRefused(
			array( 'name' => 'Camiseta', 'regular_price' => '20', 'sale_price' => '25' ),
			'invalid_argument'
		);

		$this->assertStringContainsString( 'never apply', $error->get_error_message() );
	}

	public function test_sale_price_equal_to_regular_price_is_allowed(): void {
		$fields = $this->normalize( array( 'name' => 'X', 'regular_price' => '20', 'sale_price' => '20' ) );

		$this->assertSame( '20', $fields['sale_price'] );
	}

	/**
	 * Clearing a discount has to be expressible, or a sale can be started and
	 * never ended.
	 */
	public function test_empty_sale_price_clears_the_discount(): void {
		$fields = $this->normalize( array( 'sale_price' => '' ), true );

		$this->assertArrayHasKey( 'sale_price', $fields );
		$this->assertSame( '', $fields['sale_price'] );
	}

	public function test_unparseable_price_is_refused_with_the_offending_value(): void {
		$error = $this->assertRefused(
			array( 'name' => 'X', 'regular_price' => 'consultar' ),
			'invalid_argument'
		);

		$this->assertStringContainsString( 'consultar', $error->get_error_message() );
	}

	public function test_negative_price_is_refused(): void {
		$this->assertRefused( array( 'name' => 'X', 'regular_price' => '-5' ), 'invalid_argument' );
	}

	// ---- types -------------------------------------------------------------

	public function test_type_defaults_to_simple_on_create(): void {
		$this->assertSame( 'simple', $this->normalize( array( 'name' => 'X' ) )['type'] );
	}

	/**
	 * A variable product with no attributes and no variations cannot be added to
	 * a cart. Creating the shell and calling it done would leave a shop that
	 * looks stocked and sells nothing.
	 */
	public function test_variable_products_are_refused_with_an_explanation(): void {
		$error = $this->assertRefused( array( 'name' => 'X', 'type' => 'variable' ), 'unsupported_type' );

		$this->assertStringContainsString( 'variations', $error->get_error_message() );
	}

	public function test_unknown_type_is_refused(): void {
		$this->assertRefused( array( 'name' => 'X', 'type' => 'bundle' ), 'invalid_argument' );
	}

	public function test_external_product_without_a_url_is_refused(): void {
		$this->assertRefused( array( 'name' => 'X', 'type' => 'external' ), 'missing_argument' );
	}

	// ---- stock -------------------------------------------------------------

	/**
	 * A quantity stored with stock management off is a number WooCommerce never
	 * reads, and it surfaces months later as "the stock counter is broken".
	 */
	public function test_a_stock_quantity_switches_stock_management_on(): void {
		$fields = $this->normalize( array( 'name' => 'X', 'stock_quantity' => 12 ) );

		$this->assertSame( 12, $fields['stock_quantity'] );
		$this->assertTrue( $fields['manage_stock'] );
	}

	public function test_explicit_manage_stock_false_is_respected(): void {
		$fields = $this->normalize( array( 'name' => 'X', 'stock_quantity' => 12, 'manage_stock' => false ) );

		$this->assertFalse( $fields['manage_stock'] );
	}

	public function test_unknown_stock_status_is_refused(): void {
		$this->assertRefused( array( 'name' => 'X', 'stock_status' => 'maybe' ), 'invalid_argument' );
	}

	/**
	 * @dataProvider booleans
	 */
	public function test_booleans_are_read_however_the_client_sends_them( $given, bool $expected ): void {
		$this->assertSame( $expected, KarMCP_Woo_Product_Input::truthy( $given ) );
	}

	public static function booleans(): array {
		return array(
			array( true, true ),
			array( false, false ),
			array( 'yes', true ),
			array( 'true', true ),
			array( '1', true ),
			array( 1, true ),
			array( 'no', false ),
			array( '0', false ),
			array( '', false ),
		);
	}

	// ---- everything else ---------------------------------------------------

	public function test_name_is_required_on_create_and_optional_on_update(): void {
		$this->assertRefused( array( 'regular_price' => '10' ), 'missing_argument' );
		$this->assertSame( '10', $this->normalize( array( 'regular_price' => '10' ), true )['regular_price'] );
	}

	public function test_an_empty_update_is_refused(): void {
		$this->assertRefused( array(), 'missing_argument', true );
	}

	public function test_unknown_status_is_refused(): void {
		$this->assertRefused( array( 'name' => 'X', 'status' => 'live' ), 'invalid_argument' );
	}

	public function test_categories_accept_names_and_ids_together(): void {
		$fields = $this->normalize(
			array( 'name' => 'X', 'categories' => array( 'Camisetas', 14, '', 'Novedades' ) )
		);

		$this->assertSame( array( 'Camisetas', 14, 'Novedades' ), $fields['categories'] );
	}

	public function test_categories_must_be_an_array(): void {
		$this->assertRefused( array( 'name' => 'X', 'categories' => 'Camisetas' ), 'invalid_argument' );
	}

	public function test_image_ids_are_cast_and_deduplicated(): void {
		$fields = $this->normalize(
			array( 'name' => 'X', 'image_ids' => array( '12', 12, 0, 'no', 34 ) )
		);

		$this->assertSame( array( 12, 34 ), $fields['image_ids'] );
	}

	public function test_dimensions_accept_comma_decimals(): void {
		$fields = $this->normalize( array( 'name' => 'X', 'weight' => '0,75', 'length' => '12,5' ) );

		$this->assertSame( '0.75', $fields['weight'] );
		$this->assertSame( '12.5', $fields['length'] );
	}
}
