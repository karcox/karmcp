<?php
/**
 * Modules tab. The main form holds one simple card per module (toggle + tier
 * badge + description + a "Show Settings" button when active). Each module's
 * knobs live in a separate overlay with its own form + Save button (its own
 * settings group), rendered after the main form so forms never nest.
 *
 * @package KarMCP
 */

// Included from inside a method (KarMCP_Admin::render_page), so everything here
// is a local, not a global. The prefix sniff can't see the include site.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$registry = class_exists( 'KarMCP_Modules_Registry' ) ? KarMCP_Modules_Registry::instance() : null;
$modules  = $registry ? $registry->all() : array();
?>
<div class="karmcp-modules-tab">
	<h2><?php esc_html_e( 'Modules', 'karmcp' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Turn substantial features on or off. Each module is self-contained; some are free and some are Pro.', 'karmcp' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( KarMCP_Admin::SETTINGS_GROUP_MODULES ); ?>

		<div class="elementor-mcp-tools-grid karmcp-modules-grid">
			<?php
			foreach ( $modules as $module ) :
				$active    = $module->is_active();
				$available = $module->is_available();
				$is_pro    = 'pro' === $module->tier();
				// NOTE: deliberately no is-enabled/is-disabled here. Those classes
				// drive the Tools-grid toggle visuals via JS; on this page there's
				// no such JS, so a stale is-enabled would pin the switch "on" even
				// after unchecking. The switch is driven purely by :checked (the
				// .karmcp-switch CSS), which reflects clicks live.
				$state = $available ? '' : 'is-unavailable';
				?>
				<div class="elementor-mcp-tool-card karmcp-module-card <?php echo esc_attr( $state ); ?>">
					<label class="karmcp-module-head karmcp-switch">
						<input
							type="checkbox"
							name="<?php echo esc_attr( KarMCP_Module::OPTION_ACTIVE ); ?>[]"
							value="<?php echo esc_attr( $module->id() ); ?>"
							<?php checked( $active ); ?>
							<?php disabled( ! $available ); ?>
						/>
						<span class="elementor-mcp-toggle" aria-hidden="true"><span class="elementor-mcp-toggle-track"></span></span>
						<span class="elementor-mcp-tool-info">
							<span class="elementor-mcp-tool-name">
								<?php echo esc_html( $module->title() ); ?>
								<span class="elementor-mcp-badge elementor-mcp-badge--<?php echo $is_pro ? 'pro' : 'free'; ?>">
									<?php echo $is_pro ? esc_html__( 'Pro', 'karmcp' ) : esc_html__( 'Free', 'karmcp' ); ?>
								</span>
							</span>
							<span class="elementor-mcp-tool-desc"><?php echo esc_html( $module->description() ); ?></span>
						</span>
					</label>

					<?php if ( ! $available ) : ?>
						<p class="karmcp-module-unavailable">
							<?php
							if ( KarMCP_Image_Optimization_Module::ID === $module->id() ) {
								esc_html_e( 'Not available on this server (WebP support is missing in the image editor).', 'karmcp' );
							} else {
								// There is no licensing in this build, so "buy Pro" would be
								// selling something that does not exist. Say what is true:
								// the feature is not part of this version.
								esc_html_e( 'Not included in this version.', 'karmcp' );
							}
							?>
						</p>
					<?php elseif ( $active && $module->has_settings() ) : ?>
						<div class="karmcp-module-card-actions">
							<button type="button" class="button karmcp-module-settings-btn" data-modal="karmcp-modal-<?php echo esc_attr( $module->id() ); ?>">
								<?php esc_html_e( 'Show Settings', 'karmcp' ); ?>
							</button>
						</div>
					<?php elseif ( $active && '' !== $module->settings_url() ) : ?>
						<div class="karmcp-module-card-actions">
							<a class="button karmcp-module-settings-btn" href="<?php echo esc_url( $module->settings_url() ); ?>">
								<?php
								/* translators: %s: module title */
								echo esc_html( sprintf( __( 'Configure %s →', 'karmcp' ), $module->title() ) );
								?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php submit_button( __( 'Save Modules', 'karmcp' ) ); ?>
	</form>

	<?php
	// Per-module settings overlays — outside the main form (no nested forms).
	foreach ( $modules as $module ) :
		if ( ! $module->is_active() || ! $module->is_available() || ! $module->has_settings() ) {
			continue;
		}
		$modal_id = 'karmcp-modal-' . $module->id();
		?>
		<div class="karmcp-modal" id="<?php echo esc_attr( $modal_id ); ?>" hidden>
			<div class="karmcp-modal-backdrop" data-close></div>
			<div class="karmcp-modal-panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: module title */ __( '%s settings', 'karmcp' ), $module->title() ) ); ?>">
				<div class="karmcp-modal-head">
					<h2><?php echo esc_html( $module->title() ); ?></h2>
					<button type="button" class="karmcp-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'karmcp' ); ?>">
						<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M6 6l8 8M14 6l-8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
					</button>
				</div>

				<form method="post" action="options.php" class="karmcp-modal-form">
					<?php settings_fields( $module->settings_group() ); ?>
					<div class="karmcp-modal-body"><?php $module->render_settings(); ?></div>
					<div class="karmcp-modal-foot">
						<?php submit_button( __( 'Save Settings', 'karmcp' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<?php if ( KarMCP_Image_Optimization_Module::ID === $module->id() ) : ?>
					<div class="karmcp-modal-bulk">
						<p class="karmcp-modal-bulk-title"><?php esc_html_e( 'Existing library', 'karmcp' ); ?></p>
						<div class="karmcp-module-bulk">
							<button type="button" class="button" id="karmcp-bulk-optimize"><?php esc_html_e( 'Optimize existing library', 'karmcp' ); ?></button>
							<button type="button" class="button" id="karmcp-bulk-restore"><?php esc_html_e( 'Restore originals', 'karmcp' ); ?></button>
							<div class="karmcp-bulk-progress" hidden>
								<div class="karmcp-bulk-bar"><span></span></div>
								<span class="karmcp-bulk-status"></span>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
