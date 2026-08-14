<?php
/**
 * Sandbox > Widget Builder view.
 *
 * Pro users: a table of AI-generated custom Elementor widgets with status,
 * last-error, view spec/PHP, activate/deactivate, and delete. The widgets are
 * created by AI agents through the MCP tools and live in an isolated uploads
 * sandbox — this screen is the human management / kill-switch surface.
 * Free users: upgrade CTA.
 *
 * Moved out of page-widgets.php (now a view=-routed thin router) so the
 * Sandbox parent page can show a 3-card overview instead. Markup/logic below
 * is unchanged from the original combined page.
 *
 * @package KarMCP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_wb_pro = class_exists( 'KarMCP_Widget_Store' ) && KarMCP_Widget_Store::user_has_access();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_wb_imported = isset( $_GET['imported'] ) ? sanitize_text_field( wp_unslash( $_GET['imported'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_wb_import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : '';
?>

<p class="karmcp-sandbox-back">
	<a href="<?php echo esc_url( menu_page_url( 'karmcp-widgets', false ) ); ?>" class="elementor-mcp-header-btn elementor-mcp-header-btn--secondary">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Back to Sandbox', 'karmcp' ); ?>
	</a>
</p>

<?php if ( '1' === $karmcp_wb_imported ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bundle imported as a new draft widget.', 'karmcp' ); ?></p></div>
<?php elseif ( '' !== $karmcp_wb_import_error ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $karmcp_wb_import_error ); ?></p></div>
<?php endif; ?>

<div class="elementor-mcp-widget-builder">

	<div class="elementor-mcp-pro-prompts">
		<div class="elementor-mcp-pro-prompts-header">
			<div class="elementor-mcp-pro-prompts-heading">
				<h2>
					<?php esc_html_e( 'Widgets', 'karmcp' ); ?>
					<span class="elementor-mcp-badge elementor-mcp-badge--pro">PRO</span>
				</h2>
				<p class="description">
					<?php esc_html_e( 'Code your AI agent generated through the MCP tools, starting with custom Elementor widgets. Everything lives in an isolated sandbox under wp-content/karmcp-sandbox, never in your theme, core, or other plugins. Active widgets appear in the Elementor panel under "Custom (KarMCP)".', 'karmcp' ); ?>
				</p>
			</div>
		</div>

		<?php if ( ! $karmcp_wb_pro ) : ?>

			<div class="elementor-mcp-pro-cta">
				<p>
					<?php esc_html_e( 'Custom Widgets is not available in this build: the generator and sandbox compiler are not part of KarMCP.', 'karmcp' ); ?>
				</p>
			</div>

		<?php else : ?>

			<?php $karmcp_wb_list = KarMCP_Widget_Store::list_widgets( 'any' ); ?>

			<div class="notice notice-warning inline" style="margin: 12px 0;">
				<p>
					<strong><?php esc_html_e( 'Heads up:', 'karmcp' ); ?></strong>
					<?php esc_html_e( 'These widgets are PHP compiled by this plugin from an AI-supplied spec (the AI never writes raw PHP). Output is escaped by control type. You can deactivate or delete any widget here at any time.', 'karmcp' ); ?>
				</p>
			</div>

			<details class="karmcp-sb-disclosure">
				<summary><span class="dashicons dashicons-upload" aria-hidden="true"></span><?php esc_html_e( 'Import a bundle', 'karmcp' ); ?></summary>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
					<?php wp_nonce_field( KarMCP_Admin::NONCE_SANDBOX_BUNDLE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( KarMCP_Admin::ACTION_IMPORT_ARTIFACT ); ?>" />
					<p>
						<input type="file" name="bundle" accept="application/json,.json" required />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import bundle', 'karmcp' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Import a .json bundle exported from another site (or from Export below). Imports always land as a new inactive draft.', 'karmcp' ); ?></p>
				</form>
			</details>

			<?php echo KarMCP_Admin::render_cloud_library( 'widget' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally. ?>

			<?php if ( empty( $karmcp_wb_list ) ) : ?>

				<p class="description" style="margin-top: 16px;">
					<?php esc_html_e( 'No custom widgets yet. Ask your AI agent to create one with the create-custom-widget tool.', 'karmcp' ); ?>
				</p>

			<?php else : ?>

				<table class="widefat striped elementor-mcp-widgets-table" data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_widgets' ) ); ?>" style="margin-top: 16px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Widget', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Machine name', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'karmcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $karmcp_wb_list as $karmcp_w ) :
							$karmcp_wid    = (int) $karmcp_w['widget_id'];
							$karmcp_active = ( 'active' === $karmcp_w['status'] );
							?>
							<tr data-widget-id="<?php echo esc_attr( (string) $karmcp_wid ); ?>">
								<td>
									<strong><?php echo esc_html( $karmcp_w['title'] ); ?></strong>
									<?php if ( ! empty( $karmcp_w['last_error'] ) ) : ?>
										<br /><span style="color:#b32d2e;font-size:12px;">
											<?php
											printf(
												/* translators: %s: error message */
												esc_html__( 'Auto-deactivated after an error: %s', 'karmcp' ),
												esc_html( $karmcp_w['last_error'] )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $karmcp_w['widget_name'] ); ?></code></td>
								<td>
									<span class="elementor-mcp-badge <?php echo esc_attr( $karmcp_active ? 'elementor-mcp-badge--pro' : '' ); ?>">
										<?php echo $karmcp_active ? esc_html__( 'Active', 'karmcp' ) : esc_html__( 'Inactive', 'karmcp' ); ?>
									</span>
								</td>
								<td class="karmcp-sb-actions">
									<button type="button" class="button elementor-mcp-wb-toggle" data-status="<?php echo esc_attr( $karmcp_active ? 'draft' : 'active' ); ?>">
										<span class="dashicons dashicons-<?php echo $karmcp_active ? 'controls-pause' : 'controls-play'; ?>" aria-hidden="true"></span>
										<?php echo $karmcp_active ? esc_html__( 'Deactivate', 'karmcp' ) : esc_html__( 'Activate', 'karmcp' ); ?>
									</button>
									<button type="button" class="button karmcp-sb-danger elementor-mcp-wb-delete">
										<span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete', 'karmcp' ); ?>
									</button>
									<a class="button" href="<?php echo esc_url( KarMCP_Admin::sandbox_export_url( 'widget', $karmcp_wid ) ); ?>">
										<span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export', 'karmcp' ); ?>
									</a>
									<?php
									// Cloud-backup button (Save to Cloud → Saved). Pre-escaped markup.
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									echo KarMCP_Admin::render_sandbox_cloud_actions( 'widget', $karmcp_wid );
									?>
									<button
										type="button"
										class="button"
										data-karmcp-code-view
										data-karmcp-code-title="<?php echo esc_attr( $karmcp_w['title'] ); ?>"
										data-karmcp-code-filename="<?php echo esc_attr( $karmcp_w['widget_name'] ); ?>.php"
									><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e( 'View code', 'karmcp' ); ?></button>
									<pre class="karmcp-code-src" hidden><?php echo esc_html( KarMCP_Widget_Store::get_php( $karmcp_wid ) ); ?></pre>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<script>
				( function () {
					var table = document.querySelector( '.elementor-mcp-widgets-table' );
					if ( ! table ) { return; }
					var nonce = table.getAttribute( 'data-nonce' ) || '';
					var ajaxUrl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

					function post( action, body ) {
						body.append( 'action', action );
						body.append( 'nonce', nonce );
						return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
					}

					table.addEventListener( 'click', function ( e ) {
						var row = e.target.closest( 'tr[data-widget-id]' );
						if ( ! row ) { return; }
						var id = row.getAttribute( 'data-widget-id' );

						if ( e.target.classList.contains( 'elementor-mcp-wb-toggle' ) ) {
							e.target.disabled = true;
							var b = new FormData();
							b.append( 'widget_id', id );
							b.append( 'status', e.target.getAttribute( 'data-status' ) );
							post( 'karmcp_toggle_widget', b ).then( function ( res ) {
								if ( res && res.success ) { window.location.reload(); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}

						if ( e.target.classList.contains( 'elementor-mcp-wb-delete' ) ) {
							/* global confirm */
							if ( ! confirm( '<?php echo esc_js( __( 'Delete this widget permanently? Pages using it will lose it.', 'karmcp' ) ); ?>' ) ) { return; }
							e.target.disabled = true;
							var d = new FormData();
							d.append( 'widget_id', id );
							post( 'karmcp_delete_widget', d ).then( function ( res ) {
								if ( res && res.success ) { row.parentNode.removeChild( row ); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}
						// Save to Cloud / Publish / View / Push update are handled by the
						// shared sandbox-cloud.js state machine.
					} );
				} )();
				</script>

			<?php endif; ?>

		<?php endif; ?>

	</div>

</div>
