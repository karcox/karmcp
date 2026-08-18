<?php
/**
 * Registers all KarMCP abilities with the WordPress Abilities API.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registrar that coordinates registration of all ability groups.
 *
 * @since 1.0.0
 */
class KarMCP_Ability_Registrar {

	/**
	 * The data access layer.
	 *
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * The element factory.
	 *
	 * @var KarMCP_Element_Factory
	 */
	private $factory;

	/**
	 * The schema generator.
	 *
	 * @var KarMCP_Schema_Generator
	 */
	private $schema_generator;

	/**
	 * The settings validator.
	 *
	 * @var KarMCP_Settings_Validator
	 */
	private $validator;

	/**
	 * All registered ability names.
	 *
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param KarMCP_Data               $data             The data access layer.
	 * @param KarMCP_Element_Factory    $factory          The element factory.
	 * @param KarMCP_Schema_Generator   $schema_generator The schema generator.
	 * @param KarMCP_Settings_Validator $validator        The settings validator.
	 */
	public function __construct(
		KarMCP_Data $data,
		KarMCP_Element_Factory $factory,
		KarMCP_Schema_Generator $schema_generator,
		KarMCP_Settings_Validator $validator
	) {
		$this->data             = $data;
		$this->factory          = $factory;
		$this->schema_generator = $schema_generator;
		$this->validator        = $validator;
	}

	/**
	 * Registers all abilities across all phases.
	 *
	 * Must be called during the `wp_abilities_api_init` action.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $elementor_active Whether Elementor is active. When false, only
	 *                               pure-WordPress tool groups are registered.
	 *                               Default true (preserves prior behavior for any
	 *                               caller that does not pass the flag).
	 *
	 * @return string[] Array of registered ability names.
	 */
	public function register_all( bool $elementor_active = true ): array {
		$profile = defined( 'KARMCP_PROFILE_REGISTRATION' ) && KARMCP_PROFILE_REGISTRATION;
		$started = $profile ? microtime( true ) : 0.0;
		try {
			$this->register_groups( $elementor_active );
		} catch ( \Throwable $e ) {
			// Ability registration runs on every admin page load and every REST
			// request, so an exception here is a site-wide fatal: wp-admin becomes
			// unreachable and the owner has to recover the site (issue #100, where a
			// host malware scanner had quarantined one of our class files, leaving
			// require_once satisfied but the class undeclared).
			//
			// No single tool group is worth locking an admin out of their own site.
			// Keep whatever registered before the failure and carry on; the tools
			// from the failed group are simply absent.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'KarMCP: ability registration stopped early: ' . $e->getMessage() );
			}
		}

		if ( $profile && function_exists( 'error_log' ) ) {
			error_log( sprintf( 'KarMCP: ability registration took %.1f ms (%d tools).', ( microtime( true ) - $started ) * 1000, count( $this->ability_names ) ) );
		}

		/**
		 * Filters the registered ability names.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $ability_names The registered ability names.
		 */
		$this->ability_names = apply_filters( 'karmcp_ability_names', $this->ability_names );

		// F-013: karmcp_ability_names is a public seam, so a third-party
		// callback can hand back non-strings or names that don't match the MCP
		// ability-name grammar. Drop anything invalid before the list reaches
		// create_server(), which would otherwise register broken/undefined tools.
		$this->ability_names = self::sanitize_ability_names( $this->ability_names );

