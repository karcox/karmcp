<?php
/**
 * Main plugin orchestrator.
 *
 * Singleton that initializes all components, registers hooks for the
 * Abilities API and MCP Adapter, and coordinates the plugin lifecycle.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin orchestrator singleton.
 *
 * @since 1.0.0
 */
class KarMCP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
	 * The ability registrar.
	 *
	 * Built lazily by registrar(); null until something first asks for a tool.
	 *
	 * @var KarMCP_Ability_Registrar|null
	 */
	private $registrar = null;

	/**
	 * REST namespace every MCP server on this site is mounted under — ours and
	 * the adapter's own default one. The route gate tests the namespace, not our
	 * server, so declining a request never 404s somebody else's server.
	 */
	const MCP_ROUTE_NAMESPACE = 'mcp';

	/** Our server's route within that namespace, and its server id. */
	const MCP_SERVER_ROUTE = 'karmcp-server';

	/** @var float|null MCP request start time (for the request log). */
	private $mcp_req_start = null;

	/** @var string MCP request tool name (for the request log). */
	private $mcp_req_tool = '';

	/** @var string MCP request id header (for the request log). */
	private $mcp_req_id = '';

	/**
	 * The admin settings page handler.
	 *
	 * @var KarMCP_Admin|null
	 */
	private $admin = null;

	/**
	 * Registered ability names (populated after registration).
	 *
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Gets the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Initializes the plugin components and hooks.
	 *
	 * @since 1.0.0
	 */
	private function init(): void {
		// Instantiate core components.
		$this->data             = new KarMCP_Data();
		$this->factory          = new KarMCP_Element_Factory();
		$this->schema_generator = new KarMCP_Schema_Generator();
		// The registrar is built on first use, not here: it lives among the tool
		// classes, which no longer load on a request that never asks for a tool.
		// See KarMCP_Bootstrap::load_ability_classes().

		// Admin settings page.
		if ( is_admin() && class_exists( 'KarMCP_Admin' ) ) {
			$this->admin = new KarMCP_Admin();
			$this->admin->init();
		}

		// Register hooks.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// The Abilities API is lazy-loaded: wp_abilities_api_init fires on first
		// wp_get_ability() call. The default MCP server's tool registration triggers
		// this during mcp_adapter_init at priority 10. We hook at priority 20 so
		// the Abilities API is initialized and our abilities are registered by then.
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ), 20 );

		// Declining our own server is not enough to stay out of a foreign REST
		// request. The adapter builds ITS default server on the same action at
		// priority 10 — before us — and building it calls wp_get_abilities()
		// twice for resource/prompt discovery. That forces the lazy Abilities
		// API, which fires register_abilities() below, which loads all 76 tool
		// classes and registers ~200 abilities. So the request paid in full
		// before our hook was ever reached. This is the filter that stops it.
		add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ) );

		// Apply the disabled-tools option from the admin settings page on every
		// request. The admin class is only loaded in is_admin() context, so the
		// MCP REST endpoint would otherwise never see this filter and would
		// expose every registered tool regardless of what the user disabled.
		add_filter( 'karmcp_ability_names', array( $this, 'filter_disabled_tools' ) );

		// Refuse MCP requests whose Host header no longer matches this site's
		// home host (connector left pointed at an old/temporary domain). Named,
		// not required: the autoloader resolves the class when the filter fires,
		// which on a page view is never.
		add_filter( 'rest_pre_dispatch', array( 'KarMCP_MCP_Host_Guard', 'guard' ), 5, 3 );
		// Never cache/buffer MCP responses (LiteSpeed/QUIC drop-suspect, Issue 1).
		add_filter( 'rest_pre_serve_request', array( 'KarMCP_MCP_Host_Guard', 'no_store_headers' ), 10, 4 );

		// Record every MCP request (tool, status, duration) for the MCP Log tab.
		// The store is named inside the callbacks, so it loads on the first MCP
		// request rather than on every page view.
		add_filter( 'rest_pre_dispatch', array( $this, 'mcp_log_pre_dispatch' ), 6, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'mcp_log_post_dispatch' ), 10, 3 );
	}

	/**
	 * Capture the start of an MCP request (timer + tool name + request id).
	 *
	 * @param mixed $result  Pass-through.
	 * @param mixed $server  REST server (unused).
	 * @param mixed $request WP_REST_Request.
	 * @return mixed Unmodified $result.
	 */
	public function mcp_log_pre_dispatch( $result, $server, $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) && 0 === strpos( (string) $request->get_route(), '/mcp/karmcp-server' ) ) {
			$body = json_decode( (string) $request->get_body(), true );

			// Client probes for methods this server does not implement do not earn
			// a row: leaving mcp_req_start null is what makes post_dispatch skip it.
			if ( ! KarMCP_MCP_Request_Log::should_record( $body ) ) {
				return $result;
			}

			$this->mcp_req_start = microtime( true );
			$this->mcp_req_tool  = is_array( $body ) ? (string) ( $body['params']['name'] ?? ( $body['method'] ?? '' ) ) : '';
			$this->mcp_req_id    = (string) ( $request->get_header( 'x-request-id' ) ? $request->get_header( 'x-request-id' ) : '' );
		}
		return $result;
	}

	/**
	 * Record the result of an MCP request.
	 *
	 * @param mixed $response REST response / WP_Error.
	 * @param mixed $server   REST server (unused).
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed Unmodified $response.
	 */
	public function mcp_log_post_dispatch( $response, $server, $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) && 0 === strpos( (string) $request->get_route(), '/mcp/karmcp-server' ) && null !== $this->mcp_req_start ) {
			$status = is_wp_error( $response ) ? 'error' : ( is_object( $response ) && method_exists( $response, 'get_status' ) ? (string) $response->get_status() : 'ok' );
			$error  = is_wp_error( $response ) ? $response->get_error_message() : '';
			KarMCP_MCP_Request_Log::record(
				array(
					'tool'   => $this->mcp_req_tool,
					'status' => $status,
					'ms'     => (int) round( ( microtime( true ) - $this->mcp_req_start ) * 1000 ),
					'req_id' => $this->mcp_req_id,
					'error'  => $error,
				)
			);
			$this->mcp_req_start = null;
		}
		return $response;
	}

	/**
	 * Removes tools the user disabled from the registered ability names list.
	 *
	 * @since 1.6.0
	 *
	 * @param string[] $names The registered ability names.
	 * @return string[] Ability names with disabled tools removed.
	 */
	public function filter_disabled_tools( array $names ): array {
		$disabled = get_option( 'karmcp_disabled_tools', array() );
		if ( ! is_array( $disabled ) || empty( $disabled ) ) {
			return $names;
		}

		return array_values( array_diff( $names, $disabled ) );
	}

	/**
	 * Option name for the "Activate Abilities API for KarMCP" server gate.
	 *
	 * @since 1.7.4
	 * @var string
	 */
	const OPTION_SERVER_ENABLED = 'karmcp_server_enabled';

	/**
	 * Whether the MCP server should be exposed. On by default; the Connection
	 * tab toggle writes '0' to switch it off.
	 *
	 * @since 1.7.4
	 *
	 * @return bool
	 */
	public static function is_server_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_SERVER_ENABLED, '1' );
	}

	/**
	 * Option: "compact tool mode" (the meta-tool dispatcher). Default OFF.
	 *
	 * @var string
	 */
	const OPTION_DISPATCHER_MODE = 'karmcp_dispatcher_mode';

	/**
	 * Whether "compact tool mode" (the meta-tool dispatcher) is on. Default OFF —
	 * when on, the server surfaces the 3 dispatcher tools instead of every
	 * individual tool.
	 *
	 * @since 3.2.0
	 *
	 * @return bool
	 */
	public static function is_dispatcher_mode(): bool {
		return '1' === (string) get_option( self::OPTION_DISPATCHER_MODE, '0' );
	}

	/**
	 * Registers the ability category.
	 *
	 * Called during `wp_abilities_api_categories_init`.
	 *
	 * @since 1.0.0
	 */
	public function register_category(): void {
		wp_register_ability_category(
			'karmcp',
			array(
				'label'       => __( 'KarMCP', 'karmcp' ),
				'description' => __( 'Tools for reading and manipulating Elementor page designs via MCP.', 'karmcp' ),
			)
		);
	}

	/**
	 * Registers all abilities with the WordPress Abilities API.
	 *
	 * Called during `wp_abilities_api_init`.
	 *
	 * @since 1.0.0
	 */
	public function register_abilities(): void {
		$this->ability_names = $this->registrar()->register_all( KarMCP_Bootstrap::elementor_active() );
	}

	/**
	 * The ability registrar, built on first use.
	 *
	 * This is the seam the deferred tool-class load hangs on: the registrar and
	 * every class it registers are required here, at the moment something first
	 * asks for a tool, instead of on every request. A front-end page view that
	 * never reaches a tool never parses any of it.
	 *
	 * @since 1.16.2
	 *
	 * @return KarMCP_Ability_Registrar
	 */
	private function registrar(): KarMCP_Ability_Registrar {
		if ( null === $this->registrar ) {
			KarMCP_Bootstrap::load_ability_classes();
			$validator       = new KarMCP_Settings_Validator( $this->schema_generator );
			$this->registrar = new KarMCP_Ability_Registrar( $this->data, $this->factory, $this->schema_generator, $validator );
		}
		return $this->registrar;
	}

	/**
	 * Returns the active (post-filter) ability names — the exact set exposed to
	 * the MCP server, with user-disabled tools and Pro-disabled-by-default
	 * already removed. Used by the AI Chat
	 * /execute-ability and /abilities endpoints so the chat can never run a tool
	 * the admin disabled. Triggers the lazy Abilities API init if it hasn't run.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	public function get_active_ability_names(): array {
		if ( empty( $this->ability_names ) && function_exists( 'wp_get_ability' ) ) {
			// Any known ability triggers wp_abilities_api_init → register_abilities().
			wp_get_ability( 'karmcp/list-pages' );
		}
		return is_array( $this->ability_names ) ? $this->ability_names : array();
	}

	/**
	 * Lets the adapter build its default server only when the request could
	 * reach an MCP endpoint.
	 *
	 * Composed, not overridden: a site that already switched the default server
	 * off keeps it off.
	 *
	 * @since 1.30.0
	 *
	 * @param mixed $create Whether the adapter intends to create it.
	 * @return bool
	 */
	public static function filter_default_server( $create ): bool {
		return (bool) $create && self::request_needs_mcp_server();
	}

	/**
	 * Whether this request can actually reach an MCP endpoint.
	 *
	 * The adapter hooks its `init()` to `rest_api_init` (or to `init` under
	 * WP-CLI), and `rest_api_init` fires on EVERY REST request, not only the
	 * ones under our route. Building the server is not cheap: `create_server()`
	 * calls `wp_get_ability()` for each name, which triggers the lazy Abilities
	 * API, which loads the 76 tool classes (~1.2 MB) and registers ~200
	 * abilities with their JSON schemas. Every `/wp-json/wp/v2/…` call paid that
	 * — opening the block editor, each autosave, and any REST call made by any
	 * other plugin on the site. In an editing session that is dozens of times.
	 *
	 * Passing only the names and letting the callbacks resolve later is not an
	 * option, whatever the audit assumed: `McpComponentRegistry::register_ability_tool()`
	 * resolves each ability at construction, so a server built from unregistered
	 * names would expose zero tools and log one line per name.
	 *
	 * So the test is the route, and it is deliberately written to skip only what
	 * it can positively identify as somebody else's:
	 *
	 * - anything under the `mcp/` namespace. Not just our own route: the
	 *   adapter's default server shares the namespace, and gating on our route
	 *   alone would 404 it for anyone who uses it;
	 * - `/` — the `/wp-json/` discovery index, which lists our route and is how
	 *   some clients find it. It is one rare request; paying full price for it
	 *   costs nothing and keeps discovery working, which is what made route
	 *   filtering look unattractive in the first place;
	 * - an empty route — `rest_get_server()` called outside a served REST
	 *   request (an internal `rest_do_request()`, a plugin preloading in
	 *   wp-admin). We cannot tell what it wants, so we build, exactly as before.
	 *
	 * @since 1.30.0
	 *
	 * @return bool
	 */
	public static function request_needs_mcp_server(): bool {
		$needed = true;

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			// Under WP-CLI the adapter runs on `init` and any command may want it.
			$needed = true;
		} elseif ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			// Set by WordPress before `rest_api_init` fires, and it covers both
			// pretty permalinks and the `?rest_route=` fallback — which is why it
			// is read here instead of REQUEST_URI.
			$route = '/' . ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' );
			if ( '/' !== $route ) {
				$needed = 0 === strpos( $route, '/' . self::MCP_ROUTE_NAMESPACE . '/' );
			}
		}

		/**
		 * Filters whether the MCP server is built for this request.
		 *
		 * The escape hatch for a client that reaches the endpoint by some route
		 * this does not recognise. Returning true costs the full tool load.
		 *
		 * @since 1.30.0
		 *
		 * @param bool $needed Whether to build the server.
		 */
		$needed = (bool) apply_filters( 'karmcp_needs_mcp_server', $needed );

		if ( ! $needed && defined( 'KARMCP_PROFILE_REGISTRATION' ) && KARMCP_PROFILE_REGISTRATION ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] )
				? (string) $GLOBALS['wp']->query_vars['rest_route']
				: '(none)';
			error_log( 'KarMCP: skipped MCP server build for REST route ' . $route . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in profiling, behind a constant nobody defines in production.
		}

		return $needed;
	}

	/**
	 * Registers the MCP server with the MCP Adapter.
	 *
	 * Called during `mcp_adapter_init`.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP\MCP\Core\McpAdapter $mcp_adapter The MCP adapter instance.
	 */
	public function register_mcp_server( $mcp_adapter ): void {
		// "Activate Abilities API for KarMCP" gate (Connection tab). On by default;
		// when switched off, the abilities stay registered in core but no MCP
		// server endpoint is created — nothing is exposed to AI agents.
		if ( ! self::is_server_enabled() ) {
			return;
		}

		// Not a request that can reach the MCP endpoint: building the server here
		// would load every tool class and register ~200 abilities for nothing.
		if ( ! self::request_needs_mcp_server() ) {
			return;
		}

		if ( empty( $this->ability_names ) ) {
			return;
		}

		// Compact tool mode: surface only the 3 dispatcher tools instead of every
		// individual ability (the rest stay registered and reachable via call-tool).
		if ( self::is_dispatcher_mode() ) {
			// Exactly the 3 meta-tools — the core context abilities are folded in
			// too (reachable through call-tool), so the surface stays at 3.
			$tools = KarMCP_Dispatcher_Abilities::NAMES;
		} else {
			$tools = $this->ability_names;

			// Also expose WordPress core's read-only context abilities (site/user/
			// environment info) on our server — registered by core, free to surface.
			foreach ( array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' ) as $karmcp_core_ability ) {
				if ( function_exists( 'wp_get_ability' ) && wp_get_ability( $karmcp_core_ability ) && ! in_array( $karmcp_core_ability, $tools, true ) ) {
					$tools[] = $karmcp_core_ability;
				}
			}
		}

		$mcp_adapter->create_server(
			self::MCP_SERVER_ROUTE,                                   // server_id
			self::MCP_ROUTE_NAMESPACE,                                // route_namespace
			self::MCP_SERVER_ROUTE,                                   // route
			__( 'KarMCP Server', 'karmcp' ),            // server_name
			KarMCP_Site_Context::compose_instructions( KarMCP_Site_Context::default_base() . "\n\n" . KarMCP_Site_Context::environment_summary() ), // description (base + env + site context)
			'v' . KARMCP_VERSION,                              // version
			array( \WP\MCP\Transport\HttpTransport::class ),          // transports
			null,                                                     // error_handler (use default)
			null,                                                     // observability_handler
			$tools,                                                   // tools
			array(),                                                  // resources
			array(),                                                  // prompts
			// OAuth bearer auth when enabled (falls through to App Password); else adapter default.
			( class_exists( 'KarMCP_OAuth_Server' ) && KarMCP_OAuth_Server::is_enabled() )
				? array( 'KarMCP_OAuth_Bearer', 'permission_callback' )
				: null                                                // transport_permission_callback
		);
	}

	/**
	 * Gets the data access layer instance.
	 *
	 * @since 1.0.0
	 *
	 * @return KarMCP_Data
	 */
	public function get_data(): KarMCP_Data {
		return $this->data;
	}

	/**
	 * Gets the element factory instance.
	 *
	 * @since 1.0.0
	 *
	 * @return KarMCP_Element_Factory
	 */
	public function get_factory(): KarMCP_Element_Factory {
		return $this->factory;
	}

	/**
	 * Gets the schema generator instance.
	 *
	 * @since 1.0.0
	 *
	 * @return KarMCP_Schema_Generator
	 */
	public function get_schema_generator(): KarMCP_Schema_Generator {
		return $this->schema_generator;
	}

	/**
	 * Prevents cloning.
	 *
	 * @since 1.0.0
	 */
	private function __clone() {}
}
