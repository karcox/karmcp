<?php
/**
 * Front-end redirect handler. On template_redirect (before the theme serves a
 * 404), matches the requested path against enabled redirects and issues a
 * 301/302. Skips wp-admin, REST, cron, and login. The hot path is a single
 * indexed source_path lookup.
 *
 * @package KarMCP
 * @since   3.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect handler.
 *
 * @since 3.11.0
 */
class KarMCP_Redirect_Handler {

	/**
	 * Register the front-end hook (early, before 404 templating).
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Contexts where redirects must never fire.
	 *
	 * @return bool
	 */
	public static function should_skip(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return (bool) preg_match( '#^(wp-admin|wp-json|wp-login\.php)#', ltrim( $uri, '/' ) );
	}

	/**
	 * Match the current request and redirect when a source is found.
	 */
	public static function maybe_redirect(): void {
		if ( self::should_skip() || ! class_exists( 'KarMCP_Redirect_Store' ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = KarMCP_Redirect_Store::normalize_path( $uri );
		if ( '/' === $path ) {
			return;
		}
		$row = KarMCP_Redirect_Store::find_by_source( $path );
		if ( ! $row || empty( $row['enabled'] ) ) {
			return;
		}
		$target = KarMCP_Redirect_Store::resolve_target( $row );
		if ( '' === $target ) {
			return; // Target post is gone → treat as inactive.
		}
		if ( KarMCP_Redirect_Store::would_loop( $path, $target ) ) {
			return;
		}
		// Forward the original query string to a query-less target.
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query && false === strpos( $target, '?' ) ) {
			$target .= '?' . $query;
		}
		KarMCP_Redirect_Store::record_hit( (int) $row['id'] );
		$code = in_array( (int) $row['status_code'], array( 301, 302 ), true ) ? (int) $row['status_code'] : 301;
		wp_redirect( $target, $code ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}
}
