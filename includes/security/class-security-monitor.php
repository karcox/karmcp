<?php
/**
 * Keeps the last security scan, runs it on a schedule, and says how old it is.
 *
 * The reason this exists at all: until now `scan-security` only ran when an
 * agent asked, and a scan that only happens when asked is not monitoring. A
 * vulnerability lands on a Tuesday; if nobody opens a session that week, nobody
 * finds out.
 *
 * The single rule that governs the whole class:
 *
 * > A scan that failed, or data that is stale, must never render as "you are
 * > clean". Reporting *"I don't know"* as *"nothing found"* is the failure that
 * > leaves someone compromised and reassured.
 *
 * So freshness() is a first-class answer with three states, not a boolean, and
 * it is pure — which is the part that gets tested.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled scanning and result storage.
 *
 * @since 1.5.0
 */
class KarMCP_Security_Monitor {

	const OPTION_LAST = 'karmcp_security_last_scan';
	const CRON_HOOK   = 'karmcp_security_scheduled_scan';

	/** Older than this and the report is presented as stale, not as good news. */
	const STALE_AFTER = 2 * 86400;

	/**
	 * @since 1.5.0
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Offset by an hour so a mass upgrade across sites does not have all
			// of them scanning in the same minute.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		add_action( 'admin_notices', array( __CLASS__, 'critical_notice' ) );
	}

	/**
	 * How trustworthy the stored report is. Pure.
	 *
	 * @since 1.5.0
	 *
	 * @param array $stored The stored record; empty when nothing ran.
	 * @param int   $now    Current timestamp.
	 * @param int   $stale_after Seconds after which a report is stale.
	 * @return array{state:string,age:int,label_key:string}
	 *         state ∈ never|failed|stale|fresh
	 */
	public static function freshness( array $stored, int $now, int $stale_after = self::STALE_AFTER ): array {
		if ( empty( $stored ) || empty( $stored['finished_at'] ) ) {
			return array(
				'state'     => 'never',
				'age'       => 0,
				'label_key' => 'never',
			);
		}

		$age = max( 0, $now - (int) $stored['finished_at'] );

		if ( ! empty( $stored['failed'] ) ) {
			return array(
				'state'     => 'failed',
				'age'       => $age,
				'label_key' => 'failed',
			);
		}

		return array(
			'state'     => $age > $stale_after ? 'stale' : 'fresh',
			'age'       => $age,
			'label_key' => $age > $stale_after ? 'stale' : 'fresh',
		);
	}

	/**
	 * The stored report, or an empty array.
	 *
	 * @since 1.5.0
	 * @return array
	 */
	public static function last(): array {
		$v = get_option( self::OPTION_LAST, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Stores a report. Only the summary and the findings are kept — the malware
	 * walk's file list is large and of no use a day later.
	 *
	 * @since 1.5.0
	 *
	 * @param array $report Scanner output.
	 * @return void
	 */
	public static function store( array $report ): void {
		update_option(
			self::OPTION_LAST,
			array(
				'finished_at'         => time(),
				'failed'              => false,
				'summary'             => $report['summary'] ?? array(),
				'sections'            => $report['sections'] ?? array(),
				'top_recommendations' => $report['top_recommendations'] ?? array(),
				'scan_meta'           => $report['scan_meta'] ?? array(),
			),
			false
		);
	}

	/**
	 * Records that a scan could not complete. Deliberately keeps the previous
	 * report's data out of the record: a failure must not be able to masquerade
	 * as yesterday's clean result.
	 *
	 * @since 1.5.0
	 *
	 * @param string $reason Human-readable reason.
	 * @return void
	 */
	public static function store_failure( string $reason ): void {
		update_option(
			self::OPTION_LAST,
			array(
				'finished_at' => time(),
				'failed'      => true,
				'reason'      => $reason,
				'summary'     => array(),
				'sections'    => array(),
			),
			false
		);
	}

	/**
	 * The scheduled scan. Skips the deep malware walk: this runs unattended on
	 * whatever host the site is on, and a bounded scan that finishes beats a
	 * thorough one that times out and stores nothing.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function run_scheduled(): void {
		if ( ! class_exists( 'KarMCP_Security_Scanner' ) ) {
			return;
		}
		try {
			$scanner = new KarMCP_Security_Scanner();
			$report  = $scanner->scan( array( 'deep' => false ) );
			self::store( $report );
		} catch ( \Throwable $e ) {
			// Name the class, the file and the line. Storing only getMessage()
			// left a real failure — "Attempt to assign property on false", thrown
			// by a third-party update checker reached through the scan — with no
			// way to find what threw it. It is the same dead end 1.2.1 removed
			// from the tools, and it was reintroduced here.
			//
			// Reaching this at all is now unusual: each audit has its own guard,
			// so a single broken check no longer takes the scan with it. This
			// catches what escapes the orchestrator itself.
			$file = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $e->getFile() ) : $e->getFile();
			$root = ( defined( 'ABSPATH' ) && function_exists( 'wp_normalize_path' ) ) ? wp_normalize_path( ABSPATH ) : '';
			if ( '' !== $root && 0 === strpos( $file, $root ) ) {
				$file = substr( $file, strlen( $root ) );
			}

			self::store_failure(
				sprintf(
					/* translators: 1: exception class, 2: message, 3: file, 4: line. */
					__( '%1$s: %2$s (at %3$s line %4$d)', 'karmcp' ),
					get_class( $e ),
					$e->getMessage(),
					$file,
					$e->getLine()
				)
			);
		}
	}

	/**
	 * An admin notice when the last scan found something critical, or when it
	 * could not run. The second half matters as much as the first.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function critical_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stored = self::last();
		$fresh  = self::freshness( $stored, time() );
		$url    = admin_url( 'admin.php?page=' . KarMCP_Admin::PAGE_SLUG . '-security' );

		if ( 'failed' === $fresh['state'] ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'KarMCP security scan failed.', 'karmcp' ),
				esc_html__( 'The site has not been checked — this is not the same as being clean.', 'karmcp' ),
				esc_url( $url ),
				esc_html__( 'Open Security', 'karmcp' )
			);
			return;
		}

		$critical = (int) ( $stored['summary']['counts']['critical'] ?? 0 );
		if ( $critical > 0 ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of critical findings. */
						_n( 'KarMCP found %d critical security issue.', 'KarMCP found %d critical security issues.', $critical, 'karmcp' ),
						$critical
					)
				),
				esc_url( $url ),
				esc_html__( 'Review them', 'karmcp' )
			);
		}
	}
}
