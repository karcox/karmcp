<?php
/**
 * Widget Bundle Adapter — presents the existing Widget Builder store
 * (`KarMCP_Widget_Store`) as a portable, cloud-ready
 * `KarMCP_Sandbox_Artifact`, without changing the store's internals.
 *
 * @package KarMCP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin adapter: reads/writes go through `KarMCP_Widget_Store`'s existing
 * static API; this class only shapes the result into (and out of) the
 * `KarMCP_Sandbox_Bundle` envelope.
 *
 * @since 3.7.0
 */
class KarMCP_Widget_Bundle_Adapter implements KarMCP_Sandbox_Artifact {

	const META_UUID = '_karmcp_uuid';

	/**
	 * @since 3.7.0
	 * @return string
	 */
	public function kind(): string {
		return 'widget';
	}

	/**
	 * Mints (once) and returns the shared cross-kind UUID for a widget post.
	 *
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return string
	 */
	public function uuid( int $id ): string {
		$u = (string) get_post_meta( $id, self::META_UUID, true );
		if ( '' === $u ) {
			$u = wp_generate_uuid4();
			update_post_meta( $id, self::META_UUID, $u );
		}
		return $u;
	}

	/**
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return array
	 */
	public function sync_meta( int $id ): array {
		return array(
			'uuid'       => $this->uuid( $id ),
			'origin'     => (string) get_post_meta( $id, '_karmcp_origin', true ) ?: 'local',
			'remote_id'  => (string) get_post_meta( $id, '_karmcp_remote_id', true ),
			'sync_state' => (string) get_post_meta( $id, '_karmcp_sync_state', true ) ?: 'dirty',
			'version'    => (int) get_post_meta( $id, '_karmcp_version', true ),
			'updated_at' => (string) get_post_meta( $id, '_karmcp_updated_at', true ),
		);
	}

	/**
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return string
	 */
	public function checksum( int $id ): string {
		return KarMCP_Sandbox_Bundle::checksum( $this->assets( $id ) );
	}

	/**
	 * The widget's generated files (PHP always present; CSS/JS only if set).
	 *
	 * @param int $id Widget post ID.
	 * @return array<string,string>
	 */
	private function assets( int $id ): array {
		$assets = array( 'widget.php' => KarMCP_Widget_Store::get_php( $id ) );
		$css    = KarMCP_Widget_Store::get_css( $id );
		if ( '' !== $css ) {
			$assets['style.css'] = $css;
		}
		$js = KarMCP_Widget_Store::get_js( $id );
		if ( '' !== $js ) {
			$assets['script.js'] = $js;
		}
		return $assets;
	}

	/**
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return array|WP_Error
	 */
	public function to_bundle( int $id ) {
		$summary = KarMCP_Widget_Store::summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		$spec = KarMCP_Widget_Store::get_spec( $id ) ?? array();
		$sm   = $this->sync_meta( $id );
		return KarMCP_Sandbox_Bundle::build(
			'widget',
			$sm['uuid'],
			array(
				'title'       => (string) ( $summary['title'] ?? '' ),
				'description' => '',
				'author'      => (string) ( wp_get_current_user()->user_login ?? '' ),
				'license'     => 'GPL-2.0-or-later',
			),
			$spec,
			$this->assets( $id ),
			max( 1, $sm['version'] ),
			$sm['updated_at'] ?: gmdate( 'c' )
		);
	}

	/**
	 * Imports a bundle as a NEW draft widget (never overwrites an existing one).
	 *
	 * @since 3.7.0
	 *
	 * @param array $bundle Bundle as produced by to_bundle()/validate()-shaped.
	 * @return int|WP_Error New local widget post ID.
	 */
	public function apply_bundle( array $bundle ) {
		$valid = KarMCP_Sandbox_Bundle::validate( $bundle );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'widget' !== $bundle['kind'] ) {
			return new WP_Error( 'kind_mismatch', __( 'Bundle is not a widget.', 'karmcp' ) );
		}
		$res = KarMCP_Widget_Store::create( is_array( $bundle['spec'] ) ? $bundle['spec'] : array(), false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$new_id = (int) $res['widget_id'];
		update_post_meta( $new_id, self::META_UUID, sanitize_text_field( (string) $bundle['uuid'] ) );
		update_post_meta( $new_id, '_karmcp_origin', 'imported' );
		return $new_id;
	}
}
