<?php
/**
 * Knobs for the Guardrails module card. Included by render_settings() with
 * $policy in scope. Rendered inside the Modules settings <form>.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$p = KarMCP_Guardrails_Module::PREFIX;

/**
 * Render one toggle-switch row.
 *
 * @param string $name  Option key.
 * @param bool   $on    Current state.
 * @param string $title Row title.
 * @param string $desc  Row description.
 */
$karmcp_gr_toggle = static function ( $name, $on, $title, $desc ) {
	printf(
		'<label class="karmcp-switch karmcp-gr-toggle">
			<input type="checkbox" name="%s" value="1"%s />
			<span class="elementor-mcp-toggle" aria-hidden="true"><span class="elementor-mcp-toggle-track"></span></span>
			<span class="karmcp-gr-toggle-text">
				<span class="karmcp-gr-toggle-title">%s</span>
				<span class="karmcp-gr-toggle-desc">%s</span>
			</span>
		</label>',
		esc_attr( $name ),
		checked( $on, true, false ),
		esc_html( $title ),
		esc_html( $desc )
	);
};

$karmcp_gr_days = array(
	1 => __( 'Mon', 'karmcp' ),
	2 => __( 'Tue', 'karmcp' ),
	3 => __( 'Wed', 'karmcp' ),
	4 => __( 'Thu', 'karmcp' ),
	5 => __( 'Fri', 'karmcp' ),
	6 => __( 'Sat', 'karmcp' ),
	7 => __( 'Sun', 'karmcp' ),
);
?>
<div class="karmcp-gr">

	<?php
	$karmcp_gr_toggle(
		$p . 'read_only',
		$policy['read_only'],
		__( 'Read-only mode', 'karmcp' ),
		__( 'Block every write tool. Agents can still read and report, but cannot change anything.', 'karmcp' )
	);

	$karmcp_gr_toggle(
		$p . 'block_destructive',
		$policy['block_destructive'],
		__( 'Block destructive tools', 'karmcp' ),
		__( 'Refuse anything flagged destructive — deleting posts, plugins, themes, rows or files.', 'karmcp' )
	);
	?>

	<hr class="karmcp-gr-sep" />

	<?php
	$karmcp_gr_toggle(
		$p . 'freeze',
		$policy['freeze'],
		__( 'Freeze writes on a schedule', 'karmcp' ),
		__( 'Refuse writes inside a recurring window — business hours on a client site, for instance. Reads are never affected.', 'karmcp' )
	);
	?>

	<div class="karmcp-gr-field">
		<div class="karmcp-gr-inline">
			<label for="<?php echo esc_attr( $p . 'freeze_from' ); ?>"><?php esc_html_e( 'From', 'karmcp' ); ?></label>
			<input
				type="time"
				id="<?php echo esc_attr( $p . 'freeze_from' ); ?>"
				name="<?php echo esc_attr( $p . 'freeze_from' ); ?>"
				value="<?php echo esc_attr( $policy['freeze_from'] ); ?>"
			/>
			<label for="<?php echo esc_attr( $p . 'freeze_to' ); ?>"><?php esc_html_e( 'to', 'karmcp' ); ?></label>
			<input
				type="time"
				id="<?php echo esc_attr( $p . 'freeze_to' ); ?>"
				name="<?php echo esc_attr( $p . 'freeze_to' ); ?>"
				value="<?php echo esc_attr( $policy['freeze_to'] ); ?>"
			/>
		</div>
		<span class="karmcp-gr-hint"><?php esc_html_e( 'Site time. An end earlier than the start runs overnight (22:00 to 06:00).', 'karmcp' ); ?></span>
	</div>

	<div class="karmcp-gr-field">
		<div class="karmcp-gr-days">
			<?php foreach ( $karmcp_gr_days as $karmcp_gr_n => $karmcp_gr_label ) : ?>
				<label class="karmcp-gr-day">
					<input
						type="checkbox"
						name="<?php echo esc_attr( $p . 'freeze_days' ); ?>[]"
						value="<?php echo esc_attr( (string) $karmcp_gr_n ); ?>"
						<?php checked( in_array( $karmcp_gr_n, $policy['freeze_days'], true ) ); ?>
					/>
					<span><?php echo esc_html( $karmcp_gr_label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<span class="karmcp-gr-hint"><?php esc_html_e( 'Days the freeze applies. None selected means every day.', 'karmcp' ); ?></span>
	</div>

	<hr class="karmcp-gr-sep" />

	<div class="karmcp-gr-field">
		<div class="karmcp-gr-field-head">
			<label for="<?php echo esc_attr( $p . 'protected_posts' ); ?>"><?php esc_html_e( 'Protected posts', 'karmcp' ); ?></label>
		</div>
		<input
			type="text"
			class="regular-text"
			id="<?php echo esc_attr( $p . 'protected_posts' ); ?>"
			name="<?php echo esc_attr( $p . 'protected_posts' ); ?>"
			value="<?php echo esc_attr( implode( ', ', $policy['protected_posts'] ) ); ?>"
			placeholder="12, 340, 1288"
		/>
		<span class="karmcp-gr-hint"><?php esc_html_e( 'Post or page IDs no tool may modify. Comma-separated.', 'karmcp' ); ?></span>
	</div>

	<div class="karmcp-gr-field">
		<div class="karmcp-gr-field-head">
			<label for="<?php echo esc_attr( $p . 'protected_types' ); ?>"><?php esc_html_e( 'Protected content types', 'karmcp' ); ?></label>
		</div>
		<input
			type="text"
			class="regular-text"
			id="<?php echo esc_attr( $p . 'protected_types' ); ?>"
			name="<?php echo esc_attr( $p . 'protected_types' ); ?>"
			value="<?php echo esc_attr( implode( ', ', $policy['protected_types'] ) ); ?>"
			placeholder="product, shop_order"
		/>
		<span class="karmcp-gr-hint"><?php esc_html_e( 'Post type slugs left alone entirely — orders, courses, anything an agent has no business editing. Comma-separated.', 'karmcp' ); ?></span>
	</div>

	<hr class="karmcp-gr-sep" />

	<div class="karmcp-gr-field">
		<div class="karmcp-gr-field-head">
			<label for="<?php echo esc_attr( $p . 'notes' ); ?>"><?php esc_html_e( 'House rules', 'karmcp' ); ?></label>
		</div>
		<textarea
			id="<?php echo esc_attr( $p . 'notes' ); ?>"
			name="<?php echo esc_attr( $p . 'notes' ); ?>"
			rows="5"
			class="large-text code"
			placeholder="<?php echo esc_attr__( '- Spanish copy, formal register (usted).
- Never touch the header or footer templates.
- New pages start as drafts.', 'karmcp' ); ?>"
		><?php echo esc_textarea( $policy['notes'] ); ?></textarea>
		<span class="karmcp-gr-hint"><?php esc_html_e( 'Free text handed to every agent as part of its discovery context, alongside the rules above. Conventions, tone, things to leave alone. Markdown.', 'karmcp' ); ?></span>
	</div>

</div>
