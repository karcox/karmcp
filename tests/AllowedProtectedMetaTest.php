<?php
/**
 * Public unit tests for the post context on `karmcp_content_allowed_protected_meta`.
 *
 * Plugin CPTs keep their whole configuration in protected meta — a JetPopup
 * wrapper is `_settings`, `_styles`, `_content_type`, `_conditions` and
 * `_relation_type` — so a site that wants MCP to create one has to open the
 * guard. Until the filter carried the post type, the only possible answer was
 * global: allowing `_settings` for one CPT allowed it on every post type, for
 * reads too, and those are unprefixed names other plugins use as well.
 *
 * These pin that a callback can now scope its answer, and that the default
 * — refuse everything — is unchanged.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/abilities/class-content-abilities.php';

class AllowedProtectedMetaTest extends \PHPUnit\Framework\TestCase {

	/** JetPopup's wrapper, as verified against 236 live popups. */
	private const POPUP_META = array( '_settings', '_styles', '_content_type', '_conditions', '_relation_type' );

	private KarMCP_Content_Abilities $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		karmcp_test_reset_hooks();
		$this->abilities = new KarMCP_Content_Abilities();
	}

	/**
	 * Calls the private guard, which is where the context has to arrive.
	 *
	 * @param array  $meta      Meta map to validate.
	 * @param string $post_type Post type in play.
	 * @return true|WP_Error
	 */
	private function guard( array $meta, string $post_type = '' ) {
		$method = new \ReflectionMethod( KarMCP_Content_Abilities::class, 'reject_protected_meta' );
		return $method->invoke( $this->abilities, $meta, $post_type, 0 );
	}

	public function test_protected_meta_is_still_refused_by_default() {
		$result = $this->guard( array( '_settings' => 'x' ), 'jet-popup' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'protected_meta', $result->get_error_code() );
	}

	public function test_a_callback_can_allow_the_popup_wrapper_for_one_post_type() {
		add_filter(
			'karmcp_content_allowed_protected_meta',
			function ( $keys, $post_type ) {
				return 'jet-popup' === $post_type ? self::POPUP_META : $keys;
			},
			10,
			2
		);

		$meta = array_fill_keys( self::POPUP_META, 'x' );

		// Allowed on the type the site authorised...
		$this->assertTrue( $this->guard( $meta, 'jet-popup' ) );

		// ...and still refused everywhere else, which is the whole point of the
		// context: `_settings` is not a JetPopup-specific name.
		foreach ( array( 'page', 'post', 'product', '' ) as $other_type ) {
			$result = $this->guard( $meta, $other_type );
			$this->assertInstanceOf( 'WP_Error', $result, "should refuse on '{$other_type}'" );
		}
	}

	public function test_the_filter_receives_the_post_type_and_id() {
		$seen = array();
		add_filter(
			'karmcp_content_allowed_protected_meta',
			function ( $keys, $post_type, $post_id ) use ( &$seen ) {
				$seen = array( $post_type, $post_id );
				return $keys;
			},
			10,
			3
		);

		$method = new \ReflectionMethod( KarMCP_Content_Abilities::class, 'allowed_protected_meta' );
		$method->invoke( $this->abilities, 'jet-popup', 777 );

		$this->assertSame( array( 'jet-popup', 777 ), $seen );
	}

	public function test_a_one_argument_callback_still_works() {
		// Anything already written against the 1.1.0 signature must keep working:
		// extra args passed to a callback that does not declare them are ignored.
		add_filter(
			'karmcp_content_allowed_protected_meta',
			function ( $keys ) {
				return array( '_settings' );
			}
		);

		$this->assertTrue( $this->guard( array( '_settings' => 'x' ), 'jet-popup' ) );
	}

	public function test_unprotected_meta_needs_no_filter() {
		$this->assertTrue( $this->guard( array( 'acg_course' => 10445 ), 'jet-popup' ) );
	}
}
