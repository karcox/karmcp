<?php
/**
 * Public unit test for KarMCP_Atomic_Props::is_container_supported() — the
 * issue #111 (part 2) fix. On installs where Elementor's Flexbox Container
 * experiment is OFF, the `container` element type is not registered, so
 * add-container / build-page would store a page that renders empty. This helper
 * reports whether a `container` will render, and the creation tools refuse up
 * front when it won't. The false path (experiment off) is verified live by
 * toggling the experiment; here we pin that the helper never fatals and returns
 * true when Elementor is not loaded at all (the callers guard Elementor
 * separately, so a non-Elementor context must not block).
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-atomic-props.php';

class ContainerSupportTest extends \PHPUnit\Framework\TestCase {

	public function test_returns_true_when_elementor_absent() {
		// The test harness does not load Elementor, so the guard's first branch
		// (class_exists '\Elementor\Plugin' === false) returns true.
		$this->assertFalse( class_exists( '\Elementor\Plugin' ), 'harness must not load Elementor' );
		$this->assertTrue( KarMCP_Atomic_Props::is_container_supported() );
	}
}
