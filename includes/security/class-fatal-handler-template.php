<?php
/**
 * The source of the `fatal-error-handler.php` drop-in, and its installer.
 *
 * WordPress loads `wp-content/fatal-error-handler.php` instead of its own
 * handler when the file exists. That is our code running *at the moment of the
 * fatal*, which is the only place from which a dead site can be brought back —
 * once PHP has died, the REST API is gone and MCP with it, so the agent cannot
 * help at exactly the moment it is most wanted.
 *
 * One correction to what is usually assumed: **WordPress's own Recovery Mode
 * does not fix the site**. It emails a link and pauses the extension for that
 * recovery session; the visitor keeps seeing the error. That is why this exists.
 *
 * The drop-in itself is shipped as a template string rather than as a PHP file
 * of its own, for the same reason `class-security-malware-audit.php` assembles
 * its patterns at runtime: a standalone file that a host scanner decides to
 * quarantine gets zeroed rather than removed, and a zeroed drop-in is a fatal
 * on every request with no clue as to why.
 *
 * @package KarMCP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and installs the fatal-error handler drop-in.
 *
 * @since 1.6.0
 */
class KarMCP_Fatal_Handler_Template {

	const DROPIN         = 'fatal-error-handler.php';
	const MARKER         = 'KarMCP fatal-error handler';
	const OPTION_LOG     = 'karmcp_fatal_log';
	const OPTION_PAUSED  = 'karmcp_fatal_paused';
	const OPTION_CONFIG  = 'karmcp_fatal_config';

	/** Never auto-disabled, whatever they do. */
	const ALWAYS_PROTECTED = array( 'karmcp/karmcp.php' );

	/**
	 * Full path to the drop-in.
	 *
	 * @since 1.6.0
	 * @return string
	 */
	public static function path(): string {
		return WP_CONTENT_DIR . '/' . self::DROPIN;
	}

	/**
	 * Whether the installed drop-in is ours.
	 *
	 * @since 1.6.0
	 * @return string 'none' | 'ours' | 'foreign'
	 */
	public static function status(): string {
		$path = self::path();
		if ( ! file_exists( $path ) ) {
			return 'none';
		}
		$head = (string) @file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		return ( false !== strpos( $head, self::MARKER ) ) ? 'ours' : 'foreign';
	}

