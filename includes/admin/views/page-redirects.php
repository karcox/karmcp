<?php
/**
 * Redirects admin tab — manage 301/302 redirects, review suggested redirects
 * from AI deletes/renames, and run the broken-link scan. All actions route
 * through KarMCP_Redirect_Store + the change ledger (reversible in History).
 *
 * Rendered inside KarMCP_Admin::render_page() ($this = the admin instance).
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_store_ok    = class_exists( 'KarMCP_Redirect_Store' );
$karmcp_rows        = $karmcp_store_ok ? KarMCP_Redirect_Store::all( array( 'limit' => 500 ) ) : array();
$karmcp_suggestions = get_option( 'karmcp_redirect_suggestions', array() );
$karmcp_suggestions = is_array( $karmcp_suggestions ) ? $karmcp_suggestions : array();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice + edit prefill on an admin-gated page.
$karmcp_notice = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- edit prefill only.
$karmcp_edit_id = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
$karmcp_edit    = ( $karmcp_edit_id && $karmcp_store_ok ) ? KarMCP_Redirect_Store::get( $karmcp_edit_id ) : null;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill source from an accepted suggestion.
$karmcp_prefill_source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';

$karmcp_notices = array(
	'created' => array( 'ok', __( 'Redirect created.', 'karmcp' ) ),
	'updated' => array( 'ok', __( 'Redirect updated.', 'karmcp' ) ),
	'deleted' => array( 'ok', __( 'Redirect deleted (reversible from History).', 'karmcp' ) ),
);
?>
<div class="karmcp-redirects">
	<style>
		.karmcp-redirects { max-width: 1000px; }
		.karmcp-redirects .karmcp-rd-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 20px; margin: 0 0 20px; }
		.karmcp-redirects .karmcp-rd-card h2 { margin-top: 0; }
		.karmcp-redirects .karmcp-rd-form label { display: block; font-weight: 600; margin: 12px 0 4px; }
		.karmcp-redirects .karmcp-rd-form input[type="text"], .karmcp-redirects .karmcp-rd-form input[type="url"] { width: 100%; max-width: 520px; }
		.karmcp-redirects .karmcp-rd-row { display: flex; gap: 24px; flex-wrap: wrap; }
		.karmcp-redirects .karmcp-rd-notice { padding: 10px 14px; border-radius: 6px; margin: 0 0 16px; border-left: 4px solid #6366f1; background: #f5f3ff; }
		.karmcp-redirects .karmcp-rd-notice.err { border-left-color: #dc2626; background: #fef2f2; }
		.karmcp-redirects .karmcp-rd-sugg { border-left: 4px solid #f59e0b; background: #fffbeb; padding: 12px 16px; border-radius: 6px; margin: 0 0 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
		.karmcp-redirects code { background: #f0f0f1; padding: 1px 6px; border-radius: 3px; }
		.karmcp-redirects .karmcp-rd-off { opacity: .55; }
	</style>

	<?php if ( isset( $karmcp_notices[ $karmcp_notice ] ) ) : ?>
		<div class="karmcp-rd-notice"><?php echo esc_html( $karmcp_notices[ $karmcp_notice ][1] ); ?></div>
	<?php elseif ( 0 === strpos( $karmcp_notice, 'error' ) ) : ?>
		<div class="karmcp-rd-notice err"><?php echo esc_html__( 'Could not save the redirect.', 'karmcp' ) . ' ' . esc_html( substr( $karmcp_notice, 6 ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $karmcp_store_ok ) : ?>
		<div class="karmcp-rd-notice err"><?php esc_html_e( 'The redirect store is unavailable.', 'karmcp' ); ?></div>
	<?php else : ?>

	<div class="karmcp-rd-card">
		<h2><?php echo $karmcp_edit ? esc_html__( 'Edit Redirect', 'karmcp' ) : esc_html__( 'Add Redirect', 'karmcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Send an old path to a new URL or post. Use this after deleting or renaming a page so old links keep working.', 'karmcp' ); ?></p>
		<form class="karmcp-rd-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="karmcp_redirect_save" />
			<?php wp_nonce_field( 'karmcp_redirect_save' ); ?>
			<input type="hidden" name="redirect_id" value="<?php echo (int) ( $karmcp_edit['id'] ?? 0 ); ?>" />

			<label for="karmcp-rd-source"><?php esc_html_e( 'Source path', 'karmcp' ); ?></label>
			<input type="text" id="karmcp-rd-source" name="source" placeholder="/old-page" required
				value="<?php echo esc_attr( $karmcp_edit['source_path'] ?? $karmcp_prefill_source ); ?>" />

			<div class="karmcp-rd-row">
				<div style="flex:1 1 320px;">
					<label for="karmcp-rd-target"><?php esc_html_e( 'Target URL', 'karmcp' ); ?></label>
					<input type="text" id="karmcp-rd-target" name="target" placeholder="https://example.com/new-page"
						value="<?php echo esc_attr( ( empty( $karmcp_edit['target_post_id'] ) ? ( $karmcp_edit['target'] ?? '' ) : '' ) ); ?>" />
				</div>
				<div style="flex:0 0 200px;">
					<label for="karmcp-rd-post"><?php esc_html_e( 'or Target post ID', 'karmcp' ); ?></label>
					<input type="number" id="karmcp-rd-post" name="target_post_id" min="0" style="width:120px;"
						value="<?php echo esc_attr( ! empty( $karmcp_edit['target_post_id'] ) ? (int) $karmcp_edit['target_post_id'] : '' ); ?>" />
				</div>
			</div>

			<div class="karmcp-rd-row">
				<div>
					<label for="karmcp-rd-code"><?php esc_html_e( 'Status code', 'karmcp' ); ?></label>
					<select id="karmcp-rd-code" name="status_code">
						<option value="301" <?php selected( (int) ( $karmcp_edit['status_code'] ?? 301 ), 301 ); ?>><?php esc_html_e( '301 Permanent', 'karmcp' ); ?></option>
						<option value="302" <?php selected( (int) ( $karmcp_edit['status_code'] ?? 301 ), 302 ); ?>><?php esc_html_e( '302 Temporary', 'karmcp' ); ?></option>
					</select>
				</div>
				<div>
					<label><?php esc_html_e( 'Query string', 'karmcp' ); ?></label>
					<label style="font-weight:400;"><input type="checkbox" name="ignore_query" value="1" <?php checked( ! isset( $karmcp_edit['ignore_query'] ) || ! empty( $karmcp_edit['ignore_query'] ) ); ?> /> <?php esc_html_e( 'Match regardless of ?query', 'karmcp' ); ?></label>
				</div>
			</div>

			<p style="margin-top:16px;">
				<button type="submit" class="button button-primary"><?php echo $karmcp_edit ? esc_html__( 'Update redirect', 'karmcp' ) : esc_html__( 'Add redirect', 'karmcp' ); ?></button>
				<?php if ( $karmcp_edit ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=karmcp-redirects' ) ); ?>"><?php esc_html_e( 'Cancel', 'karmcp' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<?php if ( ! empty( $karmcp_suggestions ) ) : ?>
	<div class="karmcp-rd-card">
		<h2><?php esc_html_e( 'Suggested redirects', 'karmcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Old URLs left dead by a delete or rename. Add a redirect so visitors and search engines do not hit a 404.', 'karmcp' ); ?></p>
		<?php foreach ( array_reverse( $karmcp_suggestions ) as $karmcp_s ) :
			$karmcp_old = (string) ( $karmcp_s['old_path'] ?? '' );
			if ( '' === $karmcp_old ) {
				continue;
			}
			?>
			<div class="karmcp-rd-sugg">
				<span><code><?php echo esc_html( $karmcp_old ); ?></code> — <?php echo esc_html( 'slug-changed' === ( $karmcp_s['reason'] ?? '' ) ? __( 'renamed', 'karmcp' ) : __( 'deleted', 'karmcp' ) ); ?></span>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=karmcp-redirects&source=' . rawurlencode( $karmcp_old ) ) . '#karmcp-rd-source' ); ?>"><?php esc_html_e( 'Add redirect', 'karmcp' ); ?></a>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="karmcp-rd-card">
		<h2><?php esc_html_e( 'Active redirects', 'karmcp' ); ?> <span class="count">(<?php echo (int) count( $karmcp_rows ); ?>)</span></h2>
		<?php if ( empty( $karmcp_rows ) ) : ?>
			<p><?php esc_html_e( 'No redirects yet.', 'karmcp' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Target', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Code', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'karmcp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'karmcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $karmcp_rows as $karmcp_r ) :
					$karmcp_tgt = ! empty( $karmcp_r['target_post_id'] )
						? sprintf( __( 'post #%d', 'karmcp' ), (int) $karmcp_r['target_post_id'] )
						: (string) $karmcp_r['target'];
					?>
					<tr class="<?php echo empty( $karmcp_r['enabled'] ) ? 'karmcp-rd-off' : ''; ?>">
						<td><code><?php echo esc_html( $karmcp_r['source_path'] ); ?></code></td>
						<td><?php echo esc_html( $karmcp_tgt ); ?></td>
						<td><?php echo (int) $karmcp_r['status_code']; ?></td>
						<td><?php echo (int) $karmcp_r['hits']; ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=karmcp-redirects&edit=' . (int) $karmcp_r['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'karmcp' ); ?></a> |
							<a href="<?php echo esc_url( KarMCP_Admin::redirect_toggle_url( (int) $karmcp_r['id'] ) ); ?>"><?php echo empty( $karmcp_r['enabled'] ) ? esc_html__( 'Enable', 'karmcp' ) : esc_html__( 'Disable', 'karmcp' ); ?></a> |
							<a href="<?php echo esc_url( KarMCP_Admin::redirect_delete_url( (int) $karmcp_r['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect? (reversible from History)', 'karmcp' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'karmcp' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php endif; ?>
</div>
