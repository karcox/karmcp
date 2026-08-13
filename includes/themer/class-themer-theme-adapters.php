<?php
/**
 * Theme adapters for standalone header/footer injection.
 *
 * On supported themes we suppress the theme's own header/footer and print ours at
 * the theme's hook, preserving the theme's content area. adapter_for() maps a
 * template (parent) slug to an adapter key; the render controller wires the hooks.
 * Unsupported themes fall back to the documented karmcp_themer_location() tag or the
 * optional full-page-takeover toggle (handled by the render controller).
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
class KarMCP_Themer_Theme_Adapters {

	/**
	 * Supported theme (template) slug => adapter config.
	 *
	 * Two shapes are supported:
	 *
	 *  - Hook adapters: { header: <action>, footer: <action> }. The render
	 *    controller clears the theme's callbacks on that action and prints ours.
	 *    Works for themes that expose a dedicated render action per slot.
	 *  - Callback adapters: { wire: <callable> }, receiving the resolved slots.
	 *    For themes that render header/footer inline with no per-slot action —
	 *    Hello Elementor is one, and the hook model simply cannot drive it.
	 *
	 * @return array<string,array{header?:string,footer?:string,wire?:callable}>
	 */
	public static function map(): array {
		/**
		 * Filters the supported theme adapters.
		 *
		 * @param array $map Theme slug => adapter config.
		 */
		return apply_filters(
			'karmcp_themer_theme_adapters',
			array(
				'astra'           => array( 'header' => 'astra_header', 'footer' => 'astra_footer' ),
				'generatepress'   => array( 'header' => 'generate_header', 'footer' => 'generate_footer' ),
				'kadence'         => array( 'header' => 'kadence_header', 'footer' => 'kadence_footer' ),
				'oceanwp'         => array( 'header' => 'ocean_header', 'footer' => 'ocean_footer' ),
				'blocksy'         => array( 'header' => 'blocksy:header', 'footer' => 'blocksy:footer' ),
				'neve'            => array( 'header' => 'neve_after_header_wrapper_hook', 'footer' => 'neve_before_footer_hook' ),
				// Hello Elementor exposes NO header/footer action. Upstream mapped
				// it to `hello_elementor_header` / `hello_elementor_footer`, which
				// do not exist in the theme, so injection never fired. See
				// KarMCP_Themer_Hello_Adapter for what the theme actually does.
				'hello-elementor' => array( 'wire' => array( 'KarMCP_Themer_Hello_Adapter', 'wire' ) ),
			)
		);
	}

	/**
	 * Whether Elementor Pro's Theme Builder already renders a given location.
	 *
	 * Themes that support Elementor's theme locations (Hello among them) call
	 * `elementor_theme_do_location()` BEFORE falling back to their own markup, so
	 * when Pro owns a location the theme prints nothing there — and injecting our
	 * own part would stack a second header/footer on top of Elementor's.
	 *
	 * @param string $location 'header' | 'footer' | 'single' | 'archive'.
	 * @return bool
	 */
	public static function elementor_owns_location( string $location ): bool {
		if ( ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
			return false;
		}
		$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		if ( ! method_exists( $module, 'get_conditions_manager' ) ) {
			return false;
		}
		return ! empty( $module->get_conditions_manager()->get_documents_for_location( $location ) );
	}

	/**
	 * Adapter key for a template (parent) slug, or null when unsupported.
	 *
	 * @param string $template_slug The active theme's template (parent) slug.
	 * @return string|null
	 */
	public static function adapter_for( string $template_slug ): ?string {
		$map = self::map();
		return isset( $map[ $template_slug ] ) ? $template_slug : null;
	}

	/**
	 * Whether a template slug is supported.
	 *
	 * @param string $template_slug Template slug.
	 * @return bool
	 */
	public static function is_supported( string $template_slug ): bool {
		return null !== self::adapter_for( $template_slug );
	}

	/**
	 * The active theme's adapter key (uses the parent/template slug), or null.
	 *
	 * @return string|null
	 */
	public static function current(): ?string {
		return self::adapter_for( (string) get_template() );
	}
}