		return $this->ability_names;
	}

	/**
	 * Registers every ability group. Called through register_all()'s guard.
	 *
	 * @param bool $elementor_active Whether the Elementor-dependent groups load.
	 * @return void
	 */
	private function register_groups( bool $elementor_active = true ): void {
		// ---- Always-on: pure-WordPress tool groups (no Elementor needed) ----

		// Media Library query ability (list/search the site's own uploads).
		$media_library = new KarMCP_Media_Library_Abilities( $this->data );
		$media_library->register();
		$this->ability_names = array_merge( $this->ability_names, $media_library->get_ability_names() );

		// resize-media — only when the Image Optimization module is active (it reuses
		// that module's backup + compress + WebP machinery).
		if ( class_exists( 'KarMCP_Image_Resize_Abilities' )
			&& class_exists( 'KarMCP_Image_Optimization_Module' )
			&& KarMCP_Image_Optimization_Module::module_is_active() ) {
			$resize = new KarMCP_Image_Resize_Abilities();
			$resize->register();
			$this->ability_names = array_merge( $this->ability_names, $resize->get_ability_names() );
		}

		// WordPress Content abilities (posts/pages/CPT CRUD + taxonomy + meta).
		$content = new KarMCP_Content_Abilities();
		$content->register();
		$this->ability_names = array_merge( $this->ability_names, $content->get_ability_names() );

		// Redirect Manager abilities (301/302 redirects + broken-link scan; no
		// Elementor). Gated on the Redirects module (on by default) — abilities
		// register before the module boots on init:5, so gate on is_enabled().
		if ( class_exists( 'KarMCP_Redirect_Module' ) && KarMCP_Redirect_Module::is_enabled() ) {
			$redirects = new KarMCP_Redirect_Abilities();
			$redirects->register();
			$this->ability_names = array_merge( $this->ability_names, $redirects->get_ability_names() );
		}

		// harden-site — applies the configuration fixes the hardening audit
		// reports. Always registered: it is not module-gated, and its dry-run
		// default plus confirm gate are what make it safe.
		$security_fix = new KarMCP_Security_Fix_Abilities();
		$security_fix->register();
		$this->ability_names = array_merge( $this->ability_names, $security_fix->get_ability_names() );

		// Recovery tools (fatal log, paused plugins, resume) plus update-core.
		// Always registered: they are how a site that came back tells you why it
		// went down, and update-core's own confirm gate is what makes it safe.
		$recovery = new KarMCP_Recovery_Abilities();
		$recovery->register();
		$this->ability_names = array_merge( $this->ability_names, $recovery->get_ability_names() );

		// clean-database — the acting half of the performance audit. Always
		// registered; its dry-run default and confirm gate are the safety.
		$db_clean = new KarMCP_DB_Cleanup_Abilities();
		$db_clean->register();
		$this->ability_names = array_merge( $this->ability_names, $db_clean->get_ability_names() );

		// Known-vulnerability tool. Gated on its module, which ships off because
		// it is the one part of the plugin that fetches from a third party.
		if ( class_exists( 'KarMCP_Vulnerabilities_Module' ) && KarMCP_Vulnerabilities_Module::is_enabled() ) {
			$vulns = new KarMCP_Vulnerability_Abilities();
			$vulns->register();
			$this->ability_names = array_merge( $this->ability_names, $vulns->get_ability_names() );
		}

		// Login Guard tools (see and lift sign-in lockouts). Gated on its module,
		// which ships OFF — same reason as above: abilities register before the
		// module boots, so the gate is the static is_enabled().
		if ( class_exists( 'KarMCP_Login_Guard_Module' ) && KarMCP_Login_Guard_Module::is_enabled() ) {
			$login_guard = new KarMCP_Login_Guard_Abilities();
			$login_guard->register();
			$this->ability_names = array_merge( $this->ability_names, $login_guard->get_ability_names() );
		}

		// Gutenberg block abilities (discover blocks/patterns + incremental block-tree edits).
		$gutenberg = new KarMCP_Gutenberg_Abilities();
		$gutenberg->register();
		$this->ability_names = array_merge( $this->ability_names, $gutenberg->get_ability_names() );

		// Page Snapshot — always-on normalized page digest (read foundation).
		$snapshot = new KarMCP_Snapshot_Abilities( $this->data );
		$snapshot->register();
		$this->ability_names = array_merge( $this->ability_names, $snapshot->get_ability_names() );

		// render-page — the rendered-output digest. Always on and not
		// Elementor-gated: it reads whatever the page renders to, whoever built it.
		if ( class_exists( 'KarMCP_Render_Abilities' ) ) {
			$render = new KarMCP_Render_Abilities();
			$render->register();
			$this->ability_names = array_merge( $this->ability_names, $render->get_ability_names() );
		}

		// audit-page-seo — grades the rendered digest against the stored SEO
		// metadata. Read-only, and deliberately not Elementor-gated: it audits
		// whatever the page renders to, whoever built it.
		if ( class_exists( 'KarMCP_Seo_Audit_Abilities' ) ) {
			$seo_audit = new KarMCP_Seo_Audit_Abilities();
			$seo_audit->register();
			$this->ability_names = array_merge( $this->ability_names, $seo_audit->get_ability_names() );

			// Also fills the `seo` heavy section of get-page-snapshot, which
			// reserved the seam for it and until now returned an empty stub.
			KarMCP_Seo_Audit_Abilities::register_snapshot_section();
		}

		// audit-page-a11y — the other half of the same seam.
		if ( class_exists( 'KarMCP_A11y_Audit_Abilities' ) ) {
			$a11y_audit = new KarMCP_A11y_Audit_Abilities();
			$a11y_audit->register();
			$this->ability_names = array_merge( $this->ability_names, $a11y_audit->get_ability_names() );

			KarMCP_A11y_Audit_Abilities::register_snapshot_section();
		}

		// AI-safe transactions — change ledger + rollback (always-on, write foundation).
		$transactions = new KarMCP_Transaction_Abilities();
		$transactions->register();
		$this->ability_names = array_merge( $this->ability_names, $transactions->get_ability_names() );

		// Content search — lexical index over pages/templates/widgets/globals (always-on).
		$search = new KarMCP_Search_Abilities();
		$search->register();
		$this->ability_names = array_merge( $this->ability_names, $search->get_ability_names() );

		// Content mirror — export/restore page content as git-trackable files (always-on).
		$mirror = new KarMCP_Content_Mirror_Abilities();
		$mirror->register();
		$this->ability_names = array_merge( $this->ability_names, $mirror->get_ability_names() );

		// Compact tool mode dispatcher (list-tools/get-tool-schema/call-tool).
		// Registered ALWAYS so wp_get_ability() resolves them, but deliberately
		// NOT added to $this->ability_names — register_mcp_server() surfaces them
		// only when dispatcher mode is on (otherwise they'd double the surface).
		$dispatcher = new KarMCP_Dispatcher_Abilities();
		$dispatcher->register();

		// KarMCP Themer MCP tools — only when the (free) Themer module is active.
		// The module boots on init:5, after this runs, so gate on the option directly.
		if ( class_exists( 'KarMCP_Themer_Abilities' )
			&& class_exists( 'KarMCP_Themer_Module' )
			&& KarMCP_Themer_Module::is_enabled() ) {
			$themer = new KarMCP_Themer_Abilities();
			$themer->register();
			$this->ability_names = array_merge( $this->ability_names, $themer->get_ability_names() );
		}

		// KarMCP Themer PHP-Template MCP tools — only when the feature toggle is on
		// (its own option, independent of the base Themer tools above).
		if ( class_exists( 'KarMCP_Themer_PHP_Abilities' )
			&& class_exists( 'KarMCP_Themer_PHP' )
			&& KarMCP_Themer_PHP::enabled() ) {
			$themer_php = new KarMCP_Themer_PHP_Abilities();
			$themer_php->register();
			$this->ability_names = array_merge( $this->ability_names, $themer_php->get_ability_names() );
		}

		// WordPress Settings abilities (curated site-settings read/update).
		$settings = new KarMCP_Settings_Abilities();
		$settings->register();
		$this->ability_names = array_merge( $this->ability_names, $settings->get_ability_names() );

		// WordPress Plugins & Themes abilities.
		$plugins = new KarMCP_Plugin_Abilities();
		$plugins->register();
		$this->ability_names = array_merge( $this->ability_names, $plugins->get_ability_names() );

		$themes = new KarMCP_Theme_Abilities();
		$themes->register();
		$this->ability_names = array_merge( $this->ability_names, $themes->get_ability_names() );

		// WordPress Users abilities.
		$users = new KarMCP_User_Abilities();
		$users->register();
		$this->ability_names = array_merge( $this->ability_names, $users->get_ability_names() );

		// WordPress Nav Menu abilities (menus, items, theme locations, render).
		$nav_menus = new KarMCP_Nav_Menu_Abilities();
		$nav_menus->register();
		$this->ability_names = array_merge( $this->ability_names, $nav_menus->get_ability_names() );

		// ACF abilities — only when Advanced Custom Fields (free or Pro) is active.
		if ( class_exists( 'KarMCP_ACF_Abilities' ) && KarMCP_ACF_Abilities::acf_active() ) {
			$acf = new KarMCP_ACF_Abilities();
			$acf->register();
			$this->ability_names = array_merge( $this->ability_names, $acf->get_ability_names() );
		}

		// WooCommerce abilities (Pro) — only when WooCommerce is active.
		if ( class_exists( 'KarMCP_Woo_Integration' ) && KarMCP_Woo_Integration::woo_active() ) {
			$woo = new KarMCP_Woo_Integration();
			$woo->register();
			$this->ability_names = array_merge( $this->ability_names, $woo->get_ability_names() );
		}

		// build-site — the composite that lays down pages, menu, front page and
		// palette. Not Elementor-gated: the palette step is the only Elementor
		// part and it degrades to a note.
		if ( class_exists( 'KarMCP_Site_Builder_Abilities' ) ) {
			$site_builder = new KarMCP_Site_Builder_Abilities();
			$site_builder->register();
			$this->ability_names = array_merge( $this->ability_names, $site_builder->get_ability_names() );
		}

		// Structured data (JSON-LD). Plain WordPress, so it registers everywhere.
		if ( class_exists( 'KarMCP_Structured_Data_Abilities' ) ) {
			$structured_data = new KarMCP_Structured_Data_Abilities();
			$structured_data->register();
			$this->ability_names = array_merge( $this->ability_names, $structured_data->get_ability_names() );
		}

		// duplicate-post — the copy primitive. Always on: it is plain WordPress,
		// and it is what makes plugin CPTs reachable at all.
		if ( class_exists( 'KarMCP_Duplicate_Abilities' ) ) {
			$duplicate = new KarMCP_Duplicate_Abilities();
			$duplicate->register();
			$this->ability_names = array_merge( $this->ability_names, $duplicate->get_ability_names() );
		}

		// Multilingual integrations. Each registers only when its plugin is
		// active, and a site running both would get both tool pairs — which is
		// correct, since only one of them owns any given post.
		$translation_integrations = array();
		if ( class_exists( 'KarMCP_Polylang_Integration' ) ) {
			$translation_integrations[] = new KarMCP_Polylang_Integration();
		}
		if ( class_exists( 'KarMCP_WPML_Integration' ) ) {
			$translation_integrations[] = new KarMCP_WPML_Integration();
		}
		foreach ( $translation_integrations as $translation_integration ) {
			if ( $translation_integration->is_available() ) {
				$translation_integration->register();
				$this->ability_names = array_merge( $this->ability_names, $translation_integration->get_ability_names() );
			}
		}

		// Meta Box abilities — only when Meta Box (free or extensions) is active.
		if ( class_exists( 'KarMCP_Meta_Box_Abilities' ) && KarMCP_Meta_Box_Abilities::metabox_active() ) {
			$metabox = new KarMCP_Meta_Box_Abilities();
			$metabox->register();
			$this->ability_names = array_merge( $this->ability_names, $metabox->get_ability_names() );
		}

		// Forms-tab integrations. Each registers only when its plugin is active
		// (is_available()). Only the Contact Form 7 adapter ships in this build;
		// the entry-storing adapters (WPForms, Gravity, Fluent, Ninja, Formidable,
		// MetForm, SureForms, Forminator) were upstream Pro files and are absent.
		$form_integrations = array();
		if ( class_exists( 'KarMCP_CF7_Integration' ) ) {
			$form_integrations[] = new KarMCP_CF7_Integration();
		}
		foreach ( $form_integrations as $form_integration ) {
			if ( $form_integration->is_available() ) {
				$form_integration->register();
				$this->ability_names = array_merge( $this->ability_names, $form_integration->get_ability_names() );
			}
		}

		// SEO-plugin integrations. Each registers only when its SEO plugin is
		// active. Only the Slim SEO adapter ships in this build; the Yoast,
		// Rank Math, AIOSEO, SEOPress, SEO Framework and SureRank adapters were
		// upstream Pro files and are absent.
		$seo_integrations = array();
		if ( class_exists( 'KarMCP_SlimSEO_Integration' ) ) {
			$seo_integrations[] = new KarMCP_SlimSEO_Integration();
		}
		foreach ( $seo_integrations as $seo_integration ) {
			if ( $seo_integration->is_available() ) {
				$seo_integration->register();
				$this->ability_names = array_merge( $this->ability_names, $seo_integration->get_ability_names() );
			}
		}

		// Themes-tab integrations — the framework-agnostic active-theme pack always,
		// per-framework packs only when that framework is the active theme.
		$theme_integrations = array();
		if ( class_exists( 'KarMCP_Active_Theme_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Active_Theme_Integration();
		}
		if ( class_exists( 'KarMCP_Astra_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Astra_Integration();
		}
		if ( class_exists( 'KarMCP_Spectra_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Spectra_Integration();
		}
		if ( class_exists( 'KarMCP_Kadence_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Kadence_Integration();
		}
		if ( class_exists( 'KarMCP_Kadence_Blocks_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Kadence_Blocks_Integration();
		}
		// GeneratePress + GenerateBlocks (Pro; classes only present when Pro loaded).
		if ( class_exists( 'KarMCP_GeneratePress_Integration' ) ) {
			$theme_integrations[] = new KarMCP_GeneratePress_Integration();
		}
		if ( class_exists( 'KarMCP_GenerateBlocks_Integration' ) ) {
			$theme_integrations[] = new KarMCP_GenerateBlocks_Integration();
		}
		// Blocksy (Pro): blocks + Companion extensions.
		if ( class_exists( 'KarMCP_Blocksy_Blocks_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Blocksy_Blocks_Integration();
		}
		if ( class_exists( 'KarMCP_Blocksy_Extensions_Integration' ) ) {
			$theme_integrations[] = new KarMCP_Blocksy_Extensions_Integration();
		}
		foreach ( $theme_integrations as $theme_integration ) {
			if ( $theme_integration->is_available() ) {
				$theme_integration->register();
				$this->ability_names = array_merge( $this->ability_names, $theme_integration->get_ability_names() );
			}
		}

		// Elementor addon widget packs (Pro). Each pack contributes ONE read
		// tool for discovery + curation; widgets are placed with the generic
		// add-free-widget tool, so there is deliberately no write tool here.
		$addon_packs = array();
		foreach ( array( 'KarMCP_EssentialAddons_Integration', 'KarMCP_PremiumAddons_Integration' ) as $karmcp_addon_class ) {
			if ( class_exists( $karmcp_addon_class ) ) {
				$addon_packs[] = new $karmcp_addon_class();
			}
		}
		foreach ( $addon_packs as $addon_pack ) {
			if ( $addon_pack->is_available() ) {
				$addon_pack->register();
				$this->ability_names = array_merge( $this->ability_names, $addon_pack->get_ability_names() );
			}
		}

		// Ultimate Addons for Elementor (Pro), formerly Header Footer Elementor.
		// Both a widget pack AND a data plugin, so unlike the pure packs it keeps
		// the house read/write dispatcher pair: discovery + templates on read,
		// templates on write.
		if ( class_exists( 'KarMCP_UAE_Integration' ) ) {
			$karmcp_uae = new KarMCP_UAE_Integration();
			if ( $karmcp_uae->is_available() ) {
				$karmcp_uae->register();
				$this->ability_names = array_merge( $this->ability_names, $karmcp_uae->get_ability_names() );
			}
		}

		// Performance Analyzer (read-only).
		$performance = new KarMCP_Performance_Abilities();
		$performance->register();
		$this->ability_names = array_merge( $this->ability_names, $performance->get_ability_names() );

		// Filesystem abilities (writes disabled-by-default).
		$filesystem = new KarMCP_Filesystem_Abilities();
		$filesystem->register();
		$this->ability_names = array_merge( $this->ability_names, $filesystem->get_ability_names() );

		// Database abilities (writes disabled-by-default).
		$database = new KarMCP_Database_Abilities();
		$database->register();
		$this->ability_names = array_merge( $this->ability_names, $database->get_ability_names() );

		// WP-CLI tools (run + background jobs; disabled-by-default, manage_options).
		$wpcli = new KarMCP_WPCLI_Abilities();
		$wpcli->register();
		$this->ability_names = array_merge( $this->ability_names, $wpcli->get_ability_names() );

		// Security & Malware Scanner (read-only).
		$security = new KarMCP_Security_Abilities();
		$security->register();
		$this->ability_names = array_merge( $this->ability_names, $security->get_ability_names() );

		// PHP Snippet abilities (Sandbox) — free, capability-gated, no Elementor.
		if ( class_exists( 'KarMCP_PHP_Snippet_Abilities' ) ) {
			$php_snippets = new KarMCP_PHP_Snippet_Abilities();
			$php_snippets->register();
			$this->ability_names = array_merge( $this->ability_names, $php_snippets->get_ability_names() );
		}

		// Block Builder (Pro; self-guards on license). Gutenberg, not Elementor-gated.
		if ( class_exists( 'KarMCP_Block_Builder_Abilities' ) ) {
			$block_builder = new KarMCP_Block_Builder_Abilities();
			$block_builder->register();
			$this->ability_names = array_merge( $this->ability_names, $block_builder->get_ability_names() );
		}
		// Sandbox cloud export/import (free; operates over the bundle contract).
		if ( class_exists( 'KarMCP_Sandbox_Cloud_Abilities' ) ) {
			$cloud = new KarMCP_Sandbox_Cloud_Abilities();
			$cloud->register();
			$this->ability_names = array_merge( $this->ability_names, $cloud->get_ability_names() );
		}

		// Stock-image provider tools (search-images + sideload-image) — pure WP core
		// (a stock-provider search + a Media Library sideload), no Elementor needed,
		// so they register on any site. add-stock-image (adds a widget) is gated below.
		$stock_images = new KarMCP_Stock_Image_Abilities( $this->data, $this->factory );
		$stock_images->register_provider_tools();
		$this->ability_names = array_merge( $this->ability_names, $stock_images->provider_tool_names() );

		// ---- Elementor-dependent groups: only when Elementor is active ----
		if ( $elementor_active ) {
			// P0 query/discovery.
			$query = new KarMCP_Query_Abilities( $this->data, $this->schema_generator );
			$query->register();
			$this->ability_names = array_merge( $this->ability_names, $query->get_ability_names() );

			// P1 page CRUD.
			$pages = new KarMCP_Page_Abilities( $this->data, $this->factory );
			$pages->register();
			$this->ability_names = array_merge( $this->ability_names, $pages->get_ability_names() );

			// P1 layout/container.
			$layout = new KarMCP_Layout_Abilities( $this->data, $this->factory, $this->validator );
			$layout->register();
			$this->ability_names = array_merge( $this->ability_names, $layout->get_ability_names() );

			// Widgets (catalog-backed).
			$widgets = new KarMCP_Widget_Abilities( $this->data, $this->factory, $this->schema_generator, $this->validator );
			$widgets->register();
			$this->ability_names = array_merge( $this->ability_names, $widgets->get_ability_names() );

			// Templates.
			$templates = new KarMCP_Template_Abilities( $this->data, $this->factory );
			$templates->register();
			$this->ability_names = array_merge( $this->ability_names, $templates->get_ability_names() );

			// Global settings.
			$globals = new KarMCP_Global_Abilities( $this->data );
			$globals->register();
			$this->ability_names = array_merge( $this->ability_names, $globals->get_ability_names() );

			// Composite build-page.
			$composite = new KarMCP_Composite_Abilities( $this->data, $this->factory );
			$composite->register();
			$this->ability_names = array_merge( $this->ability_names, $composite->get_ability_names() );

			// Stock images: the add-stock-image widget tool (provider search + sideload
			// registered unconditionally above); this one adds an image widget so it
			// needs Elementor.
			$stock_images->register_widget_tool();
			$this->ability_names[] = 'karmcp/add-stock-image';

			// add-contact-form. Self-gates on the Form widget actually being
			// registered, which is the honest test for "Elementor Pro is here"
			// — a Pro install with the widget disabled would fail otherwise.
			if ( class_exists( 'KarMCP_Form_Widget_Abilities' ) && KarMCP_Form_Widget_Abilities::form_widget_available() ) {
				$form_widget = new KarMCP_Form_Widget_Abilities( $this->data, $this->factory );
				$form_widget->register();
				$this->ability_names = array_merge( $this->ability_names, $form_widget->get_ability_names() );
			}

			// SVG icons.
			$svg_icons = new KarMCP_Svg_Icon_Abilities( $this->data, $this->factory );
			$svg_icons->register();
			$this->ability_names = array_merge( $this->ability_names, $svg_icons->get_ability_names() );

			// Custom code (CSS, JS, snippets).
			$custom_code = new KarMCP_Custom_Code_Abilities( $this->data, $this->factory );
			$custom_code->register();
			$this->ability_names = array_merge( $this->ability_names, $custom_code->get_ability_names() );

			// Atomic widgets (Elementor 4.0+; self-guards on version).
			$atomic_widgets = new KarMCP_Atomic_Widget_Abilities( $this->data, $this->factory );
			$atomic_widgets->register();
			$this->ability_names = array_merge( $this->ability_names, $atomic_widgets->get_ability_names() );

			// Atomic layout (Elementor 4.0+; includes detect-elementor-version).
			$atomic_layout = new KarMCP_Atomic_Layout_Abilities( $this->data, $this->factory );
			$atomic_layout->register();
			$this->ability_names = array_merge( $this->ability_names, $atomic_layout->get_ability_names() );

			// Global Classes reader — self-gates on Elementor 4.0+.
			if ( class_exists( 'KarMCP_Global_Classes_Abilities' ) ) {
				$global_classes = new KarMCP_Global_Classes_Abilities();
				$global_classes->register();
				$this->ability_names = array_merge( $this->ability_names, $global_classes->get_ability_names() );
			}

			// Global Classes writer (create/update/delete) — self-gates on 4.0+.
			if ( class_exists( 'KarMCP_Global_Classes_Write_Abilities' ) ) {
				$global_classes_write = new KarMCP_Global_Classes_Write_Abilities();
				$global_classes_write->register();
				$this->ability_names = array_merge( $this->ability_names, $global_classes_write->get_ability_names() );
			}

			// Brand kit / system-kit (Pro; self-guards on license).
			if ( class_exists( 'KarMCP_System_Kit_Abilities' ) ) {
				$brand_kits = new KarMCP_System_Kit_Abilities();
				$brand_kits->register();
				$this->ability_names = array_merge( $this->ability_names, $brand_kits->get_ability_names() );
			}

			// SEO toolkit (Pro; self-guards on license).
			if ( class_exists( 'KarMCP_Seo_Abilities' ) ) {
				$seo = new KarMCP_Seo_Abilities( $this->data );
				$seo->register();
				$this->ability_names = array_merge( $this->ability_names, $seo->get_ability_names() );
			}

			// Accessibility toolkit (Pro; self-guards on license).
			if ( class_exists( 'KarMCP_A11y_Abilities' ) ) {
				$a11y = new KarMCP_A11y_Abilities( $this->data );
				$a11y->register();
				$this->ability_names = array_merge( $this->ability_names, $a11y->get_ability_names() );
			}

			// Widget Builder — compiles custom Elementor widgets from a spec.
			if ( class_exists( 'KarMCP_Widget_Builder_Abilities' ) ) {
				$widget_builder = new KarMCP_Widget_Builder_Abilities();
				$widget_builder->register();
				$this->ability_names = array_merge( $this->ability_names, $widget_builder->get_ability_names() );
			}

			// Element extensions — options added to elements Elementor already
			// ships. Atomic-only, so it needs Elementor like the rest of this
			// block; the version check lives in the tools themselves.
			if ( class_exists( 'KarMCP_Extension_Builder_Abilities' ) ) {
				$extensions = new KarMCP_Extension_Builder_Abilities();
				$extensions->register();
				$this->ability_names = array_merge( $this->ability_names, $extensions->get_ability_names() );
			}
		}

		// Skills read-side. Not Elementor-dependent, so it registers regardless of
		// whether Elementor is active — but gated by the Agent Skills module so
		// the admin can switch the runtime exposure off in one place.
		if ( class_exists( 'KarMCP_Skill_Abilities' )
			&& class_exists( 'KarMCP_Agent_Skills_Module' )
			&& KarMCP_Agent_Skills_Module::is_enabled() ) {
			$skills = new KarMCP_Skill_Abilities();
			$skills->register();
			$this->ability_names = array_merge( $this->ability_names, $skills->get_ability_names() );
		}

		// Project Memory (Pro; self-guards on license). Not Elementor-dependent;
		// gated by the Memory module so the admin can switch the runtime exposure
		// off. is_enabled() runs before the module's init boot.
		if ( class_exists( 'KarMCP_Memory_Abilities' )
			&& class_exists( 'KarMCP_Memory_Module' )
			&& KarMCP_Memory_Module::is_enabled() ) {
			$memory = new KarMCP_Memory_Abilities();
			$memory->register();
			$this->ability_names = array_merge( $this->ability_names, $memory->get_ability_names() );
		}

		// Backup / Migrate / Sync MCP tools (Pro; self-guards on license). Not
		// Elementor-dependent; gated by the Migrate module. is_enabled() runs
		// before the module's init:5 boot (abilities register on
		// wp_abilities_api_init). The two destructive tools ship disabled-by-default.
		if ( class_exists( 'KarMCP_Migrate_Abilities' )
			&& class_exists( 'KarMCP_Migrate_Module' )
			&& KarMCP_Migrate_Module::is_enabled() ) {
			$migrate = new KarMCP_Migrate_Abilities();
			$migrate->register();
			$this->ability_names = array_merge( $this->ability_names, $migrate->get_ability_names() );
		}
	}

	/**
	 * Keeps only well-formed MCP ability names — `[a-z0-9-]+/[a-z0-9-]+` — from
	 * whatever the karmcp_ability_names filter returned. Non-array input
	 * yields an empty list.
	 *
	 * @since 3.3.1
	 *
	 * @param mixed $names Filter return value.
	 * @return string[] Validated ability names.
	 */
	private static function sanitize_ability_names( $names ): array {
		if ( ! is_array( $names ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$names,
				static function ( $name ) {
					return is_string( $name ) && (bool) preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
				}
			)
		);
	}

	/**
	 * Gets the list of registered ability names.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Array of ability names.
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}
}
