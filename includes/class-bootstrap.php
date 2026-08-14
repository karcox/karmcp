<?php
/**
 * Plugin bootstrap: dependency check, class loading, and hook wiring.
 *
 * Hooked to `plugins_loaded` (priority 20) by the main plugin file. Everything
 * here is orchestration — loading class files and wiring them together — not
 * feature logic, which lives in the loaded classes.
 *
 * @package KarMCP
 * @since   2.1.0 (extracted from karmcp_init)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and wires the plugin.
 *
 * @since 2.1.0
 */
class KarMCP_Bootstrap {

	/**
	 * Boots the plugin: runs the fallback migration, makes the MCP Adapter
	 * available, checks dependencies, then loads classes and wires hooks.
	 *
	 * @since 2.1.0 (since 1.0.0 as karmcp_init)
	 */
	public static function boot(): void {
		// Fallback legacy-data migration (the primary snapshot happens in the
		// legacy guard while the old plugin is still present). Idempotent.
		KarMCP_Migration::migrate();

		// Make the MCP Adapter available (active standalone plugin, else our
		// bundled copy) BEFORE the dependency check, so the adapter is never a
		// "go install this" blocker. The Abilities API is core in WP 6.9+/7.0.
		require_once KARMCP_DIR . 'includes/class-mcp-adapter-bootstrap.php';
		KarMCP_Adapter_Bootstrap::ensure();

		if ( ! self::check_dependencies() ) {
			return;
		}

		self::load_classes();

		// Relocate the sandbox from the legacy uploads/karmcp-widgets location to
		// wp-content/karmcp-sandbox once. Runs here (plugins_loaded) so it completes
		// before the artifact loaders fire on `init`. Idempotent, option-gated.
		KarMCP_Sandbox_Paths::maybe_migrate();

		self::wire_hooks();

		if ( is_admin() ) {
			self::load_admin();
		}

		// Boot the plugin singleton.
		KarMCP_Plugin::instance();
	}

