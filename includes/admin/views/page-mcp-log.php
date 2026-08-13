<?php
/**
 * MCP Log tab — recent MCP requests (tool, status, duration) so failures can be
 * matched to server-side outcomes. Bug report Issue 5.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KARMCP_DIR . 'includes/class-mcp-request-log.php';

// Handle "Clear log" (self-POST, nonce + capability gated).
if ( isset( $_POST['karmcp_mcp_log_clear'] ) && current_user_can( 'manage_options' )
	&& isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'karmcp_mcp_log_clear' ) ) {
	KarMCP_MCP_Request_Log::clear();
	echo '<div class="notice notice-success inline"><p>' . esc_html__( 'MCP log cleared.', 'karmcp' ) . '</p></div>';
}

$karmcp_log   = array_reverse( KarMCP_MCP_Request_Log::all() ); // newest first.
$karmcp_debug = KarMCP_MCP_Request_Log::debug_enabled();
?>
<div class="elementor-mcp-section">
	<h2><?php esc_html_e( 'MCP Log', 'karmcp' ); ?></h2>
	<p class="elementor-mcp-activate-note">
		<?php esc_html_e( 'The last 100 MCP requests to this site, with tool, result status and duration — use this to match a connector failure to a server-side outcome.', 'karmcp' ); ?>
		<?php if ( ! $karmcp_debug ) : ?>
			<br><?php esc_html_e( 'Enable WP_DEBUG to also record the underlying error message for failed requests.', 'karmcp' ); ?>
		<?php endif; ?>
	</p>

	<?php if ( empty( $karmcp_log ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No MCP requests recorded yet.', 'karmcp' ); ?></p></div>
	<?php else : ?>
		<form method="post" style="margin:10px 0;">
			<?php wp_nonce_field( 'karmcp_mcp_log_clear' ); ?>
			<button type="submit" name="karmcp_mcp_log_clear" value="1" class="button"><?php esc_html_e( 'Clear log', 'karmcp' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When (UTC)', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Tool', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Request ID', 'karmcp' ); ?></th>
					<?php if ( $karmcp_debug ) : ?><th><?php esc_html_e( 'Error', 'karmcp' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $karmcp_log as $karmcp_row ) : ?>
					<?php $karmcp_status = (string) ( $karmcp_row['status'] ?? '' ); ?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $karmcp_row['ts'] ?? 0 ) ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $karmcp_row['tool'] ?? '' ) ); ?></code></td>
						<td>
							<span style="<?php echo ( 'error' === $karmcp_status || ( is_numeric( $karmcp_status ) && (int) $karmcp_status >= 400 ) ) ? 'color:#b32d2e;font-weight:600' : 'color:#1a7f4b'; ?>">
								<?php echo esc_html( $karmcp_status ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (int) ( $karmcp_row['ms'] ?? 0 ) ); ?> ms</td>
						<td><code style="font-size:11px;"><?php echo esc_html( (string) ( $karmcp_row['req_id'] ?? '' ) ); ?></code></td>
						<?php if ( $karmcp_debug ) : ?>
							<td style="max-width:340px;word-break:break-word;font-size:12px;color:#b32d2e;"><?php echo esc_html( (string) ( $karmcp_row['error'] ?? '' ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
