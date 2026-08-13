<?php
/**
 * KarMCP Themer as a free module.
 *
 * On by default. register() owns ALL front-end wiring: the CPT, the condition-index
 * rebuild hooks, the render controller, and the metabox. The MCP ability group is
 * gated separately in the ability registrar on the module's active state (abilities
 * register on wp_abilities_api_init, before this init:5 boot). Disabling the module
 * stops the CPT, the front-end takeover, and the tab; the registrar then omits the
 * tools too — a true kill switch.
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
class KarMCP_Themer_Module extends KarMCP_Module {

	public function id(): string {
		return 'themer';
	}

	public function title(): string {
		return __( 'Themer', 'karmcp' );
	}

	public function description(): string {
		return __( 'Build your site\'s header, footer, single, archive, search & 404 layouts with any page builder, and control where each applies.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	public function default_active(): bool {
		return true;
	}

	/** The native CPT screen (its own dashboard menu) is the config surface. */
	public function settings_url(): string {
		return admin_url( 'edit.php?post_type=' . KarMCP_Themer_CPT::POST_TYPE );
	}

	public function render_settings(): void {}

	/**
	 * Whether the module is active (static helper for the ability registrar, which
	 * runs before init:5). Reads the active-modules option directly.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$active = (array) get_option( KarMCP_Module::OPTION_ACTIVE, array() );
		return in_array( 'themer', $active, true );
	}

	/** Option marker: the condition index was healed after the save-order fix. */
	const OPTION_INDEX_HEALED = 'karmcp_themer_index_healed';

	/** Wire everything. Booted by the registry on init:5 only when active. */
	public function register(): void {
		// Granular condition layer (unlimited templates, per-object targeting,
		// Exclude rules, priority). Wired FIRST so the quota, matcher, selector
		// and schema filters are in place before the CPT or metabox read them.
		if ( class_exists( 'KarMCP_Themer_Extended' ) ) {
			KarMCP_Themer_Extended::init();
		}

		$cpt = new KarMCP_Themer_CPT();
		$cpt->register();

		// Header Footer Elementor builds the same header/footer slots. Warn the
		// admin and, until they pick one system, let Themer win deterministically.
		if ( class_exists( 'KarMCP_Themer_HFE_Conflict' ) ) {
			KarMCP_Themer_HFE_Conflict::init();
		}

		KarMCP_Themer_Index::register_hooks();

		// One-time heal: a prior build could leave the condition index empty (the
		// rebuild raced the metabox meta writes), so existing templates silently
		// stopped applying. Rebuild once on upgrade so they resolve again without
		// the admin re-saving each template.
		if ( '1' !== (string) get_option( self::OPTION_INDEX_HEALED, '' ) ) {
			KarMCP_Themer_Index::rebuild();
			update_option( self::OPTION_INDEX_HEALED, '1', true );
		}

		if ( ! is_admin() ) {
			( new KarMCP_Themer_Render_Controller() )->init();
		}

		if ( is_admin() && class_exists( 'KarMCP_Themer_Metabox' ) ) {
			( new KarMCP_Themer_Metabox() )->init();
		}

		// Dynamic content blocks (Gutenberg) — register on both front end (render)
		// and admin (editor). Elementor dynamic widgets self-gate on Elementor.
		if ( class_exists( 'KarMCP_Themer_Blocks' ) ) {
			( new KarMCP_Themer_Blocks() )->init();
		}
		if ( class_exists( 'KarMCP_Themer_Widgets' ) ) {
			( new KarMCP_Themer_Widgets() )->init();
		}
		if ( class_exists( 'KarMCP_Themer_PHP' ) ) {
			( new KarMCP_Themer_PHP() )->init();
		}
	}
}
