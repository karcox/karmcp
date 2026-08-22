<?php
/**
 * The canonical answer to "could a web server execute a file with this name?".
 *
 * One list, two consumers with different questions. The media-intake tools
 * (`upload-media`, `sideload-image`, the featured-image sideload,
 * `upload-svg-icon`) ask about extensions hiding INSIDE a filename —
 * "photo.php.jpg" — because Apache's AddHandler matches any extension in the
 * name, not just the last one. The malware audit asks about the FINAL
 * extension of files already sitting under uploads/. Before this class each
 * consumer carried its own hardcoded list, and they had already drifted:
 * upload-media refused `.php8` while the security scanner built to catch
 * exactly that file did not know the extension existed.
 *
 * WordPress core offers nothing to reuse here: `wp_get_ext_types()` has no
 * executable bucket, and `sanitize_file_name()` defuses double extensions by
 * silently renaming them — the outcome the intake tools deliberately replace
 * with an explicit refusal.
 *
 * @package KarMCP
 * @since   1.33.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executable-extension checks shared by media intake and the malware audit.
 *
 * @since 1.33.1
 */
class KarMCP_Filename_Guard {

	/**
	 * The PHP family: extensions this server's own interpreter may run.
	 *
	 * Consumed two ways: as part of {@see executable_extensions()} for the
	 * inner-segment check, and by the malware audit to build the "PHP file
	 * under uploads/" regex — which is why it is its own constant rather than
	 * folded into the full list below (scanning a `.jsp` for PHP malware
	 * patterns would be noise, refusing to *store* one is not).
	 *
	 * @since 1.33.1
	 *
	 * @var string[]
	 */
	public const PHP_FAMILY = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phps',
		'pht',
		'phtm',
		'phtml',
		'phar',
	);

	/**
	 * Non-PHP extensions some hosts hand to an interpreter or SSI processor.
	 *
	 * `pl` is deliberately absent: as a two-letter inner segment it collides
	 * with the Poland ccTLD and ordinary abbreviations ("onet.pl.jpg" is a
	 * screenshot, not a Perl script), and a `.pl` handler on an uploads
	 * directory is rare enough that the false positives outweighed it.
	 *
	 * @since 1.33.1
	 *
	 * @var string[]
	 */
	private const OTHER_HANDLERS = array(
		'cgi',
		'asp',
		'aspx',
		'jsp',
		'jspx',
		'shtml',
		'shtm',
	);

	/**
	 * Every extension the intake tools refuse inside a filename.
	 *
	 * @since 1.33.1
	 *
	 * @return string[]
	 */
	public static function executable_extensions(): array {
		return array_merge( self::PHP_FAMILY, self::OTHER_HANDLERS );
	}

	/**
	 * Returns the first executable extension hiding INSIDE a filename, or ''.
	 *
	 * Only the inner segments are judged — "photo.php.jpg" answers "php",
	 * while "payload.php" answers '': a final extension is already resolved
	 * against the site's allowed types by wp_check_filetype(), whose refusal
	 * names the type and (for SVG) the module that would allow it, and this
	 * check must not swallow that better message. The leading segment is the
	 * base name, which no server reads as an extension, so "php.jpg" passes.
	 *
	 * Callers run this on the filename AS IT ARRIVED, before
	 * sanitize_file_name(): core defuses the inner extension by renaming it
	 * ("photo.php_.jpg"), so a check placed after never sees it.
	 *
	 * @since 1.33.1
	 *
	 * @param string $filename The filename as received, before sanitizing.
	 * @return string The offending extension, lowercased, or ''.
	 */
	public static function executable_extension_in( string $filename ): string {
		$segments = explode( '.', strtolower( $filename ) );
		$count    = count( $segments );
		if ( $count < 3 ) {
			return '';
		}
		foreach ( array_slice( $segments, 1, $count - 2 ) as $segment ) {
			if ( in_array( $segment, self::executable_extensions(), true ) ) {
				return $segment;
			}
		}
		return '';
	}

	/**
	 * The PHP-family alternation for a final-extension regex, e.g.
	 * "php|php3|…|phar". The caller supplies its own anchors and delimiters.
	 *
	 * @since 1.33.1
	 *
	 * @return string
	 */
	public static function php_extension_alternation(): string {
		return implode( '|', self::PHP_FAMILY );
	}
}
