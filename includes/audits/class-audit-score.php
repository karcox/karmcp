<?php
/**
 * Scoring shared by every page audit.
 *
 * Kept separate from the rules so the SEO audit and the accessibility audit
 * that follows it grade on the same curve. Two audits that each invent their
 * own 0-100 produce numbers nobody can compare.
 *
 * Pure: arrays in, arrays out, no WordPress.
 *
 * @package KarMCP
 * @since   1.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a list of findings into a score, a grade and a tally.
 *
 * @since 1.14.0
 */
class KarMCP_Audit_Score {

	/**
	 * What each status costs. `info` costs nothing on purpose: it exists to
	 * tell the reader something true, not to punish them for it.
	 */
	const WEIGHTS = array(
		'critical' => 15,
		'warning'  => 5,
		'info'     => 0,
		'pass'     => 0,
	);

	/**
	 * Grade boundaries, highest first.
	 */
	const GRADES = array(
		90 => 'A',
		80 => 'B',
		70 => 'C',
		60 => 'D',
	);

	/**
	 * Counts findings by status.
	 *
	 * Always returns every status key, including the zeroes — a caller that
	 * renders a summary should not have to guard each lookup.
	 *
	 * @param array $findings Findings.
	 * @return array<string,int>
	 */
	public static function counts( array $findings ): array {
		$counts = array_fill_keys( array_keys( self::WEIGHTS ), 0 );

		foreach ( $findings as $finding ) {
			$status = isset( $finding['status'] ) ? (string) $finding['status'] : 'info';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Scores a list of findings out of 100.
	 *
	 * @param array $findings Findings.
	 * @return int 0-100.
	 */
	public static function score( array $findings ): int {
		$score = 100;

		foreach ( $findings as $finding ) {
			$status = isset( $finding['status'] ) ? (string) $finding['status'] : 'info';
			$score -= self::WEIGHTS[ $status ] ?? 0;
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Letter grade for a score.
	 *
	 * @param int $score 0-100.
	 * @return string A-F.
	 */
	public static function grade( int $score ): string {
		foreach ( self::GRADES as $floor => $letter ) {
			if ( $score >= $floor ) {
				return $letter;
			}
		}
		return 'F';
	}

	/**
	 * The whole summary in one call.
	 *
	 * @param array $findings Findings.
	 * @return array { score, grade, counts }
	 */
	public static function summarize( array $findings ): array {
		$score = self::score( $findings );

		return array(
			'score'  => $score,
			'grade'  => self::grade( $score ),
			'counts' => self::counts( $findings ),
		);
	}
}
