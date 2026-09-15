<?php
/**
 * Inspects an uploaded plugin or theme ZIP before anything is extracted.
 *
 * Installing from an uploaded archive means installing code nobody reviewed
 * on the way in, so the archive is read as hostile until it has proved
 * otherwise. Everything here runs against the ZIP's central directory with the
 * archive still closed to the filesystem: WordPress's own unzip only starts
 * once every check has passed.
 *
 * What is refused, and why each one matters:
 *
 * - An entry path that is absolute, climbs with `..`, uses a backslash or a
 *   drive letter, or carries a NUL — any of them can write outside the
 *   destination the upgrader believes it is extracting into.
 * - A symlink entry. WordPress's own extractor writes every entry with
 *   put_contents() as a regular file (wp-admin/includes/file.php), so today a
 *   link arrives as a small file holding its target, not as a link. This is
 *   defence in depth for the extractor that might not: a different filesystem
 *   method, or a future core that uses ZipArchive::extractTo(), which honours
 *   the bits and creates the link for real.
 * - An archive with no single top-level folder. WordPress installs a package
 *   into a folder named after it; files spread at the root either land beside
 *   every other plugin or are renamed into a folder nobody chose.
 * - Too many entries, too many bytes once expanded, or one entry that expands
 *   far beyond its compressed size. A few kilobytes can describe gigabytes, and
 *   the disk fills during extraction, before any other check could object. The
 *   sizes checked are the ones the archive declares, which its author controls
 *   — and they are also what extraction costs, because WordPress reads each
 *   entry with getFromIndex(), sized from that same declaration.
 * - A package that does not identify itself: a plugin with no `Plugin Name:`
 *   header in a top-level PHP file, a theme with no `Theme Name:` in its
 *   style.css. Without that there is nothing to check an overwrite against.
 *
 * No filesystem writes and no WordPress state: it reads the archive and
 * returns a description or an error, so every rule is testable against a
 * real, deliberately malformed ZIP.
 *
 * @package KarMCP
 * @since   1.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static inspector for uploaded plugin and theme archives.
 *
 * @since 1.41.0
 */
class KarMCP_Zip_Package_Inspector {

	/**
	 * Default ceilings. A real plugin or theme sits far below all three; they
	 * exist to bound what extraction may cost, not to judge a package's size.
	 *
	 * @since 1.41.0
	 * @var array<string,int>
	 */
	const DEFAULT_LIMITS = array(
		'max_entries'       => 20000,
		'max_uncompressed'  => 209715200, // 200 MB.
		'max_ratio'         => 200,
		'ratio_floor_bytes' => 1048576,   // The ratio is only judged past 1 MB, where a bomb lives.
		'header_bytes'      => 8192,      // WordPress reads headers from the first 8 KB too.
	);

	/**
	 * Unix file-type bits for a symbolic link, as stored in the high word of a
	 * ZIP entry's external attributes.
	 *
	 * @since 1.41.0
	 * @var int
	 */
	const S_IFLNK = 0120000;

