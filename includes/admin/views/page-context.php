<?php
/**
 * Context tab: site-wide guidance the MCP server delivers to AI agents as
 * `instructions` (applied automatically at connection).
 *
 * @package KarMCP
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_ctx       = KarMCP_Site_Context::get_context();
$karmcp_ctx_on    = KarMCP_Site_Context::is_enabled();
$karmcp_ctx_base  = KarMCP_Site_Context::default_base();
$karmcp_ctx_final = KarMCP_Site_Context::compose_instructions( $karmcp_ctx_base );
?>

<div class="karmcp-context">
	<div class="karmcp-section">
		<h2><?php esc_html_e( 'Site Context', 'karmcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Stable, site-wide guidance that every AI agent connecting to this site receives automatically and applies to all of its work here, your business identity, brand voice, content rules, technical constraints, and guardrails. It is delivered as the MCP server\'s instructions at connection; changes take effect the next time an agent connects.', 'karmcp' ); ?>
		</p>

		<form method="post" action="options.php" class="karmcp-context-form">
			<?php settings_fields( KarMCP_Admin::SETTINGS_GROUP_CONTEXT ); ?>

			<label class="karmcp-activate-toggle">
				<input type="checkbox" name="<?php echo esc_attr( KarMCP_Site_Context::OPTION_ENABLED ); ?>" value="1" <?php checked( $karmcp_ctx_on ); ?> />
				<strong><?php esc_html_e( 'Send this context to connected AI agents', 'karmcp' ); ?></strong>
			</label>

			<p class="karmcp-context-toolbar">
				<button type="button" class="button" id="karmcp-context-template"><?php esc_html_e( 'Insert starter template', 'karmcp' ); ?></button>
				<span class="karmcp-context-counter" id="karmcp-context-counter" aria-live="polite"></span>
			</p>

			<textarea
				id="karmcp-context-text"
				name="<?php echo esc_attr( KarMCP_Site_Context::OPTION_CONTEXT ); ?>"
				class="large-text code"
				rows="16"
				maxlength="<?php echo esc_attr( (string) KarMCP_Site_Context::MAX_CHARS ); ?>"
				placeholder="<?php esc_attr_e( '# About this site&#10;&#10;Write guidance in Markdown…', 'karmcp' ); ?>"
			><?php echo esc_textarea( $karmcp_ctx ); ?></textarea>

			<p class="karmcp-activate-note karmcp-activate-note--security">
				<strong><?php esc_html_e( 'Note:', 'karmcp' ); ?></strong>
				<?php esc_html_e( 'Whatever you write here steers every connected agent. Keep it accurate, and avoid instructions you would not want an agent to follow.', 'karmcp' ); ?>
			</p>

			<?php submit_button( __( 'Save Context', 'karmcp' ) ); ?>
		</form>
	</div>

	<div class="karmcp-section">
		<h2><?php esc_html_e( 'What agents receive', 'karmcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The exact instructions string sent to an AI client when it connects (server overview + your context).', 'karmcp' ); ?></p>
		<pre class="karmcp-context-preview" id="karmcp-context-preview"><?php echo esc_html( $karmcp_ctx_final ); ?></pre>
	</div>
</div>
