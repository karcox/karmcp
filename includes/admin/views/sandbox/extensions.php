<?php
/**
 * Sandbox > Element extensions view.
 *
 * A table of the options an AI agent added to Elementor's own elements: what
 * each one targets, which props it declares, and the switch to pause or delete
 * it. Unlike a widget or a block, an extension is not something you insert —
 * it changes elements the site already uses, which is exactly why this screen
 * exists.
 *
 * @package KarMCP
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_ext_can = class_exists( 'KarMCP_Extension_Store' ) && KarMCP_Extension_Store::user_has_access();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_ext_imported = isset( $_GET['imported'] ) ? sanitize_text_field( wp_unslash( $_GET['imported'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_ext_import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : '';
?>

<p class="karmcp-sandbox-back">
	<a href="<?php echo esc_url( menu_page_url( 'karmcp-widgets', false ) ); ?>" class="karmcp-header-btn karmcp-header-btn--secondary">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Back to Sandbox', 'karmcp' ); ?>
	</a>
</p>

<?php if ( '1' === $karmcp_ext_imported ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bundle imported as a new draft extension.', 'karmcp' ); ?></p></div>
<?php elseif ( '' !== $karmcp_ext_import_error ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $karmcp_ext_import_error ); ?></p></div>
<?php endif; ?>

<div class="karmcp-widget-builder">

	<div class="karmcp-pro-prompts">
		<div class="karmcp-pro-prompts-header">
			<div class="karmcp-pro-prompts-heading">
				<h2><?php esc_html_e( 'Extensions', 'karmcp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Options added to Elementor\'s own elements — a section in the settings panel of the containers you target, compiled from a spec into wp-content/karmcp-sandbox. Requires Elementor 4.2 or newer: extensions attach to atomic elements, not to classic sections and columns.', 'karmcp' ); ?>
				</p>
			</div>
		</div>

		<?php if ( ! $karmcp_ext_can ) : ?>

			<div class="karmcp-pro-cta">
				<p>
					<?php esc_html_e( 'Managing element extensions requires an administrator account: an extension is executable code that runs wherever its target elements appear.', 'karmcp' ); ?>
				</p>
			</div>

		<?php else : ?>

			<?php $karmcp_ext_list = KarMCP_Extension_Store::instance()->list_extensions( 'any' ); ?>

			<div class="notice notice-warning inline" style="margin: 12px 0;">
				<p>
					<strong><?php esc_html_e( 'Heads up:', 'karmcp' ); ?></strong>
					<?php esc_html_e( 'An extension changes elements you did not create. It can only add data- and aria- attributes and a CSS class of its own, never inline scripts or styles, and every prop it declares is prefixed karmcp_ so it cannot shadow one of Elementor\'s. Deactivate or delete any of them here at any time.', 'karmcp' ); ?>
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
					<p class="description"><?php esc_html_e( 'Imports always land as a new inactive draft, recompiled locally from the spec.', 'karmcp' ); ?></p>
				</form>
			</details>


			<?php if ( empty( $karmcp_ext_list ) ) : ?>

				<p class="description" style="margin-top: 16px;">
					<?php esc_html_e( 'No element extensions yet. Ask your AI agent to create one with the create-element-extension tool.', 'karmcp' ); ?>
				</p>

			<?php else : ?>

				<table class="widefat striped karmcp-extensions-table" data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_extensions' ) ); ?>" style="margin-top: 16px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Extension', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Applies to', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'karmcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $karmcp_ext_list as $karmcp_e ) :
							$karmcp_eid    = (int) $karmcp_e['extension_id'];
							$karmcp_active = ( 'active' === $karmcp_e['status'] );
							$karmcp_code   = KarMCP_Extension_Store::instance()->get_asset( $karmcp_eid, 'extension.php' );
							?>
							<tr data-extension-id="<?php echo esc_attr( (string) $karmcp_eid ); ?>">
								<td>
									<strong><?php echo esc_html( $karmcp_e['title'] ); ?></strong>
									<?php if ( ! empty( $karmcp_e['props'] ) ) : ?>
										<br /><span class="description"><?php echo esc_html( implode( ', ', (array) $karmcp_e['props'] ) ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $karmcp_e['last_error'] ) ) : ?>
										<br /><span style="color:#b32d2e;font-size:12px;">
											<?php
											printf(
												/* translators: %s: error message */
												esc_html__( 'Auto-deactivated after an error: %s', 'karmcp' ),
												esc_html( $karmcp_e['last_error'] )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$karmcp_targets = (array) $karmcp_e['targets'];
									if ( in_array( '*', $karmcp_targets, true ) ) {
										esc_html_e( 'Every atomic element', 'karmcp' );
									} else {
										echo '<code>' . esc_html( implode( '</code> <code>', $karmcp_targets ) ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above.
									}
									?>
								</td>
								<td>
									<span class="karmcp-badge <?php echo esc_attr( $karmcp_active ? 'karmcp-badge--pro' : '' ); ?>">
										<?php echo $karmcp_active ? esc_html__( 'Active', 'karmcp' ) : esc_html__( 'Inactive', 'karmcp' ); ?>
									</span>
								</td>
								<td class="karmcp-sb-actions">
									<button type="button" class="button karmcp-wb-toggle" data-status="<?php echo esc_attr( $karmcp_active ? 'draft' : 'active' ); ?>">
										<span class="dashicons dashicons-<?php echo $karmcp_active ? 'controls-pause' : 'controls-play'; ?>" aria-hidden="true"></span>
										<?php echo $karmcp_active ? esc_html__( 'Deactivate', 'karmcp' ) : esc_html__( 'Activate', 'karmcp' ); ?>
									</button>
									<button type="button" class="button karmcp-sb-danger karmcp-wb-delete">
										<span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete', 'karmcp' ); ?>
									</button>
									<a class="button" href="<?php echo esc_url( KarMCP_Admin::sandbox_export_url( 'extension', $karmcp_eid ) ); ?>">
										<span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export', 'karmcp' ); ?>
									</a>
									<?php
									// Cloud-backup button. Pre-escaped markup.
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<button
										type="button"
										class="button"
										data-karmcp-code-view
										data-karmcp-code-title="<?php echo esc_attr( $karmcp_e['title'] ); ?>"
										data-karmcp-code-filename="extension-<?php echo esc_attr( (string) $karmcp_eid ); ?>.php"
									><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e( 'View code', 'karmcp' ); ?></button>
									<pre class="karmcp-code-src" hidden><?php echo esc_html( $karmcp_code ); ?></pre>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<script>
				( function () {
					var table = document.querySelector( '.karmcp-extensions-table' );
					if ( ! table ) { return; }
					var nonce = table.getAttribute( 'data-nonce' ) || '';
					var ajaxUrl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

					function post( action, body ) {
						body.append( 'action', action );
						body.append( 'nonce', nonce );
						return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
					}

					table.addEventListener( 'click', function ( e ) {
						var row = e.target.closest( 'tr[data-extension-id]' );
						if ( ! row ) { return; }
						var id = row.getAttribute( 'data-extension-id' );

						if ( e.target.classList.contains( 'karmcp-wb-toggle' ) ) {
							e.target.disabled = true;
							var b = new FormData();
							b.append( 'extension_id', id );
							b.append( 'status', e.target.getAttribute( 'data-status' ) );
							post( 'karmcp_toggle_extension', b ).then( function ( res ) {
								if ( res && res.success ) { window.location.reload(); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}

						if ( e.target.classList.contains( 'karmcp-wb-delete' ) ) {
							/* global confirm */
							if ( ! confirm( '<?php echo esc_js( __( 'Delete this extension permanently? Its option disappears from every element that had it.', 'karmcp' ) ); ?>' ) ) { return; }
							e.target.disabled = true;
							var d = new FormData();
							d.append( 'extension_id', id );
							post( 'karmcp_delete_extension', d ).then( function ( res ) {
								if ( res && res.success ) { row.parentNode.removeChild( row ); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}
					} );
				} )();
				</script>

			<?php endif; ?>

		<?php endif; ?>

	</div>

</div>
