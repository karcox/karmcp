<?php
/**
 * The Navigator label goes to one of two keys, and until 1.38.0 every write
 * went to the same one.
 *
 * Elementor keeps that label in `settings._title` on a classic element and in
 * the root-level `editor_settings.title` on a v4 atomic one, and neither side
 * falls back to the other. Writing the v4 spelling onto a classic container
 * stored a root key nothing reads: the write succeeded, the value read back,
 * and the Navigator went on showing "Container". It is the silent-write family
 * this file's neighbours already cover — the CSS class key, the null deletion,
 * the background activator — and it failed the same way, in the direction that
 * costs a trip to the editor to discover nothing happened.
 *
 * Pure array work, so it is pinned here rather than left to a manual check.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-id-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-atomic-widget-map.php';
require_once dirname( __DIR__ ) . '/includes/class-atomic-props.php';
require_once dirname( __DIR__ ) . '/includes/class-element-factory.php';
require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';

class NavigatorLabelRoutingTest extends \PHPUnit\Framework\TestCase {

	private KarMCP_Data $data;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->data = new KarMCP_Data();
	}

	/**
	 * Builds a one-element tree.
	 *
	 * @param string $el_type  Element type.
	 * @param array  $extra    Extra top-level element keys (e.g. widgetType, editor_settings).
	 * @param array  $settings Starting settings.
	 * @return array
	 */
	private function tree( string $el_type, array $extra = array(), array $settings = array() ): array {
		return array(
			array_merge(
				array(
					'id'       => 'abc1234',
					'elType'   => $el_type,
					'settings' => $settings,
					'elements' => array(),
				),
				$extra
			),
		);
	}

	/**
	 * Applies a settings payload and returns the whole element node, because
	 * what this suite is about is which half of the node the value landed in.
	 *
	 * @param array $data     The tree.
	 * @param array $settings The payload.
	 * @return array The element node after the write.
	 */
	private function write( array $data, array $settings ): array {
		$this->assertTrue( $this->data->update_element_settings( $data, 'abc1234', $settings ) );
		return $data[0];
	}

	// ---------------------------------------------------------------------
	// The predicate
	// ---------------------------------------------------------------------

	public function test_atomic_containers_are_recognised_by_their_el_type() {
		$this->assertTrue( KarMCP_Data::is_atomic_element( array( 'elType' => 'e-flexbox' ) ) );
		$this->assertTrue( KarMCP_Data::is_atomic_element( array( 'elType' => 'e-div-block' ) ) );
	}

	public function test_atomic_widgets_are_recognised_by_their_widget_type() {
		$this->assertTrue(
			KarMCP_Data::is_atomic_element(
				array(
					'elType'     => 'widget',
					'widgetType' => 'e-heading',
				)
			)
		);
	}

	public function test_classic_elements_are_not_atomic() {
		$this->assertFalse( KarMCP_Data::is_atomic_element( array( 'elType' => 'container' ) ) );
		$this->assertFalse( KarMCP_Data::is_atomic_element( array( 'elType' => 'section' ) ) );
		$this->assertFalse(
			KarMCP_Data::is_atomic_element(
				array(
					'elType'     => 'widget',
					'widgetType' => 'heading',
				)
			)
		);
	}

	// ---------------------------------------------------------------------
	// Classic elements: the label belongs in settings._title
	// ---------------------------------------------------------------------

	public function test_v4_spelling_on_a_classic_container_lands_in_settings_title() {
		$el = $this->write(
			$this->tree( 'container' ),
			array( 'editor_settings' => array( 'title' => 'Hero' ) )
		);

		$this->assertSame( 'Hero', $el['settings']['_title'] );
		$this->assertArrayNotHasKey(
			'editor_settings',
			$el,
			'A classic element has no editor_settings, so writing one leaves a root key nothing reads.'
		);
	}

	public function test_v4_spelling_on_a_classic_widget_lands_in_settings_title() {
		$el = $this->write(
			$this->tree( 'widget', array( 'widgetType' => 'heading' ) ),
			array( 'editor_settings' => array( 'title' => 'Page title' ) )
		);

		$this->assertSame( 'Page title', $el['settings']['_title'] );
		$this->assertArrayNotHasKey( 'editor_settings', $el );
	}

	public function test_classic_spelling_on_a_classic_element_is_left_alone() {
		$el = $this->write(
			$this->tree( 'container' ),
			array( '_title' => 'Hero' )
		);

		$this->assertSame( 'Hero', $el['settings']['_title'] );
		$this->assertArrayNotHasKey( 'editor_settings', $el );
	}

	public function test_other_editor_settings_keys_still_reach_the_root_on_a_classic_element() {
		$el = $this->write(
			$this->tree( 'container' ),
			array(
				'editor_settings' => array(
					'title'    => 'Hero',
					'whatever' => 'kept',
				),
			)
		);

		$this->assertSame( 'Hero', $el['settings']['_title'] );
		$this->assertSame( array( 'whatever' => 'kept' ), $el['editor_settings'] );
	}

	public function test_a_label_left_by_an_older_version_is_cleared_from_a_classic_element() {
		$el = $this->write(
			$this->tree( 'container', array( 'editor_settings' => array( 'title' => 'Stale' ) ) ),
			array( 'editor_settings' => array( 'title' => 'Hero' ) )
		);

		$this->assertSame( 'Hero', $el['settings']['_title'] );
		$this->assertArrayNotHasKey(
			'editor_settings',
			$el,
			'Leaving the old key behind gives the element two labels, with the invisible one on top.'
		);
	}

	// ---------------------------------------------------------------------
	// Atomic elements: the label belongs in the root editor_settings.title
	// ---------------------------------------------------------------------

	public function test_v4_spelling_on_an_atomic_container_stays_at_the_root() {
		$el = $this->write(
			$this->tree( 'e-flexbox', array( 'editor_settings' => array() ) ),
			array( 'editor_settings' => array( 'title' => 'Hero' ) )
		);

		$this->assertSame( 'Hero', $el['editor_settings']['title'] );
		$this->assertArrayNotHasKey( '_title', $el['settings'] );
	}

	public function test_classic_spelling_on_an_atomic_element_is_routed_to_the_root() {
		$el = $this->write(
			$this->tree( 'e-div-block', array( 'editor_settings' => array() ) ),
			array( '_title' => 'Hero' )
		);

		$this->assertSame( 'Hero', $el['editor_settings']['title'] );
		$this->assertArrayNotHasKey(
			'_title',
			$el['settings'],
			'`_title` is not a prop of an atomic element and would sit in its typed settings unread.'
		);
	}

	public function test_classic_spelling_on_an_atomic_widget_is_routed_to_the_root() {
		$el = $this->write(
			$this->tree( 'widget', array( 'widgetType' => 'e-heading', 'editor_settings' => array() ) ),
			array( '_title' => 'Section heading' )
		);

		$this->assertSame( 'Section heading', $el['editor_settings']['title'] );
		$this->assertArrayNotHasKey( '_title', $el['settings'] );
	}

	public function test_a_partial_editor_settings_update_keeps_its_siblings() {
		$el = $this->write(
			$this->tree( 'e-flexbox', array( 'editor_settings' => array( 'other' => 'kept' ) ) ),
			array( 'editor_settings' => array( 'title' => 'Hero' ) )
		);

		$this->assertSame( 'Hero', $el['editor_settings']['title'] );
		$this->assertSame( 'kept', $el['editor_settings']['other'] );
	}

	// ---------------------------------------------------------------------
	// Both spellings at once, and deletions
	// ---------------------------------------------------------------------

	public function test_the_native_spelling_wins_when_both_are_sent_to_a_classic_element() {
		$el = $this->write(
			$this->tree( 'container' ),
			array(
				'_title'          => 'Chosen',
				'editor_settings' => array( 'title' => 'Translated' ),
			)
		);

		$this->assertSame( 'Chosen', $el['settings']['_title'] );
	}

	public function test_the_native_spelling_wins_when_both_are_sent_to_an_atomic_element() {
		$el = $this->write(
			$this->tree( 'e-flexbox', array( 'editor_settings' => array() ) ),
			array(
				'_title'          => 'Translated',
				'editor_settings' => array( 'title' => 'Chosen' ),
			)
		);

		$this->assertSame( 'Chosen', $el['editor_settings']['title'] );
		$this->assertArrayNotHasKey( '_title', $el['settings'] );
	}

	public function test_a_null_label_deletes_it_on_a_classic_element() {
		$el = $this->write(
			$this->tree( 'container', array(), array( '_title' => 'Hero' ) ),
			array( 'editor_settings' => array( 'title' => null ) )
		);

		$this->assertArrayNotHasKey( '_title', $el['settings'] );
	}

	public function test_a_null_label_in_the_native_spelling_also_deletes_it_on_an_atomic_element() {
		// The spelling an agent is most likely to use for an atomic element,
		// and the one the merge cannot delete on its own: without a special
		// case it left a literal `"title": null` behind and reported success.
		$el = $this->write(
			$this->tree( 'e-flexbox', array( 'editor_settings' => array( 'title' => 'Hero', 'other' => 'kept' ) ) ),
			array( 'editor_settings' => array( 'title' => null ) )
		);

		$this->assertArrayNotHasKey( 'title', $el['editor_settings'] );
		$this->assertSame( 'kept', $el['editor_settings']['other'] );
	}

	public function test_a_null_label_deletes_it_on_an_atomic_element() {
		$el = $this->write(
			$this->tree( 'e-flexbox', array( 'editor_settings' => array( 'title' => 'Hero' ) ) ),
			array( '_title' => null )
		);

		$this->assertArrayNotHasKey(
			'title',
			$el['editor_settings'],
			'A merge can only add or overwrite, so a deletion has to clear the stored key itself.'
		);
	}

	// ---------------------------------------------------------------------
	// What the routing must not disturb
	// ---------------------------------------------------------------------

	public function test_a_styles_map_still_reaches_the_root_of_an_atomic_element() {
		$el = $this->write(
			$this->tree( 'e-flexbox', array( 'styles' => array(), 'editor_settings' => array() ) ),
			array(
				'editor_settings' => array( 'title' => 'Hero' ),
				'styles'          => array( 'e-abc' => array( 'id' => 'e-abc' ) ),
			)
		);

		$this->assertSame( 'Hero', $el['editor_settings']['title'] );
		$this->assertSame( 'e-abc', $el['styles']['e-abc']['id'] );
		$this->assertArrayNotHasKey( 'styles', $el['settings'] );
	}

	public function test_a_payload_with_no_label_leaves_both_keys_alone() {
		$el = $this->write(
			$this->tree( 'container', array(), array( '_title' => 'Hero' ) ),
			array( 'align' => 'center' )
		);

		$this->assertSame( 'Hero', $el['settings']['_title'] );
		$this->assertSame( 'center', $el['settings']['align'] );
		$this->assertArrayNotHasKey( 'editor_settings', $el );
	}
}
