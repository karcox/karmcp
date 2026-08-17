<?php
/**
 * Connection info tab view for the KarMCP admin settings page.
 *
 * Organised into two sub-tabs: "Connections" (server gate + strict schemas +
 * status + client wizard) and "3rd Party Services" (stock-image provider keys).
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var KarMCP_Admin $this */
$karmcp_endpoint      = class_exists( 'KarMCP_Site_Context' ) ? KarMCP_Site_Context::mcp_endpoint() : rest_url( 'mcp/karmcp-server' );
$karmcp_enabled_count = $this->get_enabled_tool_count();
$karmcp_total_count   = $this->get_total_tool_count();
$karmcp_has_adapter   = class_exists( '\WP\MCP\Core\McpAdapter' );

// Adapter provenance: bundled with KarMCP, an external plugin, or unavailable.
$karmcp_adapter_source = class_exists( 'KarMCP_Adapter_Bootstrap' )
	? KarMCP_Adapter_Bootstrap::source()
	: ( $karmcp_has_adapter ? 'external' : 'none' );
$karmcp_adapter_label = 'bundled' === $karmcp_adapter_source
	? __( 'Active (bundled)', 'karmcp' )
	: ( 'external' === $karmcp_adapter_source ? __( 'Active (plugin)', 'karmcp' ) : __( 'Not Active', 'karmcp' ) );

// Abilities API is core in WordPress 6.9+/7.0.
$karmcp_has_abilities = function_exists( 'wp_register_ability' );

// The "Activate Abilities API for KarMCP" gate (on by default).
$karmcp_server_enabled = class_exists( 'KarMCP_Plugin' )
	? KarMCP_Plugin::is_server_enabled()
	: ( '1' === (string) get_option( 'karmcp_server_enabled', '1' ) );
?>

