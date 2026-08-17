<?php
/**
 * Tools tab view for the KarMCP admin settings page.
 *
 * Displays all MCP tools grouped by category with toggle switches.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var KarMCP_Admin $this */
$karmcp_all_tools     = $this->get_all_tools();
$karmcp_disabled      = get_option( KarMCP_Admin::OPTION_DISABLED_TOOLS, array() );
$karmcp_disabled      = is_array( $karmcp_disabled ) ? $karmcp_disabled : array();
$karmcp_enabled_count = $this->get_enabled_tool_count();
$karmcp_total_count   = $this->get_total_tool_count();
$karmcp_compact_mode  = '1' === (string) get_option( KarMCP_Plugin::OPTION_DISPATCHER_MODE, '0' );

$karmcp_tabs               = KarMCP_Admin::platform_tabs();
$karmcp_buckets            = KarMCP_Admin::partition_by_platform( $karmcp_all_tools );
$karmcp_elementor_active   = KarMCP_Bootstrap::elementor_active();

/**
 * Per-tab enabled/total counts, computed from the stored disabled set.
 */
$karmcp_tab_counts = array();
foreach ( $karmcp_buckets as $karmcp_tab_id => $karmcp_tab_cats ) {
	$karmcp_t_total   = 0;
	$karmcp_t_enabled = 0;
	foreach ( $karmcp_tab_cats as $karmcp_cat ) {
		foreach ( $karmcp_cat['tools'] as $karmcp_s => $karmcp_unused ) {
			$karmcp_t_total++;
			if ( ! in_array( $karmcp_s, $karmcp_disabled, true ) ) {
				$karmcp_t_enabled++;
			}
		}
	}
	$karmcp_tab_counts[ $karmcp_tab_id ] = array( 'enabled' => $karmcp_t_enabled, 'total' => $karmcp_t_total );
}

// Human-readable labels for badge slugs. "pro" means the KarMCP Pro
// license; "elementor-pro" means the tool needs Elementor Pro (a different
// product) — kept visually distinct so the two aren't confused.
$karmcp_badge_labels = array(
	'pro'           => __( 'Pro', 'karmcp' ),
	'elementor-pro' => __( 'Elementor Pro', 'karmcp' ),
	'read-only'     => __( 'read-only', 'karmcp' ),
	'free'          => __( 'Free', 'karmcp' ),
	'destructive'   => __( 'destructive', 'karmcp' ),
);
?>

