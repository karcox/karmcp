<?php
/**
 * The curated catalog may only publish controls the widget actually has.
 *
 * The catalog is what an agent reads *before* writing, so a wrong name there
 * is expensive in a particular way: the write is accepted, the response says
 * success, and the setting silently does nothing. It cost four buttons keeping
 * their factory padding, found in the browser rather than in any error.
 *
 * The trap is scope. Elementor registers same-purpose controls in different
 * documents, and the kit's name is often the more obvious one:
 *
 *   button_padding  → kit global button styles (theme-style-buttons.php:241)
 *   text_padding    → the button widget itself  (button-trait.php:496)
 *
 * A blanket ban on the kit's names would be wrong, and this test was written
 * that way first: `button_padding` is a perfectly real control on
 * call-to-action, on WooCommerce's add-to-cart, and on a dozen Jet widgets. The
 * name is not the problem — the pairing is. So what is pinned here is per
 * widget: the button entry against the button widget's own controls.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class WidgetCatalogControlNamesTest extends TestCase {

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function catalog(): array {
		$catalog = array();
		foreach ( array( 'free', 'pro', 'woo' ) as $tier ) {
			$file = __DIR__ . '/../includes/widgets/catalog-' . $tier . '.php';
			if ( file_exists( $file ) ) {
				$catalog += (array) require $file;
			}
		}
		return $catalog;
	}

	/**
	 * Every control the core button widget registers, read from Elementor 4.2.x
	 * on 2026-08-17: `includes/widgets/traits/button-trait.php` plus the
	 * widget's own `align`. Group controls expand to `<name>_<field>`, which is
	 * why `background_color` and `button_box_shadow_box_shadow` belong here.
	 *
	 * To refresh: read that trait and list every add_control /
	 * add_responsive_control name, expanding each add_group_control.
	 *
	 * @var string[]
	 */
	private const BUTTON_WIDGET_CONTROLS = array(
		// Content.
		'button_type', 'text', 'link', 'size', 'selected_icon', 'icon_align',
		'icon_indent', 'button_css_id', 'align',
		// Style — normal.
		'button_text_color', 'background_background', 'background_color',
		'button_box_shadow_box_shadow_type', 'button_box_shadow_box_shadow',
		// Style — hover.
		'hover_color', 'button_background_hover_background',
		'button_background_hover_color', 'button_hover_border_color',
		'button_hover_box_shadow_box_shadow_type', 'button_hover_box_shadow_box_shadow',
		'button_hover_transition_duration', 'hover_animation',
		// Border, spacing, type.
		'border_border', 'border_width', 'border_color', 'border_radius',
		'text_padding',
		'typography_typography', 'typography_font_family', 'typography_font_size',
		'typography_font_weight', 'typography_text_transform',
		'typography_letter_spacing', 'typography_line_height',
		'text_shadow_text_shadow',
	);

	public function test_the_button_entry_only_publishes_real_button_controls(): void {
		$catalog = $this->catalog();
		$this->assertArrayHasKey( 'button', $catalog );

		foreach ( array_keys( $catalog['button']['params'] ) as $param ) {
			$this->assertContains(
				$param,
				self::BUTTON_WIDGET_CONTROLS,
				sprintf( 'The button catalog publishes "%s", which the button widget does not register.', $param )
			);
		}
	}

	/**
	 * The regression itself, named outright. `button_padding` on this widget is
	 * the kit's control: accepted on write, stored, never rendered.
	 */
	public function test_the_button_entry_uses_the_widget_padding_not_the_kits(): void {
		$params = $this->catalog()['button']['params'];

		$this->assertArrayHasKey( 'text_padding', $params );
		$this->assertArrayNotHasKey( 'button_padding', $params, 'That is the kit control, and it does nothing here.' );
	}

	/**
	 * Every entry a widget declares as required must be one it also publishes,
	 * or the schema asks for a parameter it never describes.
	 */
	public function test_required_params_are_published(): void {
		foreach ( $this->catalog() as $widget => $entry ) {
			$params = array_keys( (array) ( $entry['params'] ?? array() ) );
			foreach ( (array) ( $entry['required'] ?? array() ) as $required ) {
				$this->assertContains( $required, $params, $widget . ' requires an undeclared param.' );
			}
		}
	}

	/**
	 * Cheap structural checks across the whole catalog: a param with no type is
	 * a param the schema cannot describe, and an empty description is what
	 * sends an agent guessing at the name instead of reading it.
	 */
	public function test_every_published_param_is_described(): void {
		foreach ( $this->catalog() as $widget => $entry ) {
			foreach ( (array) ( $entry['params'] ?? array() ) as $name => $spec ) {
				$this->assertArrayHasKey( 'type', $spec, $widget . '.' . $name );
				$this->assertNotSame( '', trim( (string) ( $spec['description'] ?? '' ) ), $widget . '.' . $name );
			}
		}
	}
}
