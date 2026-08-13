<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface KarMCP_Sandbox_Artifact {
	public function kind(): string;
	public function uuid( int $id ): string;
	public function to_bundle( int $id );          // array|WP_Error
	public function apply_bundle( array $bundle );  // int|WP_Error (new local id)
	public function checksum( int $id ): string;
	public function sync_meta( int $id ): array;
}
