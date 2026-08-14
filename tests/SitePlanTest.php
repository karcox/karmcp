<?php
/**
 * The site-brief planner.
 *
 * build-site is the one tool that touches four domains in one call, so the
 * decisions all happen here, before anything is written: a composite that
 * validates as it goes fails halfway, and half a site is worse than none — the
 * pages exist, the menu does not, and the front page points at nothing.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-site-plan.php';

class SitePlanTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	private function plan( array $brief ): array {
		$plan = KarMCP_Site_Plan::build( $brief );
		$this->assertIsArray( $plan, 'Expected the brief to plan.' );
		return $plan;
	}

	private function assertRefused( array $brief, string $expected_code ): WP_Error {
		$plan = KarMCP_Site_Plan::build( $brief );
		$this->assertInstanceOf( WP_Error::class, $plan, 'Expected the brief to be refused.' );
		$this->assertSame( $expected_code, $plan->get_error_code() );
		return $plan;
	}

	private function brief( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_name' => 'Estudio Karcox',
				'pages'     => array(
					array( 'title' => 'Inicio', 'front_page' => true ),
					array( 'title' => 'Servicios' ),
					array( 'title' => 'Contacto' ),
				),
			),
			$overrides
		);
	}

	// ---- pages -------------------------------------------------------------

	public function test_slugs_are_derived_from_titles(): void {
		$plan = $this->plan( $this->brief() );

		$this->assertSame( array( 'inicio', 'servicios', 'contacto' ), array_column( $plan['pages'], 'slug' ) );
	}

	public function test_a_bare_string_is_accepted_as_a_page(): void {
		$plan = $this->plan( array( 'pages' => array( 'Sobre nosotros' ) ) );

		$this->assertSame( 'Sobre nosotros', $plan['pages'][0]['title'] );
		$this->assertSame( 'sobre-nosotros', $plan['pages'][0]['slug'] );
	}

	/**
	 * WordPress silently renames the second page to `contacto-2`, and the menu
	 * item then points at a page nobody meant to create.
	 */
	public function test_two_pages_with_the_same_slug_are_refused(): void {
		$error = $this->assertRefused(
			array( 'pages' => array( array( 'title' => 'Contacto' ), array( 'title' => 'Contacto' ) ) ),
			'invalid_argument'
		);

		$this->assertStringContainsString( 'contacto', $error->get_error_message() );
	}

	public function test_two_front_pages_are_refused(): void {
		$this->assertRefused(
			array(
				'pages' => array(
					array( 'title' => 'A', 'front_page' => true ),
					array( 'title' => 'B', 'front_page' => true ),
				),
			),
			'invalid_argument'
		);
	}

	public function test_a_brief_with_no_pages_is_refused(): void {
		$this->assertRefused( array( 'pages' => array() ), 'missing_argument' );
	}

	public function test_a_page_with_no_title_is_refused(): void {
		$this->assertRefused( array( 'pages' => array( array( 'slug' => 'x' ) ) ), 'missing_argument' );
	}

	public function test_an_unreasonable_page_count_is_refused(): void {
		$this->assertRefused(
			array( 'pages' => array_fill( 0, 40, array( 'title' => 'X' ) ) ),
			'too_many_pages'
		);
	}

	// ---- the menu ----------------------------------------------------------

	/**
	 * Most themes already render a home link, so a front page in the menu shows
	 * up twice.
	 */
	public function test_the_front_page_stays_out_of_the_menu_by_default(): void {
		$plan = $this->plan( $this->brief() );

		$this->assertSame( array( 'Servicios', 'Contacto' ), array_column( $plan['menu']['items'], 'title' ) );
		$this->assertSame( 'inicio', $plan['front_page'] );
	}

	public function test_the_front_page_can_be_put_in_the_menu_explicitly(): void {
		$plan = $this->plan(
			array( 'pages' => array( array( 'title' => 'Inicio', 'front_page' => true, 'in_menu' => true ) ) )
		);

		$this->assertSame( array( 'Inicio' ), array_column( $plan['menu']['items'], 'title' ) );
	}

	public function test_a_page_can_be_kept_out_of_the_menu(): void {
		$plan = $this->plan(
			array( 'pages' => array( array( 'title' => 'Aviso legal', 'in_menu' => false ) ) )
		);

		$this->assertSame( array(), $plan['menu']['items'] );
	}

	public function test_the_menu_takes_its_name_from_the_site(): void {
		$this->assertSame( 'Estudio Karcox', $this->plan( $this->brief() )['menu']['name'] );
	}

	public function test_the_menu_can_be_skipped_entirely(): void {
		$this->assertNull( $this->plan( $this->brief( array( 'menu' => false ) ) )['menu'] );
	}

	// ---- colours -----------------------------------------------------------

	/**
	 * @dataProvider hexes
	 */
	public function test_hex_colours_are_normalized( string $given, ?string $expected ): void {
		$this->assertSame( $expected, KarMCP_Site_Plan::normalize_hex( $given ) );
	}

	public static function hexes(): array {
		return array(
			array( '#ff5733', '#FF5733' ),
			array( 'ff5733', '#FF5733' ),
			array( '#FFF', '#FFFFFF' ),
			array( '  #a1b2c3  ', '#A1B2C3' ),
			array( 'rebeccapurple', null ),
			array( '#12345', null ),
			array( '', null ),
		);
	}

	public function test_colours_are_normalized_in_the_plan(): void {
		$plan = $this->plan( $this->brief( array( 'colors' => array( 'primary' => '0af', 'text' => '#222222' ) ) ) );

		$this->assertSame( array( 'primary' => '#00AAFF', 'text' => '#222222' ), $plan['colors'] );
	}

	public function test_an_unknown_colour_slot_is_refused(): void {
		$this->assertRefused( $this->brief( array( 'colors' => array( 'tertiary' => '#fff' ) ) ), 'invalid_argument' );
	}

	/**
	 * Elementor stores whatever string it is handed, so a colour name would be
	 * saved and then render as nothing at all.
	 */
	public function test_a_colour_that_is_not_hex_is_refused_with_the_value(): void {
		$error = $this->assertRefused( $this->brief( array( 'colors' => array( 'primary' => 'azul' ) ) ), 'invalid_argument' );

		$this->assertStringContainsString( 'azul', $error->get_error_message() );
	}

	// ---- typography --------------------------------------------------------

	public function test_typography_is_carried_through(): void {
		$plan = $this->plan( $this->brief( array( 'typography' => array( 'headings' => 'Inter', 'body' => 'Georgia' ) ) ) );

		$this->assertSame( array( 'headings' => 'Inter', 'body' => 'Georgia' ), $plan['typography'] );
	}

	public function test_an_empty_brief_section_produces_nothing(): void {
		$plan = $this->plan( $this->brief() );

		$this->assertSame( array(), $plan['colors'] );
		$this->assertSame( array(), $plan['typography'] );
	}
}
