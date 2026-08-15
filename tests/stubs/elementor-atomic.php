<?php
/**
 * Minimal stubs of Elementor 4.2's atomic API, enough to run a generated
 * element extension.
 *
 * Only the surface the generated code touches: the prop types it declares, the
 * control classes it builds, and an element double that records what the
 * extension put on the wrapper. The fluent setters return $this and remember
 * their arguments, so a test can assert on the shape the extension asked for.
 *
 * @package KarMCP
 */

namespace Elementor\Modules\AtomicWidgets\PropTypes\Primitives {

	class Prop_Type_Double {
		public array $calls = array();

		public static function make(): self {
			return new static();
		}

		public function __call( $name, $arguments ) {
			$this->calls[ $name ] = $arguments[0] ?? null;
			return $this;
		}
	}

	class String_Prop_Type extends Prop_Type_Double {}
	class Number_Prop_Type extends Prop_Type_Double {}
	class Boolean_Prop_Type extends Prop_Type_Double {}
}

namespace Elementor\Modules\AtomicWidgets\PropTypes {

	class Size_Prop_Type extends \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Prop_Type_Double {}
}

namespace Elementor\Modules\AtomicWidgets\Controls {

	class Section {
		public string $label = '';
		public array $items = array();

		public static function make(): self {
			return new self();
		}

		public function set_label( string $label ): self {
			$this->label = $label;
			return $this;
		}

		public function set_items( array $items ): self {
			$this->items = $items;
			return $this;
		}

		public function get_items(): array {
			return $this->items;
		}
	}
}

namespace Elementor\Modules\AtomicWidgets\Controls\Types {

	class Control_Double {
		public string $bound = '';
		public string $label = '';
		public array $options = array();
		public string $placeholder = '';

		public static function bind_to( string $prop ): self {
			$control = new static();
			$control->bound = $prop;
			return $control;
		}

		public function set_label( string $label ): self {
			$this->label = $label;
			return $this;
		}

		public function set_options( array $options ): self {
			$this->options = $options;
			return $this;
		}

		public function set_placeholder( string $placeholder ): self {
			$this->placeholder = $placeholder;
			return $this;
		}

		public function get_type(): string {
			$parts = explode( '\\', static::class );
			return strtolower( str_replace( '_Control', '', end( $parts ) ) );
		}
	}

	class Switch_Control extends Control_Double {}
	class Select_Control extends Control_Double {}
	class Text_Control extends Control_Double {}
	class Textarea_Control extends Control_Double {}
	class Number_Control extends Control_Double {}
	class Size_Control extends Control_Double {}
}

namespace KarMCP\Tests {

	/**
	 * Stands in for an atomic element: reports its type, hands over settings,
	 * and records wrapper attributes the way Elementor accumulates them.
	 */
	abstract class Atomic_Element_Double {

		public array $wrapper = array();

		/** Raw props, the `$$type`-wrapped shape Elementor stores. */
		public array $props = array();

		private array $settings;

		public function __construct( array $settings = array(), array $props = array() ) {
			$this->settings = $settings;
			$this->props    = $props;
		}

		public function get_atomic_settings(): array {
			return $this->settings;
		}

		public function get_settings( $key = null ) {
			return $this->props;
		}

		public function set_settings( $key, $value = null ): void {
			$this->props[ $key ] = $value;
		}

		/** The class list as the Twig template would read it. */
		public function classes(): array {
			return $this->props['classes']['value'] ?? array();
		}

		/** Attributes as name => value, flattened from the key-value shape. */
		public function attributes(): array {
			$out = array();
			foreach ( $this->props['attributes']['value'] ?? array() as $item ) {
				$name  = $item['value']['key']['value'] ?? '';
				$value = $item['value']['value']['value'] ?? '';
				if ( '' !== $name ) {
					$out[ $name ] = $value;
				}
			}
			return $out;
		}

		/** Elementor accumulates rather than overwrites; so does this. */
		public function add_render_attribute( string $target, string $name, $value = null ): void {
			if ( '_wrapper' !== $target ) {
				return;
			}
			$this->wrapper[ $name ][] = (string) $value;
		}

		/** The attribute as it would be printed. */
		public function attribute( string $name ): ?string {
			return isset( $this->wrapper[ $name ] ) ? implode( ' ', $this->wrapper[ $name ] ) : null;
		}
	}

	class Div_Block_Double extends Atomic_Element_Double {
		public static function get_element_type(): string {
			return 'e-div-block';
		}
	}

	class Heading_Double extends Atomic_Element_Double {
		public static function get_element_type(): string {
			return 'e-heading';
		}
	}
}
