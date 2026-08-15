<?php
/**
 * Uninstall cleanup.
 *
 * Wired to WordPress's uninstall hook from the bootstrap file. Removes
 * plugin-owned options/transients/user-meta, drops the tables the plugin
 * created, and, critically, the generated executable PHP (custom widgets + PHP
 * snippets) which must never survive an uninstall.
 *
 * @package KarMCP
 * @since   2.1.0 (extracted from karmcp_after_uninstall, since 1.6.1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes plugin-owned data on uninstall.
 *
 * @since 2.1.0
 */
class KarMCP_Uninstaller {

	/**
	 * Runs the uninstall cleanup.
	 *
	 * On a network install every site has its own options table and its own set
	 * of `{$wpdb->prefix}karmcp_*` tables, so the cleanup runs once per site —
	 * uninstalling from the network admin otherwise leaves every subsite's data
	 * behind, including its OAuth clients and access tokens.
	 *
	 * @since 2.1.0
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clean_site();
				restore_current_blog();
			}
			return;
		}

		self::clean_site();
	}

	/**
	 * Everything the plugin owns on the current site.
	 *
	 * @since 1.2.0
	 */
	private static function clean_site(): void {
		// Generated PHP goes first: those routines resolve their own file paths,
		// and running them after the options sweep would mean asking a store to
		// clean up with its settings already deleted.
		self::delete_generated_php();
		self::delete_options();
		self::delete_user_meta();
		self::drop_tables();
	}

	/**
	 * Delete every option and transient the plugin owns.
	 *
	 * Swept by prefix rather than listed by name. The plugin owns roughly thirty
	 * option keys across settings, module state, schema versions, cloud
	 * connection and audit logs, and the old hand-maintained list had drifted to
	 * seven of them — a list that has to be edited every time a feature is added
	 * is a list that will be wrong again.
	 *
	 * @since 1.2.0
	 */
	private static function delete_options(): void {
		global $wpdb;

		// LIKE patterns: `_` is a single-character wildcard in SQL, so the
		// underscore in the prefix is escaped or `karmcpX...` would match too.
		$patterns = array(
			$wpdb->esc_like( 'karmcp_' ) . '%',
			$wpdb->esc_like( '_transient_karmcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_karmcp_' ) . '%',
			$wpdb->esc_like( '_site_transient_karmcp_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_karmcp_' ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup; no cache to prime.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		}

		// Leftovers from the plugin this build's settings were migrated from, and
		// from the upstream Pro overlay. Neither carries the karmcp_ prefix, so
		// the sweep above does not reach them.
		delete_option( 'elementor_mcp_disabled_tools' );
		delete_option( 'karmcp_ai_models' );

		wp_cache_flush();
	}

	/**
	 * Drop the plugin's own user meta: notice dismissals and the leftover AI
	 * keys from the upstream overlay.
	 *
	 * @since 1.2.0
	 */
	private static function delete_user_meta(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( 'karmcp_' ) . '%'
			)
		);
	}

	/**
	 * Remove generated executable PHP.
	 *
	 * This is the part that is not merely tidiness: a widget or snippet file
	 * left in uploads is code that still runs.
	 *
	 * @since 1.2.0
	 */
	private static function delete_generated_php(): void {
		if ( ! class_exists( 'KarMCP_Widget_Store' ) ) {
			require_once KARMCP_DIR . 'includes/class-widget-store.php';
		}
		if ( class_exists( 'KarMCP_Widget_Store' ) ) {
			KarMCP_Widget_Store::uninstall_cleanup();
		}

		if ( ! class_exists( 'KarMCP_PHP_Snippet_Store' ) ) {
			require_once KARMCP_DIR . 'includes/class-php-snippet-store.php';
		}
		if ( class_exists( 'KarMCP_PHP_Snippet_Store' ) ) {
			KarMCP_PHP_Snippet_Store::uninstall_cleanup();
		}

		// Both routines above delete their own posts as well as their files,
		// because a widget or snippet post IS the source of executable code.
		//
		// The remaining post types are deliberately LEFT in place: brand-kit
		// backups, Themer templates and Skills are things a person wrote, and a
		// user who removes the plugin to reinstall it would otherwise lose them.
		// Those rows are inert without the plugin — nothing registers the types,
		// so nothing renders them.
	}

	/**
	 * Drop the seven tables the plugin creates.
	 *
	 * The OAuth pair matters most: leaving it behind leaves registered clients
	 * and live access/refresh tokens on the site after the plugin that
	 * understood them is gone.
	 *
	 * @since 1.2.0
	 */
	private static function drop_tables(): void {
		global $wpdb;

		$tables = array(
			'karmcp_oauth_tokens',
			'karmcp_oauth_clients',
			'karmcp_change_blobs',
			'karmcp_search_index',
			'karmcp_redirects',
			'karmcp_login_events',
			'karmcp_vulnerabilities',
		);

		foreach ( $tables as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB -- identifier is a constant from the list above; DROP cannot be prepared.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}
	}
}