	/**
	 * Whether Elementor is loaded/active in this request.
	 *
	 * Single source of truth for the optional-Elementor gate: the tool registrar,
	 * the admin Tools page, and the Brand Kits / Templates tabs all read this.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public static function elementor_active(): bool {
		return (bool) did_action( 'elementor/loaded' );
	}

	/**
	 * Loads all class files (core, data, abilities, features). Self-guarded
	 * feature groups (Pro / atomic) are loaded unconditionally; they no-op on
	 * registration when their gate isn't met.
	 *
	 * @since 2.1.0
	 */
	private static function load_classes(): void {
		// Schema compatibility + the karmcp_register_ability() entry point
		// must load before any ability group registers.
		require_once KARMCP_DIR . 'includes/class-schema-compat.php';
		require_once KARMCP_DIR . 'includes/class-id-generator.php';
		require_once KARMCP_DIR . 'includes/class-url-guard.php';
		require_once KARMCP_DIR . 'includes/class-site-context.php';
		require_once KARMCP_DIR . 'includes/class-elementor-data.php';
		require_once KARMCP_DIR . 'includes/class-element-factory.php';
		require_once KARMCP_DIR . 'includes/schemas/class-control-mapper.php';
		require_once KARMCP_DIR . 'includes/schemas/class-schema-generator.php';
		require_once KARMCP_DIR . 'includes/validators/class-element-validator.php';
		require_once KARMCP_DIR . 'includes/validators/class-settings-validator.php';
		// Widget catalog — source of truth for the 5 catalog-backed widget tools.
		require_once KARMCP_DIR . 'includes/widgets/class-widget-catalog.php';
		require_once KARMCP_DIR . 'includes/abilities/class-query-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-page-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-layout-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-widget-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-template-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-global-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-composite-abilities.php';
		require_once KARMCP_DIR . 'includes/class-secret.php';
		require_once KARMCP_DIR . 'includes/class-unsplash-client.php';
		require_once KARMCP_DIR . 'includes/class-pexels-client.php';
		require_once KARMCP_DIR . 'includes/class-pixabay-client.php';
		require_once KARMCP_DIR . 'includes/class-stock-image-providers.php';
		require_once KARMCP_DIR . 'includes/abilities/class-stock-image-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-media-library-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-image-resize-abilities.php';
		require_once KARMCP_DIR . 'includes/class-block-tree.php';
		require_once KARMCP_DIR . 'includes/abilities/class-gutenberg-abilities.php';
		require_once KARMCP_DIR . 'includes/class-page-snapshot.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-util.php';
		require_once KARMCP_DIR . 'includes/cloud/class-cloud.php';
		require_once KARMCP_DIR . 'includes/cloud/class-cloud-http.php';
		require_once KARMCP_DIR . 'includes/cloud/class-cloud-connect.php';
		require_once KARMCP_DIR . 'includes/cloud/class-cloud-client.php';
		require_once KARMCP_DIR . 'includes/cloud/class-gateway-credential.php';
		require_once KARMCP_DIR . 'includes/cloud/class-cloud-sync.php';
		require_once KARMCP_DIR . 'includes/cloud/class-settings-sync.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-store.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-metadata.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-clients.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-authorize.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-token.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-bearer.php';
		require_once KARMCP_DIR . 'includes/oauth/class-oauth-server.php';
		require_once KARMCP_DIR . 'includes/abilities/class-snapshot-abilities.php';
		require_once KARMCP_DIR . 'includes/class-change-log.php';
		require_once KARMCP_DIR . 'includes/class-change-blobs.php';
		require_once KARMCP_DIR . 'includes/class-change-recorder.php';
		require_once KARMCP_DIR . 'includes/abilities/class-transaction-abilities.php';
		require_once KARMCP_DIR . 'includes/class-search-ranker.php';
		require_once KARMCP_DIR . 'includes/class-search-index.php';
		require_once KARMCP_DIR . 'includes/abilities/class-search-abilities.php';
		require_once KARMCP_DIR . 'includes/redirects/class-redirect-store.php';
		require_once KARMCP_DIR . 'includes/redirects/class-redirect-handler.php';
		require_once KARMCP_DIR . 'includes/abilities/class-redirect-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-skill-abilities.php';
		require_once KARMCP_DIR . 'includes/class-content-mirror.php';
		require_once KARMCP_DIR . 'includes/abilities/class-content-mirror-abilities.php';
		require_once KARMCP_DIR . 'includes/class-admin-bar.php';
		require_once KARMCP_DIR . 'includes/class-notifications.php';
		require_once KARMCP_DIR . 'includes/abilities/class-content-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-dispatcher-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-settings-abilities.php';
		require_once KARMCP_DIR . 'includes/class-package-guard.php';
		require_once KARMCP_DIR . 'includes/abilities/class-plugin-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-theme-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-user-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-nav-menu-abilities.php';
		require_once KARMCP_DIR . 'includes/class-nav-menu-shortcode.php';
		add_action( 'init', array( 'KarMCP_Nav_Menu_Shortcode', 'register' ) );
		// ACF tools (field values + field group discovery/authoring; writes off by default).
		require_once KARMCP_DIR . 'includes/abilities/class-acf-abilities.php';
		// Meta Box tools (field values + field group discovery; writes off by default).
		require_once KARMCP_DIR . 'includes/abilities/class-metabox-abilities.php';

		// Themes domain: the child-theme builder + the dispatcher base (must load
		// before its subclasses) + the integrations.
		require_once KARMCP_DIR . 'includes/class-child-theme-builder.php';
		require_once KARMCP_DIR . 'includes/abilities/class-theme-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-active-theme-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-astra-integration.php';
		require_once KARMCP_DIR . 'includes/blocks-catalog/class-spectra-catalog.php';
		require_once KARMCP_DIR . 'includes/abilities/class-spectra-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-kadence-integration.php';
		require_once KARMCP_DIR . 'includes/blocks-catalog/class-kadence-blocks-catalog.php';
		require_once KARMCP_DIR . 'includes/blocks-catalog/class-kadence-pattern-library.php';
		require_once KARMCP_DIR . 'includes/abilities/class-kadence-blocks-integration.php';
		// Forms-tab integrations — abstract base + Contact Form 7. The adapters for
		// the entry-storing plugins were upstream Pro files and are not in this build.
		require_once KARMCP_DIR . 'includes/abilities/forms/class-form-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/forms/class-cf7-integration.php';
		// SEO plugin integrations — abstract base + Slim SEO. The Yoast, Rank Math,
		// AIOSEO, SEOPress, SEO Framework and SureRank adapters were upstream Pro
		// files and are not in this build.
		require_once KARMCP_DIR . 'includes/abilities/seo/class-seo-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/seo/class-slimseo-integration.php';
		// Performance Analyzer (v3.0.0) — read-only server/WP/page audit.
		require_once KARMCP_DIR . 'includes/performance/class-performance-finding.php';
		require_once KARMCP_DIR . 'includes/performance/class-performance-server-audit.php';
		require_once KARMCP_DIR . 'includes/performance/class-performance-page-audit.php';
		require_once KARMCP_DIR . 'includes/performance/class-performance-analyzer.php';
		require_once KARMCP_DIR . 'includes/abilities/class-performance-abilities.php';
		// Filesystem tools (read/scan + write/edit/delete; writes off by default).
		require_once KARMCP_DIR . 'includes/class-filesystem-guard.php';
		require_once KARMCP_DIR . 'includes/abilities/class-filesystem-abilities.php';
		// Database tools (read-only query + structured writes; writes off by default).
		require_once KARMCP_DIR . 'includes/class-database-guard.php';
		require_once KARMCP_DIR . 'includes/abilities/class-database-abilities.php';
		// WP-CLI tools (run + background jobs; disabled-by-default, manage_options).
		require_once KARMCP_DIR . 'includes/wpcli/class-wpcli-validator.php';
		require_once KARMCP_DIR . 'includes/wpcli/class-wpcli-runner.php';
		require_once KARMCP_DIR . 'includes/wpcli/class-wpcli-jobs.php';
		require_once KARMCP_DIR . 'includes/abilities/class-wpcli-abilities.php';
		// Security & Malware Scanner (v3.0.0) — read-only multi-audit scan.
		require_once KARMCP_DIR . 'includes/security/class-security-finding.php';
		require_once KARMCP_DIR . 'includes/security/class-security-malware-audit.php';
		require_once KARMCP_DIR . 'includes/security/class-security-integrity-audit.php';
		require_once KARMCP_DIR . 'includes/security/class-security-hardening-audit.php';
		require_once KARMCP_DIR . 'includes/security/class-security-software-audit.php';
		require_once KARMCP_DIR . 'includes/security/class-security-scanner.php';
		require_once KARMCP_DIR . 'includes/abilities/class-security-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-svg-icon-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-custom-code-abilities.php';
		// Brand Kits. The writer + backup store + bundled-kit fetcher load
		// unconditionally so the MCP REST/CLI/proxy surface can reach them. The
		// extended brand-kit admin + system-kit abilities were upstream Pro files
		// and are not in this build.
		require_once KARMCP_DIR . 'includes/class-system-kit-writer.php';
		require_once KARMCP_DIR . 'includes/class-kit-backup-store.php';
		require_once KARMCP_DIR . 'includes/class-free-brand-kits.php';
		// Widget Builder infra (free base). The store + loader load unconditionally
		// so the MCP surface + CPT registration can reach them; the generator +
		// builder abilities ship in the Pro overlay (loaded via Pro_Loader).
		// Central sandbox storage location (wp-content/karmcp-sandbox). Every store
		// resolves paths through this, so it must load before them.
		require_once KARMCP_DIR . 'includes/sandbox/class-sandbox-paths.php';
		require_once KARMCP_DIR . 'includes/class-widget-store.php';
		require_once KARMCP_DIR . 'includes/class-widget-loader.php';
		// Sandbox Bundle — portable cloud-ready format for blocks/widgets/snippets.
		require_once KARMCP_DIR . 'includes/sandbox/class-sandbox-bundle.php';
		require_once KARMCP_DIR . 'includes/sandbox/interface-sandbox-artifact.php';
		require_once KARMCP_DIR . 'includes/sandbox/class-sandbox-store.php';
		// Bundle adapters — present the Widget Builder + PHP Snippet stores as the
		// same cloud-ready artifact surface (KarMCP_Sandbox_Artifact) as the
		// block store, without touching either store's internals.
		require_once KARMCP_DIR . 'includes/sandbox/class-widget-bundle-adapter.php';
		require_once KARMCP_DIR . 'includes/sandbox/class-snippet-bundle-adapter.php';
		// Sandbox Cloud abilities — export/import any sandbox artifact (block/
		// widget/snippet) as a portable bundle over the cloud contract. Free tree;
		// registration is wired by the ability registrar (a later task).
		require_once KARMCP_DIR . 'includes/abilities/class-sandbox-cloud-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-cloud-abilities.php';
		// PHP Code Snippets (Sandbox) — free, capability-gated. AI can author +
		// validate drafts via MCP; only an admin can activate. The loader runs
		// ACTIVE snippets (hash-verified, fatal-isolated).
		require_once KARMCP_DIR . 'includes/class-php-snippet-validator.php';
		require_once KARMCP_DIR . 'includes/class-php-snippet-store.php';
		require_once KARMCP_DIR . 'includes/class-php-snippet-loader.php';
		require_once KARMCP_DIR . 'includes/abilities/class-php-snippet-abilities.php';
		// Atomic elements support (Elementor 4.0+).
		require_once KARMCP_DIR . 'includes/class-atomic-props.php';
		require_once KARMCP_DIR . 'includes/class-atomic-styles.php';
		require_once KARMCP_DIR . 'includes/class-atomic-widget-map.php';
		require_once KARMCP_DIR . 'includes/abilities/class-atomic-widget-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-atomic-layout-abilities.php';
		// Global Classes (Class Manager) reader — self-gates on Elementor 4.0+.
		require_once KARMCP_DIR . 'includes/abilities/class-global-classes-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-global-classes-write-abilities.php';
		// Background library refresh.
		// Modules framework (free) + built-in modules. The registry boots active
		// modules on `init`; each module self-gates on its options + availability.
		require_once KARMCP_DIR . 'includes/modules/class-module.php';
		require_once KARMCP_DIR . 'includes/modules/class-modules-registry.php';
		require_once KARMCP_DIR . 'includes/modules/image-optimization/class-webp-generator.php';
		require_once KARMCP_DIR . 'includes/modules/image-optimization/class-image-optimizer.php';
		require_once KARMCP_DIR . 'includes/modules/image-optimization/class-webp-rewriter.php';
		require_once KARMCP_DIR . 'includes/modules/image-optimization/class-bulk-optimizer.php';
		require_once KARMCP_DIR . 'includes/modules/image-optimization/class-image-resizer.php';
		require_once KARMCP_DIR . 'includes/modules/image-optimization/class-image-optimization-module.php';
		require_once KARMCP_DIR . 'includes/modules/class-prompts-module.php';
		require_once KARMCP_DIR . 'includes/modules/class-brand-kits-module.php';
		require_once KARMCP_DIR . 'includes/modules/class-templates-module.php';
		require_once KARMCP_DIR . 'includes/modules/class-agent-skills-module.php';
		require_once KARMCP_DIR . 'includes/modules/svg-support/class-svg-sanitizer.php';
		require_once KARMCP_DIR . 'includes/modules/svg-support/class-svg-support-module.php';
		require_once KARMCP_DIR . 'includes/modules/guardrails/class-guardrails-policy.php';
		require_once KARMCP_DIR . 'includes/modules/guardrails/class-guardrails-module.php';
		// Agent Skills: the store + catalog load unconditionally (the post type
		// must exist so an admin can write skills), the module gates exposure.
		require_once KARMCP_DIR . 'includes/skills/class-skill-store.php';
		require_once KARMCP_DIR . 'includes/skills/class-skill-catalog.php';
		require_once KARMCP_DIR . 'includes/modules/class-cloud-module.php';
		// KarMCP Themer (free): builder-agnostic theme builder engine + module + MCP tools.
		require_once KARMCP_DIR . 'includes/themer/class-themer-matcher-registry.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-conditions.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-context.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-index.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-resolver.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-condition-schema.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-extended.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-cpt.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-content-renderer.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-theme-adapters.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-hello-adapter.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-render-controller.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-hfe-conflict.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-dynamic.php';
		require_once KARMCP_DIR . 'includes/themer/blocks/class-themer-blocks.php';
		require_once KARMCP_DIR . 'includes/themer/widgets/class-themer-widgets.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-metabox.php';
		require_once KARMCP_DIR . 'includes/themer/php/class-themer-php-store.php';
		require_once KARMCP_DIR . 'includes/themer/php/class-themer-php.php';
		require_once KARMCP_DIR . 'includes/themer/php/class-themer-php-renderer.php';
		require_once KARMCP_DIR . 'includes/abilities/class-themer-php-abilities.php';
		require_once KARMCP_DIR . 'includes/themer/php/class-themer-php-admin.php';
		require_once KARMCP_DIR . 'includes/abilities/class-themer-abilities.php';
		require_once KARMCP_DIR . 'includes/modules/class-themer-module.php';
		require_once KARMCP_DIR . 'includes/modules/class-redirect-module.php';
		require_once KARMCP_DIR . 'includes/abilities/class-ability-registrar.php';
		require_once KARMCP_DIR . 'includes/class-plugin.php';
	}

