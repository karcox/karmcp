<?php
/**
 * Themer dynamic Elementor widget classes (base + one thin subclass per element).
 *
 * Required only from KarMCP_Themer_Widgets::register_widgets(), i.e. inside
 * `elementor/widgets/register`, so \Elementor\Widget_Base is guaranteed loaded.
 * The base builds its content controls from the SAME descriptors the Gutenberg
 * blocks use (KarMCP_Themer_Blocks::blocks()) and renders through the shared
 * provider, plus a Style tab (color/typography/alignment) via Elementor selectors.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Base dynamic widget. Subclasses only declare their catalog key.
 *
 * @since 3.1.0
 */
abstract class KarMCP_Themer_Widget_Base extends \Elementor\Widget_Base {

	/** The catalog key (post-title, archive-loop, …). */
	abstract protected function karmcp_key(): string;

	/** The fully-qualified subclass names to register. */
	public static function widget_classes(): array {
		return array(
			'KarMCP_Themer_Widget_Post_Title',
			'KarMCP_Themer_Widget_Archive_Title',
			'KarMCP_Themer_Widget_Breadcrumbs',
			'KarMCP_Themer_Widget_Post_Meta',
			'KarMCP_Themer_Widget_Site_Logo',
			'KarMCP_Themer_Widget_Site_Title',
			'KarMCP_Themer_Widget_Nav_Menu',
			'KarMCP_Themer_Widget_Description',
			'KarMCP_Themer_Widget_Post_Content',
			'KarMCP_Themer_Widget_Archive_Loop',
		);
	}

	/** key => [title, eicon]. */
	private static function meta(): array {
		return array(
			'post-title'    => array( __( 'Post/Page Title', 'karmcp' ), 'eicon-post-title' ),
			'archive-title' => array( __( 'Archive Title', 'karmcp' ), 'eicon-archive-title' ),
			'breadcrumbs'   => array( __( 'Breadcrumbs', 'karmcp' ), 'eicon-navigation-horizontal' ),
			'post-meta'     => array( __( 'Post Meta', 'karmcp' ), 'eicon-post-info' ),
			'site-logo'     => array( __( 'Site Logo', 'karmcp' ), 'eicon-site-logo' ),
			'site-title'    => array( __( 'Site Title', 'karmcp' ), 'eicon-site-title' ),
			'nav-menu'      => array( __( 'Menu', 'karmcp' ), 'eicon-nav-menu' ),
			'description'   => array( __( 'Description', 'karmcp' ), 'eicon-text' ),
			'post-content'  => array( __( 'Post Content', 'karmcp' ), 'eicon-post-content' ),
			'archive-loop'  => array( __( 'Archive Posts', 'karmcp' ), 'eicon-posts-grid' ),
		);
	}

	public function get_name(): string {
		return 'karmcp-' . $this->karmcp_key();
	}

	public function get_title(): string {
		$m = self::meta();
		return $m[ $this->karmcp_key() ][0] ?? $this->karmcp_key();
	}

	public function get_icon(): string {
		$m = self::meta();
		return $m[ $this->karmcp_key() ][1] ?? 'eicon-code';
	}

	public function get_categories(): array {
		return array( KarMCP_Themer_Widgets::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'karmcp', 'themer', 'dynamic', 'theme', $this->karmcp_key() );
	}

