<?php
/**
 * Brand Kits tab view.
 *
 * Free users: a curated set of 10 bundled brand kits (coordinated colors +
 * typography) they can apply, with backup-before-apply + restore, plus an
 * upgrade banner to unlock the full library.
 * Pro users: the full categorized library fetched from the server (50+), a
 * "Sync Library" refresh, apply confirmation modal, and restore.
 *
 * A single rendering path is fed by `$karmcp_render` — the Pro bundle
 * when the site has Pro AND it loaded, otherwise the bundled free set (which
 * also serves as a graceful fallback if the Pro fetch errors). Applying and
 * backup/restore are free features as of 1.9.0; the Pro value is the bigger
 * library + the MCP brand-kit tools.
 *
 * Previews use pre-rendered, font-outlined SVGs (thumbnail_url) — bundled in the
 * plugin for the free set, served from the bundle for Pro. When absent we fall
 * back to a CSS swatch strip; no Google Fonts are ever loaded in wp-admin.
 *
 * @package KarMCP
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_has_pro    = class_exists( 'KarMCP_Pro_Brand_Kits' ) && KarMCP_Pro_Brand_Kits::user_has_access();
$karmcp_pro_bundle = null;
$karmcp_pro_error  = null;
if ( $karmcp_has_pro ) {
	$karmcp_pro_result = KarMCP_Pro_Brand_Kits::get_bundle();
	if ( is_wp_error( $karmcp_pro_result ) ) {
		$karmcp_pro_error = $karmcp_pro_result->get_error_message();
	} else {
		$karmcp_pro_bundle = $karmcp_pro_result;
	}
}

$karmcp_free_bundle = class_exists( 'KarMCP_Free_Brand_Kits' )
	? KarMCP_Free_Brand_Kits::get_bundle()
	: array( 'categories' => array() );

// Pick what to render: the Pro library when present, else the free set (which
// also covers a Pro fetch error as a graceful fallback).
$karmcp_render      = ( $karmcp_has_pro && is_array( $karmcp_pro_bundle ) )
	? $karmcp_pro_bundle
	: $karmcp_free_bundle;
$karmcp_is_free_set = ! ( $karmcp_has_pro && is_array( $karmcp_pro_bundle ) );

$karmcp_bk_backups = class_exists( 'KarMCP_Kit_Backup_Store' )
	? KarMCP_Kit_Backup_Store::list_backups()
	: array();

$karmcp_bk_total = 0;
foreach ( $karmcp_render['categories'] as $karmcp_bk_cat ) {
	$karmcp_bk_total += is_array( $karmcp_bk_cat['kits'] ?? null ) ? count( $karmcp_bk_cat['kits'] ) : 0;
}
?>

<div class="karmcp-brand-kits">

	<?php if ( ! KarMCP_Bootstrap::elementor_active() ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'Brand Kits apply colors and typography to your Elementor kit. Install and activate Elementor to use this feature.', 'karmcp' ); ?>
			<a href="<?php echo esc_url( self_admin_url( 'plugin-install.php?s=Elementor&tab=search&type=term' ) ); ?>">
				<?php esc_html_e( 'Install Elementor', 'karmcp' ); ?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( $karmcp_bk_total > 0 ) : ?>

		<div class="karmcp-pro-prompts">
			<div class="karmcp-pro-prompts-header">
				<div class="karmcp-pro-prompts-heading">
					<h2>
						<?php esc_html_e( 'Brand Kits Library', 'karmcp' ); ?>
						<?php if ( $karmcp_is_free_set ) : ?>
							<span class="karmcp-badge karmcp-badge--free"><?php esc_html_e( 'FREE', 'karmcp' ); ?></span>
						<?php else : ?>
							<span class="karmcp-badge karmcp-badge--pro">PRO</span>
						<?php endif; ?>
					</h2>
					<p class="description">
						<?php if ( $karmcp_is_free_set ) : ?>
							<?php
							printf(
								/* translators: %d: number of free brand kits */
								esc_html__( '%d coordinated color + typography kits, free to apply. One click replaces your site\'s global palette and fonts, back up first and restore any time.', 'karmcp' ),
								(int) $karmcp_bk_total
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %1$d: kits, %2$d: categories */
								esc_html__( '%1$d coordinated color + typography kits across %2$d categories. One click replaces your site\'s global palette and fonts.', 'karmcp' ),
								(int) $karmcp_bk_total,
								(int) count( $karmcp_render['categories'] )
							);
							?>
							<?php if ( ! empty( $karmcp_render['fetched_at'] ) ) : ?>
								<span class="karmcp-pro-prompts-meta">
									<?php
									printf(
										/* translators: %s: human-readable time since last sync */
										esc_html__( 'Last synced %s ago.', 'karmcp' ),
										esc_html( human_time_diff( (int) $karmcp_render['fetched_at'], time() ) )
									);
									?>
								</span>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				</div>
				<?php if ( ! $karmcp_is_free_set ) : ?>
					<button
						type="button"
						class="button karmcp-pro-sync-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_sync_pro_brand_kits' ) ); ?>"
						data-sync-action="karmcp_sync_pro_brand_kits"
					>
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( 'Sync Library', 'karmcp' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( $karmcp_has_pro && $karmcp_pro_error ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php echo esc_html( $karmcp_pro_error ); ?>
						<?php esc_html_e( 'Showing the bundled starter kits in the meantime.', 'karmcp' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( count( $karmcp_render['categories'] ) > 1 ) : ?>
				<div class="karmcp-pro-filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter by category', 'karmcp' ); ?>">
					<button type="button" class="karmcp-pro-filter is-active" data-category="all">
						<?php esc_html_e( 'All', 'karmcp' ); ?>
						<span class="karmcp-pro-filter-count"><?php echo (int) $karmcp_bk_total; ?></span>
					</button>
					<?php foreach ( $karmcp_render['categories'] as $karmcp_bk_cat ) :
						$karmcp_cat_slug  = isset( $karmcp_bk_cat['slug'] ) ? sanitize_key( $karmcp_bk_cat['slug'] ) : '';
						$karmcp_cat_label = isset( $karmcp_bk_cat['label'] ) ? (string) $karmcp_bk_cat['label'] : '';
						$karmcp_cat_count = is_array( $karmcp_bk_cat['kits'] ?? null ) ? count( $karmcp_bk_cat['kits'] ) : 0;
						if ( '' === $karmcp_cat_slug || '' === $karmcp_cat_label ) {
							continue;
						}
					?>
						<button type="button" class="karmcp-pro-filter" data-category="<?php echo esc_attr( $karmcp_cat_slug ); ?>">
							<?php echo esc_html( $karmcp_cat_label ); ?>
							<span class="karmcp-pro-filter-count"><?php echo (int) $karmcp_cat_count; ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div
				class="karmcp-brand-kit-grid"
				data-apply-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_apply_pro_brand_kit' ) ); ?>"
			>
				<?php foreach ( $karmcp_render['categories'] as $karmcp_bk_cat ) :
					$karmcp_cat_slug  = isset( $karmcp_bk_cat['slug'] ) ? sanitize_key( $karmcp_bk_cat['slug'] ) : '';
					$karmcp_cat_label = isset( $karmcp_bk_cat['label'] ) ? (string) $karmcp_bk_cat['label'] : '';
					if ( '' === $karmcp_cat_slug || empty( $karmcp_bk_cat['kits'] ) ) {
						continue;
					}
					foreach ( $karmcp_bk_cat['kits'] as $karmcp_kit ) :
						$karmcp_k_slug  = isset( $karmcp_kit['slug'] ) ? sanitize_key( $karmcp_kit['slug'] ) : '';
						$karmcp_k_title = isset( $karmcp_kit['title'] ) ? (string) $karmcp_kit['title'] : '';
						$karmcp_k_desc  = isset( $karmcp_kit['description'] ) ? (string) $karmcp_kit['description'] : '';
						$karmcp_k_thumb = isset( $karmcp_kit['thumbnail_url'] ) ? (string) $karmcp_kit['thumbnail_url'] : '';
						if ( '' === $karmcp_k_slug ) {
							continue;
						}

						// Swatch fallback when no pre-rendered preview ships.
						$karmcp_swatches = array();
						if ( isset( $karmcp_kit['preview']['swatches'] ) && is_array( $karmcp_kit['preview']['swatches'] ) ) {
							$karmcp_swatches = $karmcp_kit['preview']['swatches'];
						} elseif ( isset( $karmcp_kit['colors'] ) && is_array( $karmcp_kit['colors'] ) ) {
							foreach ( array( 'primary', 'secondary', 'text', 'accent' ) as $karmcp_slot ) {
								if ( isset( $karmcp_kit['colors'][ $karmcp_slot ]['color'] ) ) {
									$karmcp_swatches[] = $karmcp_kit['colors'][ $karmcp_slot ]['color'];
								}
							}
						}
				?>
						<div class="karmcp-brand-kit-card" data-category="<?php echo esc_attr( $karmcp_cat_slug ); ?>">
							<?php if ( '' !== $karmcp_k_thumb ) : ?>
								<div class="karmcp-brand-kit-preview">
									<img src="<?php echo esc_url( $karmcp_k_thumb ); ?>" alt="<?php echo esc_attr( $karmcp_k_title ); ?>" loading="lazy" />
								</div>
							<?php elseif ( ! empty( $karmcp_swatches ) ) : ?>
								<div class="karmcp-brand-kit-swatches" aria-hidden="true">
									<?php
									$karmcp_widths = array( '50%', '25%', '15%', '10%' );
									foreach ( array_slice( $karmcp_swatches, 0, 4 ) as $karmcp_i => $karmcp_hex ) :
										$karmcp_hex_safe = sanitize_hex_color( (string) $karmcp_hex );
										if ( empty( $karmcp_hex_safe ) ) {
											continue;
										}
									?>
										<span
											class="karmcp-brand-kit-swatch"
											style="width:<?php echo esc_attr( $karmcp_widths[ $karmcp_i ] ?? '25%' ); ?>;background-color:<?php echo esc_attr( $karmcp_hex_safe ); ?>;"
										></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<div class="karmcp-brand-kit-body">
								<div class="karmcp-brand-kit-header">
									<h3 class="karmcp-brand-kit-title"><?php echo esc_html( $karmcp_k_title ); ?></h3>
									<span class="karmcp-prompt-tag"><?php echo esc_html( $karmcp_cat_label ); ?></span>
								</div>
								<?php if ( '' !== $karmcp_k_desc ) : ?>
									<p class="karmcp-brand-kit-desc"><?php echo esc_html( $karmcp_k_desc ); ?></p>
								<?php endif; ?>
								<div class="karmcp-brand-kit-actions">
									<button
										type="button"
										class="button button-primary karmcp-brand-kit-apply"
										data-category-slug="<?php echo esc_attr( $karmcp_cat_slug ); ?>"
										data-kit-slug="<?php echo esc_attr( $karmcp_k_slug ); ?>"
										data-kit-title="<?php echo esc_attr( $karmcp_k_title ); ?>"
									>
										<?php esc_html_e( 'Apply Kit', 'karmcp' ); ?>
									</button>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>

			<!-- Restore from backup -->
			<div class="karmcp-brand-kit-restore" data-restore-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_restore_pro_brand_kit' ) ); ?>">
				<h3><?php esc_html_e( 'Restore from backup', 'karmcp' ); ?></h3>
				<?php if ( ! empty( $karmcp_bk_backups ) ) : ?>
					<p class="description"><?php esc_html_e( 'Roll your global colors and typography back to a saved point. By default only kit-applied tokens are restored; tick the box to clobber your custom colors/typography exactly as they were.', 'karmcp' ); ?></p>
					<div class="karmcp-brand-kit-restore-row">
						<select class="karmcp-brand-kit-backup-select">
							<?php foreach ( $karmcp_bk_backups as $karmcp_backup ) : ?>
								<option value="<?php echo esc_attr( (int) $karmcp_backup['id'] ); ?>">
									<?php echo esc_html( $karmcp_backup['title'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button karmcp-brand-kit-restore-btn">
							<?php esc_html_e( 'Restore', 'karmcp' ); ?>
						</button>
					</div>
					<label class="karmcp-brand-kit-clobber">
						<input type="checkbox" class="karmcp-brand-kit-clobber-input" value="1" />
						<?php esc_html_e( 'Also restore my custom colors and typography exactly as they were', 'karmcp' ); ?>
					</label>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No backups yet. The first time you apply a kit (with the backup option checked), a restore point will appear here.', 'karmcp' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Apply confirmation modal -->
		<div class="karmcp-brand-kit-modal" hidden>
			<div class="karmcp-brand-kit-modal__backdrop" data-modal-dismiss></div>
			<div class="karmcp-brand-kit-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="karmcp-bk-modal-title">
				<h3 id="karmcp-bk-modal-title" class="karmcp-brand-kit-modal__title"></h3>
				<p class="karmcp-brand-kit-modal__body">
					<?php esc_html_e( 'This will replace your site\'s global colors and typography. Every widget using global color/type tokens will switch to the new palette.', 'karmcp' ); ?>
				</p>
				<label class="karmcp-brand-kit-modal__backup">
					<input type="checkbox" class="karmcp-brand-kit-modal__backup-input" value="1" checked />
					<?php esc_html_e( 'Back up current global settings (recommended)', 'karmcp' ); ?>
				</label>
				<div class="karmcp-brand-kit-modal__actions">
					<button type="button" class="button" data-modal-dismiss><?php esc_html_e( 'Cancel', 'karmcp' ); ?></button>
					<button type="button" class="button button-primary karmcp-brand-kit-modal__confirm"><?php esc_html_e( 'Apply Brand Kit', 'karmcp' ); ?></button>
				</div>
			</div>
		</div>

	<?php else : ?>

		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'No brand kits are available right now.', 'karmcp' ); ?></p>
		</div>

	<?php endif; ?>

</div>