	/**
	 * Inspects an archive and describes the package it holds.
	 *
	 * @since 1.41.0
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @param string $type     'plugin' or 'theme'.
	 * @param array  $limits   Overrides for DEFAULT_LIMITS (tests pass small ones).
	 * @return array|WP_Error {slug, name, version, requires_php, requires_wp, entries, uncompressed_bytes}
	 */
	public static function inspect( string $zip_path, string $type, array $limits = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'zip_extension_missing',
				__( 'The PHP zip extension is not available, and without it the archive cannot be inspected before extraction. Installing it unexamined is not an option this tool offers.', 'karmcp' )
			);
		}

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return new WP_Error( 'invalid_type', __( 'type must be "plugin" or "theme".', 'karmcp' ) );
		}

		$limits = array_merge( self::DEFAULT_LIMITS, $limits );

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::RDONLY );
		if ( true !== $opened ) {
			return new WP_Error( 'zip_unreadable', __( 'The file is not a readable ZIP archive.', 'karmcp' ) );
		}

		try {
			$count = $zip->numFiles;

			if ( $count < 1 ) {
				return new WP_Error( 'zip_empty', __( 'The archive is empty.', 'karmcp' ) );
			}

			if ( $count > $limits['max_entries'] ) {
				return new WP_Error(
					'zip_too_many_entries',
					sprintf(
						/* translators: 1: number of entries in the archive, 2: the limit. */
						__( 'The archive holds %1$d entries; the limit is %2$d.', 'karmcp' ),
						$count,
						$limits['max_entries']
					)
				);
			}

			$top_level = null;
			$total     = 0;
			$names     = array();

			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false === $stat ) {
					return new WP_Error( 'zip_unreadable', __( 'An entry of the archive could not be read.', 'karmcp' ) );
				}

				$name = (string) $stat['name'];

				if ( ! self::path_is_safe( $name ) ) {
					return new WP_Error(
						'zip_unsafe_path',
						sprintf(
							/* translators: %s: entry name inside the archive. */
							__( 'The entry "%s" would extract outside the package folder.', 'karmcp' ),
							self::printable( $name )
						)
					);
				}

				// Fail closed: an entry whose attributes cannot be read cannot be
				// ruled out as a link, and "could not check" is not "checked".
				$opsys = 0;
				$attr  = 0;
				if ( ! $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
					return new WP_Error(
						'zip_unreadable',
						sprintf(
							/* translators: %s: entry name inside the archive. */
							__( 'The attributes of the entry "%s" could not be read, so it cannot be ruled out as a symbolic link.', 'karmcp' ),
							self::printable( $name )
						)
					);
				}

				if ( self::is_symlink_entry( (int) $opsys, (int) $attr ) ) {
					return new WP_Error(
						'zip_symlink',
						sprintf(
							/* translators: %s: entry name inside the archive. */
							__( 'The entry "%s" is a symbolic link, which could redirect writes or reads outside the package.', 'karmcp' ),
							self::printable( $name )
						)
					);
				}

				$size   = (int) $stat['size'];
				$comp   = (int) $stat['comp_size'];
				$total += $size;

				if ( $total > $limits['max_uncompressed'] ) {
					return new WP_Error(
						'zip_too_large',
						sprintf(
							/* translators: %d: byte limit. */
							__( 'The archive expands past %d bytes, the limit for a package.', 'karmcp' ),
							$limits['max_uncompressed']
						)
					);
				}

				if ( $size > $limits['ratio_floor_bytes'] && $size > $comp * $limits['max_ratio'] ) {
					return new WP_Error(
						'zip_ratio',
						sprintf(
							/* translators: 1: entry name inside the archive, 2: maximum expansion ratio. */
							__( 'The entry "%1$s" expands more than %2$d times its compressed size, the signature of an archive built to fill the disk.', 'karmcp' ),
							self::printable( $name ),
							$limits['max_ratio']
						)
					);
				}

				// The folder macOS adds when it compresses one. WordPress skips it on
				// extraction (wp-admin/includes/file.php), so it is not a second
				// package folder — counting it as one refused every plugin zipped on a
				// Mac. Its entries still had to pass the path, link and size checks
				// above, which cost nothing and do not depend on WordPress skipping it.
				if ( 0 === strpos( $name, '__MACOSX/' ) ) {
					continue;
				}

				// A lone file at the root has no folder to belong to.
				if ( false === strpos( $name, '/' ) ) {
					return new WP_Error(
						'zip_no_folder',
						__( 'The archive must contain a single top-level folder named after the package, with every file inside it.', 'karmcp' )
					);
				}

				$first = (string) strstr( $name, '/', true );

				if ( null === $top_level ) {
					$top_level = $first;
				} elseif ( $first !== $top_level ) {
					return new WP_Error(
						'zip_no_folder',
						sprintf(
							/* translators: 1: first top-level folder, 2: second top-level folder. */
							__( 'The archive has more than one top-level folder ("%1$s" and "%2$s"). A package installs into exactly one.', 'karmcp' ),
							self::printable( $top_level ),
							self::printable( $first )
						)
					);
				}

				$names[] = $name;
			}

			if ( null === $top_level ) {
				return new WP_Error(
					'zip_no_folder',
					__( 'The archive must contain a single top-level folder named after the package, with every file inside it.', 'karmcp' )
				);
			}

			if ( ! self::slug_is_valid( (string) $top_level ) ) {
				return new WP_Error(
					'zip_bad_slug',
					sprintf(
						/* translators: %s: top-level folder name. */
						__( 'The top-level folder "%s" is not a valid package folder name.', 'karmcp' ),
						self::printable( (string) $top_level )
					)
				);
			}

			$header = ( 'plugin' === $type )
				? self::plugin_header( $zip, (string) $top_level, $names, $limits['header_bytes'] )
				: self::theme_header( $zip, (string) $top_level, $limits['header_bytes'] );

			if ( is_wp_error( $header ) ) {
				return $header;
			}

			return array_merge(
				array(
					'slug'               => (string) $top_level,
					'entries'            => $count,
					'uncompressed_bytes' => $total,
				),
				$header
			);
		} finally {
			$zip->close();
		}
	}

	/**
	 * Whether an entry path stays inside the folder it is extracted into.
	 *
	 * @since 1.41.0
	 *
	 * @param string $path Entry name as stored in the archive.
	 * @return bool
	 */
	public static function path_is_safe( string $path ): bool {
		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) ) {
			return false;
		}

		// Absolute, or a drive letter / stream-wrapper prefix.
		if ( '/' === $path[0] || false !== strpos( $path, ':' ) ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a ZIP entry is a symbolic link.
	 *
	 * Only a Unix-made archive can carry one: the file type lives in the high
	 * word of the external attributes. Entries from other systems do not encode
	 * links, so they are not treated as one.
	 *
	 * @since 1.41.0
	 *
	 * @param int $opsys Operating system that made the entry (ZipArchive::OPSYS_*).
	 * @param int $attr  Raw external attributes.
	 * @return bool
	 */
	public static function is_symlink_entry( int $opsys, int $attr ): bool {
		$unix = defined( 'ZipArchive::OPSYS_UNIX' ) ? ZipArchive::OPSYS_UNIX : 3;

		return $unix === $opsys && ( ( $attr >> 16 ) & 0170000 ) === self::S_IFLNK;
	}

	/**
	 * Whether a top-level folder name is usable as a plugin or theme directory.
	 *
	 * @since 1.41.0
	 *
	 * @param string $slug Folder name.
	 * @return bool
	 */
	public static function slug_is_valid( string $slug ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $slug );
	}

	/**
	 * Reads WordPress-style headers out of a file's opening bytes.
	 *
	 * The same pattern get_file_data() applies, so a header WordPress would
	 * recognise is recognised here, and one it would miss is missed here too.
	 *
	 * @since 1.41.0
	 *
	 * @param string               $contents The file's opening bytes.
	 * @param array<string,string> $fields   Map of result key => header label.
	 * @return array<string,string>
	 */
	public static function parse_header( string $contents, array $fields ): array {
		$contents = str_replace( "\r", "\n", $contents );
		$found    = array();

		foreach ( $fields as $key => $label ) {
			$found[ $key ] = '';

			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $contents, $match ) ) {
				$found[ $key ] = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		return $found;
	}

	/**
	 * Finds the plugin's main file among the top-level PHP files and reads it.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param string     $slug  Top-level folder.
	 * @param string[]   $names Every entry name.
	 * @param int        $bytes How much of each candidate to read.
	 * @return array|WP_Error
	 */
	private static function plugin_header( ZipArchive $zip, string $slug, array $names, int $bytes ) {
		foreach ( $names as $name ) {
			// Direct children of the folder only, which is where WordPress looks.
			if ( 1 !== preg_match( '#^' . preg_quote( $slug, '#' ) . '/[^/]+\.php$#i', $name ) ) {
				continue;
			}

			$header = self::parse_header(
				self::read_head( $zip, $name, $bytes ),
				array(
					'name'         => 'Plugin Name',
					'version'      => 'Version',
					'requires_php' => 'Requires PHP',
					'requires_wp'  => 'Requires at least',
				)
			);

			if ( '' !== $header['name'] ) {
				$header['main_file'] = $name;
				return $header;
			}
		}

		return new WP_Error(
			'zip_not_a_plugin',
			__( 'No PHP file directly inside the package folder has a "Plugin Name:" header, so this is not a plugin WordPress would recognise.', 'karmcp' )
		);
	}

	/**
	 * Reads the theme's style.css header.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param string     $slug  Top-level folder.
	 * @param int        $bytes How much to read.
	 * @return array|WP_Error
	 */
	private static function theme_header( ZipArchive $zip, string $slug, int $bytes ) {
		$css = $slug . '/style.css';

		if ( false === $zip->locateName( $css ) ) {
			return new WP_Error( 'zip_not_a_theme', __( 'The package folder has no style.css, so this is not a theme WordPress would recognise.', 'karmcp' ) );
		}

		$header = self::parse_header(
			self::read_head( $zip, $css, $bytes ),
			array(
				'name'         => 'Theme Name',
				'version'      => 'Version',
				'requires_php' => 'Requires PHP',
				'requires_wp'  => 'Requires at least',
			)
		);

		if ( '' === $header['name'] ) {
			return new WP_Error( 'zip_not_a_theme', __( 'style.css has no "Theme Name:" header, so this is not a theme WordPress would recognise.', 'karmcp' ) );
		}

		return $header;
	}

	/**
	 * Reads at most $bytes from the start of an entry, without inflating the rest.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param string     $name  Entry name.
	 * @param int        $bytes Byte budget.
	 * @return string
	 */
	private static function read_head( ZipArchive $zip, string $name, int $bytes ): string {
		$stream = $zip->getStream( $name );
		if ( false === $stream ) {
			return '';
		}

		$head = (string) fread( $stream, max( 1, $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a stream inside an open ZipArchive, not a path WP_Filesystem could address.
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the same in-archive stream.

		return $head;
	}

	/**
	 * An entry name made safe to quote back in an error message.
	 *
	 * @param string $name Raw entry name.
	 * @return string
	 */
	private static function printable( string $name ): string {
		$clean = (string) preg_replace( '/[^\x20-\x7E]/', '?', $name );

		return strlen( $clean ) > 120 ? substr( $clean, 0, 117 ) . '...' : $clean;
	}
}
