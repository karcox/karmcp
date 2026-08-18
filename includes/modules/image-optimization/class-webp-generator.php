<?php
/**
 * Generates `.webp` siblings for image files via WP_Image_Editor.
 *
 * A sibling is `name-800x600.jpg.webp` next to `name-800x600.jpg`, so the
 * original extension is preserved and the rewriter can find it deterministically.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebP sibling generator.
 *
 * @since 3.1.0
 */
class KarMCP_Webp_Generator {

	/** @var int JPEG/WebP quality, already clamped by the caller. */
	private $quality;

	/**
	 * @param int $quality Encode quality (40–95).
	 */
	public function __construct( int $quality ) {
		$this->quality = $quality;
	}

	/**
	 * The `.webp` sibling path for a given image file.
	 *
	 * @param string $file Absolute image path.
	 * @return string Sibling path (`$file . '.webp'`).
	 */
	public static function sibling_path( string $file ): string {
		return $file . '.webp';
	}

	/** @return bool Whether this server's image editor can output WebP. */
	public function is_available(): bool {
		return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * Generate a `.webp` sibling for one file. Skips if the sibling already
	 * exists or WebP is unsupported.
	 *
	 * A sibling that came out no smaller than its source is deleted and
	 * reported as an error rather than kept: see discard_if_larger().
	 *
	 * @param string $file Absolute image path.
	 * @return string|\WP_Error Sibling path on success, WP_Error otherwise.
	 */
	public function generate( string $file ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'webp_unsupported', __( 'This server cannot generate WebP images.', 'karmcp' ) );
		}
		$sibling = self::sibling_path( $file );
		if ( file_exists( $sibling ) ) {
			return $sibling;
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$editor->set_quality( $this->quality );
		$saved = $editor->save( $sibling, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $this->discard_if_larger( $file, $sibling );
	}

	/**
	 * Deletes a sibling that did not save anything, and says so.
	 *
	 * WebP is normally smaller, which is the whole premise, but it is not
	 * guaranteed: on photographic JPEGs already saved at a sensible quality the
	 * re-encode regularly comes out bigger. Measured on one site's library,
	 * every one of ten photographs and all of their generated sub-sizes grew —
	 * between 15% and 43% — so the conversion was adding a second file per size,
	 * and the rewriter was then serving the larger of the two to every visitor.
	 *
	 * Keeping it would be a loss twice over: disk, and bandwidth. The rewriter
	 * falls back to the original whenever the sibling is absent, so deleting it
	 * is the whole fix — nothing else needs to know.
	 *
	 * This does NOT mean the encoder is fine and WebP is at fault. A ratio this
	 * consistent usually means quality is set too high for the source material.
	 * The check is here because the outcome is what matters at upload time; the
	 * quality setting is a separate conversation, and this makes its effect
	 * visible instead of silently costing bytes.
	 *
	 * @since 1.20.3
	 *
	 * @param string $file    The source image.
	 * @param string $sibling The generated `.webp`.
	 * @return string|\WP_Error The sibling when it is smaller, WP_Error when it was discarded.
	 */
	private function discard_if_larger( string $file, string $sibling ) {
		clearstatcache( true, $file );
		clearstatcache( true, $sibling );

		$source_bytes = (int) @filesize( $file );
		$webp_bytes   = (int) @filesize( $sibling );

		// Unreadable sizes: keep what we made rather than delete on a guess.
		if ( $source_bytes <= 0 || $webp_bytes <= 0 ) {
			return $sibling;
		}

		if ( $webp_bytes < $source_bytes ) {
			return $sibling;
		}

		wp_delete_file( $sibling );

		return new \WP_Error(
			'webp_not_smaller',
			sprintf(
				/* translators: 1: file name, 2: webp size in bytes, 3: original size in bytes, 4: percentage difference. */
				__( 'Discarded the WebP for %1$s: %2$s bytes against %3$s for the source (%4$s%% larger), so keeping it would cost disk and bandwidth for nothing. If this happens to every image, the encode quality is set too high for this material.', 'karmcp' ),
				basename( $file ),
				number_format_i18n( $webp_bytes ),
				number_format_i18n( $source_bytes ),
				number_format_i18n( round( ( ( $webp_bytes - $source_bytes ) / $source_bytes ) * 100, 1 ), 1 )
			),
			array(
				'source_bytes' => $source_bytes,
				'webp_bytes'   => $webp_bytes,
			)
		);
	}
}
