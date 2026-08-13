<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class KarMCP_Sandbox_Store implements KarMCP_Sandbox_Artifact {
	const META_UUID       = '_karmcp_uuid';
	const META_ORIGIN     = '_karmcp_origin';
	const META_REMOTE_ID  = '_karmcp_remote_id';
	const META_SYNC_STATE = '_karmcp_sync_state';
	const META_VERSION    = '_karmcp_version';
	const META_UPDATED_AT = '_karmcp_updated_at';

	abstract public function kind(): string;
	abstract protected function sandbox_subdir(): string;
	abstract protected function manifest_filename(): string;

	public function ensure_uuid( int $id ): string {
		$u = (string) get_post_meta( $id, self::META_UUID, true );
		if ( '' === $u ) {
			$u = wp_generate_uuid4();
			update_post_meta( $id, self::META_UUID, $u );
		}
		return $u;
	}
	public function uuid( int $id ): string { return $this->ensure_uuid( $id ); }

	public function bump_version( int $id ): int {
		$v = (int) get_post_meta( $id, self::META_VERSION, true ) + 1;
		update_post_meta( $id, self::META_VERSION, $v );
		update_post_meta( $id, self::META_UPDATED_AT, gmdate( 'c' ) );
		update_post_meta( $id, self::META_SYNC_STATE, 'dirty' );
		if ( '' === (string) get_post_meta( $id, self::META_ORIGIN, true ) ) {
			update_post_meta( $id, self::META_ORIGIN, 'local' );
		}
		return $v;
	}

	public function sync_meta( int $id ): array {
		return array(
			'uuid'       => $this->ensure_uuid( $id ),
			'origin'     => (string) get_post_meta( $id, self::META_ORIGIN, true ) ?: 'local',
			'remote_id'  => (string) get_post_meta( $id, self::META_REMOTE_ID, true ),
			'sync_state' => (string) get_post_meta( $id, self::META_SYNC_STATE, true ) ?: 'dirty',
			'version'    => (int) get_post_meta( $id, self::META_VERSION, true ),
			'updated_at' => (string) get_post_meta( $id, self::META_UPDATED_AT, true ),
		);
	}

	public function sandbox_base(): string { return KarMCP_Sandbox_Paths::base_dir(); }
	protected function subdir_path(): string { return $this->sandbox_base() . '/' . $this->sandbox_subdir(); }
	public function artifact_dir( int $id ): string { return $this->subdir_path() . '/' . $id; }
	/**
	 * Public URL to an artifact's directory (no trailing slash). Needed to
	 * enqueue an artifact's own assets (e.g. a block's editor script/style),
	 * whose URLs WordPress's block.json `file:` resolver cannot compute for a
	 * sandbox living outside a plugin/theme.
	 *
	 * @param int $id Artifact post ID.
	 * @return string
	 */
	public function artifact_url( int $id ): string {
		return KarMCP_Sandbox_Paths::base_url() . '/' . $this->sandbox_subdir() . '/' . $id;
	}
	public function manifest_path(): string { return $this->sandbox_base() . '/' . $this->manifest_filename(); }

	protected function write_file( string $path, string $contents ): bool {
		wp_mkdir_p( dirname( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = false !== file_put_contents( $path, $contents );
		if ( $ok && function_exists( 'opcache_invalidate' ) && '.php' === substr( $path, -4 ) ) {
			opcache_invalidate( $path, true );
		}
		return $ok;
	}
	protected function read_file( string $path ): string {
		if ( ! file_exists( $path ) ) { return ''; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( $path );
	}
	protected function delete_file( string $path ): void {
		if ( file_exists( $path ) ) { /* phpcs:ignore */ @unlink( $path ); }
	}
	protected function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) { return; }
		$items = scandir( $dir ); if ( false === $items ) { return; }
		foreach ( $items as $i ) {
			if ( '.' === $i || '..' === $i ) { continue; }
			$p = $dir . '/' . $i;
			is_dir( $p ) ? $this->rmdir_recursive( $p ) : @unlink( $p ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}
	protected function ensure_sandbox() {
		$dir = $this->subdir_path();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sandbox_unwritable', __( 'Could not create the sandbox directory under wp-content.', 'karmcp' ) );
		}
		KarMCP_Sandbox_Paths::harden();
		KarMCP_Sandbox_Paths::guard_subdir( $dir );
		return true;
	}
}
