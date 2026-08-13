<?php
/**
 * Public unit test for KarMCP_Data::flush_element_cache_on_data_write() —
 * the issue #111 fix that invalidates Elementor 4.2's rendered-element cache
 * whenever `_elementor_data` is written (so MCP-created pages never serve a
 * stale/empty cached render). Behaviour is fully live-verified on a real
 * Elementor 4.2.2 site; this pins the key-matching logic.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';

class ElementCacheFlushTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		karmcp_test_reset();
		$GLOBALS['karmcp_test']['deleted_meta'] = array();
	}

	public function test_clears_element_cache_when_elementor_data_written() {
		KarMCP_Data::flush_element_cache_on_data_write( 5, 42, '_elementor_data' );
		$this->assertContains( array( 42, '_elementor_element_cache' ), $GLOBALS['karmcp_test']['deleted_meta'] );
	}

	public function test_ignores_other_meta_keys() {
		KarMCP_Data::flush_element_cache_on_data_write( 5, 42, '_edit_lock' );
		KarMCP_Data::flush_element_cache_on_data_write( 5, 42, '_elementor_css' );
		$this->assertSame( array(), $GLOBALS['karmcp_test']['deleted_meta'] );
	}
}