<div class="elementor-mcp-connection">

	<div class="karmcp-conn-subhead">
		<div class="elementor-mcp-subtabs elementor-mcp-subtabs--flush" data-subtab-key="connection" role="tablist" aria-label="<?php esc_attr_e( 'Connection sections', 'karmcp' ); ?>">
			<button type="button" class="elementor-mcp-subtab is-active" role="tab" data-tab="conn-main" aria-selected="true" aria-controls="karmcp-conn-main">
				<span class="elementor-mcp-subtab-label"><?php esc_html_e( 'MCP', 'karmcp' ); ?></span>
			</button>
			<?php if ( class_exists( 'KarMCP_Cloud_Module' ) && KarMCP_Cloud_Module::is_enabled() ) : ?>
			<button type="button" class="elementor-mcp-subtab" role="tab" data-tab="conn-cloud" aria-selected="false" aria-controls="karmcp-conn-cloud">
				<span class="elementor-mcp-subtab-label"><?php esc_html_e( 'Cloud', 'karmcp' ); ?></span>
			</button>
			<?php endif; ?>
			<button type="button" class="elementor-mcp-subtab" role="tab" data-tab="conn-services" aria-selected="false" aria-controls="karmcp-conn-services">
				<span class="elementor-mcp-subtab-label"><?php esc_html_e( '3rd Party Services', 'karmcp' ); ?></span>
			</button>
		</div>
		<div class="karmcp-subtab-actions">
			<button type="submit" form="karmcp-conn-form" class="button button-primary karmcp-subtab-save" data-save-for="conn-main"><?php esc_html_e( 'Save Settings', 'karmcp' ); ?></button>
			<button type="submit" form="karmcp-conn-services-form" class="button button-primary karmcp-subtab-save" data-save-for="conn-services" hidden><?php esc_html_e( 'Save Settings', 'karmcp' ); ?></button>
		</div>
	</div>

	<?php // ===== Sub-tab: Connections ===== ?>
	<div class="elementor-mcp-tabpanel is-active" id="karmcp-conn-main" role="tabpanel" data-tab="conn-main">

		<!-- Server Status -->
		<div class="elementor-mcp-section">
			<h2><?php esc_html_e( 'Server Status', 'karmcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Current status of your MCP server and connected components.', 'karmcp' ); ?></p>

			<div class="elementor-mcp-status-grid">
				<div class="elementor-mcp-status-card">
					<span class="elementor-mcp-status-card-icon elementor-mcp-status-card-icon--ok">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
					</span>
					<span class="elementor-mcp-status-card-info">
						<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'KarMCP', 'karmcp' ); ?></span>
						<span class="elementor-mcp-status-card-value"><?php esc_html_e( 'Active', 'karmcp' ); ?></span>
					</span>
				</div>

				<div class="elementor-mcp-status-card">
					<span class="elementor-mcp-status-card-icon <?php echo esc_attr( $karmcp_has_adapter ? 'elementor-mcp-status-card-icon--ok' : 'elementor-mcp-status-card-icon--warn' ); ?>">
						<?php if ( $karmcp_has_adapter ) : ?>
							<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
						<?php else : ?>
							<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>
						<?php endif; ?>
					</span>
					<span class="elementor-mcp-status-card-info">
						<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'MCP Adapter', 'karmcp' ); ?></span>
						<span class="elementor-mcp-status-card-value"><?php echo esc_html( $karmcp_adapter_label ); ?></span>
					</span>
				</div>

				<div class="elementor-mcp-status-card">
					<span class="elementor-mcp-status-card-icon <?php echo esc_attr( $karmcp_server_enabled ? 'elementor-mcp-status-card-icon--ok' : 'elementor-mcp-status-card-icon--warn' ); ?>">
						<?php if ( $karmcp_server_enabled ) : ?>
							<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
						<?php else : ?>
							<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>
						<?php endif; ?>
					</span>
					<span class="elementor-mcp-status-card-info">
						<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'MCP Server', 'karmcp' ); ?></span>
						<span class="elementor-mcp-status-card-value"><?php echo esc_html( $karmcp_server_enabled ? __( 'Enabled', 'karmcp' ) : __( 'Disabled', 'karmcp' ) ); ?></span>
					</span>
				</div>

				<div class="elementor-mcp-status-card">
					<span class="elementor-mcp-status-card-icon elementor-mcp-status-card-icon--ok">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
					</span>
					<span class="elementor-mcp-status-card-info">
						<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'Tools Enabled', 'karmcp' ); ?></span>
						<span class="elementor-mcp-status-card-value">
							<?php
							printf(
								/* translators: %1$d: enabled count, %2$d: total count */
								esc_html__( '%1$d / %2$d', 'karmcp' ),
								(int) $karmcp_enabled_count,
								(int) $karmcp_total_count
							);
							?>
						</span>
					</span>
				</div>
			</div>

			<div class="elementor-mcp-endpoint">
				<code><?php echo esc_html( $karmcp_endpoint ); ?></code>
				<button type="button" class="button elementor-mcp-copy-btn" data-target="elementor-mcp-endpoint-copy"><?php esc_html_e( 'Copy', 'karmcp' ); ?></button>
				<textarea id="elementor-mcp-endpoint-copy" class="elementor-mcp-copy-source"><?php echo esc_html( $karmcp_endpoint ); ?></textarea>
			</div>
		</div>

		<form method="post" action="options.php" id="karmcp-conn-form" class="elementor-mcp-activate-form">
			<?php settings_fields( KarMCP_Admin::SETTINGS_GROUP_SERVER ); ?>

			<div class="karmcp-conn-cards">

				<?php // Card A: server gate. ?>
				<div class="karmcp-conn-card">
					<h2 class="karmcp-conn-card-title"><?php esc_html_e( 'Activate Abilities API for KarMCP', 'karmcp' ); ?></h2>

					<label class="karmcp-switch karmcp-conn-toggle">
						<input
							type="checkbox"
							name="<?php echo esc_attr( KarMCP_Plugin::OPTION_SERVER_ENABLED ); ?>"
							value="1"
							<?php checked( $karmcp_server_enabled ); ?>
						/>
						<span class="elementor-mcp-toggle" aria-hidden="true"><span class="elementor-mcp-toggle-track"></span></span>
						<span class="karmcp-switch-label"><?php esc_html_e( 'Expose KarMCP tools to AI agents on this site', 'karmcp' ); ?></span>
					</label>

					<p class="elementor-mcp-activate-note elementor-mcp-activate-note--security">
						<strong><?php esc_html_e( 'Security note:', 'karmcp' ); ?></strong>
						<?php esc_html_e( 'When enabled, connected AI agents can create, edit, and delete Elementor pages and content on this site through the MCP server. Use a capable AI model and set your client to ask for confirmation before every action, read what the agent is about to do before approving.', 'karmcp' ); ?>
					</p>
					<p class="elementor-mcp-activate-note">
						<?php
						if ( $karmcp_has_abilities ) {
							printf(
								/* translators: %s: how the MCP Adapter is provided. */
								esc_html__( 'WordPress Abilities API: core (no separate plugin needed). MCP Adapter: %s.', 'karmcp' ),
								'bundled' === $karmcp_adapter_source
									? esc_html__( 'bundled with KarMCP', 'karmcp' )
									: ( 'external' === $karmcp_adapter_source ? esc_html__( 'provided by an active MCP Adapter plugin', 'karmcp' ) : esc_html__( 'unavailable', 'karmcp' ) )
							);
						} else {
							esc_html_e( 'WordPress Abilities API is unavailable, WordPress 6.9 or newer is required.', 'karmcp' );
						}
						?>
					</p>
				</div>

				<?php // Card B: strict schemas. ?>
				<div class="karmcp-conn-card">
					<h2 class="karmcp-conn-card-title"><?php esc_html_e( 'OpenAI-strict tool schemas', 'karmcp' ); ?></h2>

					<label class="karmcp-switch karmcp-conn-toggle">
						<input
							type="checkbox"
							name="karmcp_strict_schemas"
							value="1"
							<?php checked( '1' === (string) get_option( 'karmcp_strict_schemas', '0' ) ); ?>
						/>
						<span class="elementor-mcp-toggle" aria-hidden="true"><span class="elementor-mcp-toggle-track"></span></span>
						<span class="karmcp-switch-label"><?php esc_html_e( 'Enable strict function-calling schemas', 'karmcp' ); ?></span>
					</label>

					<p class="elementor-mcp-activate-note">
						<?php esc_html_e( 'Enable only for OpenAI-compatible strict function-calling clients (e.g. CrewAI) that reject the default tool schemas. It lists every property as required (optional ones become nullable) and sets additionalProperties:false. Leave this OFF for Claude, Gemini, and Antigravity, they work with the default schemas, and strict mode can break Gemini/Antigravity.', 'karmcp' ); ?>
					</p>
					<p class="elementor-mcp-activate-note">
						<?php
						printf(
							/* translators: %s: link to the Tools tab. */
							esc_html__( 'Looking for Compact tool mode? It now lives on the %s tab, next to the per-tool toggles it works with.', 'karmcp' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=karmcp-tools' ) ) . '">' . esc_html__( 'Tools', 'karmcp' ) . '</a>'
						);
						?>
					</p>
				</div>

				<?php // Card: Server URL override. ?>
				<?php
				$karmcp_detected_base = class_exists( 'KarMCP_Site_Context' ) ? KarMCP_Site_Context::detected_base_url() : home_url();
				$karmcp_base_override = (string) get_option( KarMCP_Site_Context::OPTION_BASE_URL, '' );
				?>
				<div class="karmcp-conn-card">
					<h2 class="karmcp-conn-card-title"><?php esc_html_e( 'Server URL', 'karmcp' ); ?></h2>

					<input
						type="url"
						class="regular-text"
						style="width:100%;max-width:520px;"
						name="<?php echo esc_attr( KarMCP_Site_Context::OPTION_BASE_URL ); ?>"
						value="<?php echo esc_attr( $karmcp_base_override ); ?>"
						placeholder="<?php echo esc_attr( $karmcp_detected_base ); ?>"
						inputmode="url"
						autocomplete="off"
					/>

					<p class="elementor-mcp-activate-note">
						<?php
						printf(
							/* translators: %s: the auto-detected base URL. */
							esc_html__( 'The URL AI clients use to reach this site. Auto-detected as %s. Override it only when this site is served on a different address than WordPress\'s configured Site Address, for example a staging site whose domain is pinned to a not-yet-live production URL. Leave blank to auto-detect. This value is baked into the downloadable bundle and used for OAuth sign-in.', 'karmcp' ),
							'<code>' . esc_html( $karmcp_detected_base ) . '</code>'
						);
						?>
					</p>
				</div>

				<?php // Card C: OAuth sign-in. ?>
				<?php $karmcp_oauth_available = class_exists( 'KarMCP_OAuth_Server' ) && KarMCP_OAuth_Server::is_available(); ?>
				<div class="karmcp-conn-card">
					<h2 class="karmcp-conn-card-title"><?php esc_html_e( 'OAuth sign-in for AI clients', 'karmcp' ); ?></h2>

					<label class="karmcp-switch karmcp-conn-toggle">
						<input
							type="checkbox"
							name="<?php echo esc_attr( KarMCP_OAuth_Server::OPTION_ENABLED ); ?>"
							value="1"
							<?php checked( class_exists( 'KarMCP_OAuth_Server' ) && KarMCP_OAuth_Server::option_enabled() ); ?>
							<?php disabled( ! $karmcp_oauth_available ); ?>
						/>
						<span class="elementor-mcp-toggle" aria-hidden="true"><span class="elementor-mcp-toggle-track"></span></span>
						<span class="karmcp-switch-label"><?php esc_html_e( 'Let clients connect by signing in (OAuth), no password to copy', 'karmcp' ); ?></span>
					</label>

					<?php if ( ! $karmcp_oauth_available ) : ?>
						<p class="elementor-mcp-activate-note elementor-mcp-activate-note--security">
							<?php esc_html_e( 'OAuth requires HTTPS. This site is not served over HTTPS, so OAuth sign-in is unavailable, use an Application Password below.', 'karmcp' ); ?>
						</p>
					<?php endif; ?>
					<p class="elementor-mcp-activate-note">
						<?php esc_html_e( 'Claude and other MCP clients connect through a standard authorization flow: they open a page where you approve access from your WordPress login. Administrators only; Application Passwords keep working alongside it.', 'karmcp' ); ?>
					</p>

					<?php // OAuth / DCR troubleshooting: Cloudflare (or another bot filter) blocking the client's registration POST. ?>
					<details class="karmcp-conn-troubleshoot">
						<summary><?php esc_html_e( 'Cannot connect over OAuth? ("Couldn\'t register" / Cloudflare)', 'karmcp' ); ?></summary>
						<div class="karmcp-conn-troubleshoot__body">
							<p class="description">
								<?php esc_html_e( 'If a client (e.g. Claude) reports "Couldn\'t register" or the OAuth connection never completes, a CDN or security layer in front of your site - most often Cloudflare\'s bot-fight mode - is usually blocking the client\'s server-side calls to the discovery and dynamic-registration endpoints. Your browser reaches them fine, but the client\'s server does not.', 'karmcp' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Fix it by allow-listing these paths in your CDN/WAF (in Cloudflare, add a WAF skip / Configuration Rule for them), then reconnect:', 'karmcp' ); ?>
							</p>
							<pre class="karmcp-conn-troubleshoot__code"><code>/.well-known/oauth-authorization-server
/.well-known/oauth-protected-resource
/wp-json/karmcp/oauth/*
/wp-json/mcp/karmcp-server</code></pre>
							<p class="description">
								<?php esc_html_e( 'Cannot change the CDN? Use an Application Password instead - it authenticates with a header on every request and is never blocked by bot filters. Pick "Application Password" under "Choose your authentication method" below.', 'karmcp' ); ?>
							</p>
						</div>
					</details>
				</div>

			</div>
		</form>

		<?php // ===== Choose authentication method ===== ?>
		<?php $karmcp_oauth_ok = class_exists( 'KarMCP_OAuth_Server' ) && KarMCP_OAuth_Server::is_enabled(); ?>
		<div class="elementor-mcp-section">
			<h2><?php esc_html_e( 'Choose your authentication method', 'karmcp' ); ?></h2>

			<div class="karmcp-auth-methods" role="radiogroup" aria-label="<?php esc_attr_e( 'Authentication method', 'karmcp' ); ?>">
				<label class="karmcp-auth-card<?php echo $karmcp_oauth_ok ? '' : ' is-disabled'; ?>">
					<input type="radio" name="karmcp_auth_method" value="oauth" <?php checked( $karmcp_oauth_ok ); disabled( ! $karmcp_oauth_ok ); ?> />
					<span class="karmcp-auth-card__title">
						<?php esc_html_e( 'OAuth', 'karmcp' ); ?>
						<?php if ( $karmcp_oauth_ok ) : ?><span class="karmcp-auth-badge"><?php esc_html_e( 'Recommended for your setup', 'karmcp' ); ?></span><?php endif; ?>
					</span>
					<span class="karmcp-auth-card__desc"><?php esc_html_e( 'Sign in through the browser, no password to copy.', 'karmcp' ); ?></span>
					<?php if ( ! $karmcp_oauth_ok ) : ?>
						<span class="karmcp-auth-card__note"><?php esc_html_e( 'Unavailable: OAuth needs HTTPS and the OAuth toggle enabled above. Use an Application Password.', 'karmcp' ); ?></span>
					<?php endif; ?>
				</label>

				<label class="karmcp-auth-card">
					<input type="radio" name="karmcp_auth_method" value="app-password" <?php checked( ! $karmcp_oauth_ok ); ?> />
					<span class="karmcp-auth-card__title"><?php esc_html_e( 'Application password', 'karmcp' ); ?></span>
					<span class="karmcp-auth-card__desc"><?php esc_html_e( 'Generate a password and paste it into the client config.', 'karmcp' ); ?></span>
				</label>
			</div>

			<?php if ( $karmcp_oauth_ok ) : ?>
				<p style="margin:14px 0 0;"><a href="#karmcp-conn-manage-apps" class="karmcp-manage-apps-link"><?php esc_html_e( 'Manage connected apps', 'karmcp' ); ?></a></p>
			<?php endif; ?>
		</div>

		<?php // ===== Connected apps (authorized OAuth clients) ===== ?>
		<?php
		$karmcp_oauth_clients = ( $karmcp_oauth_ok && class_exists( 'KarMCP_OAuth_Store' ) ) ? KarMCP_OAuth_Store::list_authorized_clients() : array();
		if ( ! empty( $karmcp_oauth_clients ) ) :
			?>
			<div class="elementor-mcp-section" id="karmcp-conn-manage-apps" data-authfor="oauth">
				<h2><?php esc_html_e( 'Connected apps', 'karmcp' ); ?></h2>
				<table class="widefat striped" style="max-width:760px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Client', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Connected as', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Active tokens', 'karmcp' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $karmcp_oauth_clients as $karmcp_oc ) :
						$karmcp_oc_user = get_userdata( $karmcp_oc['user_id'] );
						$karmcp_revoke  = wp_nonce_url(
							admin_url( 'admin-post.php?action=' . KarMCP_Admin::ACTION_REVOKE_OAUTH . '&client=' . rawurlencode( $karmcp_oc['client_id'] ) ),
							KarMCP_Admin::ACTION_REVOKE_OAUTH . '_' . $karmcp_oc['client_id']
						);
						?>
						<tr>
							<td>
								<?php echo esc_html( $karmcp_oc['client_name'] ); ?>
								<?php if ( class_exists( 'KarMCP_Gateway_Credential' ) && KarMCP_Gateway_Credential::CLIENT_NAME === $karmcp_oc['client_name'] ) : ?>
									<span class="description" style="display:block;"><?php esc_html_e( 'Used to manage this site from KarMCP Cloud across your other sites.', 'karmcp' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $karmcp_oc_user ? $karmcp_oc_user->user_login : '#' . (int) $karmcp_oc['user_id'] ); ?></td>
							<td><?php echo (int) $karmcp_oc['active_tokens']; ?></td>
							<td><a href="<?php echo esc_url( $karmcp_revoke ); ?>" class="button button-small"><?php esc_html_e( 'Revoke', 'karmcp' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- Connect AI Client -->
		<div class="elementor-mcp-section">
			<h2><?php esc_html_e( 'Connect Your AI Client', 'karmcp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Pick your client below for tailored setup steps. Your choice of authentication method above changes what you paste.', 'karmcp' ); ?>
			</p>

			<div data-authfor="app-password">
			<h3><?php esc_html_e( 'Step 1: Generate Your Credentials', 'karmcp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Pick an administrator and click Generate, a new Application Password is created automatically and every client config below is filled in. No need to visit your profile.', 'karmcp' ); ?>
			</p>

			<?php
			$karmcp_current_user = wp_get_current_user();
			$karmcp_admins       = get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'display_name',
					'order'   => 'ASC',
				)
			);
			// Only offer users the current user is allowed to manage.
			$karmcp_admins = array_values(
				array_filter(
					$karmcp_admins,
					static function ( $karmcp_u ) {
						return current_user_can( 'edit_user', $karmcp_u->ID );
					}
				)
			);
			// Sort the current user to the top, then alphabetically.
			usort(
				$karmcp_admins,
				static function ( $a, $b ) use ( $karmcp_current_user ) {
					if ( (int) $a->ID === (int) $karmcp_current_user->ID ) {
						return -1;
					}
					if ( (int) $b->ID === (int) $karmcp_current_user->ID ) {
						return 1;
					}
					return strcasecmp( (string) $a->display_name, (string) $b->display_name );
				}
			);
			?>

			<div class="elementor-mcp-cred-form">
				<div class="elementor-mcp-cred-field">
					<label for="elementor-mcp-b64-username"><?php esc_html_e( 'Administrator account', 'karmcp' ); ?></label>
					<select id="elementor-mcp-b64-username">
						<?php foreach ( $karmcp_admins as $karmcp_u ) : ?>
							<option
								value="<?php echo esc_attr( (string) $karmcp_u->ID ); ?>"
								data-login="<?php echo esc_attr( $karmcp_u->user_login ); ?>"
								<?php selected( (int) $karmcp_u->ID, (int) $karmcp_current_user->ID ); ?>
							>
								<?php
								echo esc_html(
									(int) $karmcp_u->ID === (int) $karmcp_current_user->ID
										/* translators: %s: username */
										? sprintf( __( '%s (you)', 'karmcp' ), $karmcp_u->user_login )
										: $karmcp_u->user_login
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="button" class="button button-primary elementor-mcp-generate-btn" id="elementor-mcp-generate-b64"><?php esc_html_e( 'Generate Password &amp; Configs', 'karmcp' ); ?></button>

				<p id="elementor-mcp-cred-status" class="description" style="display: none;"></p>

				<div id="elementor-mcp-generated-pw-row" style="display: none;">
					<div class="elementor-mcp-cred-field">
						<label for="elementor-mcp-generated-pw-copy"><?php esc_html_e( 'New Application Password (save it, shown only once)', 'karmcp' ); ?></label>
						<div class="elementor-mcp-auth-result">
							<code id="elementor-mcp-generated-pw"></code>
							<button type="button" class="button elementor-mcp-copy-btn" data-target="elementor-mcp-generated-pw-copy"><?php esc_html_e( 'Copy', 'karmcp' ); ?></button>
							<textarea id="elementor-mcp-generated-pw-copy" class="elementor-mcp-copy-source"></textarea>
						</div>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to profile */
								esc_html__( 'Manage or revoke application passwords under %s.', 'karmcp' ),
								'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Users > Profile', 'karmcp' ) . '</a>'
							);
							?>
						</p>
					</div>
				</div>

				<details class="elementor-mcp-cred-advanced">
					<summary><?php esc_html_e( 'Use an existing Application Password instead', 'karmcp' ); ?></summary>
					<div class="elementor-mcp-cred-field" style="margin-top: 8px;">
						<label for="elementor-mcp-b64-app-password"><?php esc_html_e( 'Application Password', 'karmcp' ); ?></label>
						<input type="text" id="elementor-mcp-b64-app-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'If filled in, this is used as-is and no new password is created.', 'karmcp' ); ?></p>
					</div>
				</details>

				<div id="elementor-mcp-b64-result-row" style="display: none;">
					<div class="elementor-mcp-cred-field">
						<label for="elementor-mcp-b64-result-copy"><?php esc_html_e( 'Authorization header (for direct HTTP clients)', 'karmcp' ); ?></label>
						<div class="elementor-mcp-auth-result">
							<code id="elementor-mcp-b64-result"></code>
							<button type="button" class="button elementor-mcp-copy-btn" data-target="elementor-mcp-b64-result-copy"><?php esc_html_e( 'Copy', 'karmcp' ); ?></button>
							<textarea id="elementor-mcp-b64-result-copy" class="elementor-mcp-copy-source"></textarea>
						</div>
					</div>
				</div>

				<div id="elementor-mcp-authtest-row" style="display: none;">
					<button type="button" class="button" id="elementor-mcp-authtest-btn"><?php esc_html_e( 'Test authentication', 'karmcp' ); ?></button>
					<p id="elementor-mcp-authtest-status" class="description" style="display: none;"></p>

					<div id="elementor-mcp-authtest-fix" class="elementor-mcp-authtest-fix" style="display: none;">
						<p><strong><?php esc_html_e( 'Got 401 Unauthorized? Your server is most likely stripping the Authorization header.', 'karmcp' ); ?></strong></p>
						<p class="description"><?php esc_html_e( 'Common on Apache, Plesk, LiteSpeed and some Azure/IIS stacks: the Authorization header never reaches PHP, so WordPress never sees the Application Password and every MCP "initialize" fails with Unauthorized. Pass the header through to PHP, then re-test:', 'karmcp' ); ?></p>

						<p class="description" style="margin-bottom: 4px;"><strong><?php esc_html_e( 'Apache / Plesk / LiteSpeed', 'karmcp' ); ?></strong>: <?php esc_html_e( 'add to .htaccess, above the # BEGIN WordPress block:', 'karmcp' ); ?></p>
						<pre class="elementor-mcp-authtest-snippet">&lt;IfModule mod_rewrite.c&gt;
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
&lt;/IfModule&gt;
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</pre>

						<p class="description" style="margin-bottom: 4px;"><strong><?php esc_html_e( 'Nginx', 'karmcp' ); ?></strong>: <?php esc_html_e( 'add inside the PHP location block, then reload nginx:', 'karmcp' ); ?></p>
						<pre class="elementor-mcp-authtest-snippet">fastcgi_param HTTP_AUTHORIZATION $http_authorization;</pre>

						<p class="description">
							<?php
							printf(
								/* translators: %s: command-line example */
								esc_html__( 'You can also confirm from a terminal: %s, a 200 response means auth works; 401 means the header is being stripped.', 'karmcp' ),
								'<code>curl -u "USER:APP PASSWORD" ' . esc_html( esc_url_raw( rest_url( 'wp/v2/users/me' ) ) ) . '</code>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
			</div><?php // /[data-authfor="app-password"] (Step 1 generate) ?>

			<div id="elementor-mcp-client-picker">
				<h3><?php esc_html_e( 'Connect Your AI Client', 'karmcp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Pick the app you will connect from, the setup steps below are tailored to it.', 'karmcp' ); ?></p>

				<div class="elementor-mcp-client-grid" role="tablist" aria-label="<?php esc_attr_e( 'AI client', 'karmcp' ); ?>">
					<?php foreach ( KarMCP_Admin::connection_clients() as $karmcp_client ) : ?>
						<button
							type="button"
							class="elementor-mcp-client-card"
							role="tab"
							aria-selected="false"
							data-client="<?php echo esc_attr( $karmcp_client['id'] ); ?>"
						>
							<?php if ( ! empty( $karmcp_client['image'] ) ) : ?>
								<img
									class="elementor-mcp-client-card-logo"
									src="<?php echo esc_url( KARMCP_URL . 'assets/img/' . $karmcp_client['image'] ); ?>"
									alt=""
									aria-hidden="true"
								/>
							<?php else : ?>
								<span class="dashicons dashicons-<?php echo esc_attr( $karmcp_client['icon'] ); ?>" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="elementor-mcp-client-card-label"><?php echo esc_html( $karmcp_client['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<h3 id="elementor-mcp-connect-heading" style="display: none;">
					<?php esc_html_e( 'Step 3: Connect', 'karmcp' ); ?> <span id="elementor-mcp-connect-client-name"></span>
				</h3>

				<?php // JS renders the selected client's option blocks here. ?>
				<div id="elementor-mcp-client-options"></div>

				<?php // Hidden form used to POST the .mcpb download (Claude Desktop). ?>
				<form
					id="elementor-mcp-mcpb-form"
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					style="display: none;"
				>
					<input type="hidden" name="action" value="karmcp_download_mcpb" />
					<input type="hidden" name="_karmcp_nonce" value="<?php echo esc_attr( wp_create_nonce( KarMCP_Admin::NONCE_DOWNLOAD_MCPB ) ); ?>" />
					<input type="hidden" name="user_id" id="elementor-mcp-mcpb-user-id" value="" />
					<input type="hidden" name="app_password" id="elementor-mcp-mcpb-app-password" value="" />
				</form>
			</div>
		</div>

	</div><?php // /#karmcp-conn-main ?>

	<?php // ===== Sub-tab: Cloud ===== ?>
	<?php if ( class_exists( 'KarMCP_Cloud_Module' ) && KarMCP_Cloud_Module::is_enabled() ) : ?>
	<div class="elementor-mcp-tabpanel" id="karmcp-conn-cloud" role="tabpanel" data-tab="conn-cloud">
		<?php // ===== KarMCP Cloud connect/disconnect ===== ?>
		<?php if ( class_exists( 'KarMCP_Cloud_Module' ) && KarMCP_Cloud_Module::is_enabled() ) :
			$karmcp_cloud_status = KarMCP_Cloud::status(); ?>
			<div class="elementor-mcp-section">
				<h2><?php esc_html_e( 'KarMCP Cloud', 'karmcp' ); ?></h2>
				<div class="karmcp-conn-cards">
					<div class="karmcp-conn-card">
						<h2 class="karmcp-conn-card-title"><?php esc_html_e( 'Cloud account', 'karmcp' ); ?></h2>
						<?php if ( $karmcp_cloud_status['connected'] ) : ?>
							<p class="elementor-mcp-activate-note">
								<?php esc_html_e( 'This site is connected to your KarMCP Cloud account.', 'karmcp' ); ?>
								<?php if ( ! $karmcp_cloud_status['healthy'] ) : ?>
									<strong><?php esc_html_e( 'Reconnect needed.', 'karmcp' ); ?></strong>
								<?php endif; ?>
							</p>
							<p>
								<a href="<?php echo esc_url( KarMCP_Cloud_Connect::disconnect_url() ); ?>" class="button"><?php esc_html_e( 'Disconnect', 'karmcp' ); ?></a>
							</p>
							<?php if ( ! $karmcp_cloud_status['healthy'] ) :
								$karmcp_connect_label = __( 'Reconnect', 'karmcp' );
								require KARMCP_DIR . 'includes/admin/views/partials/cloud-connect-form.php';
							endif; ?>
						<?php else : ?>
							<p class="elementor-mcp-activate-note"><?php esc_html_e( 'Connect this site to your KarMCP Cloud account to back up and sync your work.', 'karmcp' ); ?></p>
							<?php
							$karmcp_connect_label = __( 'Connect to KarMCP Cloud', 'karmcp' );
							require KARMCP_DIR . 'includes/admin/views/partials/cloud-connect-form.php';
							?>
						<?php endif; ?>
					</div>

					<?php // ===== Settings sync (paid Cloud feature) ===== ?>
					<?php if ( $karmcp_cloud_status['connected'] ) : ?>
						<?php
						$karmcp_sync_entitled = class_exists( 'KarMCP_Settings_Sync' ) && KarMCP_Settings_Sync::entitled();
						$karmcp_synced        = isset( $_GET['synced'] ) ? sanitize_key( wp_unslash( $_GET['synced'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						?>
						<div class="karmcp-conn-card">
							<h2 class="karmcp-conn-card-title"><?php esc_html_e( 'Settings sync', 'karmcp' ); ?></h2>
							<?php if ( 'push' === $karmcp_synced ) : ?>
								<div class="notice notice-success inline"><p><?php esc_html_e( 'Settings pushed to the cloud.', 'karmcp' ); ?></p></div>
							<?php elseif ( 'pull' === $karmcp_synced ) : ?>
								<div class="notice notice-success inline"><p><?php esc_html_e( 'Settings pulled from the cloud and applied.', 'karmcp' ); ?></p></div>
							<?php elseif ( 'err' === $karmcp_synced ) : ?>
								<div class="notice notice-error inline"><p><?php esc_html_e( 'Settings sync failed. Make sure your Cloud plan includes settings sync.', 'karmcp' ); ?></p></div>
							<?php endif; ?>

							<?php if ( $karmcp_sync_entitled ) : ?>
								<p class="elementor-mcp-activate-note"><?php esc_html_e( 'Copy your KarMCP settings between connected sites: tool toggles, active modules, compact-tool mode, and behavior preferences. Secrets, API keys, and this site\'s connection are never synced.', 'karmcp' ); ?></p>
								<p>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<input type="hidden" name="action" value="karmcp_settings_push" />
										<?php wp_nonce_field( 'karmcp_settings_sync' ); ?>
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Push settings to cloud', 'karmcp' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Pull settings from the cloud and overwrite this site\'s KarMCP settings?', 'karmcp' ) ); ?>');">
										<input type="hidden" name="action" value="karmcp_settings_pull" />
										<?php wp_nonce_field( 'karmcp_settings_sync' ); ?>
										<button type="submit" class="button"><?php esc_html_e( 'Pull settings from cloud', 'karmcp' ); ?></button>
									</form>
								</p>
							<?php else : ?>
								<p class="elementor-mcp-activate-note"><?php esc_html_e( 'Sync your KarMCP settings across all your sites. This is a paid KarMCP Cloud feature.', 'karmcp' ); ?></p>
								<p><a href="<?php echo esc_url( trailingslashit( KarMCP_Cloud::base_url() ) . 'account/billing' ); ?>" class="button" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade your Cloud plan', 'karmcp' ); ?></a></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div><?php // /#karmcp-conn-cloud ?>
	<?php endif; ?>

	<?php // ===== Sub-tab: 3rd Party Services ===== ?>
	<div class="elementor-mcp-tabpanel" id="karmcp-conn-services" role="tabpanel" data-tab="conn-services">
		<div class="elementor-mcp-section">
			<h2><?php esc_html_e( '3rd Party Services', 'karmcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Connect external services KarMCP tools can use. Stock-image providers power the search-images / add-stock-image tools, add at least one free key; the tools use the first connected provider unless a specific one is requested.', 'karmcp' ); ?></p>

			<form method="post" action="options.php" id="karmcp-conn-services-form" class="karmcp-services-form">
				<?php settings_fields( KarMCP_Admin::SETTINGS_GROUP_SERVICES ); ?>

				<div class="karmcp-services-grid">
					<?php
					$karmcp_stock_providers = array(
						array( 'label' => 'Unsplash', 'option' => KarMCP_Unsplash_Client::OPTION, 'const' => 'KARMCP_UNSPLASH_ACCESS_KEY', 'url' => 'https://unsplash.com/developers' ),
						array( 'label' => 'Pexels', 'option' => KarMCP_Pexels_Client::OPTION, 'const' => 'KARMCP_PEXELS_API_KEY', 'url' => 'https://www.pexels.com/api/' ),
						array( 'label' => 'Pixabay', 'option' => KarMCP_Pixabay_Client::OPTION, 'const' => 'KARMCP_PIXABAY_API_KEY', 'url' => 'https://pixabay.com/api/docs/' ),
					);
					foreach ( $karmcp_stock_providers as $karmcp_sp ) :
						$karmcp_sp_const = defined( $karmcp_sp['const'] );
						// A key is on file when the option is non-empty and not
						// constant-overridden. The value itself is NEVER rendered.
						$karmcp_sp_saved = ! $karmcp_sp_const && '' !== (string) get_option( $karmcp_sp['option'], '' );
						if ( $karmcp_sp_const ) {
							/* translators: %s: PHP constant name. */
							$karmcp_sp_placeholder = sprintf( __( 'Set via the %s constant', 'karmcp' ), $karmcp_sp['const'] );
						} elseif ( $karmcp_sp_saved ) {
							$karmcp_sp_placeholder = __( '•••••••••••••• saved, leave blank to keep', 'karmcp' );
						} else {
							$karmcp_sp_placeholder = __( 'Paste your API key', 'karmcp' );
						}
						?>
						<div class="karmcp-service-field">
							<div class="karmcp-service-field-head">
								<label for="karmcp-<?php echo esc_attr( $karmcp_sp['option'] ); ?>">
									<?php
									/* translators: %s: provider name (Unsplash / Pexels / Pixabay). */
									echo esc_html( sprintf( __( '%s API key', 'karmcp' ), $karmcp_sp['label'] ) );
									?>
									<?php if ( $karmcp_sp_saved ) : ?>
										<span class="karmcp-service-badge"><?php esc_html_e( 'saved', 'karmcp' ); ?></span>
									<?php endif; ?>
								</label>
								<a href="<?php echo esc_url( $karmcp_sp['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get a free key', 'karmcp' ); ?> &rarr;</a>
							</div>
							<input
								type="password"
								id="karmcp-<?php echo esc_attr( $karmcp_sp['option'] ); ?>"
								name="<?php echo esc_attr( $karmcp_sp['option'] ); ?>"
								value=""
								placeholder="<?php echo esc_attr( $karmcp_sp_placeholder ); ?>"
								autocomplete="off"
								autocapitalize="off"
								spellcheck="false"
								<?php disabled( $karmcp_sp_const ); ?>
							/>
							<?php if ( $karmcp_sp_saved ) : ?>
								<label class="karmcp-service-clear">
									<input type="checkbox" name="<?php echo esc_attr( $karmcp_sp['option'] . '__clear' ); ?>" value="1" />
									<?php esc_html_e( 'Remove saved key', 'karmcp' ); ?>
								</label>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php $karmcp_wpcli_const = defined( 'KARMCP_WPCLI_COMMAND' ); ?>
				<div class="karmcp-service-field" style="margin-top:22px;padding-top:20px;border-top:1px solid var(--mcp-line,#e5e7eb);">
					<div class="karmcp-service-field-head">
						<label for="karmcp-wpcli-command">
							<?php esc_html_e( 'WP-CLI base command', 'karmcp' ); ?>
							<?php if ( $karmcp_wpcli_const ) : ?><span class="karmcp-service-badge"><?php esc_html_e( 'constant', 'karmcp' ); ?></span><?php endif; ?>
						</label>
					</div>
					<input
						type="text"
						id="karmcp-wpcli-command"
						name="karmcp_wpcli_command"
						value="<?php echo esc_attr( $karmcp_wpcli_const ? '' : (string) get_option( 'karmcp_wpcli_command', '' ) ); ?>"
						<?php /* translators: %s: the PHP constant name, e.g. KARMCP_WPCLI_COMMAND. */ ?>
						placeholder="<?php echo esc_attr( $karmcp_wpcli_const ? sprintf( __( 'Set via the %s constant', 'karmcp' ), 'KARMCP_WPCLI_COMMAND' ) : 'wp, or   php /path/to/wp-cli.phar' ); ?>"
						autocomplete="off" spellcheck="false"
						<?php disabled( $karmcp_wpcli_const ); ?>
					/>
					<p class="description"><?php esc_html_e( 'The wp launcher used by the WP-CLI tools over HTTP and for background jobs (e.g. "wp", or "php /path/to/wp-cli.phar"). Leave blank if you connect only over the WP-CLI stdio transport, commands then run in-process, no binary needed.', 'karmcp' ); ?></p>
				</div>
			</form>
		</div>
	</div><?php // /#karmcp-conn-services ?>

</div>
