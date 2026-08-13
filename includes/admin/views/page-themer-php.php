<?php
/**
 * KarMCP Themer — PHP Templates review + edit page.
 *
 * @package KarMCP
 * @var array      $templates List of summaries.
 * @var array|null $detail    Full record when viewing one.
 * @var array|false $notice   One-shot { type, message } notice, or false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap karmcp-themer-php-wrap">
	<h1><?php esc_html_e( 'KarMCP Themer, PHP Templates', 'karmcp' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'PHP templates authored via MCP. Review and edit the code, then attach one to a template on its edit screen (Display Conditions box → “Render with PHP template”). A template only runs once attached.', 'karmcp' ); ?>
	</p>

	<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo 'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( is_array( $detail ) && ! isset( $detail['error'] ) ) : ?>
		<?php
		$val         = $detail['validation'];
		$has_finding = ! empty( $val['findings'] );
		$save_url    = admin_url( 'admin-post.php' );
		?>
		<h2 style="margin-bottom:6px;">
			<?php echo esc_html( $detail['title'] ); ?>
			<code><?php echo esc_html( $detail['type'] ); ?></code>
			<?php if ( ! empty( $detail['compiled'] ) ) : ?>
				<span class="dashicons dashicons-yes-alt" style="color:#008a20;" title="<?php esc_attr_e( 'Compiled (attached to a template)', 'karmcp' ); ?>"></span>
			<?php endif; ?>
		</h2>

		<?php if ( ! empty( $detail['last_error'] ) ) : ?>
			<div class="notice notice-warning inline" style="margin:6px 0;"><p><strong><?php esc_html_e( 'Last runtime error:', 'karmcp' ); ?></strong> <?php echo esc_html( $detail['last_error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $has_finding ) : ?>
			<div class="notice notice-error inline" style="margin:6px 0;">
				<p><strong><?php esc_html_e( 'Validation findings:', 'karmcp' ); ?></strong></p>
				<ul style="margin:.2em 0 .4em 1.4em;list-style:disc;">
				<?php foreach ( $val['findings'] as $f ) : ?>
					<li><strong><?php echo esc_html( $f['severity'] ); ?></strong>: <?php echo esc_html( $f['message'] ); ?> <?php echo $f['line'] ? esc_html( '(line ' . (int) $f['line'] . ')' ) : ''; ?></li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php else : ?>
			<p style="color:#008a20;margin:6px 0;">&#10003; <?php esc_html_e( 'No validation findings.', 'karmcp' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $save_url ); ?>" class="karmcp-themer-php-editor">
			<input type="hidden" name="action" value="karmcp_themer_php_save">
			<input type="hidden" name="template_id" value="<?php echo (int) $detail['template_id']; ?>">
			<?php wp_nonce_field( 'karmcp_themer_php_save_' . (int) $detail['template_id'] ); ?>

			<?php
			$type_labels = array(
				'header'  => __( 'Header', 'karmcp' ),
				'footer'  => __( 'Footer', 'karmcp' ),
				'single'  => __( 'Single (post/page)', 'karmcp' ),
				'archive' => __( 'Archive', 'karmcp' ),
				'any'     => __( 'Any type', 'karmcp' ),
			);
			?>
			<div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;">
				<p style="margin-top:0;">
					<label for="karmcp-themer-php-title"><strong><?php esc_html_e( 'Title', 'karmcp' ); ?></strong></label><br>
					<input type="text" id="karmcp-themer-php-title" name="title" class="regular-text" value="<?php echo esc_attr( $detail['title'] ); ?>">
				</p>
				<p style="margin-top:0;">
					<label for="karmcp-themer-php-type"><strong><?php esc_html_e( 'Type', 'karmcp' ); ?></strong></label><br>
					<select id="karmcp-themer-php-type" name="type">
						<?php foreach ( KarMCP_Themer_PHP_Store::TYPES as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $detail['type'], $t ); ?>><?php echo esc_html( $type_labels[ $t ] ?? ucfirst( $t ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<br><span class="description"><?php esc_html_e( 'Which Themer region this can be attached to. “Any type” matches every template type.', 'karmcp' ); ?></span>
				</p>
			</div>

			<p><label for="karmcp-themer-php-code"><strong><?php esc_html_e( 'Template code', 'karmcp' ); ?></strong></label></p>
			<textarea id="karmcp-themer-php-code" name="code" rows="20" class="large-text code" spellcheck="false" style="width:100%;font-family:Consolas,Monaco,monospace;"><?php echo esc_textarea( $detail['code'] ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Emit markup with echo/heredoc, a closing PHP tag is not allowed. eval/exec/include, network calls and file writes are rejected on save. If this template is attached, saving re-validates and recompiles it.', 'karmcp' ); ?>
			</p>

			<p class="submit" style="margin-top:8px;">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'karmcp' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'post_type' => KarMCP_Themer_CPT::POST_TYPE, 'page' => 'karmcp-themer-php' ), admin_url( 'edit.php' ) ) ); ?>">&laquo; <?php esc_html_e( 'Back to list', 'karmcp' ); ?></a>
			</p>
		</form>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Title', 'karmcp' ); ?></th>
				<th><?php esc_html_e( 'Type', 'karmcp' ); ?></th>
				<th><?php esc_html_e( 'Compiled', 'karmcp' ); ?></th>
				<th><?php esc_html_e( 'Last error', 'karmcp' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $templates ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No PHP templates yet. Ask your AI agent to create one with create-theme-php-template.', 'karmcp' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $templates as $t ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'post_type' => KarMCP_Themer_CPT::POST_TYPE, 'page' => 'karmcp-themer-php', 'view' => (int) $t['template_id'] ), admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html( $t['title'] ); ?></a></td>
						<td><code><?php echo esc_html( $t['type'] ); ?></code></td>
						<td><?php echo $t['compiled'] ? '&#10003;' : '&mdash;'; ?></td>
						<td><?php echo esc_html( $t['last_error'] ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'post_type' => KarMCP_Themer_CPT::POST_TYPE, 'page' => 'karmcp-themer-php', 'view' => (int) $t['template_id'] ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'karmcp' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this PHP template?', 'karmcp' ) ); ?>');">
								<input type="hidden" name="action" value="karmcp_themer_php_delete">
								<input type="hidden" name="template_id" value="<?php echo (int) $t['template_id']; ?>">
								<?php wp_nonce_field( 'karmcp_themer_php_delete_' . (int) $t['template_id'] ); ?>
								<button type="submit" class="button-link delete" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'karmcp' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
