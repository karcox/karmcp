<?php
/**
 * The label a snapshot shows for an element.
 *
 * Two gaps, both of which made the snapshot silent about exactly the elements
 * a label exists to make legible. It read only `settings`, so the Navigator
 * label of a v4 atomic element — which lives at the element root — was never
 * seen; and it tested candidate values with `is_string()`, which every atomic
 * prop fails, since those are `{$$type:…, value:…}`. An atomic page therefore
 * came back as an unlabelled tree of `e-flexbox` and `e-heading`.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-id-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-atomic-widget-map.php';
require_once dirname( __DIR__ ) . '/includes/class-atomic-props.php';
require_once dirname( __DIR__ ) . '/includes/class-element-factory.php';
require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';
require_once dirname( __DIR__ ) . '/includes/class-page-snapshot.php';

class SnapshotElementLabelTest extends \PHPUnit\Framework\TestCase {

	public function test_a_classic_navigator_label_is_used() {
		$this->assertSame(
			'Hero',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'   => 'container',
					'settings' => array( '_title' => 'Hero' ),
				)
			)
		);
	}

	public function test_an_atomic_navigator_label_is_used() {
		$this->assertSame(
			'Hero',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'          => 'e-flexbox',
					'settings'        => array(),
					'editor_settings' => array( 'title' => 'Hero' ),
				)
			)
		);
	}

	public function test_a_stale_root_label_on_a_classic_element_is_ignored() {
		// Every pre-1.38.0 write put the label here, on classic elements too,
		// and those elements do not repair themselves. The Elementor Navigator
		// has never shown that value; neither should the tool whose job is to
		// say what the page looks like.
		$this->assertSame(
			'Correct',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'          => 'container',
					'settings'        => array( '_title' => 'Correct' ),
					'editor_settings' => array( 'title' => 'Stale leftover' ),
				)
			)
		);
	}

	public function test_a_stale_root_label_does_not_stand_in_for_a_missing_one() {
		$this->assertSame(
			'Buy now',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'          => 'widget',
					'widgetType'      => 'heading',
					'settings'        => array( 'title' => 'Buy now' ),
					'editor_settings' => array( 'title' => 'Stale leftover' ),
				)
			)
		);
	}

	public function test_the_authors_label_beats_anything_derived_from_the_content() {
		$this->assertSame(
			'Called to action',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'          => 'widget',
					'widgetType'      => 'e-heading',
					'settings'        => array(
						'title' => array(
							'$$type' => 'string',
							'value'  => 'Buy now',
						),
					),
					'editor_settings' => array( 'title' => 'Called to action' ),
				)
			)
		);
	}

	public function test_an_atomic_prop_is_unwrapped_when_there_is_no_label() {
		$this->assertSame(
			'Buy now',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'     => 'widget',
					'widgetType' => 'e-heading',
					'settings'   => array(
						'title' => array(
							'$$type' => 'string',
							'value'  => 'Buy now',
						),
					),
				)
			)
		);
	}

	public function test_markup_is_stripped_from_a_derived_label() {
		$this->assertSame(
			'Bold heading',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => '<b>Bold</b> heading' ),
				)
			)
		);
	}

	public function test_an_empty_label_falls_through_rather_than_winning() {
		$this->assertSame(
			'Buy now',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'          => 'widget',
					'widgetType'      => 'heading',
					'settings'        => array( 'title' => 'Buy now' ),
					'editor_settings' => array( 'title' => '   ' ),
				)
			)
		);
	}

	public function test_an_element_with_nothing_to_go_on_has_no_label() {
		$this->assertSame(
			'',
			KarMCP_Page_Snapshot::element_label(
				array(
					'elType'          => 'e-div-block',
					'settings'        => array(),
					'editor_settings' => array(),
				)
			)
		);
	}
}