	/** Build content controls from the shared descriptors, then the style tab. */
	protected function register_controls(): void {
		$key    = $this->karmcp_key();
		$blocks = class_exists( 'KarMCP_Themer_Blocks' ) ? KarMCP_Themer_Blocks::blocks() : array();
		$def    = $blocks[ $key ] ?? array( 'controls' => array(), 'attributes' => array() );

		$this->start_controls_section(
			'karmcp_content',
			array( 'label' => __( 'Content', 'karmcp' ), 'tab' => \Elementor\Controls_Manager::TAB_CONTENT )
		);

		foreach ( (array) $def['controls'] as $ctrl ) {
			$args = $this->control_args( $ctrl, $def['attributes'] ?? array() );
			if ( $args ) {
				$this->add_control( $ctrl['key'], $args );
			}
		}
		if ( empty( $def['controls'] ) ) {
			$this->add_control(
				'karmcp_note',
				array(
					'type' => \Elementor\Controls_Manager::RAW_HTML,
					'raw'  => esc_html__( 'Renders the current post content dynamically.', 'karmcp' ),
				)
			);
		}
		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Map a shared control descriptor to Elementor control args.
	 *
	 * @param array $ctrl  Descriptor ({key,type,label,options}).
	 * @param array $attrs The block attributes (for defaults).
	 * @return array|null
	 */
	private function control_args( array $ctrl, array $attrs ): ?array {
		$key     = $ctrl['key'];
		$default = $attrs[ $key ]['default'] ?? null;

		switch ( $ctrl['type'] ) {
			case 'select':
				$options = array();
				foreach ( (array) ( $ctrl['options'] ?? array() ) as $opt ) {
					$options[ $opt ] = ucfirst( $opt );
				}
				return array(
					'label'   => $ctrl['label'],
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => $options,
					'default' => (string) $default,
				);
			case 'toggle':
				return array(
					'label'        => $ctrl['label'],
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'default'      => $default ? 'yes' : '',
					'return_value' => 'yes',
				);
			case 'text':
				return array(
					'label'   => $ctrl['label'],
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => (string) $default,
				);
			case 'number':
				return array(
					'label'   => $ctrl['label'],
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'default' => (int) $default,
					'min'     => 0,
				);
			case 'menu':
				return array(
					'label'   => $ctrl['label'],
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => $this->menu_options(),
					'default' => '0',
				);
		}
		return null;
	}

	/** Available nav menus for the menu picker. */
	private function menu_options(): array {
		$out   = array( '0' => __( ', Auto (first menu) , ', 'karmcp' ) );
		$menus = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus() : array();
		foreach ( $menus as $menu ) {
			$out[ (string) $menu->term_id ] = $menu->name;
		}
		return $out;
	}

	/** A shared Style tab: color, link color, typography, alignment. */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'karmcp_style',
			array( 'label' => __( 'Style', 'karmcp' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE )
		);

		$this->add_responsive_control(
			'karmcp_align',
			array(
				'label'     => __( 'Alignment', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array( 'title' => __( 'Left', 'karmcp' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Center', 'karmcp' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'karmcp' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors' => array( '{{WRAPPER}}' => 'text-align: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'karmcp_color',
			array(
				'label'     => __( 'Text color', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'karmcp_link_color',
			array(
				'label'     => __( 'Link color', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn a' => 'color: {{VALUE}};' ),
			)
		);
		if ( class_exists( '\\Elementor\\Group_Control_Typography' ) ) {
			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				array(
					'name'     => 'karmcp_typography',
					'selector' => '{{WRAPPER}} .karmcp-dyn',
				)
			);
		}

		$this->end_controls_section();

		if ( 'archive-loop' === $this->karmcp_key() ) {
			$this->register_archive_loop_style();
		}
	}

	/** A "Cards" style section for the Archive Posts widget. */
	private function register_archive_loop_style(): void {
		$this->start_controls_section(
			'karmcp_cards',
			array( 'label' => __( 'Cards', 'karmcp' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE )
		);

		$this->add_responsive_control(
			'karmcp_gap',
			array(
				'label'      => __( 'Gap between cards', 'karmcp' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array( '{{WRAPPER}} .karmcp-dyn-archive-loop' => '--karmcp-card-gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'karmcp_media_width',
			array(
				'label'       => __( 'Image width (list)', 'karmcp' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( '%', 'px' ),
				'range'       => array( '%' => array( 'min' => 15, 'max' => 60 ), 'px' => array( 'min' => 120, 'max' => 480 ) ),
				'selectors'   => array( '{{WRAPPER}} .karmcp-dyn-archive-loop--list .karmcp-dyn-card-media' => 'flex-basis: {{SIZE}}{{UNIT}}; max-width: {{SIZE}}{{UNIT}};' ),
				'description' => __( 'Only affects the List layout.', 'karmcp' ),
			)
		);

		$this->add_control(
			'karmcp_card_bg',
			array(
				'label'     => __( 'Card background', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn-card' => 'background: {{VALUE}};' ),
			)
		);
		if ( class_exists( '\\Elementor\\Group_Control_Border' ) ) {
			$this->add_group_control(
				\Elementor\Group_Control_Border::get_type(),
				array(
					'name'     => 'karmcp_card_border',
					'selector' => '{{WRAPPER}} .karmcp-dyn-card',
				)
			);
		}
		$this->add_responsive_control(
			'karmcp_card_radius',
			array(
				'label'      => __( 'Border radius', 'karmcp' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .karmcp-dyn-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;' ),
			)
		);
		$this->add_responsive_control(
			'karmcp_card_padding',
			array(
				'label'      => __( 'Content padding', 'karmcp' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .karmcp-dyn-card-body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		if ( class_exists( '\\Elementor\\Group_Control_Box_Shadow' ) ) {
			$this->add_group_control(
				\Elementor\Group_Control_Box_Shadow::get_type(),
				array(
					'name'     => 'karmcp_card_shadow',
					'selector' => '{{WRAPPER}} .karmcp-dyn-card',
				)
			);
		}

		$this->add_control(
			'karmcp_title_color',
			array(
				'label'     => __( 'Title color', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn-card-title, {{WRAPPER}} .karmcp-dyn-card-title a' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'karmcp_meta_color',
			array(
				'label'     => __( 'Meta color', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn-card-meta' => 'color: {{VALUE}}; opacity: 1;' ),
			)
		);
		$this->add_control(
			'karmcp_excerpt_color',
			array(
				'label'     => __( 'Excerpt color', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn-card-excerpt' => 'color: {{VALUE}}; opacity: 1;' ),
			)
		);
		$this->add_control(
			'karmcp_more_color',
			array(
				'label'     => __( 'Read-more color', 'karmcp' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .karmcp-dyn-card-more' => 'color: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();
	}

	/** Render via the shared provider (output already escaped in the provider). */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$args     = KarMCP_Themer_Dynamic::args_from( $this->karmcp_key(), is_array( $settings ) ? $settings : array() );
		echo KarMCP_Themer_Dynamic::render( $this->karmcp_key(), $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/** One thin subclass per element — the base does all the work. */
class KarMCP_Themer_Widget_Post_Title extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'post-title'; }
}
class KarMCP_Themer_Widget_Archive_Title extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'archive-title'; }
}
class KarMCP_Themer_Widget_Breadcrumbs extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'breadcrumbs'; }
}
class KarMCP_Themer_Widget_Post_Meta extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'post-meta'; }
}
class KarMCP_Themer_Widget_Site_Logo extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'site-logo'; }
}
class KarMCP_Themer_Widget_Site_Title extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'site-title'; }
}
class KarMCP_Themer_Widget_Nav_Menu extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'nav-menu'; }
}
class KarMCP_Themer_Widget_Description extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'description'; }
}
class KarMCP_Themer_Widget_Post_Content extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'post-content'; }
}
class KarMCP_Themer_Widget_Archive_Loop extends KarMCP_Themer_Widget_Base {
	protected function karmcp_key(): string { return 'archive-loop'; }
}