<form method="post" action="options.php" id="karmcp-tools-form">
	<?php settings_fields( KarMCP_Admin::SETTINGS_GROUP ); ?>

	<div class="karmcp-mode-cards">
	<div class="karmcp-low-mode-card">
		<label class="karmcp-low-mode-toggle">
			<input type="hidden" name="<?php echo esc_attr( KarMCP_Plugin::OPTION_DISPATCHER_MODE ); ?>" value="0" />
			<input
				type="checkbox"
				name="<?php echo esc_attr( KarMCP_Plugin::OPTION_DISPATCHER_MODE ); ?>"
				value="1"
				<?php checked( $karmcp_compact_mode ); ?>
			/>
			<span class="karmcp-toggle" aria-hidden="true">
				<span class="karmcp-toggle-track"></span>
			</span>
			<span class="karmcp-low-mode-info">
				<span class="karmcp-low-mode-title">
					<?php esc_html_e( 'Compact tool mode', 'karmcp' ); ?>
				</span>
				<span class="karmcp-low-mode-desc">
					<?php esc_html_e( 'Exposes 3 dispatcher tools (list-tools, get-tool-schema, call-tool) instead of every individual tool, so MCP clients that cap the tool count can still reach the whole surface. Your per-tool toggles below stay in effect, call-tool refuses any tool you disable. Reconnect your client after changing this.', 'karmcp' ); ?>
				</span>
			</span>
		</label>
	</div>

	<?php if ( class_exists( 'KarMCP_Themer_Module' ) && KarMCP_Themer_Module::is_enabled() ) : ?>
		<?php $karmcp_themer_php_on = '1' === (string) get_option( KarMCP_Themer_PHP::OPTION_ENABLED, '0' ); ?>
		<div class="karmcp-low-mode-card">
			<label class="karmcp-low-mode-toggle">
				<input type="hidden" name="<?php echo esc_attr( KarMCP_Themer_PHP::OPTION_ENABLED ); ?>" value="0" />
				<input
					type="checkbox"
					name="<?php echo esc_attr( KarMCP_Themer_PHP::OPTION_ENABLED ); ?>"
					value="1"
					<?php checked( $karmcp_themer_php_on ); ?>
				/>
				<span class="karmcp-toggle" aria-hidden="true">
					<span class="karmcp-toggle-track"></span>
				</span>
				<span class="karmcp-low-mode-info">
					<span class="karmcp-low-mode-title">
						<?php esc_html_e( 'Themer PHP Templates (advanced)', 'karmcp' ); ?>
					</span>
					<span class="karmcp-low-mode-desc">
						<?php esc_html_e( 'Lets AI agents author raw PHP region templates (header/footer/single/archive) into a validated sandbox, which you then select on a template to take over its render. Off by default, enabling it also reveals the “PHP Templates” screen under the KarMCP Themer menu and the per-template selector. The 5 MCP tools below still ship disabled until you enable them.', 'karmcp' ); ?>
					</span>
				</span>
			</label>
		</div>
	<?php endif; ?>
	</div><!-- .karmcp-mode-cards -->

	<?php if ( $karmcp_compact_mode ) : ?>
		<div class="karmcp-compact-banner">
			<p>
				<?php esc_html_e( 'Compact tool mode is active, your client sees only the 3 dispatcher tools (list-tools, get-tool-schema, call-tool). The per-tool toggles below still control what call-tool is allowed to run.', 'karmcp' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="karmcp-bulk-actions">
		<p class="karmcp-tools-summary">
			<?php
			printf(
				/* translators: %1$s: opening strong tag, %2$d: enabled count, %3$d: total count, %4$s: closing strong tag */
				esc_html__( '%1$s%2$d of %3$d%4$s tools enabled.', 'karmcp' ),
				'<strong>',
				(int) $karmcp_enabled_count,
				(int) $karmcp_total_count,
				'</strong>'
			);
			?>
		</p>
		<button type="button" class="button karmcp-enable-all"><?php esc_html_e( 'Enable All', 'karmcp' ); ?></button>
		<button type="button" class="button karmcp-disable-all"><?php esc_html_e( 'Disable All', 'karmcp' ); ?></button>
		<button type="submit" class="button button-primary karmcp-bulk-save"><?php esc_html_e( 'Save Changes', 'karmcp' ); ?></button>
	</div>

	<div class="karmcp-subtabs" role="tablist" aria-label="<?php esc_attr_e( 'Tool platforms', 'karmcp' ); ?>">
		<?php $karmcp_first = true; ?>
		<?php foreach ( $karmcp_tabs as $karmcp_tab_id => $karmcp_tab_label ) : ?>
			<button
				type="button"
				class="karmcp-subtab <?php echo esc_attr( $karmcp_first ? 'is-active' : '' ); ?>"
				role="tab"
				data-tab="<?php echo esc_attr( $karmcp_tab_id ); ?>"
				aria-selected="<?php echo esc_attr( $karmcp_first ? 'true' : 'false' ); ?>"
				aria-controls="karmcp-tabpanel-<?php echo esc_attr( $karmcp_tab_id ); ?>"
			>
				<span class="karmcp-subtab-label"><?php echo esc_html( $karmcp_tab_label ); ?></span>
				<span class="karmcp-subtab-count">
					<?php
					printf(
						/* translators: %1$d: enabled, %2$d: total */
						esc_html__( '%1$d / %2$d', 'karmcp' ),
						(int) $karmcp_tab_counts[ $karmcp_tab_id ]['enabled'],
						(int) $karmcp_tab_counts[ $karmcp_tab_id ]['total']
					);
					?>
				</span>
			</button>
			<?php $karmcp_first = false; ?>
		<?php endforeach; ?>
	</div>

	<?php $karmcp_first_panel = true; ?>
	<?php foreach ( $karmcp_buckets as $karmcp_tab_id => $karmcp_tab_cats ) : ?>
		<div
			class="karmcp-tabpanel <?php echo esc_attr( $karmcp_first_panel ? 'is-active' : '' ); ?>"
			id="karmcp-tabpanel-<?php echo esc_attr( $karmcp_tab_id ); ?>"
			role="tabpanel"
			data-tab="<?php echo esc_attr( $karmcp_tab_id ); ?>"
		>
			<?php if ( 'elementor' === $karmcp_tab_id && ! $karmcp_elementor_active ) : ?>
			<div class="notice notice-warning inline karmcp-elementor-inactive">
				<p>
					<?php esc_html_e( 'Elementor is not active. Install and activate Elementor to use these tools.', 'karmcp' ); ?>
					<a href="<?php echo esc_url( self_admin_url( 'plugin-install.php?s=Elementor&tab=search&type=term' ) ); ?>">
						<?php esc_html_e( 'Install Elementor', 'karmcp' ); ?>
					</a>
				</p>
			</div>
			<?php endif; ?>
			<?php
			// Plugin-integration explainer: rendered once at the very top of the
			// tab, above every category section. The note text lives on the
			// category data (first category that carries one wins).
			$karmcp_tab_note = '';
			foreach ( $karmcp_tab_cats as $karmcp_note_cat ) {
				if ( ! empty( $karmcp_note_cat['note'] ) ) {
					$karmcp_tab_note = (string) $karmcp_note_cat['note'];
					break;
				}
			}
			?>
			<?php if ( '' !== $karmcp_tab_note ) : ?>
				<p class="karmcp-cat-note karmcp-tab-note">
					<span class="karmcp-cat-note-icon" aria-hidden="true">
						<svg viewBox="0 0 20 20" width="15" height="15"><path fill="currentColor" d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 12H9v-4h2v4zm0-6H9V6h2v2z"/></svg>
					</span>
					<span><?php echo esc_html( $karmcp_tab_note ); ?></span>
				</p>
			<?php endif; ?>
			<?php
			// Render one category (the "plugin card"): header + toggle grid.
			// Defined once and reused for both ungrouped categories (inline) and
			// grouped ones (nested under a group heading on the Plugins tab).
			$karmcp_render_category = function ( $karmcp_category_id, $karmcp_category ) use ( $karmcp_disabled, $karmcp_elementor_active, $karmcp_badge_labels ) {
				?>
				<div class="karmcp-category <?php echo esc_attr( ! empty( $karmcp_category['danger'] ) ? 'is-danger' : '' ); ?>" data-category="<?php echo esc_attr( $karmcp_category_id ); ?>">
					<?php
					$karmcp_cat_total   = count( $karmcp_category['tools'] );
					$karmcp_cat_enabled = 0;
					foreach ( $karmcp_category['tools'] as $karmcp_slug => $karmcp_tool ) {
						if ( ! in_array( $karmcp_slug, $karmcp_disabled, true ) ) {
							$karmcp_cat_enabled++;
						}
					}
					$karmcp_grid_id       = 'karmcp-cat-' . $karmcp_category_id;
					$karmcp_cat_unavailable = ( ! $karmcp_elementor_active && KarMCP_Admin::is_elementor_category( $karmcp_category ) );
					?>
					<div class="karmcp-category-header">
						<button
							type="button"
							class="karmcp-category-toggle"
							aria-expanded="true"
							aria-controls="<?php echo esc_attr( $karmcp_grid_id ); ?>"
						>
							<span class="karmcp-category-chevron" aria-hidden="true">
								<svg viewBox="0 0 20 20" width="14" height="14"><path d="M6 8l4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</span>
							<span class="karmcp-category-title"><?php echo esc_html( $karmcp_category['label'] ); ?></span>
							<?php if ( ! empty( $karmcp_category['pro'] ) ) : ?>
								<span class="karmcp-badge karmcp-badge--pro"><?php esc_html_e( 'Pro', 'karmcp' ); ?></span>
							<?php endif; ?>
							<span class="karmcp-category-count">
								<?php
								printf(
									/* translators: %1$d: enabled, %2$d: total */
									esc_html__( '%1$d / %2$d', 'karmcp' ),
									(int) $karmcp_cat_enabled,
									(int) $karmcp_cat_total
								);
								?>
							</span>
						</button>
						<span class="karmcp-cat-toggle-group" role="group" aria-label="<?php esc_attr_e( 'Toggle all tools in this section', 'karmcp' ); ?>">
							<button type="button" class="karmcp-cat-btn karmcp-cat-enable-all"><?php esc_html_e( 'All', 'karmcp' ); ?></button>
							<button type="button" class="karmcp-cat-btn karmcp-cat-disable-all"><?php esc_html_e( 'None', 'karmcp' ); ?></button>
						</span>
					</div>

					<?php
					// Dispatcher-style categories (plugin integrations) lay their
					// cards out two-up with operation pills; the explanatory note
					// is rendered once at the top of the tab (see above).
					$karmcp_first_tool = reset( $karmcp_category['tools'] );
					$karmcp_has_ops    = ! empty( $karmcp_first_tool['operations'] );
					?>
					<?php
					// Section-level notice (e.g. the Astra + Spectra heads-up about
					// Spectra's File Generation setting). Only rendered when the category
					// carries one, so it appears only when actionable.
					if ( ! empty( $karmcp_category['notice'] ) && ! empty( $karmcp_category['notice']['message'] ) ) :
						$karmcp_notice_type = $karmcp_category['notice']['type'] ?? 'info';
						?>
					<div class="karmcp-cat-notice is-<?php echo esc_attr( $karmcp_notice_type ); ?>">
						<span class="karmcp-cat-notice-icon" aria-hidden="true">
							<svg viewBox="0 0 20 20" width="16" height="16"><path fill="currentColor" d="M10 1.6a8.4 8.4 0 100 16.8 8.4 8.4 0 000-16.8zM11 14H9v-2h2v2zm0-4H9V5h2v5z"/></svg>
						</span>
						<span><?php echo esc_html( $karmcp_category['notice']['message'] ); ?></span>
					</div>
					<?php endif; ?>
					<div class="karmcp-tools-grid <?php echo esc_attr( $karmcp_has_ops ? 'is-two-up' : '' ); ?>" id="<?php echo esc_attr( $karmcp_grid_id ); ?>">
						<?php foreach ( $karmcp_category['tools'] as $karmcp_slug => $karmcp_tool ) : ?>
							<?php
							$karmcp_is_enabled = ! in_array( $karmcp_slug, $karmcp_disabled, true );
							// A tool is unavailable when its whole platform is off (Elementor
							// inactive) OR the tool carries an explicit availability flag that
							// is false (e.g. the Astra tools when Astra is not the active
							// theme, the Spectra tools when the Spectra plugin is inactive).
							$karmcp_tool_unavailable = $karmcp_cat_unavailable
								|| ( array_key_exists( 'available', $karmcp_tool ) && ! $karmcp_tool['available'] );
							?>
							<label class="karmcp-tool-card <?php echo esc_attr( ( $karmcp_is_enabled ? 'is-enabled' : 'is-disabled' ) . ( $karmcp_tool_unavailable ? ' is-unavailable' : '' ) ); ?>">
								<input
									type="checkbox"
									name="<?php echo esc_attr( KarMCP_Admin::OPTION_DISABLED_TOOLS ); ?>[]"
									value="<?php echo esc_attr( $karmcp_slug ); ?>"
									data-stored-enabled="<?php echo esc_attr( in_array( $karmcp_slug, $karmcp_disabled, true ) ? '0' : '1' ); ?>"
									<?php checked( $karmcp_is_enabled ); ?>
									<?php disabled( $karmcp_tool_unavailable ); ?>
								/>
								<span class="karmcp-toggle" aria-hidden="true">
									<span class="karmcp-toggle-track"></span>
								</span>
								<span class="karmcp-tool-info">
									<span class="karmcp-tool-name">
										<?php echo esc_html( $karmcp_tool['label'] ); ?>
										<?php foreach ( $karmcp_tool['badges'] as $karmcp_badge ) : ?>
											<span class="karmcp-badge karmcp-badge--<?php echo esc_attr( $karmcp_badge ); ?>">
												<?php echo esc_html( $karmcp_badge_labels[ $karmcp_badge ] ?? $karmcp_badge ); ?>
											</span>
										<?php endforeach; ?>
									</span>
									<span class="karmcp-tool-desc"><?php echo esc_html( $karmcp_tool['description'] ); ?></span>
									<?php if ( $karmcp_tool_unavailable && ! empty( $karmcp_tool['unavailable_note'] ) ) : ?>
										<span class="karmcp-tool-unavailable-note"><?php echo esc_html( $karmcp_tool['unavailable_note'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $karmcp_tool['operations'] ) ) : ?>
										<span class="karmcp-tool-ops">
											<span class="karmcp-tool-ops-label">
												<?php
												printf(
													/* translators: %d: number of operations */
													esc_html( _n( '%d operation', '%d operations', count( $karmcp_tool['operations'] ), 'karmcp' ) ),
													count( $karmcp_tool['operations'] )
												);
												?>
											</span>
											<span class="karmcp-op-pills">
												<?php foreach ( $karmcp_tool['operations'] as $karmcp_op ) : ?>
													<span class="karmcp-op-pill"><?php echo esc_html( $karmcp_op ); ?></span>
												<?php endforeach; ?>
											</span>
										</span>
									<?php endif; ?>
									<code class="karmcp-tool-slug"><?php echo esc_html( $karmcp_slug ); ?></code>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
			};

			// Partition this tab's categories into plugin groups. Categories that
			// carry a 'group' key (the Plugins-tab integrations) cluster under a
			// group heading; everything else renders inline, ungrouped.
			$karmcp_group_defs = KarMCP_Admin::plugin_groups();
			$karmcp_grouped    = array();
			$karmcp_ungrouped  = array();
			foreach ( $karmcp_tab_cats as $karmcp_cid => $karmcp_cat ) {
				if ( ! empty( $karmcp_cat['group'] ) && isset( $karmcp_group_defs[ $karmcp_cat['group'] ] ) ) {
					$karmcp_grouped[ $karmcp_cat['group'] ][ $karmcp_cid ] = $karmcp_cat;
				} else {
					$karmcp_ungrouped[ $karmcp_cid ] = $karmcp_cat;
				}
			}

			// Ungrouped categories first (unchanged layout for non-Plugins tabs).
			foreach ( $karmcp_ungrouped as $karmcp_category_id => $karmcp_category ) {
				$karmcp_render_category( $karmcp_category_id, $karmcp_category );
			}

			// Then each non-empty group, in registry order.
			foreach ( $karmcp_group_defs as $karmcp_gid => $karmcp_gdef ) :
				if ( empty( $karmcp_grouped[ $karmcp_gid ] ) ) {
					continue;
				}
				$karmcp_g_total   = 0;
				$karmcp_g_enabled = 0;
				foreach ( $karmcp_grouped[ $karmcp_gid ] as $karmcp_gcat ) {
					foreach ( $karmcp_gcat['tools'] as $karmcp_gslug => $karmcp_gunused ) {
						$karmcp_g_total++;
						if ( ! in_array( $karmcp_gslug, $karmcp_disabled, true ) ) {
							$karmcp_g_enabled++;
						}
					}
				}
				?>
				<div class="karmcp-plugin-group" data-group="<?php echo esc_attr( $karmcp_gid ); ?>">
					<div class="karmcp-plugin-group-head">
						<span class="karmcp-plugin-group-title"><?php echo esc_html( $karmcp_gdef['label'] ); ?></span>
						<?php if ( ! empty( $karmcp_gdef['desc'] ) ) : ?>
							<span class="karmcp-plugin-group-desc"><?php echo esc_html( $karmcp_gdef['desc'] ); ?></span>
						<?php endif; ?>
						<span class="karmcp-plugin-group-count">
							<?php
							printf(
								/* translators: %1$d: enabled, %2$d: total */
								esc_html__( '%1$d / %2$d', 'karmcp' ),
								(int) $karmcp_g_enabled,
								(int) $karmcp_g_total
							);
							?>
						</span>
					</div>
					<div class="karmcp-plugin-group-body">
						<?php
						foreach ( $karmcp_grouped[ $karmcp_gid ] as $karmcp_category_id => $karmcp_category ) {
							$karmcp_render_category( $karmcp_category_id, $karmcp_category );
						}
						?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php $karmcp_first_panel = false; ?>
	<?php endforeach; ?>

	<?php submit_button( __( 'Save Changes', 'karmcp' ) ); ?>
</form>
