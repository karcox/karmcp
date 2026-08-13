<?php
/**
 * Resumable bulk optimizer for the existing Media Library.
 *
 * Exposes an admin-ajax action that processes a batch of attachment IDs per
 * request (so long libraries never time out) and reports progress. A cursor in
 * an option makes the run resumable across page loads. A companion action
 * restores originals from the `karmcp-originals` backups.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk optimizer.
 *
 * @since 3.1.0
 */
class KarMCP_Bulk_Optimizer {

	const ACTION_BATCH   = 'karmcp_optimize_batch';
	const ACTION_RESTORE = 'karmcp_optimize_restore';
	const NONCE          = 'karmcp_modules';
	const OPTION_CURSOR  = 'karmcp_module_image_optimization_bulk_cursor';

	/** @var array Module settings. */
	private $settings;

	/**
	 * @param array $settings Module settings passed to the optimizer.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/** Wire the ajax handlers. */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_BATCH, array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_' . self::ACTION_RESTORE, array( $this, 'ajax_restore' ) );
	}

	/**
	 * Clamp the batch size to 1–50 (default 10).
	 *
	 * @param int $n Requested size.
	 * @return int
	 */
	public static function batch_size( int $n ): int {
		if ( $n <= 0 ) {
			return 10;
		}
		return max( 1, min( 50, $n ) );
	}

	/**
	 * Build a progress payload.
	 *
	 * @param int $total     Total attachments to process.
	 * @param int $processed Count processed so far.
	 * @return array{total:int,processed:int,remaining:int,percent:int,done:bool}
	 */
	public static function progress( int $total, int $processed ): array {
		$total     = max( 0, $total );
		$processed = max( 0, min( $processed, $total ) );
		$remaining = $total - $processed;
		$percent   = $total > 0 ? (int) floor( ( $processed / $total ) * 100 ) : 100;
		return array(
			'total'     => $total,
			'processed' => $processed,
			'remaining' => $remaining,
			'percent'   => $percent,
			'done'      => 0 === $remaining,
		);
	}

	/** admin-ajax: process one batch. */
	public function ajax_batch(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'karmcp' ) ), 403 );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$total = count( $ids );
		$batch = self::batch_size( isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 0 );
		$done  = (int) get_option( self::OPTION_CURSOR, 0 );

		$slice     = array_slice( $ids, $done, $batch );
		$optimizer = new KarMCP_Image_Optimizer( $this->settings );
		$upload    = wp_upload_dir();
		$basedir   = $upload['basedir'] ?? '';

		foreach ( $slice as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$files = $optimizer->sizes_to_process( $meta, $basedir );
			$res   = $optimizer->process_files( $files, $upload, $meta['file'] ?? '', $basedir );
			update_post_meta( $id, KarMCP_Image_Optimizer::META_KEY, $res );
		}

		$done += count( $slice );
		update_option( self::OPTION_CURSOR, $done );

		$progress = self::progress( $total, $done );
		if ( $progress['done'] ) {
			update_option( self::OPTION_CURSOR, 0 );
		}
		wp_send_json_success( $progress );
	}

	/** admin-ajax: restore originals from backups. */
	public function ajax_restore(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'karmcp' ) ), 403 );
		}
		$upload   = wp_upload_dir();
		$basedir  = rtrim( $upload['basedir'] ?? '', '/\\' );
		$origin   = $basedir . '/karmcp-originals';
		$restored = 0;
		if ( is_dir( $origin ) ) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $origin, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					$rel  = ltrim( str_replace( $origin, '', $file->getPathname() ), '/\\' );
					$dest = $basedir . '/' . $rel;
					if ( @copy( $file->getPathname(), $dest ) ) {
						@unlink( self::sibling( $dest ) );
						++$restored;
					}
				}
			}
		}
		update_option( self::OPTION_CURSOR, 0 );
		wp_send_json_success( array( 'restored' => $restored ) );
	}

	/**
	 * @param string $file Absolute image path.
	 * @return string WebP sibling path.
	 */
	private static function sibling( string $file ): string {
		return $file . '.webp';
	}
}
