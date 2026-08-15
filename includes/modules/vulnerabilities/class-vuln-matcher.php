<?php
/**
 * Does this installed version fall inside a vulnerable range?
 *
 * Pure, and the whole reason the module can be trusted. The feed expresses
 * affected versions as ranges with explicit inclusivity on each end:
 *
 *     "1.0.0 - 1.2.3": { from_version: "1.0.0", from_inclusive: true,
 *                        to_version: "1.2.3",   to_inclusive: true }
 *
 * The traps, all of which a prototype run against a real site turned up:
 *
 * - **`*` means unbounded.** "everything up to 1.2.3" has `from_version: "*"`.
 *   Treating it as a literal makes every comparison fail and the plugin reports
 *   a clean site, which is the worst possible way to be wrong.
 * - **Exclusive ends are real.** `to_inclusive: false` on 3.8.9.1 means 3.8.9.1
 *   is *patched*. Off by one here and you tell someone to update software that
 *   is already fine, or worse, that vulnerable software is safe.
 * - **Four-component versions.** WordPress plugins ship `3.5.6.1` routinely;
 *   any comparator that assumes semver drops the fourth part.
 * - **Suffixes sort below the release.** `1.0.0-beta2` precedes `1.0.0`.
 *
 * @package KarMCP
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version-range matching.
 *
 * @since 1.7.0
 */
class KarMCP_Vuln_Matcher {

	/**
	 * Whether $version falls inside any of the ranges.
	 *
	 * @since 1.7.0
	 *
	 * @param string $version Installed version.
	 * @param array  $ranges  The `affected_versions` map from a feed record.
	 * @return bool
	 */
	public static function is_affected( string $version, array $ranges ): bool {
		if ( '' === trim( $version ) ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			if ( is_array( $range ) && self::in_range( $version, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether $version falls inside one range.
	 *
	 * @since 1.7.0
	 *
	 * @param string $version Installed version.
	 * @param array  $range   One range from `affected_versions`.
	 * @return bool
	 */
	public static function in_range( string $version, array $range ): bool {
		$from = (string) ( $range['from_version'] ?? '' );
		$to   = (string) ( $range['to_version'] ?? '' );

		if ( '' !== $from && '*' !== $from ) {
			$c = self::compare( $version, $from );
			if ( $c < 0 ) {
				return false;
			}
			if ( 0 === $c && empty( $range['from_inclusive'] ) ) {
				return false;
			}
		}

		if ( '' !== $to && '*' !== $to ) {
			$c = self::compare( $version, $to );
			if ( $c > 0 ) {
				return false;
			}
			if ( 0 === $c && empty( $range['to_inclusive'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an available update actually clears a vulnerability.
	 *
	 * The check that stops the Package Guard exception being a blank cheque:
	 * updating to something still inside the affected range fixes nothing, and
	 * having bypassed the guard for it would be worse than not bypassing it.
	 *
	 * @since 1.7.0
	 *
	 * @param string $candidate Version that would be installed.
	 * @param array  $software  The `software` entry from a feed record.
	 * @return bool
	 */
	public static function fixes( string $candidate, array $software ): bool {
		if ( empty( $software['patched'] ) ) {
			return false; // No fix exists; nothing to update to.
		}
		if ( self::is_affected( $candidate, (array) ( $software['affected_versions'] ?? array() ) ) ) {
			return false; // Still vulnerable.
		}

		$patched = array_filter( array_map( 'strval', (array) ( $software['patched_versions'] ?? array() ) ) );
		if ( empty( $patched ) ) {
			// Marked patched with no version named: leaving the affected range is
			// the best evidence available.
			return true;
		}

		foreach ( $patched as $p ) {
			if ( self::compare( $candidate, $p ) >= 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compares two version strings. -1, 0 or 1.
	 *
	 * Not `version_compare()`: that one has opinions about `p`, `pl`, `rc` and
	 * friends that do not survive the version strings plugin authors actually
	 * publish. This splits on separators and compares numerically part by part,
	 * with any alphabetic part sorting below a numeric one so `1.0.0-beta2`
	 * precedes `1.0.0`.
	 *
	 * @since 1.7.0
	 *
	 * @param string $a First version.
	 * @param string $b Second version.
	 * @return int
	 */
	public static function compare( string $a, string $b ): int {
		$pa = self::parts( $a );
		$pb = self::parts( $b );
		$n  = max( count( $pa ), count( $pb ) );

		for ( $i = 0; $i < $n; $i++ ) {
			// A missing part is zero, so 1.2 and 1.2.0 are the same version.
			$x = $pa[ $i ] ?? array( 0, 0 );
			$y = $pb[ $i ] ?? array( 0, 0 );

			if ( $x[0] !== $y[0] ) {
				// Alphabetic (kind 1) sorts BELOW numeric (kind 0).
				return $x[0] > $y[0] ? -1 : 1;
			}
			if ( $x[1] === $y[1] ) {
				continue;
			}
			return ( $x[1] < $y[1] ) ? -1 : 1;
		}

		return 0;
	}

	/**
	 * Splits a version into comparable parts: `[kind, value]`, kind 0 numeric
	 * and kind 1 alphabetic.
	 *
	 * @since 1.7.0
	 *
	 * @param string $v Version string.
	 * @return array<int,array{0:int,1:mixed}>
	 */
	private static function parts( string $v ): array {
		$v   = ltrim( trim( $v ), 'vV' );
		$out = array();

		foreach ( preg_split( '/[.\-+_]/', $v ) as $chunk ) {
			if ( '' === $chunk ) {
				continue;
			}
			if ( ctype_digit( $chunk ) ) {
				$out[] = array( 0, (int) $chunk );
				continue;
			}
			// "6beta2" → 6, then "beta2".
			if ( preg_match( '/^(\d+)(.+)$/', $chunk, $m ) ) {
				$out[] = array( 0, (int) $m[1] );
				$out[] = array( 1, strtolower( $m[2] ) );
				continue;
			}
			$out[] = array( 1, strtolower( $chunk ) );
		}

		return $out;
	}
}
