<?php
/**
 * A scan taken one check at a time, so the browser can draw a bar.
 *
 * The whole scan in a single request is one long silence followed by a page
 * reload, and on a slow host it is worse than silence: a malware walk over a
 * large tree can outlast `max_execution_time`, and the request dies with
 * nothing stored, which the tab then reports as a failed scan.
 *
 * Stepping it fixes both. Each check is its own short request, the browser is
 * told what finished, and a host limit can at most cost the one category it
 * landed in — the others are already banked.
 *
 * Progress state lives in a transient rather than an option: it is scaffolding
 * for the next minute or two, not something to leave in the options table if
 * somebody closes the tab halfway.
 *
 * @package KarMCP
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stepped scan state.
 *
 * @since 1.9.0
 */
class KarMCP_Security_Scan_Run {

	const TRANSIENT = 'karmcp_security_run';
	const TTL       = 1800;

	/**
	 * Starts a run and returns its shape for the browser.
	 *
	 * @since 1.9.0
	 *
	 * @param bool $deep Deep malware walk.
	 * @return array{checks:string[],total:int}
	 */
	public static function start( bool $deep = false ): array {
		$checks = KarMCP_Security_Scanner::checks_available();

		set_transient(
			self::TRANSIENT,
			array(
				'checks'   => $checks,
				'deep'     => $deep,
				'pending'  => $checks,
				'findings' => array(),
				'meta'     => array(),
				'started'  => microtime( true ),
			),
			self::TTL
		);

		return array(
			'checks' => $checks,
			'total'  => count( $checks ),
		);
	}

	/**
	 * Runs the next pending check.
	 *
	 * @since 1.9.0
	 * @return array|\WP_Error Progress, or an error when there is no live run.
	 */
	public static function step() {
		$run = get_transient( self::TRANSIENT );
		if ( ! is_array( $run ) || empty( $run['checks'] ) ) {
			return new \WP_Error(
				'no_run',
				__( 'This scan is no longer running — it may have been started in another tab, or left for too long. Start it again.', 'karmcp' )
			);
		}

		$total = count( $run['checks'] );

		if ( empty( $run['pending'] ) ) {
			return self::finish( $run, $total );
		}

		$check   = (string) array_shift( $run['pending'] );
		$scanner = new KarMCP_Security_Scanner();
		$result  = $scanner->run_check( $check, ! empty( $run['deep'] ) );

		$run['findings'] = array_merge( (array) $run['findings'], $result['findings'] );
		$run['meta']     = array_merge( (array) $run['meta'], $result['meta'] );

		$complete = empty( $run['pending'] );
		if ( $complete ) {
			return self::finish( $run, $total );
		}

		set_transient( self::TRANSIENT, $run, self::TTL );

		return array(
			'complete' => false,
			'done'     => $total - count( $run['pending'] ),
			'total'    => $total,
			'current'  => $check,
			'next'     => (string) ( $run['pending'][0] ?? '' ),
		);
	}

	/**
	 * Assembles and stores the finished report.
	 *
	 * Deliberately goes through the same summarize() and group_by_category() the
	 * one-shot scan uses, and stores through the same Monitor — a stepped scan
	 * has to produce a report indistinguishable from an unstepped one, or the
	 * two would slowly stop agreeing.
	 *
	 * @since 1.9.0
	 *
	 * @param array $run   Run state.
	 * @param int   $total Number of checks.
	 * @return array
	 */
	private static function finish( array $run, int $total ): array {
		$scanner  = new KarMCP_Security_Scanner();
		$findings = (array) $run['findings'];
		$summary  = $scanner->summarize( $findings );

		$meta = array_merge(
			array(
				'files_scanned'      => 0,
				'files_skipped_size' => 0,
				'truncated'          => false,
				'truncated_reason'   => null,
				'deep'               => ! empty( $run['deep'] ),
				'checks_run'         => (array) $run['checks'],
				'integrity_api'      => array( 'ok' => false, 'error' => 'not_run' ),
				'headers_fetch'      => array( 'ok' => false, 'error' => 'not_run' ),
				'stepped'            => true,
			),
			(array) $run['meta']
		);
		$meta['elapsed_ms'] = (int) round( ( microtime( true ) - (float) ( $run['started'] ?? microtime( true ) ) ) * 1000 );

		KarMCP_Security_Monitor::store(
			array(
				'summary'             => array(
					'score'         => $summary['score'],
					'grade'         => $summary['grade'],
					'counts'        => $summary['counts'],
					'penalties'     => $summary['penalties'],
					'total_penalty' => $summary['total_penalty'],
				),
				'sections'            => $scanner->group_by_category( $findings ),
				'scan_meta'           => $meta,
				'top_recommendations' => $summary['top_recommendations'],
			)
		);

		delete_transient( self::TRANSIENT );

		return array(
			'complete' => true,
			'done'     => $total,
			'total'    => $total,
			'score'    => (int) $summary['score'],
			'grade'    => (string) $summary['grade'],
		);
	}
}
