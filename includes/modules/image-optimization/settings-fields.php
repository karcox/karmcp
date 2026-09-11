<?php
/**
 * Knobs for the Image Optimization module card. Included by render_settings()
 * with $settings in scope. Rendered inside the Modules settings <form>.
 *
 * @package KarMCP
 */

// Included from inside render_settings(), so $p is a local, not a global.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$p = KarMCP_Image_Optimization_Module::PREFIX;

/**
 * Render one toggle-switch row.
 *
 * @param string $name  Option key.
 * @param bool   $on    Current state.
 * @param string $title Row title.
 * @param string $desc  Row description.
 * @param bool   $child Whether this is an indented sub-toggle.
 */
$karmcp_io_toggle = static function ( $name, $on, $title, $desc, $child = false ) {
	printf(
		'<label class="karmcp-switch karmcp-io-toggle%s">
			<input type="checkbox" name="%s" value="1"%s />
			<span class="karmcp-toggle" aria-hidden="true"><span class="karmcp-toggle-track"></span></span>
			<span class="karmcp-io-toggle-text">
				<span class="karmcp-io-toggle-title">%s</span>
				<span class="karmcp-io-toggle-desc">%s</span>
			</span>
		</label>',
		$child ? ' karmcp-io-toggle--child' : '',
		esc_attr( $name ),
		checked( $on, true, false ),
		esc_html( $title ),
		esc_html( $desc )
	);
};
?>
<div class="karmcp-io">

	<?php
	$karmcp_io_toggle(
		$p . 'compress',
		$settings['compress'],
		__( 'Compress images on upload', 'karmcp' ),
		__( 'Re-encode generated image sizes to shrink their file size.', 'karmcp' )
	);

	$karmcp_io_toggle(
		$p . 'webp',
		$settings['webp'],
		__( 'Generate WebP versions', 'karmcp' ),
		__( 'Create a .webp copy of each image size on upload.', 'karmcp' )
	);

	$karmcp_io_toggle(
		$p . 'webp_serve',
		$settings['webp_serve'],
		__( 'Serve WebP on the frontend', 'karmcp' ),
		__( 'Send WebP to browsers that support it. MCP always uses WebP regardless of this.', 'karmcp' ),
		true
	);
	?>

	<hr class="karmcp-io-sep" />

	<div class="karmcp-io-field">
		<div class="karmcp-io-field-head">
			<label for="<?php echo esc_attr( $p . 'quality' ); ?>"><?php esc_html_e( 'Quality', 'karmcp' ); ?></label>
			<output class="karmcp-io-range-out" for="<?php echo esc_attr( $p . 'quality' ); ?>"><?php echo esc_html( (string) $settings['quality'] ); ?></output>
		</div>
		<input
			type="range"
			id="<?php echo esc_attr( $p . 'quality' ); ?>"
			name="<?php echo esc_attr( $p . 'quality' ); ?>"
			class="karmcp-io-range"
			min="1"
			max="100"
			step="1"
			value="<?php echo esc_attr( (string) $settings['quality'] ); ?>"
		/>
		<div class="karmcp-io-range-scale"><span><?php esc_html_e( 'Smaller files', 'karmcp' ); ?></span><span><?php esc_html_e( 'Higher quality', 'karmcp' ); ?></span></div>
	</div>

	<div class="karmcp-io-field">
		<div class="karmcp-io-field-head">
			<label for="<?php echo esc_attr( $p . 'max_dimension' ); ?>"><?php esc_html_e( 'Max dimension cap', 'karmcp' ); ?></label>
		</div>
		<div class="karmcp-io-inline">
			<input
				type="number"
				id="<?php echo esc_attr( $p . 'max_dimension' ); ?>"
				name="<?php echo esc_attr( $p . 'max_dimension' ); ?>"
				class="karmcp-io-number"
				min="0"
				step="1"
				value="<?php echo esc_attr( (string) $settings['max_dimension'] ); ?>"
			/>
			<span class="karmcp-io-unit"><?php esc_html_e( 'px', 'karmcp' ); ?></span>
		</div>
		<span class="karmcp-io-hint"><?php esc_html_e( 'Downscale large uploads to this width/height. 0 = off.', 'karmcp' ); ?></span>
	</div>

	<hr class="karmcp-io-sep" />

	<?php
	$karmcp_io_toggle(
		$p . 'keep_originals',
		$settings['keep_originals'],
		__( 'Keep backups of originals', 'karmcp' ),
		__( 'Store an untouched copy of every file so optimization stays reversible.', 'karmcp' )
	);
	?>
</div>
