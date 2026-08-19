<?php
/**
 * Installed-schema state — one autoloaded option for every table this plugin owns.
 *
 * The four stores that own a table (search index, change blobs, OAuth, redirects)
 * each ask "is my table already at the current version?" from `init:20`, on every
 * request. Each used to answer from its own `karmcp_*_db_version` option written
 * with `update_option( …, …, false )` — autoload OFF. Without a persistent object
 * cache that is one `SELECT` per option per request, three or four of them on
 * every page a visitor loads, to answer a question whose answer only changes when
 * the plugin is updated.
 *
 * This replaces those four with a single map, `{ key => installed version }`,
 * written autoloaded so it arrives inside `alloptions` — already fetched by
 * WordPress on every request — and compared in PHP. Zero extra queries.
 *
 * Deliberately a map and not a plugin-version stamp: a store whose feature is a
 * module (redirects) can be switched on long after the version marker was last
 * written, and its key is simply absent until it installs. A single "everything
 * is installed for build X" flag would skip it forever.
 *
 * No migration is needed on upgrade: an install that predates this class has an
 * empty map, so each `maybe_install()` runs once more. `dbDelta()` on an existing,
 * up-to-date table is a no-op, and the map is written from then on. The old
 * `karmcp_*_db_version` rows are left in place and get swept by the uninstaller,
 * which deletes on the `karmcp_%` prefix.
 *
 * @package KarMCP
 * @since   1.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The installed-schema map.
 *
 * @since 1.26.0
 */
class KarMCP_Schema_State {

	/** Autoloaded option holding the map. */
	const OPTION = 'karmcp_schema_state';

	/** Map keys — one per table-owning store. */
	const KEY_SEARCH    = 'search_index';
	const KEY_CHANGELOG = 'changelog';
	const KEY_OAUTH     = 'oauth';
	const KEY_REDIRECTS = 'redirects';

	/**
	 * The whole map.
	 *
	 * @since 1.26.0
	 *
	 * @return array<string,int>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$map = array();
		foreach ( $stored as $key => $version ) {
			$map[ (string) $key ] = (int) $version;
		}
		return $map;
	}

	/**
	 * The schema version currently installed for a store, 0 when never installed.
	 *
	 * @since 1.26.0
	 *
	 * @param string $key One of the KEY_* constants.
	 * @return int
	 */
	public static function installed( string $key ): int {
		$map = self::all();
		return isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
	}

	/**
	 * Records a store's schema as installed at the given version.
	 *
	 * Autoload is passed explicitly: the whole point of this class is that the
	 * map rides along in `alloptions` instead of costing a query per request.
	 *
	 * @since 1.26.0
	 *
	 * @param string $key     One of the KEY_* constants.
	 * @param int    $version The schema version now on disk.
	 */
	public static function mark( string $key, int $version ): void {
		$map         = self::all();
		$map[ $key ] = (int) $version;
		update_option( self::OPTION, $map, true );
	}

	/**
	 * Drops a store's entry, so its next `maybe_install()` runs again.
	 *
	 * Used when a table is deleted out from under the plugin (uninstall of a
	 * single feature, test teardown); not part of the request path.
	 *
	 * @since 1.26.0
	 *
	 * @param string $key One of the KEY_* constants.
	 */
	public static function forget( string $key ): void {
		$map = self::all();
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		unset( $map[ $key ] );
		update_option( self::OPTION, $map, true );
	}
}
