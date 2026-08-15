<?php
/**
 * Sandbox > Blocks view.
 *
 * A table of AI-generated custom Gutenberg blocks with status, last-error, view
 * spec (block.json + render.php), activate/deactivate, and delete. The blocks
 * are created by AI agents through the MCP tools and live in an isolated
 * sandbox — this screen is the human management / kill-switch surface.
 *
 * Modeled on sandbox/widgets.php (same markup/JS pattern, widget → block).
 *
 * @package KarMCP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_bb_can = class_exists( 'KarMCP_Block_Store' ) && KarMCP_Block_Store::user_has_access();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_bb_imported = isset( $_GET['imported'] ) ? sanitize_text_field( wp_unslash( $_GET['imported'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_bb_import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : '';
?>

<p class="karmcp-sandbox-back">
	<a href="<?php echo esc_url( menu_page_url( 'karmcp-widgets', false ) ); ?>" class="elementor-mcp-header-btn elementor-mcp-header-btn--secondary">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Back to Sandbox', 'karmcp' ); ?>
	</a>
</p>

<?php if ( '1' === $karmcp_bb_imported ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bundle imported as a new draft block.', 'karmcp' ); ?></p></div>
<?php elseif ( '' !== $karmcp_bb_import_error ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $karmcp_bb_import_error ); ?></p></div>
<?php endif; ?>

<div class="elementor-mcp-widget-builder">

	<div class="elementor-mcp-pro-prompts">
		<div class="elementor-mcp-pro-prompts-header">
			<div class="elementor-mcp-pro-prompts-heading">
				<h2><?php esc_html_e( 'Blocks', 'karmcp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Custom Gutenberg blocks your AI agent generated through the MCP tools, starting from a structured spec. Everything lives in an isolated sandbox under wp-content/karmcp-sandbox, never in your theme, core, or other plugins. Active blocks appear in the block editor inserter under "KarMCP Custom".', 'karmcp' ); ?>
				</p>
			</div>
		</div>

		<?php if ( ! $karmcp_bb_can ) : ?>

			<div class="elementor-mcp-pro-cta">
				<p>
					<?php esc_html_e( 'Managing custom blocks requires an administrator account: a generated block is executable code that runs on every page it is placed on.', 'karmcp' ); ?>
				</p>
			</div>

		<?php else : ?>

			<?php $karmcp_bb_list = KarMCP_Block_Store::instance()->list_blocks( 'any' ); ?>

			<div class="notice notice-warning inline" style="margin: 12px 0;">
				<p>
					<strong><?php esc_html_e( 'Heads up:', 'karmcp' ); ?></strong>
					<?php esc_html_e( 'These blocks are compiled by this plugin from an AI-supplied spec (the AI never writes raw PHP or JS). Output is escaped by control type. You can deactivate or delete any block here at any time.', 'karmcp' ); ?>
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

			<?php echo KarMCP_Admin::render_cloud_library( 'block' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally. ?>

			<?php if ( empty( $karmcp_bb_list ) ) : ?>

				<p class="description" style="margin-top: 16px;">
					<?php esc_html_e( 'No custom blocks yet. Ask your AI agent to create one with the create-custom-block tool.', 'karmcp' ); ?>
				</p>

			<?php else : ?>

				<table class="widefat striped elementor-mcp-blocks-table" data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_blocks' ) ); ?>" style="margin-top: 16px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Block', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Machine name', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'karmcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $karmcp_bb_list as $karmcp_b ) :
							$karmcp_bid    = (int) $karmcp_b['block_id'];
							$karmcp_active = ( 'active' === $karmcp_b['status'] );
							$karmcp_bcode  = "// block.json\n" . KarMCP_Block_Store::instance()->get_asset( $karmcp_bid, 'block.json' )
								. "\n\n// render.php\n" . KarMCP_Block_Store::instance()->get_asset( $karmcp_bid, 'render.php' );
							?>
							<tr data-block-id="<?php echo esc_attr( (string) $karmcp_bid ); ?>">
								<td>
									<strong><?php echo esc_html( $karmcp_b['title'] ); ?></strong>
									<?php if ( ! empty( $karmcp_b['last_error'] ) ) : ?>
										<br /><span style="color:#b32d2e;font-size:12px;">
											<?php
											printf(
												/* translators: %s: error message */
												esc_html__( 'Auto-deactivated after an error: %s', 'karmcp' ),
												esc_html( $karmcp_b['last_error'] )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $karmcp_b['block_name'] ); ?></code></td>
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
									<a class="button" href="<?php echo esc_url( KarMCP_Admin::sandbox_export_url( 'block', $karmcp_bid ) ); ?>">
										<span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export', 'karmcp' ); ?>
									</a>
									<?php
									// Cloud-backup button. Pre-escaped markup.
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									echo KarMCP_Admin::render_sandbox_cloud_actions( 'block', $karmcp_bid );
									?>
									<button
										type="button"
										class="button"
										data-karmcp-code-view
										data-karmcp-code-title="<?php echo esc_attr( $karmcp_b['title'] ); ?>"
										data-karmcp-code-filename="<?php echo esc_attr( $karmcp_b['block_name'] ); ?>.php"
									><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e( 'View code', 'karmcp' ); ?></button>
									<pre class="karmcp-code-src" hidden><?php echo esc_html( $karmcp_bcode ); ?></pre>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<script>
				( function () {
					var table = document.querySelector( '.elementor-mcp-blocks-table' );
					if ( ! table ) { return; }
					var nonce = table.getAttribute( 'data-nonce' ) || '';
					var ajaxUrl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

					function post( action, body ) {
						body.append( 'action', action );
						body.append( 'nonce', nonce );
						return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
					}

					table.addEventListener( 'click', function ( e ) {
						var row = e.target.closest( 'tr[data-block-id]' );
						if ( ! row ) { return; }
						var id = row.getAttribute( 'data-block-id' );

						if ( e.target.classList.contains( 'elementor-mcp-wb-toggle' ) ) {
							e.target.disabled = true;
							var b = new FormData();
							b.append( 'block_id', id );
							b.append( 'status', e.target.getAttribute( 'data-status' ) );
							post( 'karmcp_toggle_block', b ).then( function ( res ) {
								if ( res && res.success ) { window.location.reload(); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}

						if ( e.target.classList.contains( 'elementor-mcp-wb-delete' ) ) {
							/* global confirm */
							if ( ! confirm( '<?php echo esc_js( __( 'Delete this block permanently? Pages using it will lose it.', 'karmcp' ) ); ?>' ) ) { return; }
							e.target.disabled = true;
							var d = new FormData();
							d.append( 'block_id', id );
							post( 'karmcp_delete_block', d ).then( function ( res ) {
								if ( res && res.success ) { row.parentNode.removeChild( row ); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}
						// Cloud-backup action handled by shared sandbox-cloud.js.
					} );
				} )();
				</script>

			<?php endif; ?>

		<?php endif; ?>

	</div>

</div>
