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
		// Translations. Registered before the dependency check so the notice that
		// check prints is itself translatable, and deferred to `init` because
		// WordPress 6.7+ warns about a text domain loaded any earlier.
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

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

		if ( is_admin() && self::admin_context_needs_tools() ) {
			self::load_admin();
		}

		// Boot the plugin singleton.
		KarMCP_Plugin::instance();
	}

	/**
	 * Load the plugin's translations.
	 *
	 * Required, not optional: the automatic loading WordPress added in 4.6 only
	 * covers translations it downloads for plugins hosted on wordpress.org. This
	 * one updates from its own GitHub releases, so without
	 * this call the ~3,000 translatable strings could never resolve to anything
	 * but English, whatever was placed in `languages/`.
	 *
	 * @since 1.2.0
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'karmcp', false, dirname( KARMCP_BASENAME ) . '/languages' );
	}

	/**
	 * Whether Elementor is loaded/active in this request.
	 *
	 * Single source of truth for the optional-Elementor gate: the tool registrar,
	 * the admin Tools page, and the Templates tab all read this.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public static function elementor_active(): bool {
		return (bool) did_action( 'elementor/loaded' );
	}

	/**
	 * Loads the two runtime files an autoloader cannot reach.
	 *
	 * This used to be 153 `require_once` calls, run unconditionally on every
	 * request — front-end page views included. That is ~1.5 MB of PHP parsed to
	 * declare the malware scanner, the stock-image clients, the OAuth server and
	 * the WP-CLI runner, none of which a visitor can reach. A hand-kept list
	 * cannot know what a given request needs; only use can, and that is what
	 * `KarMCP_Autoloader` now decides, against the generated `includes/classmap.php`.
	 *
	 * Dropping the list is safe because no class file in this tree does work at
	 * include time: loading a file never registered a hook — `wire_hooks()` does
	 * that, by name, and a string callable resolves through the autoloader when
	 * the hook fires. `ClassmapTest` pins that property so it stays true.
	 *
	 * What is left are the two files that declare a global FUNCTION next to their
	 * class. A function has no autoload hook in PHP, so if nothing happens to
	 * touch the class first the function simply does not exist:
	 *
	 * - `karmcp_register_ability()` — the entry point every ability group calls.
	 * - `karmcp_themer_location()` — a template tag an unsupported theme calls
	 *   from its own header.php, which runs before anything names the class.
	 *
	 * A third such file fails `ClassmapTest`, which is where the rule is checked
	 * rather than remembered.
	 *
	 * @since 2.1.0
	 */
	private static function load_classes(): void {
		require_once KARMCP_DIR . 'includes/class-schema-compat.php';
		require_once KARMCP_DIR . 'includes/themer/class-themer-render-controller.php';

		// Shortcode registration by name: the class loads when `init` fires it.
		add_action( 'init', array( 'KarMCP_Nav_Menu_Shortcode', 'register' ) );
	}

	/**
	 * Loads the tool classes — the ~76 files under includes/abilities/ plus the
	 * registrar that wires them.
	 *
	 * These used to load on every request, front-end page views included. That
	 * is several MB of PHP parsed to define classes a visitor will never reach,
	 * and on a 128MB host it is the difference between the site working and not.
	 * Nothing outside includes/abilities/ depends on them at load time, so they
	 * can wait for the moment a caller actually needs a tool:
	 *
	 * - `wp_abilities_api_init` — the Abilities API is lazy, the hook fires on
	 *   the first wp_get_ability() call, which is the MCP server registering
	 *   its tools. This is the hot path and the reason the split pays.
	 * - `mcp_adapter_init` — the server build reads the dispatcher's names.
	 * - wp-admin — the Tools tab lists every tool and seeds their defaults.
	 *
	 * Idempotent: safe to call from all of them, and cheap after the first.
	 * The order below is the original load order, which several groups depend
	 * on — the dispatch trait before the integrations that use it, each
	 * abstract base before its subclasses.
	 *
	 * @since 1.16.2
	 */
	public static function load_ability_classes(): void {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		// Shared dispatch for the two-tool plugin integrations. Loads before any
		// of them, since a trait has to exist when the class that uses it does.
		require_once KARMCP_DIR . 'includes/abilities/trait-operation-dispatcher.php';
		require_once KARMCP_DIR . 'includes/abilities/class-query-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-page-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-layout-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-widget-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-template-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-global-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-composite-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-stock-image-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-media-library-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-image-resize-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-gutenberg-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-snapshot-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-render-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-transaction-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-search-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-redirect-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-login-guard-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-security-fix-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-recovery-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-vulnerability-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-db-cleanup-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-skill-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-skill-write-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-content-mirror-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-content-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-dispatcher-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-settings-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-plugin-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-theme-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-user-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-nav-menu-abilities.php';
		// ACF tools (field values + field group discovery/authoring; writes off by default).
		require_once KARMCP_DIR . 'includes/abilities/class-acf-abilities.php';
		// Meta Box tools (field values + field group discovery; writes off by default).
		require_once KARMCP_DIR . 'includes/abilities/class-metabox-abilities.php';
		// Themes domain: the dispatcher base (must load before its subclasses)
		// + the integrations.
		require_once KARMCP_DIR . 'includes/abilities/class-theme-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-active-theme-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-astra-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-spectra-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-kadence-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-kadence-blocks-integration.php';
		// Forms-tab integrations — abstract base + Contact Form 7. The adapters for
		// the entry-storing plugins were upstream Pro files and are not in this build.
		require_once KARMCP_DIR . 'includes/abilities/forms/class-form-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/forms/class-cf7-form-builder.php';
		require_once KARMCP_DIR . 'includes/abilities/forms/class-cf7-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/forms/class-elementor-form-builder.php';
		require_once KARMCP_DIR . 'includes/abilities/forms/class-form-widget-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/woo/class-woo-product-input.php';
		require_once KARMCP_DIR . 'includes/abilities/woo/class-woo-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-duplicate-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-structured-data-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-site-builder-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/i18n/class-translation-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/i18n/class-polylang-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/i18n/class-wpml-integration.php';
		// SEO plugin integrations — abstract base + KarSEO. The Yoast, Rank Math,
		// AIOSEO, SEOPress, SEO Framework and SureRank adapters were upstream Pro
		// files and are not in this build.
		require_once KARMCP_DIR . 'includes/abilities/seo/class-seo-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/seo/class-karseo-integration.php';
		require_once KARMCP_DIR . 'includes/abilities/class-performance-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-seo-audit-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-a11y-audit-abilities.php';
		// Filesystem tools (read/scan + write/edit/delete; writes off by default).
		require_once KARMCP_DIR . 'includes/abilities/class-filesystem-abilities.php';
		// Database tools (read-only query + structured writes; writes off by default).
		require_once KARMCP_DIR . 'includes/abilities/class-database-abilities.php';
		// WP-CLI tools (run + background jobs; disabled-by-default, manage_options).
		require_once KARMCP_DIR . 'includes/abilities/class-wpcli-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-security-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-svg-icon-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-custom-code-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-widget-builder-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-block-builder-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-extension-builder-abilities.php';
		// Sandbox Cloud abilities — export/import any sandbox artifact (block/
		// widget/snippet) as a portable bundle over the cloud contract.
		require_once KARMCP_DIR . 'includes/abilities/class-sandbox-cloud-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-php-snippet-abilities.php';
		// Atomic elements support (Elementor 4.0+).
		require_once KARMCP_DIR . 'includes/abilities/class-atomic-widget-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-atomic-layout-abilities.php';
		// Global Classes (Class Manager) reader — self-gates on Elementor 4.0+.
		require_once KARMCP_DIR . 'includes/abilities/class-global-classes-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-global-classes-write-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-themer-php-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-themer-abilities.php';
		require_once KARMCP_DIR . 'includes/abilities/class-ability-registrar.php';
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

		// Update checks against this plugin's own GitHub releases. WordPress
		// fires this filter only for plugins whose Update URI names github.com,
		// and the class loads through the autoloader when it does — a site that
		// never opens an update screen never parses it.
		add_filter( 'update_plugins_github.com', array( 'KarMCP_Updater', 'check' ), 10, 3 );
		// And the details screen that the update row links to, which would
		// otherwise ask wordpress.org about a plugin it has never heard of.
		add_filter( 'plugins_api', array( 'KarMCP_Updater', 'plugin_information' ), 10, 3 );

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
		// Structured data: print a post's JSON-LD in the document head.
		KarMCP_Structured_Data::init();
		add_action( 'init', array( 'KarMCP_Kit_Backup_Store', 'register_post_type' ) );
		add_action( 'init', array( 'KarMCP_Widget_Store', 'register_post_type' ) );
		add_action( 'init', array( 'KarMCP_Skill_Store', 'register_post_type' ) );
		KarMCP_Skill_Editor::init();
		( new KarMCP_Widget_Loader() )->register_hooks();
		add_action( 'init', array( 'KarMCP_PHP_Snippet_Store', 'register_post_type' ) );
		( new KarMCP_PHP_Snippet_Loader() )->register_hooks();
		// Block Builder: the karmcp_block CPT plus the loader that registers
		// active blocks with Gutenberg. Guarded because the uninstaller and the
		// tests load parts of this tree on their own.
		if ( class_exists( 'KarMCP_Block_Store' ) && class_exists( 'KarMCP_Block_Loader' ) ) {
			add_action( 'init', array( 'KarMCP_Block_Store', 'register_post_type' ) );
			( new KarMCP_Block_Loader() )->register_hooks();
		}
		// Element extensions. The CPT registers on init like the rest; the
		// loader hooks earlier on init, because Elementor asks elements for
		// their schema as soon as it builds one.
		if ( class_exists( 'KarMCP_Extension_Store' ) && class_exists( 'KarMCP_Extension_Loader' ) ) {
			add_action( 'init', array( 'KarMCP_Extension_Store', 'register_post_type' ) );
			( new KarMCP_Extension_Loader() )->register_hooks();
		}
		// Modules: register built-ins + Pro modules, seed defaults once, boot the
		// active ones on `init` (after registration, before most feature hooks).
		$karmcp_modules = KarMCP_Modules_Registry::instance();
		$karmcp_modules->register( new KarMCP_Image_Optimization_Module() );
		$karmcp_modules->register( new KarMCP_Themer_Module() );
		$karmcp_modules->register( new KarMCP_Redirect_Module() );
		$karmcp_modules->register( new KarMCP_Agent_Skills_Module() );
		$karmcp_modules->register( new KarMCP_SVG_Support_Module() );
		$karmcp_modules->register( new KarMCP_Guardrails_Module() );
		$karmcp_modules->register( new KarMCP_Login_Guard_Module() );
		$karmcp_modules->register( new KarMCP_Vulnerabilities_Module() );
		do_action( 'karmcp_register_modules', $karmcp_modules );
		$karmcp_modules->apply_defaults();
		add_action( 'init', array( $karmcp_modules, 'boot_active' ), 5 );

		// Admin-bar MCP status + exposure toggle (front-end + wp-admin; the class
		// self-gates on capability + is_admin_bar_showing()).
		// Hardening the admin switched on, plus the scheduled scan. Both are
		// unconditional: the runtime no-ops when nothing is applied, and the
		// monitor's cron has to exist even on a site nobody visits in wp-admin.
		KarMCP_Security_Hardening_Runtime::init();
		KarMCP_Security_Monitor::init();
		// Reactivating a plugin the fatal handler paused clears its pause record.
		// Named, not instantiated: the class loads when a plugin is activated,
		// which is rare and never on a page view.
		add_action( 'activated_plugin', array( 'KarMCP_Fatal_Handler_Template', 'forget' ) );

		( new KarMCP_Admin_Bar() )->init();
	}

	/**
	 * Whether this wp-admin request is one that actually needs the admin screens
	 * and the tool classes behind them.
	 *
	 * `is_admin()` is true for `admin-ajax.php` too, so without this check the
	 * heartbeat tick of an open editor — plus every autosave and every AJAX call
	 * made by any OTHER plugin on the site — loaded the admin class and the whole
	 * ability layer: ~2.9 MB of PHP for a request that never touches either.
	 *
	 * Every handler this plugin registers on admin-ajax is prefixed `karmcp_`
	 * (KarMCP_Admin, KarMCP_Bulk_Optimizer, KarMCP_Themer_Extended), so the action
	 * name is a complete and reliable test. Note the last two live in
	 * load_classes(), not here, so they keep working either way.
	 *
	 * `admin-post.php` is not an AJAX request, so the `admin_post_karmcp_*`
	 * handlers on KarMCP_Admin are unaffected and still load.
	 *
	 * @since 1.26.0
	 *
	 * @return bool
	 */
	private static function admin_context_needs_tools(): bool {
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return true;
		}
		// Routing only — this decides which files to load, never what to do with
		// the request. The handler that acts on it does its own nonce and
		// capability checks, which is where that belongs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		return 0 === strpos( $action, 'karmcp_' );
	}

	/**
	 * Loads admin-only classes and wires the Pro library admin-ajax handlers.
	 *
	 * @since 2.1.0
	 */
	private static function load_admin(): void {
		// wp-admin is a tool consumer: the Tools tab lists every tool and seeds
		// the disabled-by-default slugs, and the sandbox screens resolve artifacts
		// through a tool class. No deferral here.
		self::load_ability_classes();

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
