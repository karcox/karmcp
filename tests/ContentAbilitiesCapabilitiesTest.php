<?php
/**
 * Which capability create-post checks.
 *
 * `wp_insert_post()` enforces nothing — it writes whatever row it is handed —
 * so the only gate on the tool is the one the tool performs. Checking the
 * generic `edit_posts` meant a user who could write a blog post could also
 * create a post of ANY registered type, including the types this plugin gates
 * behind `manage_options`. These pin the capability to the target type.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/abilities/class-content-abilities.php';

class ContentAbilitiesCapabilitiesTest extends TestCase {

	/** @var KarMCP_Content_Abilities */
	private $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->abilities = new KarMCP_Content_Abilities();

		// A default `post` (core's cap object) plus a type that declares its own,
		// the way KarMCP registers Skills.
		$GLOBALS['karmcp_test']['existing_types']    = array( 'post', 'page', 'karmcp_skill', 'product' );
		$GLOBALS['karmcp_test']['post_type_objects'] = array(
			'post'         => (object) array(
				'cap' => (object) array(
					'create_posts'      => 'edit_posts',
					'publish_posts'     => 'publish_posts',
					'edit_others_posts' => 'edit_others_posts',
				),
			),
			'karmcp_skill' => (object) array(
				'cap' => (object) array(
					'create_posts'      => 'manage_options',
					'publish_posts'     => 'manage_options',
					'edit_others_posts' => 'manage_options',
				),
			),
			'product'      => (object) array(
				'cap' => (object) array(
					'create_posts'      => 'edit_products',
					'publish_posts'     => 'publish_products',
					'edit_others_posts' => 'edit_others_products',
				),
			),
			// A type registered without any capabilities of its own.
			'page'         => (object) array( 'cap' => (object) array() ),
		);
	}

	// -----------------------------------------------------------------
	// type_cap()
	// -----------------------------------------------------------------

	public function test_core_post_resolves_to_the_generic_capability(): void {
		$this->assertSame(
			'edit_posts',
			KarMCP_Content_Abilities::type_cap( 'post', 'create_posts', 'edit_posts' )
		);
	}

	public function test_a_type_with_its_own_capabilities_resolves_to_them(): void {
		$this->assertSame(
			'edit_products',
			KarMCP_Content_Abilities::type_cap( 'product', 'create_posts', 'edit_posts' )
		);
	}

	public function test_a_type_without_capabilities_falls_back(): void {
		$this->assertSame(
			'edit_posts',
			KarMCP_Content_Abilities::type_cap( 'page', 'create_posts', 'edit_posts' )
		);
	}

	public function test_an_unregistered_type_falls_back(): void {
		$this->assertSame(
			'edit_posts',
			KarMCP_Content_Abilities::type_cap( 'does_not_exist', 'create_posts', 'edit_posts' )
		);
	}

	// -----------------------------------------------------------------
	// check_create_permission()
	// -----------------------------------------------------------------

	public function test_an_author_may_create_a_plain_post(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' );
		$this->assertTrue( $this->abilities->check_create_permission( array( 'post_type' => 'post' ) ) );
	}

	public function test_no_post_type_defaults_to_post(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' );
		$this->assertTrue( $this->abilities->check_create_permission( array() ) );
		$this->assertTrue( $this->abilities->check_create_permission( null ) );
	}

	/**
	 * The escalation this closes: a skill is an operating manual that steers
	 * every agent on the site, registered `manage_options` for every operation.
	 * Someone who can only write blog posts must not be able to author one.
	 */
	public function test_an_author_may_not_create_a_skill(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' );
		$this->assertFalse( $this->abilities->check_create_permission( array( 'post_type' => 'karmcp_skill' ) ) );
	}

	public function test_an_author_may_not_create_a_product(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' );
		$this->assertFalse( $this->abilities->check_create_permission( array( 'post_type' => 'product' ) ) );
	}

	public function test_a_shop_manager_may_create_a_product(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts', 'edit_products' );
		$this->assertTrue( $this->abilities->check_create_permission( array( 'post_type' => 'product' ) ) );
	}

	public function test_an_administrator_may_create_a_skill(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts', 'manage_options' );
		$this->assertTrue( $this->abilities->check_create_permission( array( 'post_type' => 'karmcp_skill' ) ) );
	}

	// -----------------------------------------------------------------
	// The plugin's own types are off-limits to the generic content tools
	// -----------------------------------------------------------------

	/**
	 * Capability is only half of it. Even an administrator must not route these
	 * through create-post: each type has a dedicated tool that runs a guard the
	 * generic path does not — a PHP snippet is validated before it is stored.
	 */
	public function test_karmcp_types_are_not_writable_here(): void {
		$managed = KarMCP_Content_Abilities::managed_post_types();

		$this->assertContains( 'karmcp_skill', $managed );
		$this->assertContains( 'karmcp_php_snippet', $managed );
		$this->assertContains( 'karmcp_widget', $managed );
		$this->assertContains( 'karmcp_theme_tpl', $managed );

		$GLOBALS['karmcp_test']['caps']           = array( 'edit_posts', 'manage_options' );
		$GLOBALS['karmcp_test']['existing_types'] = array_merge(
			$GLOBALS['karmcp_test']['existing_types'],
			$managed
		);

		foreach ( $managed as $type ) {
			$result = $this->abilities->execute_create_post( array( 'post_type' => $type, 'title' => 'x' ) );
			$this->assertInstanceOf( WP_Error::class, $result, $type . ' must be refused' );
			$this->assertSame( 'invalid_post_type', $result->get_error_code(), $type );
		}
	}
}
