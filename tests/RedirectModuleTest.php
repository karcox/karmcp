<?php
/**
 * Public unit tests for KarMCP_Redirect_Module — identity, default-on, and
 * the static is_enabled() gate the ability registrar / admin / content wiring
 * key off.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/modules/class-module.php';
require_once dirname( __DIR__ ) . '/includes/modules/class-redirect-module.php';

class RedirectModuleTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
	}

	public function test_identity_and_default_on() {
		$m = new KarMCP_Redirect_Module();
		$this->assertSame( 'redirects', $m->id() );
		$this->assertSame( 'free', $m->tier() );
		$this->assertTrue( $m->default_active() );
	}

	public function test_is_enabled_reads_active_modules_option() {
		$GLOBALS['karmcp_test']['options']['karmcp_active_modules'] = array();
		$this->assertFalse( KarMCP_Redirect_Module::is_enabled() );

		$GLOBALS['karmcp_test']['options']['karmcp_active_modules'] = array( 'themer', 'redirects' );
		$this->assertTrue( KarMCP_Redirect_Module::is_enabled() );
	}

	public function test_is_active_matches_is_enabled() {
		$m = new KarMCP_Redirect_Module();
		$GLOBALS['karmcp_test']['options']['karmcp_active_modules'] = array( 'redirects' );
		$this->assertTrue( $m->is_active() );
		$this->assertSame( $m->is_active(), KarMCP_Redirect_Module::is_enabled() );
	}
}
