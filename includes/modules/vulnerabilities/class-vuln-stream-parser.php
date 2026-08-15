<?php
/**
 * Splits the vulnerability feed into records without ever holding it whole.
 *
 * Measured on 2026-08-15: the production feed is 11.2 MB gzipped and **150.87 MB
 * decompressed**, 38,701 records. `json_decode()` on that needs on the order of
 * a gigabyte of PHP memory, which is not available on a normal host and
 * certainly not on shared hosting. So the feed cannot be loaded into memory —
 * not "should not", cannot.
 *
 * The root document is `{"uuid": {...}, "uuid": {...}}`, so the way through is
 * to walk the stream counting braces and hand back one record at a time. Only
 * one record is ever decoded, and memory stays flat regardless of feed size.
 *
 * Brace counting has exactly one trap and this class exists to get it right:
 * braces inside strings do not nest, and a brace inside a string that follows a
 * backslash is not a delimiter either. Miss either and the splitter silently
 * emits garbage on the first record whose description contains a `{`.
 *
 * split() is pure — string in, records out, no filesystem — which is what makes
 * it testable against a toy document.
 *
 * @package KarMCP
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incremental JSON object splitter.
 *
 * @since 1.7.0
 */
class KarMCP_Vuln_Stream_Parser {

	/**
	 * Walks a chunk of the root object and yields `key => raw-json` pairs.
	 *
	 * Returns whatever it could not finish so the caller can prepend the next
	 * chunk to it — which is how this works on a stream rather than a string.
	 *
	 * @since 1.7.0
	 *
	 * @param string $buffer Buffer, starting anywhere in the root object.
	 * @param bool   $started Whether the opening `{` of the root has been consumed.
	 * @return array{records:array<string,string>,remainder:string,started:bool}
	 */
	public static function split( string $buffer, bool $started = false ): array {
		$records = array();
		$len     = strlen( $buffer );
		$i       = 0;

		if ( ! $started ) {
			while ( $i < $len && '{' !== $buffer[ $i ] ) {
				++$i;
			}
			if ( $i >= $len ) {
				return array(
					'records'   => array(),
					'remainder' => '',
					'started'   => false,
				);
			}
			++$i; // Past the root brace.
			$started = true;
		}

		$consumed = $i;

		while ( $i < $len ) {
			// Find the next key string.
			while ( $i < $len && '"' !== $buffer[ $i ] ) {
				if ( '}' === $buffer[ $i ] ) {
					// End of the root object.
					return array(
						'records'   => $records,
						'remainder' => '',
						'started'   => true,
					);
				}
				++$i;
			}
			if ( $i >= $len ) {
				break;
			}

			$key_end = self::end_of_string( $buffer, $i );
			if ( null === $key_end ) {
				break; // Key is cut off; wait for more.
			}
			$key = substr( $buffer, $i + 1, $key_end - $i - 1 );
			$i   = $key_end + 1;

			// Skip to the value.
			while ( $i < $len && ( ':' === $buffer[ $i ] || ' ' === $buffer[ $i ] || "\n" === $buffer[ $i ] || "\r" === $buffer[ $i ] || "\t" === $buffer[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $len || '{' !== $buffer[ $i ] ) {
				break; // Value not here yet, or not an object.
			}

			$value_end = self::end_of_object( $buffer, $i );
			if ( null === $value_end ) {
				break; // Record is cut off; wait for more.
			}

			$records[ $key ] = substr( $buffer, $i, $value_end - $i + 1 );
			$i               = $value_end + 1;
			$consumed        = $i;

			// Skip the comma and any whitespace.
			while ( $i < $len && ( ',' === $buffer[ $i ] || ' ' === $buffer[ $i ] || "\n" === $buffer[ $i ] || "\r" === $buffer[ $i ] || "\t" === $buffer[ $i ] ) ) {
				++$i;
			}
			$consumed = $i;
		}

		return array(
			'records'   => $records,
			'remainder' => substr( $buffer, $consumed ),
			'started'   => $started,
		);
	}

	/**
	 * Index of the closing quote of the string starting at $start.
	 *
	 * @since 1.7.0
	 *
	 * @param string $s     Buffer.
	 * @param int    $start Index of the opening quote.
	 * @return int|null Null when the string is not terminated in this buffer.
	 */
	private static function end_of_string( string $s, int $start ): ?int {
		$len = strlen( $s );
		for ( $i = $start + 1; $i < $len; $i++ ) {
			if ( '\\' === $s[ $i ] ) {
				++$i; // Skip whatever it escapes, including a quote.
				continue;
			}
			if ( '"' === $s[ $i ] ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the brace closing the object that starts at $start.
	 *
	 * The whole reason this is hand-written: a `{` inside a string value is not
	 * an opening brace, and a `"` preceded by a backslash does not end a string.
	 * A naive counter breaks on the first vulnerability whose description
	 * contains a brace, and breaks silently.
	 *
	 * @since 1.7.0
	 *
	 * @param string $s     Buffer.
	 * @param int    $start Index of the opening brace.
	 * @return int|null Null when the object is not closed in this buffer.
	 */
	private static function end_of_object( string $s, int $start ): ?int {
		$len      = strlen( $s );
		$depth    = 0;
		$in_string = false;

		for ( $i = $start; $i < $len; $i++ ) {
			$c = $s[ $i ];

			if ( $in_string ) {
				if ( '\\' === $c ) {
					++$i;
					continue;
				}
				if ( '"' === $c ) {
					$in_string = false;
				}
				continue;
			}

			if ( '"' === $c ) {
				$in_string = true;
				continue;
			}
			if ( '{' === $c ) {
				++$depth;
				continue;
			}
			if ( '}' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return null;
	}
}
