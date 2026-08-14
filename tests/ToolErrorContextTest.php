<?php
/**
 * Whether a failing tool says where it failed.
 *
 * Both of these regressions are invisible: the tool still returns an error, so
 * nothing looks broken from the outside — the error just does not carry enough
 * to act on. That is exactly the kind of thing that rots, so it is pinned here.
 *
 * The case behind it: saving Elementor kit settings throws a bare
 * `Access denied.` from deep inside Elementor when the user fails `edit_post`
 * on the kit. WordPress core catches it and keeps only getMessage(), so the
 * client received a literal that appears nowhere in this plugin, with no file,
 * no line and no class.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-schema-compat.php';
require_once __DIR__ . '/../includes/abilities/class-content-abilities.php';

class ToolErrorContextTest extends TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	// -----------------------------------------------------------------
	// error_from_throwable()
	// -----------------------------------------------------------------

	/** The origin of the throw survives: class, file and line. */
	public function test_error_names_the_class_file_and_line(): void {
		$line = 0;
		try {
			$line = __LINE__ + 1;
			throw new \RuntimeException( 'Access denied.' );
		} catch ( \Throwable $thrown ) {
			$error = KarMCP_Schema_Compat::error_from_throwable( $thrown, 'karmcp/update-global-colors' );
		}

		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 'unhandled_exception', $error->get_error_code() );

		$message = $error->get_error_message();
		$this->assertStringContainsString( 'RuntimeException', $message );
		$this->assertStringContainsString( 'karmcp/update-global-colors', $message );
		$this->assertStringContainsString( 'ToolErrorContextTest.php:' . $line, $message );
		$this->assertStringContainsString( 'Access denied.', $message );
	}

	/** The same facts are available as data, not only prose. */
	public function test_error_data_carries_the_origin(): void {
		try {
			throw new \LogicException( 'boom', 42 );
		} catch ( \Throwable $thrown ) {
			$error = KarMCP_Schema_Compat::error_from_throwable( $thrown, 'karmcp/add-block' );
		}

		$data = $error->get_error_data();
		$this->assertSame( 'karmcp/add-block', $data['tool'] );
		$this->assertSame( 'LogicException', $data['exception'] );
		$this->assertSame( 42, $data['code'] );
		$this->assertStringStartsWith( 'ToolErrorContextTest.php:', $data['origin'] );
	}

	/**
	 * An Error is caught too, not just an Exception. A TypeError out of a widget
	 * schema is every bit as opaque as a thrown string, and left uncaught it is
	 * a 500 with an empty body rather than a tool error.
	 */
	public function test_php_errors_are_converted_too(): void {
		try {
			throw new \TypeError( 'bad argument' );
		} catch ( \Throwable $thrown ) {
			$error = KarMCP_Schema_Compat::error_from_throwable( $thrown, 'karmcp/update-widget' );
		}

		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertStringContainsString( 'TypeError', $error->get_error_message() );
	}

	/** A wrapped cause is reported, since that is usually the real origin. */
	public function test_previous_throwable_is_reported(): void {
		try {
			throw new \RuntimeException( 'outer', 0, new \InvalidArgumentException( 'inner' ) );
		} catch ( \Throwable $thrown ) {
			$error = KarMCP_Schema_Compat::error_from_throwable( $thrown, 'karmcp/build-page' );
		}

		$data = $error->get_error_data();
		$this->assertArrayHasKey( 'previous', $data );
		$this->assertStringContainsString( 'InvalidArgumentException', $data['previous'] );
		$this->assertStringContainsString( 'inner', $data['previous'] );
	}

	// -----------------------------------------------------------------
	// check_edit_permission() / check_delete_permission()
	// -----------------------------------------------------------------

	/**
	 * A per-post denial names the post and the capability. Returning a bare
	 * `false` here is what the adapter renders as "Permission denied" with
	 * nothing else attached.
	 */
	public function test_per_post_denial_names_post_and_capability(): void {
		$abilities = new KarMCP_Content_Abilities();

		$GLOBALS['karmcp_test']['caps']      = array( 'edit_posts' );
		$GLOBALS['karmcp_test']['post_caps'] = array( 19 => false );
		$GLOBALS['karmcp_test']['posts']     = array(
			19 => (object) array( 'ID' => 19, 'post_type' => 'elementor_library' ),
		);

		$result = $abilities->check_edit_permission( array( 'post_id' => 19 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'edit_post', $message );
		$this->assertStringContainsString( '19', $message );
		$this->assertStringContainsString( 'elementor_library', $message );

		$data = $result->get_error_data();
		$this->assertSame( 'edit_post', $data['required_capability'] );
		$this->assertSame( 19, $data['post_id'] );
		$this->assertSame( 'elementor_library', $data['post_type'] );
	}

	/** Missing the general capability is reported by name as well. */
	public function test_missing_general_capability_is_named(): void {
		$abilities = new KarMCP_Content_Abilities();

		$GLOBALS['karmcp_test']['caps'] = array();

		$result = $abilities->check_edit_permission( array( 'post_id' => 19 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_capability', $result->get_error_code() );
		$this->assertStringContainsString( 'edit_posts', $result->get_error_message() );
	}

	/** The permitted paths still return a plain true, which is what the adapter wants. */
	public function test_permitted_returns_true(): void {
		$abilities = new KarMCP_Content_Abilities();

		$GLOBALS['karmcp_test']['caps']      = array( 'edit_posts' );
		$GLOBALS['karmcp_test']['post_caps'] = array( 19 => true );

		$this->assertTrue( $abilities->check_edit_permission( array( 'post_id' => 19 ) ) );
		$this->assertTrue( $abilities->check_edit_permission( array() ) );
	}
}
