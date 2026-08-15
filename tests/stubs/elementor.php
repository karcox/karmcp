<?php
/**
 * Minimal Elementor stubs, enough to load and run a generated widget class.
 *
 * The generated widget extends Widget_Base and reaches for Controls_Manager
 * constants and Icons_Manager, so a test that actually executes the compiler's
 * output needs those symbols to exist. Nothing here models Elementor's real
 * behaviour beyond the surface the generated code touches.
 *
 * @package KarMCP
 */

namespace Elementor;

class Controls_Manager {
	const TAB_CONTENT = 'content';
	const TAB_STYLE   = 'style';

	const TEXT     = 'text';
	const TEXTAREA = 'textarea';
	const WYSIWYG  = 'wysiwyg';
	const NUMBER   = 'number';
	const SELECT   = 'select';
	const SWITCHER = 'switcher';
	const COLOR    = 'color';
	const URL      = 'url';
	const MEDIA    = 'media';
	const ICONS    = 'icons';
}

class Icons_Manager {
	/** Prints, like the real one, so the generated helper has something to capture. */
	public static function render_icon( $icon, $attributes = array(), $tag = 'i' ) {
		$class = isset( $icon['value'] ) ? (string) $icon['value'] : '';
		echo '<i class="' . htmlspecialchars( $class, ENT_QUOTES, 'UTF-8' ) . '"></i>';
		return true;
	}
}

abstract class Widget_Base {

	/** Settings the test hands the widget, as get_settings_for_display() returns. */
	public $karmcp_test_settings = array();

	/** Controls the widget registered, name => args. */
	public $karmcp_test_controls = array();

	/** Sections the widget opened, id => args. */
	public $karmcp_test_sections = array();

	/** Section currently open, to catch an unbalanced start/end pair. */
	private $karmcp_open_section = null;

	public function get_settings_for_display( $setting = null ) {
		return $this->karmcp_test_settings;
	}

	public function start_controls_section( $id, $args = array() ) {
		if ( null !== $this->karmcp_open_section ) {
			throw new \RuntimeException( 'start_controls_section() called while "' . $this->karmcp_open_section . '" was still open.' );
		}
		$this->karmcp_open_section          = $id;
		$this->karmcp_test_sections[ $id ] = $args;
	}

	public function end_controls_section() {
		if ( null === $this->karmcp_open_section ) {
			throw new \RuntimeException( 'end_controls_section() called with no section open.' );
		}
		$this->karmcp_open_section = null;
	}

	public function add_control( $name, $args = array(), $options = array() ) {
		if ( null === $this->karmcp_open_section ) {
			throw new \RuntimeException( 'add_control("' . $name . '") called outside a controls section.' );
		}
		$this->karmcp_test_controls[ $name ] = $args;
	}

	/** Stands in for Elementor's control-stack build, as the store's runtime check does. */
	public function get_stack( $with_common_controls = true ) {
		$this->register_controls();
		if ( null !== $this->karmcp_open_section ) {
			throw new \RuntimeException( 'register_controls() left section "' . $this->karmcp_open_section . '" open.' );
		}
		return array( 'controls' => $this->karmcp_test_controls );
	}

	/** Runs render() and returns what it printed. */
	public function karmcp_test_render( array $settings ): string {
		$this->karmcp_test_settings = $settings;
		ob_start();
		$this->render();
		return (string) ob_get_clean();
	}

	abstract protected function render();

	protected function register_controls() {}
}
