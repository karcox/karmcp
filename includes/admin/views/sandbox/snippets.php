<?php
/**
 * Sandbox > PHP Snippets view.
 *
 * Free but capability-gated: AI agents can draft PHP snippets through the
 * MCP tools, but they stay inactive until a human reviews and activates them
 * here.
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
?>

<p class="karmcp-sandbox-back">
	<a href="<?php echo esc_url( menu_page_url( 'karmcp-widgets', false ) ); ?>" class="karmcp-header-btn karmcp-header-btn--secondary">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Back to Sandbox', 'karmcp' ); ?>
	</a>
</p>

<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_sn_imported = isset( $_GET['imported'] ) ? sanitize_text_field( wp_unslash( $_GET['imported'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice render after a redirect, no state change.
$karmcp_sn_import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : '';
?>
<?php if ( '1' === $karmcp_sn_imported ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bundle imported as a new draft snippet.', 'karmcp' ); ?></p></div>
<?php elseif ( '' !== $karmcp_sn_import_error ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $karmcp_sn_import_error ); ?></p></div>
<?php endif; ?>

<?php
// ===== PHP Snippets (free, capability-gated) =====
$karmcp_sn_can   = class_exists( 'KarMCP_PHP_Snippet_Store' ) && KarMCP_PHP_Snippet_Store::can_edit();
$karmcp_sn_list  = class_exists( 'KarMCP_PHP_Snippet_Store' ) ? KarMCP_PHP_Snippet_Store::list_snippets( 'any' ) : array();
$karmcp_sn_nonce = wp_create_nonce( 'karmcp_php_snippets' );
?>
<div class="karmcp-pro-prompts karmcp-php-snippets" data-nonce="<?php echo esc_attr( $karmcp_sn_nonce ); ?>" style="margin-top: 28px;">
	<div class="karmcp-pro-prompts-header">
		<div class="karmcp-pro-prompts-heading">
			<h2>
				<?php esc_html_e( 'PHP Snippets', 'karmcp' ); ?>
				<span class="karmcp-badge karmcp-badge--free"><?php esc_html_e( 'FREE', 'karmcp' ); ?></span>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Run small pieces of PHP on your site, as a [karmcp_snippet] shortcode or on a WordPress hook. An AI agent can draft snippets through the MCP tools, but they stay INACTIVE until you review and activate them here.', 'karmcp' ); ?>
			</p>
		</div>
	</div>

	<div class="notice notice-error inline" style="margin: 12px 0;">
		<p>
			<strong><?php esc_html_e( 'This runs real PHP on your site.', 'karmcp' ); ?></strong>
			<?php esc_html_e( 'The validator blocks obviously dangerous code (shell/eval/file writes/network/obfuscation), but static analysis is a guardrail, not a guarantee, only activate code you have read and trust. Activation is the approval step; AI can only create inactive drafts.', 'karmcp' ); ?>
		</p>
	</div>

	<?php if ( ! $karmcp_sn_can ) : ?>

		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Managing PHP snippets requires the manage_options and unfiltered_html capabilities.', 'karmcp' ); ?></p>
		</div>

	<?php else : ?>

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


		<details class="karmcp-sn-add" style="margin: 14px 0;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( '+ Add a snippet', 'karmcp' ); ?></summary>
			<form class="karmcp-sn-form" style="margin-top: 12px; max-width: 760px;">
				<input type="hidden" name="snippet_id" value="0" />
				<p>
					<label><strong><?php esc_html_e( 'Title', 'karmcp' ); ?></strong><br />
						<input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'My snippet', 'karmcp' ); ?>" />
					</label>
				</p>
				<p>
					<label><strong><?php esc_html_e( 'PHP code', 'karmcp' ); ?></strong> <span class="description"><?php esc_html_e( '(no <?php tag needed; use return or echo for shortcode output)', 'karmcp' ); ?></span><br />
						<textarea name="code" rows="8" spellcheck="false" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:13px;" placeholder="return 'Hello, ' . get_bloginfo('name');"></textarea>
					</label>
				</p>
				<p style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
					<label><strong><?php esc_html_e( 'Runs as', 'karmcp' ); ?></strong><br />
						<select name="context">
							<option value="shortcode"><?php esc_html_e( 'Shortcode', 'karmcp' ); ?></option>
							<option value="hook"><?php esc_html_e( 'Hook', 'karmcp' ); ?></option>
							<option value="both"><?php esc_html_e( 'Both', 'karmcp' ); ?></option>
						</select>
					</label>
					<label class="karmcp-sn-hookfield" style="display:none;"><strong><?php esc_html_e( 'Hook', 'karmcp' ); ?></strong><br />
						<input type="text" name="hook" class="regular-text" placeholder="wp_footer" />
					</label>
					<label class="karmcp-sn-hookfield" style="display:none;"><strong><?php esc_html_e( 'Priority', 'karmcp' ); ?></strong><br />
						<input type="number" name="priority" value="10" style="width:80px;" />
					</label>
				</p>
				<p>
					<button type="submit" class="button button-primary karmcp-sn-save"><?php esc_html_e( 'Save draft', 'karmcp' ); ?></button>
					<span class="karmcp-sn-formmsg" style="margin-left:10px;"></span>
				</p>
				<div class="karmcp-sn-findings"></div>
			</form>
		</details>

		<?php if ( empty( $karmcp_sn_list ) ) : ?>
			<p class="description"><?php esc_html_e( 'No snippets yet. Add one above, or ask your AI agent to draft one with the create-php-snippet tool.', 'karmcp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped karmcp-snippets-table" style="margin-top: 8px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Snippet', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Runs', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'karmcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $karmcp_sn_list as $karmcp_s ) :
						$karmcp_sid    = (int) $karmcp_s['snippet_id'];
						$karmcp_sact   = ( 'active' === $karmcp_s['status'] );
						$karmcp_srec   = KarMCP_PHP_Snippet_Store::get( $karmcp_sid );
						$karmcp_scode  = is_array( $karmcp_srec ) ? (string) $karmcp_srec['code'] : '';
						$karmcp_sval   = is_array( $karmcp_srec ) && isset( $karmcp_srec['validation'] ) ? $karmcp_srec['validation'] : array( 'findings' => array() );
						$karmcp_swarn  = 0;
						foreach ( ( $karmcp_sval['findings'] ?? array() ) as $karmcp_f ) {
							if ( 'warning' === ( $karmcp_f['severity'] ?? '' ) ) {
								$karmcp_swarn++;
							}
						}
						?>
						<tr
							data-snippet-id="<?php echo esc_attr( (string) $karmcp_sid ); ?>"
							data-title="<?php echo esc_attr( $karmcp_s['title'] ); ?>"
							data-context="<?php echo esc_attr( $karmcp_s['context'] ); ?>"
							data-hook="<?php echo esc_attr( $karmcp_s['hook'] ); ?>"
							data-priority="<?php echo esc_attr( (string) $karmcp_s['priority'] ); ?>"
						>
							<td>
								<strong><?php echo esc_html( $karmcp_s['title'] ); ?></strong>
								<br /><code
									class="karmcp-copy-text"
									data-karmcp-copy-text="<?php echo esc_attr( $karmcp_s['shortcode'] ); ?>"
									data-karmcp-copied="<?php esc_attr_e( 'Copied!', 'karmcp' ); ?>"
									title="<?php esc_attr_e( 'Click to copy', 'karmcp' ); ?>"
									role="button"
									tabindex="0"
									style="font-size:11px;"
								><?php echo esc_html( $karmcp_s['shortcode'] ); ?></code>
								<?php if ( $karmcp_swarn > 0 ) : ?>
									<br /><span style="color:#996800;font-size:12px;">
										<?php
										printf(
											/* translators: %d: number of warnings */
											esc_html( _n( '%d validator warning, review the code', '%d validator warnings, review the code', $karmcp_swarn, 'karmcp' ) ),
											(int) $karmcp_swarn
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( ! empty( $karmcp_s['last_error'] ) ) : ?>
									<br /><span style="color:#b32d2e;font-size:12px;">
										<?php
										printf(
											/* translators: %s: error message */
											esc_html__( 'Auto-deactivated after an error: %s', 'karmcp' ),
											esc_html( $karmcp_s['last_error'] )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $karmcp_s['context'] ); ?>
								<?php if ( 'shortcode' !== $karmcp_s['context'] && '' !== $karmcp_s['hook'] ) : ?>
									<br /><code style="font-size:11px;"><?php echo esc_html( $karmcp_s['hook'] ); ?></code>
								<?php endif; ?>
							</td>
							<td>
								<span class="karmcp-badge <?php echo esc_attr( $karmcp_sact ? 'karmcp-badge--free' : '' ); ?>">
									<?php echo $karmcp_sact ? esc_html__( 'Active', 'karmcp' ) : esc_html__( 'Inactive', 'karmcp' ); ?>
								</span>
							</td>
							<td class="karmcp-sb-actions">
								<button type="button" class="button karmcp-sn-toggle" data-status="<?php echo esc_attr( $karmcp_sact ? 'draft' : 'active' ); ?>">
									<span class="dashicons dashicons-<?php echo $karmcp_sact ? 'controls-pause' : 'controls-play'; ?>" aria-hidden="true"></span>
									<?php echo $karmcp_sact ? esc_html__( 'Deactivate', 'karmcp' ) : esc_html__( 'Activate', 'karmcp' ); ?>
								</button>
								<button type="button" class="button karmcp-sn-edit"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Edit', 'karmcp' ); ?></button>
								<button type="button" class="button karmcp-sb-danger karmcp-sn-delete"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete', 'karmcp' ); ?></button>
								<a class="button" href="<?php echo esc_url( KarMCP_Admin::sandbox_export_url( 'snippet', $karmcp_sid ) ); ?>">
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
									data-karmcp-code-title="<?php echo esc_attr( $karmcp_s['title'] ); ?>"
									data-karmcp-code-filename="snippet-<?php echo (int) $karmcp_sid; ?>.php"
								><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e( 'View code', 'karmcp' ); ?></button>
								<pre class="karmcp-code-src karmcp-sn-code" hidden><?php echo esc_html( $karmcp_scode ); ?></pre>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<script>
		( function () {
			var root = document.querySelector( '.karmcp-php-snippets' );
			if ( ! root ) { return; }
			var nonce = root.getAttribute( 'data-nonce' ) || '';
			var ajaxUrl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

			function post( action, body ) {
				body.append( 'action', action );
				body.append( 'nonce', nonce );
				return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
			}

			function renderFindings( box, validation ) {
				box.innerHTML = '';
				if ( ! validation || ! validation.findings || ! validation.findings.length ) { return; }
				var ul = document.createElement( 'ul' );
				ul.style.margin = '8px 0 0';
				validation.findings.forEach( function ( f ) {
					var li = document.createElement( 'li' );
					li.style.color = ( f.severity === 'critical' ) ? '#b32d2e' : '#996800';
					li.textContent = '[' + f.severity + '] ' + ( f.line ? 'line ' + f.line + ': ' : '' ) + f.message;
					ul.appendChild( li );
				} );
				box.appendChild( ul );
			}

			// Show/hide the hook fields based on context.
			var form = root.querySelector( '.karmcp-sn-form' );
			function syncHookFields() {
				if ( ! form ) { return; }
				var ctx = form.querySelector( '[name="context"]' ).value;
				var show = ( ctx === 'hook' || ctx === 'both' );
				root.querySelectorAll( '.karmcp-sn-hookfield' ).forEach( function ( el ) {
					el.style.display = show ? '' : 'none';
				} );
			}
			if ( form ) {
				form.querySelector( '[name="context"]' ).addEventListener( 'change', syncHookFields );
				syncHookFields();

				form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var btn = form.querySelector( '.karmcp-sn-save' );
					var msg = form.querySelector( '.karmcp-sn-formmsg' );
					var findings = form.querySelector( '.karmcp-sn-findings' );
					findings.innerHTML = '';
					msg.textContent = '';
					btn.disabled = true;
					var b = new FormData( form );
					post( 'karmcp_save_php_snippet', b ).then( function ( res ) {
						btn.disabled = false;
						if ( res && res.success ) { window.location.reload(); return; }
						msg.style.color = '#b32d2e';
						msg.textContent = ( res && res.data && res.data.message ) || 'Failed.';
						if ( res && res.data && res.data.validation ) { renderFindings( findings, res.data.validation ); }
					} ).catch( function () { btn.disabled = false; msg.textContent = 'Request failed.'; } );
				} );
			}

			var table = root.querySelector( '.karmcp-snippets-table' );
			if ( table ) {
				table.addEventListener( 'click', function ( e ) {
					var row = e.target.closest( 'tr[data-snippet-id]' );
					if ( ! row ) { return; }
					var id = row.getAttribute( 'data-snippet-id' );

					if ( e.target.classList.contains( 'karmcp-sn-toggle' ) ) {
						var status = e.target.getAttribute( 'data-status' );
						if ( status === 'active' && ! confirm( '<?php echo esc_js( __( 'Activate this snippet? It will run real PHP on your site. Make sure you have read and trust the code.', 'karmcp' ) ); ?>' ) ) { return; }
						e.target.disabled = true;
						var tb = new FormData();
						tb.append( 'snippet_id', id );
						tb.append( 'status', status );
						post( 'karmcp_toggle_php_snippet', tb ).then( function ( res ) {
							if ( res && res.success ) { window.location.reload(); }
							else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
						} ).catch( function () { e.target.disabled = false; } );
					}

					if ( e.target.classList.contains( 'karmcp-sn-delete' ) ) {
						if ( ! confirm( '<?php echo esc_js( __( 'Delete this snippet permanently?', 'karmcp' ) ); ?>' ) ) { return; }
						e.target.disabled = true;
						var db = new FormData();
						db.append( 'snippet_id', id );
						post( 'karmcp_delete_php_snippet', db ).then( function ( res ) {
							if ( res && res.success ) { row.parentNode.removeChild( row ); }
							else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
						} ).catch( function () { e.target.disabled = false; } );
					}

					if ( e.target.classList.contains( 'karmcp-sn-edit' ) && form ) {
						var add = root.querySelector( '.karmcp-sn-add' );
						if ( add ) { add.open = true; }
						form.querySelector( '[name="snippet_id"]' ).value = id;
						form.querySelector( '[name="title"]' ).value = row.getAttribute( 'data-title' ) || '';
						form.querySelector( '[name="context"]' ).value = row.getAttribute( 'data-context' ) || 'shortcode';
						form.querySelector( '[name="hook"]' ).value = row.getAttribute( 'data-hook' ) || '';
						form.querySelector( '[name="priority"]' ).value = row.getAttribute( 'data-priority' ) || '10';
						var pre = row.querySelector( '.karmcp-sn-code' );
						form.querySelector( '[name="code"]' ).value = pre ? pre.textContent : '';
						syncHookFields();
						form.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
				} );
			}
		} )();
		</script>

	<?php endif; ?>

</div>
