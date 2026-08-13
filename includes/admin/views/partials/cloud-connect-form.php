<?php
/**
 * Shared "Connect to KarMCP Cloud" form with the disclosed, default-on gateway consent.
 *
 * Expects (optional): $karmcp_connect_label (string) — the submit button label.
 *
 * @package KarMCP
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$karmcp_connect_label = ( isset( $karmcp_connect_label ) && '' !== $karmcp_connect_label )
	? $karmcp_connect_label
	: __( 'Connect to KarMCP Cloud', 'karmcp' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="karmcp-cloud-connect-form">
	<input type="hidden" name="action" value="<?php echo esc_attr( KarMCP_Cloud_Connect::ACTION_CONNECT ); ?>" />
	<?php wp_nonce_field( KarMCP_Cloud_Connect::ACTION_CONNECT ); ?>
	<label class="karmcp-gateway-optin">
		<input type="checkbox" name="karmcp_gateway_optin" value="1" checked="checked" />
		<?php esc_html_e( 'Also let me manage this site through the KarMCP gateway (recommended)', 'karmcp' ); ?>
		<span class="description">
			<?php esc_html_e( 'Authorizes the KarMCP gateway to run MCP tools on this site on your behalf, so you can manage all your sites from a single AI connection. It never gets your password — it uses a revocable token, and only the tools you have enabled. Revoke anytime from Users → Authorized Apps or your KarMCP Cloud dashboard.', 'karmcp' ); ?>
		</span>
	</label>
	<p><button type="submit" class="button button-primary"><?php echo esc_html( $karmcp_connect_label ); ?></button></p>
</form>