	/**
	 * Registers the non-admin runtime hooks shared by all contexts (post types,
	 * sandbox loaders, background library refresh).
	 *
	 * @since 2.1.0
	 */
	private static function wire_hooks(): void {
		// structuredContent must be a JSON object; the adapter assigns a tool's
		// return value to it verbatim, so a list result makes strict clients
		// reject the response. Our own abilities are normalized at registration,
		// but this catches everything else the server surfaces, including the
		// three core/* abilities we do not register ourselves. Normalization is
		// idempotent, so running on both paths is harmless.
		add_filter(
			'mcp_adapter_tool_call_result',
			array( 'KarMCP_Schema_Compat', 'normalize_result' ),
			99
		);

		// Invalidate Elementor's rendered-element cache on any _elementor_data
		// write, so MCP-created/edited pages never serve a stale empty render (#111).
		KarMCP_Data::init();
		// Content search index: install-on-init + incremental re-index on save/delete.
		KarMCP_Search_Index::init();
		KarMCP_Change_Blobs::init();
		// The Redirect Manager (store table install + front-end 301/302 handler) is
		// booted by KarMCP_Redirect_Module::register() only when the module is
		// active — a true kill switch from the Modules tab.
		// OAuth sign-in: install storage on init (routes wired in later phases).
		KarMCP_OAuth_Server::init();
		// Content mirror: auto-export-on-save (gated by its option) + delete cleanup.
		KarMCP_Content_Mirror::init();
		add_action( 'init', array( 'KarMCP_Kit_Backup_Store', 'register_post_type' ) );
		add_action( 'init', array( 'KarMCP_Widget_Store', 'register_post_type' ) );
		add_action( 'init', array( 'KarMCP_Skill_Store', 'register_post_type' ) );
		( new KarMCP_Widget_Loader() )->register_hooks();
		add_action( 'init', array( 'KarMCP_PHP_Snippet_Store', 'register_post_type' ) );
		( new KarMCP_PHP_Snippet_Loader() )->register_hooks();
		// Block Builder (Pro overlay). Registers the karmcp_block CPT + Gutenberg
		// block-category/init hooks only when the Pro class is present; the
		// loader self-gates on license internally.
		if ( class_exists( 'KarMCP_Block_Store' ) ) {
			add_action( 'init', array( 'KarMCP_Block_Store', 'register_post_type' ) );
			( new KarMCP_Block_Loader() )->register_hooks();
		}
		// Background refresh of the Pro Prompts / Brand Kits libraries — registered
		// unconditionally (cron runs in a non-admin context) so an expired 24h
		// cache self-heals without the user clicking "Sync Library".
		// Modules: register built-ins + Pro modules, seed defaults once, boot the
		// active ones on `init` (after registration, before most feature hooks).
		$karmcp_modules = KarMCP_Modules_Registry::instance();
		$karmcp_modules->register( new KarMCP_Image_Optimization_Module() );
		$karmcp_modules->register( new KarMCP_Prompts_Module() );
		$karmcp_modules->register( new KarMCP_Brand_Kits_Module() );
		$karmcp_modules->register( new KarMCP_Templates_Module() );
		$karmcp_modules->register( new KarMCP_Themer_Module() );
		$karmcp_modules->register( new KarMCP_Redirect_Module() );
		$karmcp_modules->register( new KarMCP_Agent_Skills_Module() );
		$karmcp_modules->register( new KarMCP_SVG_Support_Module() );
		$karmcp_modules->register( new KarMCP_Guardrails_Module() );
		$karmcp_modules->register( new KarMCP_Cloud_Module() );
		do_action( 'karmcp_register_modules', $karmcp_modules );
		$karmcp_modules->apply_defaults();
		add_action( 'init', array( $karmcp_modules, 'boot_active' ), 5 );

		// Admin-bar MCP status + exposure toggle (front-end + wp-admin; the class
		// self-gates on capability + is_admin_bar_showing()).
		( new KarMCP_Admin_Bar() )->init();
	}

