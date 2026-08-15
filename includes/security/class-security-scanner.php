<?php
/**
 * Security & Malware Scanner orchestrator.
 *
 * resolve_checks(), summarize(), group_by_category() are pure (unit-tested).
 * scan() wires the four audits together (verified live). Read-only.
 *
 * @package KarMCP
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class KarMCP_Security_Scanner {

	const CRITICAL_WEIGHT   = 20;
	const WARNING_WEIGHT    = 5;
	const CATEGORY_CRIT_CAP = 60;

	/**
	 * Warnings are capped per category too, and that is a fix rather than a
	 * tuning choice. Uncapped, a site with thirty outdated plugins scored
	 * 100 - 150 = 0 before a single other check ran, which made the score
	 * identical for "needs updating" and "actively compromised" — and a score
	 * that cannot distinguish those is not worth showing. Measured on a real
	 * install: 46 warnings, all of them ordinary, forced a hard zero.
	 */
	const CATEGORY_WARN_CAP = 25;
	const TOP_RECS          = 8;

	// The fifth, 'vulnerability', is appended at runtime by checks_available()
	// when the module that supplies it is on: a category with no data behind it
	// would score the site down for a check it never actually made.
	const ALL_CHECKS = array( 'malware', 'integrity', 'hardening', 'software' );

	/** @var KarMCP_Security_Malware_Audit|null Built on first use. */
	private $malware;
	/** @var KarMCP_Security_Integrity_Audit|null Built on first use. */
	private $integrity;
	/** @var KarMCP_Security_Hardening_Audit|null Built on first use. */
	private $hardening;
	/** @var KarMCP_Security_Software_Audit|null Built on first use. */
	private $software;

	/**
	 * Audits are built lazily, on the first scan that needs them.
	 *
	 * Registering the scan-security tool must not instantiate the audit engine.
	 * Ability registration runs on every admin page load and every REST request,
	 * so eagerly constructing the audits here meant a single unavailable audit
	 * class turned tool registration into a site-wide fatal (issue #100, where a
	 * host malware scanner had quarantined the malware-audit file). Deferring
	 * construction keeps that failure inside the one tool that needs the class.
	 *
	 * Passing instances still works and is what the tests inject.
	 */
	public function __construct(
		?KarMCP_Security_Malware_Audit $malware = null,
		?KarMCP_Security_Integrity_Audit $integrity = null,
		?KarMCP_Security_Hardening_Audit $hardening = null,
		?KarMCP_Security_Software_Audit $software = null
	) {
		$this->malware   = $malware;
		$this->integrity = $integrity;
		$this->hardening = $hardening;
		$this->software  = $software;
	}

	private function malware(): KarMCP_Security_Malware_Audit {
		if ( null === $this->malware ) {
			$this->malware = new KarMCP_Security_Malware_Audit();
		}
		return $this->malware;
	}

	private function integrity(): KarMCP_Security_Integrity_Audit {
		if ( null === $this->integrity ) {
			$this->integrity = new KarMCP_Security_Integrity_Audit();
		}
		return $this->integrity;
	}

	private function hardening(): KarMCP_Security_Hardening_Audit {
		if ( null === $this->hardening ) {
			$this->hardening = new KarMCP_Security_Hardening_Audit();
		}
		return $this->hardening;
	}

	private function software(): KarMCP_Security_Software_Audit {
		if ( null === $this->software ) {
			$this->software = new KarMCP_Security_Software_Audit();
		}
		return $this->software;
	}

	/**
	 * Pure: normalize the requested checks to a canonical-ordered valid subset.
	 *
	 * @param array|null $requested
	 * @return string[]
	 */
	/**
	 * Every check that can actually run here. The vulnerability category only
	 * exists when its module is enabled — otherwise the scanner would report a
	 * category it has no data for, and an empty category reads as a pass.
	 *
	 * @since 1.7.0
	 * @return string[]
	 */
	public static function checks_available(): array {
		$checks = self::ALL_CHECKS;
		if ( class_exists( 'KarMCP_Vulnerabilities_Module' ) && KarMCP_Vulnerabilities_Module::is_enabled() ) {
			$checks[] = 'vulnerability';
		}
		return $checks;
	}

	public function resolve_checks( ?array $requested ): array {
		$available = self::checks_available();
		if ( empty( $requested ) ) {
			return $available;
		}
		$valid = array();
		foreach ( $available as $check ) {
			if ( in_array( $check, $requested, true ) ) {
				$valid[] = $check;
			}
		}
		return empty( $valid ) ? self::ALL_CHECKS : $valid;
	}

	/**
	 * Live: run the requested audits and assemble the report.
	 *
	 * @param array $input { checks?, deep?, max_files?, max_seconds? }
	 * @return array
	 */
	public function scan( array $input ): array {
		$checks = $this->resolve_checks( isset( $input['checks'] ) && is_array( $input['checks'] ) ? $input['checks'] : null );
		$deep   = ! empty( $input['deep'] );

		$max_files   = $this->clamp( (int) ( $input['max_files'] ?? KarMCP_Security_Malware_Audit::MAX_FILES ), 1, KarMCP_Security_Malware_Audit::MAX_FILES_CEILING );
		$max_seconds = $this->clamp( (int) ( $input['max_seconds'] ?? KarMCP_Security_Malware_Audit::TIME_BUDGET ), 1, KarMCP_Security_Malware_Audit::TIME_BUDGET_CEILING );

		$started   = microtime( true );
		$findings  = array();
		$scan_meta = array(
			'files_scanned'      => 0,
			'files_skipped_size' => 0,
			'truncated'          => false,
			'truncated_reason'   => null,
			'deep'               => $deep,
			'checks_run'         => $checks,
			'integrity_api'      => array( 'ok' => false, 'error' => 'not_run' ),
			'headers_fetch'      => array( 'ok' => false, 'error' => 'not_run' ),
			'elapsed_ms'         => 0,
		);

		// Each audit runs inside its own guard. One of them throwing must not
		// discard the four that finished: the first real failure came from a
		// third-party update-checker blowing up inside the software audit, and
		// it threw away a complete malware, integrity and hardening report to
		// store nothing but "failed". A category that could not run is reported
		// as a category that could not run — the same rule as everywhere else in
		// this section, applied one level down.
		if ( in_array( 'malware', $checks, true ) ) {
			try {
				$m = $this->malware()->run( $deep, $max_files, $max_seconds );
				$findings = array_merge( $findings, $m['findings'] );
				$scan_meta['files_scanned']      = (int) $m['stats']['files_scanned'];
				$scan_meta['files_skipped_size'] = (int) $m['stats']['files_skipped_size'];
				$scan_meta['truncated']          = (bool) $m['stats']['truncated'];
				$scan_meta['truncated_reason']   = $m['stats']['truncated_reason'];
			} catch ( \Throwable $e ) {
				$findings[] = self::audit_failed( 'malware', $e );
			}
		}
		if ( in_array( 'integrity', $checks, true ) ) {
			try {
				$i = $this->integrity()->run();
				$findings = array_merge( $findings, $i['findings'] );
				$scan_meta['integrity_api'] = $i['api'];
			} catch ( \Throwable $e ) {
				$findings[] = self::audit_failed( 'integrity', $e );
			}
		}
		if ( in_array( 'hardening', $checks, true ) ) {
			try {
				$h = $this->hardening()->run();
				$findings = array_merge( $findings, $h['findings'] );
				$scan_meta['headers_fetch'] = $h['headers_fetch'];
			} catch ( \Throwable $e ) {
				$findings[] = self::audit_failed( 'hardening', $e );
			}
		}
		if ( in_array( 'software', $checks, true ) ) {
			try {
				$findings = array_merge( $findings, $this->software()->run() );
			} catch ( \Throwable $e ) {
				$findings[] = self::audit_failed( 'software', $e );
			}
		}
		if ( in_array( 'vulnerability', $checks, true ) && class_exists( 'KarMCP_Vuln_Audit' ) ) {
			try {
				$vuln     = new KarMCP_Vuln_Audit();
				$findings = array_merge( $findings, $vuln->run() );
			} catch ( \Throwable $e ) {
				$findings[] = self::audit_failed( 'vulnerability', $e );
			}
		}

		$summary               = $this->summarize( $findings );
		$scan_meta['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		return array(
			'summary'             => array(
				'score'  => $summary['score'],
				'grade'  => $summary['grade'],
				'counts' => $summary['counts'],
			),
			'sections'            => $this->group_by_category( $findings ),
			'scan_meta'           => $scan_meta,
			'top_recommendations' => $summary['top_recommendations'],
		);
	}

	/**
	 * Pure: counts, score (per-category critical cap), grade, ranked recs.
	 *
	 * @param array $findings Finding[]
	 * @return array { counts, score, grade, top_recommendations }
	 */
	/**
	 * The finding that stands in for an audit that could not run.
	 *
	 * A **warning**, not an info: the category produced no results and the
	 * reason is that it broke, which the score should notice. And it names the
	 * exception class, file and line, because a failure reported as bare text
	 * is exactly the dead end 1.2.1 removed from the tools — a third-party
	 * update-checker throwing inside an audit is unfindable without them.
	 *
	 * The path is relative to the WordPress root: it is what read-file accepts,
	 * and one less thing disclosed to a client.
	 *
	 * @since 1.7.2
	 *
	 * @param string     $category Which audit failed.
	 * @param \Throwable $e        What escaped it.
	 * @return array Finding
	 */
	private static function audit_failed( string $category, \Throwable $e ): array {
		// wp_normalize_path() rather than a hand-rolled str_replace: it is what
		// the rest of the plugin uses and it handles Windows paths and stream
		// wrappers without a literal backslash in the source.
		$file = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $e->getFile() ) : $e->getFile();
		$root = ( defined( 'ABSPATH' ) && function_exists( 'wp_normalize_path' ) ) ? wp_normalize_path( ABSPATH ) : '';
		if ( '' !== $root && 0 === strpos( $file, $root ) ) {
			$file = substr( $file, strlen( $root ) );
		}

		return KarMCP_Security_Finding::make(
			$category . '_audit_failed',
			$category,
			'Check could not run',
			'warning',
			array(
				'exception' => get_class( $e ),
				'file'      => $file,
				'line'      => $e->getLine(),
			),
			sprintf(
				'The %1$s check did not finish, so nothing was verified in it. %2$s threw "%3$s" at %4$s:%5$d.',
				$category,
				get_class( $e ),
				$e->getMessage(),
				$file,
				$e->getLine()
			),
			'The file and line above name the code that threw — often a third-party plugin reached through WordPress rather than this scanner. The other categories in this report are unaffected and their results stand.'
		);
	}

	public function summarize( array $findings ): array {
		$counts        = array( 'critical' => 0, 'warning' => 0, 'pass' => 0, 'info' => 0 );
		$cat_crit_pen  = array();
		$cat_warn_pen  = array();

		foreach ( $findings as $f ) {
			$status = (string) ( $f['status'] ?? 'info' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
			// Resolved for both branches, not just the critical one: reading it
			// only inside the first would carry the previous finding's category
			// into every warning, and silently mis-bucket the penalties.
			$cat = (string) ( $f['category'] ?? 'malware' );

			if ( 'critical' === $status ) {
				$cat_crit_pen[ $cat ] = min( self::CATEGORY_CRIT_CAP, ( $cat_crit_pen[ $cat ] ?? 0 ) + self::CRITICAL_WEIGHT );
			} elseif ( 'warning' === $status ) {
				$cat_warn_pen[ $cat ] = min( self::CATEGORY_WARN_CAP, ( $cat_warn_pen[ $cat ] ?? 0 ) + self::WARNING_WEIGHT );
			}
		}

		$score = 100 - array_sum( $cat_crit_pen ) - array_sum( $cat_warn_pen );
		$score = max( 0, min( 100, $score ) );

		if ( $score >= 90 )     { $grade = 'A'; }
		elseif ( $score >= 80 ) { $grade = 'B'; }
		elseif ( $score >= 70 ) { $grade = 'C'; }
		elseif ( $score >= 60 ) { $grade = 'D'; }
		else                    { $grade = 'F'; }

		return array(
			'counts'              => $counts,
			'score'               => $score,
			'grade'               => $grade,
			'top_recommendations' => $this->rank_recommendations( $findings ),
		);
	}

	/**
	 * @param array $findings Finding[]
	 * @return array category => Finding[]
	 */
	public function group_by_category( array $findings ): array {
		$sections = array( 'malware' => array(), 'integrity' => array(), 'hardening' => array(), 'software' => array(), 'vulnerability' => array() );
		foreach ( $findings as $f ) {
			$cat = (string) ( $f['category'] ?? 'malware' );
			if ( ! isset( $sections[ $cat ] ) ) {
				$sections[ $cat ] = array();
			}
			$sections[ $cat ][] = $f;
		}
		return $sections;
	}

	// ---- helpers ------------------------------------------------------

	private function rank_recommendations( array $findings ): array {
		$crit = array();
		$warn = array();
		foreach ( $findings as $f ) {
			$rec = trim( (string) ( $f['recommendation'] ?? '' ) );
			if ( '' === $rec ) {
				continue;
			}
			$line = sprintf( '[%s] %s', (string) ( $f['label'] ?? '' ), $rec );
			if ( 'critical' === ( $f['status'] ?? '' ) ) {
				$crit[] = $line;
			} elseif ( 'warning' === ( $f['status'] ?? '' ) ) {
				$warn[] = $line;
			}
		}
		return array_slice( array_merge( $crit, $warn ), 0, self::TOP_RECS );
	}

	private function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
