<?php
/**
 * Regression test for KarMCP_Login_Guard::restrict_user_endpoints().
 *
 * The `rest_endpoints` structure is not a list of handlers.
 * `WP_REST_Server::register_route()` does `$route_args['namespace'] = $ns`
 * before storing it, so each route carries a `namespace` STRING next to its
 * numeric handler entries. Iterating it as handlers and writing a permission
 * callback into that string throws
 *
 *     TypeError: Cannot access offset of type string on string
 *
 * which took the entire REST API of a live site down — `/wp-json/` included,
 * on every request — while the front end kept rendering perfectly, because
 * nothing on a normal page load runs this filter. The module lives in
 * `karmcp_active_modules`, so reinstalling the plugin did not clear it either.
 *
 * The fixture below mirrors core's real shape, `namespace` key included. Drop
 * the is_array() guard and this test fatals rather than fails, which is the
 * point: it reproduces the outage instead of describing it.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/security/class-login-guard.php';

class LoginGuardRestEndpointsTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	/**
	 * The `/wp/v2/users` entry as WP_REST_Server actually stores it.
	 *
	 * @return array
	 */
	private function endpoints(): array {
		return array(
			'/wp/v2/users' => array(
				array(
					'methods'             => array( 'GET' => true ),
					'callback'            => 'get_items',
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => array( 'POST' => true ),
					'callback'            => 'create_item',
					'permission_callback' => '__return_false',
				),
				// The entry that caused the outage.
				'namespace'           => 'wp/v2',
			),
		);
	}

	public function test_the_namespace_string_does_not_fatal() {
		$out = KarMCP_Login_Guard::restrict_user_endpoints( $this->endpoints() );

		// Survived, and left the namespace exactly as core wrote it.
		$this->assertSame( 'wp/v2', $out['/wp/v2/users']['namespace'] );
	}

	public function test_real_handlers_are_still_wrapped() {
		$out = KarMCP_Login_Guard::restrict_user_endpoints( $this->endpoints() );

		foreach ( array( 0, 1 ) as $i ) {
			$this->assertInstanceOf(
				\Closure::class,
				$out['/wp/v2/users'][ $i ]['permission_callback'],
				"handler {$i} must still be wrapped"
			);
		}
	}

	public function test_the_wrapper_refuses_anonymous_callers() {
		$out      = KarMCP_Login_Guard::restrict_user_endpoints( $this->endpoints() );
		$callback = $out['/wp/v2/users'][0]['permission_callback'];

		$GLOBALS['karmcp_test']['logged_in'] = false;
		$result                              = $callback( null );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'karmcp_enumeration_blocked', $result->get_error_code() );
	}

	public function test_absent_route_and_non_array_input_are_no_ops() {
		$this->assertSame( array(), KarMCP_Login_Guard::restrict_user_endpoints( array() ) );
		$this->assertSame( 'nope', KarMCP_Login_Guard::restrict_user_endpoints( 'nope' ) );
	}
}