	/**
	 * Whether the installed drop-in was written by THIS version of the plugin.
	 *
	 * The drop-in is a copy in wp-content, so updating the plugin does not
	 * update it. That was a real defect and an invisible one: 1.7.4 fixed the
	 * handler to ignore non-fatal errors, the plugin was updated, and the file
	 * on disk carried on recording notices exactly as before. Every future fix
	 * to this template would have been just as silent.
	 *
	 * @since 1.7.5
	 * @return bool
	 */
	public static function is_current(): bool {
		if ( 'ours' !== self::status() ) {
			return false;
		}
		$head = (string) @file_get_contents( self::path(), false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		$want = self::MARKER . ' v' . ( defined( 'KARMCP_VERSION' ) ? KARMCP_VERSION : '0' );
		return false !== strpos( $head, $want );
	}

	/**
	 * Rewrites the drop-in when the plugin has moved on. Cheap enough to call on
	 * every admin request: one bounded read of the first kilobyte.
	 *
	 * Only ever touches a drop-in that is already ours — somebody else's handler
	 * is still left alone.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public static function refresh_if_stale(): void {
		if ( 'ours' === self::status() && ! self::is_current() ) {
			self::install();
		}
	}

	/**
	 * Installs the drop-in.
	 *
	 * Refuses to overwrite somebody else's handler unless told to, and backs it
	 * up when it does: another plugin's fatal handler is load-bearing for that
	 * plugin, and silently replacing it is how you turn one broken thing into two.
	 *
	 * @since 1.6.0
	 *
	 * @param bool $force Replace a foreign drop-in (backed up first).
	 * @return true|\WP_Error
	 */
	public static function install( bool $force = false ) {
		$status = self::status();

		if ( 'foreign' === $status && ! $force ) {
			return new \WP_Error(
				'foreign_dropin',
				__( 'Another plugin already installs wp-content/fatal-error-handler.php. Replacing it may break that plugin, so it is not done automatically — pass force:true to replace it (the existing file is backed up alongside it first).', 'karmcp' )
			);
		}

		$path = self::path();

		if ( 'foreign' === $status ) {
			$backup = $path . '.karmcp-backup';
			if ( ! @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				return new \WP_Error( 'backup_failed', __( 'Could not back up the existing fatal-error handler, so it was left untouched.', 'karmcp' ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp-content, before WP_Filesystem is available on this path.
		$written = @file_put_contents( $path, self::source() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $written ) {
			return new \WP_Error(
				'write_failed',
				sprintf(
					/* translators: %s: path. */
					__( 'Could not write %s. Check that wp-content is writable.', 'karmcp' ),
					self::DROPIN
				)
			);
		}

		return true;
	}

	/**
	 * Removes our drop-in and restores a backed-up foreign one if there is one.
	 *
	 * @since 1.6.0
	 * @return true|\WP_Error
	 */
	public static function uninstall() {
		if ( 'ours' !== self::status() ) {
			return new \WP_Error( 'not_ours', __( 'The installed fatal-error handler is not KarMCP\'s, so it was left alone.', 'karmcp' ) );
		}

		$path   = self::path();
		$backup = $path . '.karmcp-backup';

		if ( file_exists( $backup ) ) {
			if ( ! @rename( $backup, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'restore_failed', __( 'Could not restore the previous fatal-error handler.', 'karmcp' ) );
			}
			return true;
		}

		wp_delete_file( $path );
		return true;
	}

	/**
	 * The drop-in source.
	 *
	 * Every constraint in this string comes from where it runs. It executes on
	 * shutdown after a fatal, so memory may already be exhausted and WordPress
	 * may be half-loaded: **no autoloader, no plugin classes, no dependencies**.
	 * If the handler itself dies the site is worse off than with no handler at
	 * all, so it does the least it can get away with and wraps the rest in a
	 * try/catch that swallows everything.
	 *
	 * @since 1.6.0
	 * @return string
	 */
	public static function source(): string {
		$marker  = self::MARKER . ' v' . ( defined( 'KARMCP_VERSION' ) ? KARMCP_VERSION : '0' );
		$log    = self::OPTION_LOG;
		$paused = self::OPTION_PAUSED;
		$config = self::OPTION_CONFIG;

		return <<<PHP
<?php
/**
 * {$marker}
 *
 * Installed by KarMCP. Do not edit — reinstalled from the Security tab.
 *
 * Runs on shutdown after a fatal error, where memory may be exhausted and
 * WordPress half-loaded. No autoloader, no plugin classes, no dependencies:
 * a handler that dies leaves the site worse off than no handler at all.
 */

if ( ! class_exists( 'WP_Fatal_Error_Handler' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
}

class KarMCP_Fatal_Error_Handler extends WP_Fatal_Error_Handler {

	const LOG_OPTION    = '{$log}';
	const PAUSED_OPTION = '{$paused}';
	const CONFIG_OPTION = '{$config}';
	const KEEP          = 25;

	/**
	 * Error types that actually end the request. error_get_last() returns the
	 * last error of ANY severity, so without this filter a deprecation notice
	 * or an "undefined property" warning was recorded as a fatal — and, far
	 * worse, counted towards auto-deactivating the plugin that emitted it.
	 * Observed on a real site: Elementor Pro would have been switched off for a
	 * notice.
	 *
	 * Deliberately the same five types WordPress itself treats as fatal in
	 * WP_Fatal_Error_Handler::detect_error(). E_CORE_WARNING and
	 * E_COMPILE_WARNING are not on that list and do not end the request, so they
	 * are not on this one either.
	 */
	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR );

	public function handle() {
		try {
			\$error = error_get_last();
			if ( is_array( \$error ) && in_array( (int) ( \$error['type'] ?? 0 ), self::FATAL_TYPES, true ) ) {
				\$this->karmcp_record( \$error );
			}
		} catch ( \\Throwable \$ignored ) {
			// Never let the handler become the failure. The site is already
			// broken; making it broken in a second way helps nobody.
			unset( \$ignored );
		}

		parent::handle();
	}

	private function karmcp_record( array \$error ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		\$file   = isset( \$error['file'] ) ? (string) \$error['file'] : '';
		\$plugin = \$this->karmcp_plugin_for( \$file );
		\$now    = time();

		\$log = get_option( self::LOG_OPTION, array() );
		\$log = is_array( \$log ) ? \$log : array();
		\$log[] = array(
			'at'      => \$now,
			'type'    => isset( \$error['type'] ) ? (int) \$error['type'] : 0,
			'message' => isset( \$error['message'] ) ? substr( (string) \$error['message'], 0, 500 ) : '',
			'file'    => \$this->karmcp_relative( \$file ),
			'line'    => isset( \$error['line'] ) ? (int) \$error['line'] : 0,
			'plugin'  => \$plugin,
		);
		if ( count( \$log ) > self::KEEP ) {
			\$log = array_slice( \$log, -self::KEEP );
		}
		update_option( self::LOG_OPTION, \$log, false );

		if ( '' !== \$plugin ) {
			\$this->karmcp_maybe_pause( \$plugin, \$log, \$now );
		}
	}

	/**
	 * Disables the offending plugin, but only on repeat and never one on the
	 * protected list. A single transient fatal must not be able to take the
	 * shop offline in a different way than the bug would have.
	 */
	private function karmcp_maybe_pause( \$plugin, array \$log, \$now ) {
		\$config    = get_option( self::CONFIG_OPTION, array() );
		\$config    = is_array( \$config ) ? \$config : array();
		\$enabled   = ! empty( \$config['auto_pause'] );
		\$strikes   = isset( \$config['strikes'] ) ? max( 2, (int) \$config['strikes'] ) : 3;
		\$window    = isset( \$config['window'] ) ? max( 60, (int) \$config['window'] ) : 600;
		\$protected = isset( \$config['protected'] ) && is_array( \$config['protected'] ) ? \$config['protected'] : array();

		if ( ! \$enabled ) {
			return;
		}
		if ( in_array( \$plugin, \$protected, true ) || 'karmcp/karmcp.php' === \$plugin ) {
			return;
		}

		\$hits = 0;
		foreach ( \$log as \$entry ) {
			if ( isset( \$entry['plugin'], \$entry['at'] ) && \$entry['plugin'] === \$plugin && ( \$now - (int) \$entry['at'] ) <= \$window ) {
				\$hits++;
			}
		}
		if ( \$hits < \$strikes ) {
			return;
		}

		\$active = get_option( 'active_plugins', array() );
		\$active = is_array( \$active ) ? \$active : array();
		\$index  = array_search( \$plugin, \$active, true );
		if ( false === \$index ) {
			return;
		}

		unset( \$active[ \$index ] );
		update_option( 'active_plugins', array_values( \$active ) );

		\$paused = get_option( self::PAUSED_OPTION, array() );
		\$paused = is_array( \$paused ) ? \$paused : array();
		\$paused[ \$plugin ] = array(
			'at'     => \$now,
			'hits'   => \$hits,
			'window' => \$window,
		);
		update_option( self::PAUSED_OPTION, \$paused, false );
	}

	private function karmcp_plugin_for( \$file ) {
		if ( '' === \$file || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}
		\$dir  = wp_normalize_path( WP_PLUGIN_DIR );
		\$path = wp_normalize_path( \$file );
		if ( 0 !== strpos( \$path, \$dir . '/' ) ) {
			return '';
		}
		\$rest  = substr( \$path, strlen( \$dir ) + 1 );
		\$slug  = strtok( \$rest, '/' );
		if ( ! \$slug ) {
			return '';
		}
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as \$entry ) {
			if ( 0 === strpos( (string) \$entry, \$slug . '/' ) ) {
				return (string) \$entry;
			}
		}
		return '';
	}

	private function karmcp_relative( \$file ) {
		\$file = wp_normalize_path( (string) \$file );
		\$root = wp_normalize_path( ABSPATH );
		return ( 0 === strpos( \$file, \$root ) ) ? substr( \$file, strlen( \$root ) ) : \$file;
	}
}

return new KarMCP_Fatal_Error_Handler();
PHP;
	}
}
