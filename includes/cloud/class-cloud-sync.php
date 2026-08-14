<?php
/**
 * KarMCP Cloud sync: push/pull sandbox artifact bundles + config via the Cloud API.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KarMCP_Cloud_Sync {
	/**
	 * @return KarMCP_Sandbox_Cloud_Abilities
	 */
	private static function abilities(): KarMCP_Sandbox_Cloud_Abilities {
		return new KarMCP_Sandbox_Cloud_Abilities();
	}

	private static function not_connected(): \WP_Error {
		return new \WP_Error( 'not_connected', __( 'This site is not connected to KarMCP Cloud.', 'karmcp' ) );
	}

	/**
	 * Workspace plan + usage.
	 *
	 * @return array|\WP_Error
	 */
	public static function status() {
		return KarMCP_Cloud_Client::get( '/api/cloud/v1/me' );
	}

	/**
	 * List the account's cloud artifacts (optionally by kind).
	 *
	 * @param string $kind Optional kind filter.
	 * @return array|\WP_Error
	 */
	public static function list_remote( string $kind = '' ) {
		$path = '/api/cloud/v1/artifacts' . ( '' !== $kind ? '?kind=' . rawurlencode( $kind ) : '' );
		return KarMCP_Cloud_Client::get( $path );
	}

	/**
	 * Back up a local sandbox artifact to the cloud.
	 *
	 * @param string $kind Artifact kind (block/widget/snippet).
	 * @param int    $id   Local artifact id.
	 * @return array|\WP_Error
	 */
	public static function backup( string $kind, int $id ) {
		if ( ! KarMCP_Cloud::is_connected() ) {
			return self::not_connected();
		}
		$art = self::abilities()->resolve_artifact( $kind );
		if ( ! $art ) {
			return new \WP_Error( 'unknown_kind', __( 'Unknown artifact kind.', 'karmcp' ) );
		}
		$bundle = $art->to_bundle( $id );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		return KarMCP_Cloud_Client::put(
			'/api/cloud/v1/artifacts',
			array(
				'artifact_uuid'    => (string) ( $bundle['uuid'] ?? '' ),
				'kind'             => (string) ( $bundle['kind'] ?? $kind ),
				'title'            => (string) ( $bundle['meta']['title'] ?? '' ),
				'origin_site_uuid' => KarMCP_Cloud::site_uuid(),
				'bundle'           => (string) wp_json_encode( $bundle ),
				'checksum'         => (string) ( $bundle['checksum'] ?? '' ),
			)
		);
	}

	/**
	 * The sandbox CPT post type for each artifact kind.
	 *
	 * @return array<string,string>
	 */
	private static function kind_post_types(): array {
		return array(
			'snippet' => class_exists( 'KarMCP_PHP_Snippet_Store' ) ? KarMCP_PHP_Snippet_Store::POST_TYPE : 'karmcp_php_snippet',
			'widget'  => class_exists( 'KarMCP_Widget_Store' ) ? KarMCP_Widget_Store::POST_TYPE : 'karmcp_widget',
			'block'   => class_exists( 'KarMCP_Block_Store' ) ? KarMCP_Block_Store::POST_TYPE : 'karmcp_block',
		);
	}

	/**
	 * Back up every local sandbox artifact (optionally only the given kinds) to
	 * the cloud in one call — the bulk counterpart to backup(). Reuses the
	 * per-artifact backup() so each push keeps its checksum/validation.
	 *
	 * @param string[] $kinds Kinds to sync (block/widget/snippet); empty = all.
	 * @return array|\WP_Error { pushed, failed, items:[{kind,id,ok,error?}] }.
	 */
	public static function bulk_backup( array $kinds = array() ) {
		if ( ! KarMCP_Cloud::is_connected() ) {
			return self::not_connected();
		}
		$map     = self::kind_post_types();
		$kinds   = empty( $kinds ) ? array_keys( $map ) : array_values( array_intersect( $kinds, array_keys( $map ) ) );
		$results = array( 'pushed' => 0, 'failed' => 0, 'items' => array() );

		foreach ( $kinds as $kind ) {
			// Skip a kind whose store isn't available (e.g. block on a free build).
			if ( ! self::abilities()->resolve_artifact( $kind ) ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'      => $map[ $kind ],
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( (array) $ids as $id ) {
				$res = self::backup( $kind, (int) $id );
				if ( is_wp_error( $res ) ) {
					$results['failed']++;
					$results['items'][] = array( 'kind' => $kind, 'id' => (int) $id, 'ok' => false, 'error' => $res->get_error_message() );
				} else {
					$results['pushed']++;
					$results['items'][] = array( 'kind' => $kind, 'id' => (int) $id, 'ok' => true );
				}
			}
		}
		return $results;
	}

	/**
	 * Pull a cloud artifact into this site (imports as a new local draft).
	 *
	 * @param string $artifact_uuid Cloud artifact uuid.
	 * @param string $kind          Artifact kind (falls back to the bundle's kind).
	 * @return array|\WP_Error
	 */
	public static function pull( string $artifact_uuid, string $kind = '' ) {
		if ( ! KarMCP_Cloud::is_connected() ) {
			return self::not_connected();
		}
		$res = KarMCP_Cloud_Client::get( '/api/cloud/v1/artifacts/' . rawurlencode( $artifact_uuid ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$bundle = json_decode( (string) ( $res['bundle'] ?? '' ), true );
		if ( ! is_array( $bundle ) ) {
			return new \WP_Error( 'bad_bundle', __( 'The cloud artifact could not be read.', 'karmcp' ) );
		}
		$art = self::abilities()->resolve_artifact( '' !== $kind ? $kind : (string) ( $bundle['kind'] ?? '' ) );
		if ( ! $art ) {
			return new \WP_Error( 'unknown_kind', __( 'Unknown artifact kind.', 'karmcp' ) );
		}
		$new_id = $art->apply_bundle( $bundle );
		return is_wp_error( $new_id ) ? $new_id : array( 'id' => (int) $new_id );
	}

	/**
	 * Push a config blob (settings/brand_kit/tool_toggles) to the cloud.
	 *
	 * @param string $type Config type.
	 * @param array  $data Config data.
	 * @return array|\WP_Error
	 */
	public static function push_config( string $type, array $data ) {
		if ( ! KarMCP_Cloud::is_connected() ) {
			return self::not_connected();
		}
		return KarMCP_Cloud_Client::put(
			'/api/cloud/v1/config/' . rawurlencode( $type ),
			array( 'scope' => 'site', 'site_uuid' => KarMCP_Cloud::site_uuid(), 'data' => (string) wp_json_encode( $data ) )
		);
	}

	/**
	 * Pull a config blob from the cloud.
	 *
	 * @param string $type Config type.
	 * @return array|\WP_Error
	 */
	public static function pull_config( string $type ) {
		return KarMCP_Cloud_Client::get(
			'/api/cloud/v1/config/' . rawurlencode( $type ) . '?site_uuid=' . rawurlencode( KarMCP_Cloud::site_uuid() )
		);
	}

}