	/**
	 * Loads admin-only classes and wires the Pro library admin-ajax handlers.
	 *
	 * @since 2.1.0
	 */
	private static function load_admin(): void {
		require_once KARMCP_DIR . 'includes/admin/class-admin.php';
		require_once KARMCP_DIR . 'includes/admin/class-mcpb-builder.php';

		// Non-blocking, per-user-dismissible nudge to install Elementor when it is
		// absent (Elementor is optional; every other tool works without it).
		require_once KARMCP_DIR . 'includes/admin/class-elementor-notice.php';
		( new KarMCP_Elementor_Notice() )->init();

		// The upstream upgrade banner and Facebook community banner were removed
		// with the fork: both marketed a storefront and a community that are not
		// ours. The Elementor notice above stays — it reports a real missing
		// dependency.
	}

	/**
	 * Checks that all required dependencies are available, queuing an admin
	 * notice listing anything missing.
	 *
	 * @since 2.1.0 (since 1.0.0 as karmcp_check_dependencies)
	 *
	 * @return bool True if all dependencies are met.
	 */
	private static function check_dependencies(): bool {
		// PHP 8.1+ is required. Elementor 4.0+ uses 8.1+ features that silently
		// fail on older PHP (writes no-op, _elementor_data never persists).
		// WordPress only enforces Requires PHP at activation, not on every load —
		// so we re-check here to surface a clear admin notice if the host
		// downgraded PHP after the plugin was already installed.
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						sprintf(
							/* translators: %s: current PHP version */
							esc_html__( 'KarMCP requires PHP 8.1 or higher. Your server is running PHP %s, please upgrade PHP to avoid silent Elementor write failures.', 'karmcp' ),
							esc_html( PHP_VERSION )
						)
					);
				}
			);
			return false;
		}

		$missing = array();

		// WordPress Abilities API must be available. Core in WordPress 6.9+ (and
		// 7.0); only missing on older WordPress, which the plugin doesn't support.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$missing[] = 'WordPress Abilities API (requires WordPress 6.9+)';
		}

		// MCP Adapter: bundled with the plugin (KarMCP_Adapter_Bootstrap::ensure()
		// ran above). Only fails if the bundled source is missing/corrupt — a
		// broken build, not a user action.
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$missing[] = 'WordPress MCP Adapter (bundled, reinstall the plugin if this persists)';
		}

		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				function () use ( $missing ) {
					$list = implode( ', ', $missing );
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						sprintf(
							/* translators: %s: comma-separated list of missing dependencies */
							esc_html__( 'KarMCP requires the following to be installed and active: %s', 'karmcp' ),
							'<strong>' . esc_html( $list ) . '</strong>'
						)
					);
				}
			);

			return false;
		}

		// Elementor is OPTIONAL. When absent, the plugin still loads and every
		// beyond-Elementor tool works; only the Elementor tool family + the
		// Elementor admin areas are unavailable. The non-blocking, per-user
		// dismissible "Install Elementor" nudge is handled by
		// KarMCP_Elementor_Notice, wired in load_admin().

		return true;
	}
}
