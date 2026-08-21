<?php
/**
 * Classmap autoloader.
 *
 * Replaces the hand-written `require_once` list that `KarMCP_Bootstrap::load_classes()`
 * ran on every request. That list could not tell whether a class was needed by
 * THIS request, so it loaded all of them: ~1.5 MB of PHP parsed on a front-end
 * page view to define the malware scanner, the stock-image clients, the OAuth
 * server and the WP-CLI job runner, none of which a visitor can reach.
 *
 * Use decides now, request by request. Two properties of the tree make that
 * safe, and both are pinned by ClassmapTest rather than trusted:
 *
 * 1. No class file does work at include time. Loading a file never registered
 *    a hook — `wire_hooks()` does that, by name — so dropping the eager loads
 *    cannot silently unhook anything.
 * 2. Exactly two class files declare a global function alongside their class
 *    (`karmcp_register_ability()`, `karmcp_themer_location()`). A function is
 *    not reachable by autoload, so those two keep loading eagerly. A third one
 *    fails the test.
 *
 * @package KarMCP
 * @since   1.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves KarMCP_* class names against the generated map.
 *
 * @since 1.30.0
 */
class KarMCP_Autoloader {

	/** Prefix every symbol this plugin declares carries. */
	const PREFIX = 'KarMCP_';

	/**
	 * The map, loaded on first miss.
	 *
	 * @var array<string,string>|null
	 */
	private static $map = null;

	/**
	 * Registers the autoloader.
	 *
	 * Appended, not prepended: another plugin's autoloader has no business
	 * being asked for our classes, but ours has no business jumping the queue
	 * either, and the prefix check below makes the position academic.
	 *
	 * @since 1.30.0
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads the file that declares a class, if we own the name.
	 *
	 * @since 1.30.0
	 *
	 * @param string $class_name Fully qualified class name being resolved.
	 */
	public static function load( string $class_name ): void {
		// Namespaced classes are never ours — the bundled MCP Adapter lives
		// under WP\MCP and has its own resolver, claimed in the main file.
		if ( 0 !== strncmp( $class_name, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return;
		}

		if ( null === self::$map ) {
			self::$map = self::map();
		}

		$key = strtolower( $class_name );
		if ( isset( self::$map[ $key ] ) ) {
			require_once KARMCP_DIR . self::$map[ $key ];
		}
	}

	/**
	 * The generated map.
	 *
	 * Read once per request and only on the first KarMCP_* miss. Building it by
	 * scanning the tree instead would stat 265 files per request, which is the
	 * cost this whole change exists to remove.
	 *
	 * @since 1.30.0
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		$file = KARMCP_DIR . 'includes/classmap.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$map = require $file;

		return is_array( $map ) ? $map : array();
	}
}
