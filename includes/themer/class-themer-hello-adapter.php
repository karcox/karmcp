<?php
/**
 * Hello Elementor header/footer adapter.
 *
 * Hello Elementor does not expose a per-slot action hook, so the generic
 * "remove_all_actions() then add_action()" adapter model cannot drive it. The
 * upstream map pointed at `hello_elementor_header` / `hello_elementor_footer`,
 * neither of which exists in the theme — the injection silently never happened.
 *
 * What the theme actually does (verified against Hello Elementor 3.4.6):
 *
 *   header.php:  wp_body_open();  [skip link]
 *                if ( ! elementor_theme_do_location( 'header' ) ) {
 *                    if ( hello_elementor_display_header_footer() ) {
 *                        get_template_part( 'template-parts/dynamic-header' | 'header' );
 *                    }
 *                }
 *   footer.php:  same shape for 'footer', then wp_footer();
 *
 * So the integration is:
 *
 *   1. Elementor Pro wins first. If its Theme Builder owns a location, we do NOT
 *      touch that slot — same deference the render controller applies to the body.
 *      Injecting anyway would stack a second header on top of Elementor's.
 *   2. Suppress the theme's own parts with the `hello_elementor_header_footer`
 *      filter. That filter is a SINGLE boolean covering header AND footer, so when
 *      we only fill one slot we re-emit the theme's part for the other one,
 *      mirroring the theme's own branch.
 *   3. Print ours: header on `wp_body_open`, footer on `get_footer` (which fires
 *      before footer.php is loaded, i.e. exactly where the theme footer goes).
 *
 * Known nuance: `wp_body_open` fires just BEFORE the theme's skip link, so our
 * header lands ahead of it. The theme offers no hook at the header block itself,
 * and every header/footer plugin for Hello integrates the same way.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Callback adapter for the Hello Elementor theme.
 */
final class KarMCP_Themer_Hello_Adapter {

	/**
	 * Decide what to do with each slot (pure).
	 *
	 * Separated from the WordPress wiring so the decision table is unit-testable.
	 *
	 * @param array $slots       Resolved slots { header, footer, body }.
	 * @param bool  $el_header   Elementor Pro owns the header location.
	 * @param bool  $el_footer   Elementor Pro owns the footer location.
	 * @return array{header:bool,footer:bool,suppress:bool,restore_header:bool,restore_footer:bool}
	 */
	public static function plan( array $slots, bool $el_header, bool $el_footer ): array {
		// Fill a slot only when we have a template AND Elementor Pro is not
		// already rendering that location.
		$header = ! empty( $slots['header'] ) && ! $el_header;
		$footer = ! empty( $slots['footer'] ) && ! $el_footer;

		// Only interfere with the theme when we are actually replacing something.
		$suppress = $header || $footer;

		// The suppression filter is all-or-nothing, so any slot we are NOT filling
		// has to be re-emitted by us — unless Elementor Pro owns it, in which case
		// the theme would not have rendered it anyway.
		return array(
			'header'         => $header,
			'footer'         => $footer,
			'suppress'       => $suppress,
			'restore_header' => $suppress && ! $header && ! $el_header,
			'restore_footer' => $suppress && ! $footer && ! $el_footer,
		);
	}

	/**
	 * Wire the theme. Called by KarMCP_Themer_Render_Controller.
	 *
	 * @param array $slots Resolved slots.
	 */
	public static function wire( array $slots ): void {
		$plan = self::plan(
			$slots,
			KarMCP_Themer_Theme_Adapters::elementor_owns_location( 'header' ),
			KarMCP_Themer_Theme_Adapters::elementor_owns_location( 'footer' )
		);

		if ( ! $plan['suppress'] ) {
			return;
		}

		// Stand the theme's own header/footer down.
		add_filter( 'hello_elementor_header_footer', '__return_false' );

		add_action(
			'wp_body_open',
			static function () use ( $slots, $plan ) {
				if ( $plan['header'] ) {
					echo KarMCP_Themer_Content_Renderer::render( (int) $slots['header'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template markup.
				} elseif ( $plan['restore_header'] ) {
					self::theme_part( 'header' );
				}
			}
		);

		add_action(
			'get_footer',
			static function () use ( $slots, $plan ) {
				if ( $plan['footer'] ) {
					echo KarMCP_Themer_Content_Renderer::render( (int) $slots['footer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template markup.
				} elseif ( $plan['restore_footer'] ) {
					self::theme_part( 'footer' );
				}
			}
		);
	}

	/**
	 * Re-emit one of the theme's own parts, mirroring Hello's own branch.
	 *
	 * Every theme helper is function_exists-guarded: `hello_header_footer_experiment_active()`
	 * is not present in every Hello release, and a missing helper must degrade to the
	 * classic part rather than fatal.
	 *
	 * @param string $slot 'header' | 'footer'.
	 */
	private static function theme_part( string $slot ): void {
		$dynamic = did_action( 'elementor/loaded' )
			&& function_exists( 'hello_header_footer_experiment_active' )
			&& hello_header_footer_experiment_active();

		get_template_part( 'template-parts/' . ( $dynamic ? 'dynamic-' : '' ) . $slot );
	}
}
