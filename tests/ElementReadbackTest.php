<?php
/**
 * What you can read back after writing an atomic element.
 *
 * A v4 atomic element keeps half its state outside `settings`: the local
 * style classes in `styles` and the Navigator label in `editor_settings`, both
 * siblings of `settings` at the element root. The write side has routed both
 * since 1.38.0; the read side returned neither, so the only way to confirm an
 * atomic write was `export-page` and its raw tree. A write tool that reports
 * success and a read tool that cannot show the result is the same blind spot
 * from the other end.
 *
 * The shaping is pure array work, so it is pinned here against the real
 * method; the ability wrapper around it is a permission check and a find.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/abilities/class-query-abilities.php';

class ElementReadbackTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @param array $element The element node.
	 * @return array
	 */
	private function readback( array $element ): array {
		return KarMCP_Query_Abilities::element_readback( $element );
	}

	public function test_an_atomic_element_reports_its_local_styles() {
		$out = $this->readback(
			array(
				'id'       => 'abc1234',
				'elType'   => 'e-flexbox',
				'settings' => array( 'classes' => array( 'e-abc' ) ),
				'styles'   => array(
					'e-abc' => array(
						'id'       => 'e-abc',
						'variants' => array(),
					),
				),
			)
		);

		$this->assertSame( 'e-abc', $out['styles']['e-abc']['id'] );
	}

	public function test_an_atomic_element_reports_its_navigator_label() {
		$out = $this->readback(
			array(
				'id'              => 'abc1234',
				'elType'          => 'e-flexbox',
				'settings'        => array(),
				'editor_settings' => array( 'title' => 'Hero' ),
			)
		);

		$this->assertSame( 'Hero', $out['editor_settings']['title'] );
	}

	public function test_an_empty_root_key_is_omitted_rather_than_returned_empty() {
		// The factory seeds both as empty arrays on every atomic element, so
		// returning them regardless would put two always-present empty objects
		// in every response and make "has none" indistinguishable from "has an
		// empty one".
		$out = $this->readback(
			array(
				'id'              => 'abc1234',
				'elType'          => 'e-flexbox',
				'settings'        => array(),
				'styles'          => array(),
				'editor_settings' => array(),
			)
		);

		$this->assertArrayNotHasKey( 'styles', $out );
		$this->assertArrayNotHasKey( 'editor_settings', $out );
	}

	public function test_a_classic_element_is_unchanged() {
		$out = $this->readback(
			array(
				'id'       => 'abc1234',
				'elType'   => 'container',
				'settings' => array( '_title' => 'Hero' ),
			)
		);

		$this->assertSame(
			array( 'element_id', 'elType', 'widgetType', 'settings' ),
			array_keys( $out ),
			'A classic element has neither key, so the response keeps the shape it always had.'
		);
		$this->assertSame( 'Hero', $out['settings']['_title'] );
	}
}
