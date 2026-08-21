<?php
/**
 * Generates includes/classmap.php — the class => file map the autoloader reads.
 *
 * The plugin used to load its runtime with two hand-written `require_once`
 * lists in class-bootstrap.php: 153 files on every request, front-end page
 * views included, to define classes a visitor never reaches. A hand-kept list
 * cannot answer "is this needed on this request?" — only actual use can, and
 * that is what an autoloader is. This script produces the map it resolves
 * against, so nothing has to be maintained by hand.
 *
 * Usage:
 *   php bin/generate-classmap.php           write includes/classmap.php
 *   php bin/generate-classmap.php --check   exit 1 if the committed map is stale
 *
 * The --check mode is what CI and ClassmapTest run: adding a class file without
 * regenerating is the one way this can rot, and it fails loudly instead.
 *
 * @package KarMCP
 * @since   1.30.0
 */

const KARMCP_SCAN_DIR = 'includes';
const KARMCP_MAP_FILE = 'includes/classmap.php';

/**
 * Every class, interface and trait a file declares.
 *
 * Token-based, not a regex: declarations guarded by `class_exists()` sit one
 * brace deep (the themer widget classes are all inside such a guard) and a
 * regex over line starts either misses them or matches `::class` in a string.
 *
 * @param string $file Absolute path.
 * @return string[] Declared symbol names.
 */
function karmcp_declared_symbols( string $file ): array {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$count  = count( $tokens );
	$names  = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			continue;
		}
		if ( ! in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
			continue;
		}
		// `Foo::class` is a T_CLASS preceded by `::`, not a declaration.
		$prev = $tokens[ $i - 1 ] ?? null;
		if ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
			continue;
		}
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$next = $tokens[ $j ];
			if ( is_array( $next ) && in_array( $next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			// A T_STRING here is the name; anything else is an anonymous class.
			if ( is_array( $next ) && T_STRING === $next[0] ) {
				$names[] = $next[1];
			}
			break;
		}
	}

	return $names;
}

/**
 * Builds the map by walking the tree.
 *
 * @param string $root Plugin root, no trailing slash.
 * @return array<string,string> Lowercased class name => path relative to root.
 */
function karmcp_build_map( string $root ): array {
	$map      = array();
	$owner    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . KARMCP_SCAN_DIR, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

		foreach ( karmcp_declared_symbols( $file->getPathname() ) as $name ) {
			$key = strtolower( $name );
			// Two files declaring the same class is a real bug: whichever the
			// autoloader picked would win at random. Fail instead of guessing.
			if ( isset( $owner[ $key ] ) ) {
				fwrite( STDERR, "Duplicate declaration of {$name}: {$owner[ $key ]} and {$relative}" . PHP_EOL );
				exit( 2 );
			}
			$owner[ $key ] = $relative;
			$map[ $key ]   = $relative;
		}
	}

	ksort( $map );
	return $map;
}

/**
 * Renders the map as the PHP file that gets committed.
 *
 * @param array<string,string> $map Class map.
 * @return string File contents.
 */
function karmcp_render_map( array $map ): string {
	$lines = array();
	$width = 0;
	foreach ( array_keys( $map ) as $key ) {
		$width = max( $width, strlen( $key ) );
	}
	foreach ( $map as $key => $path ) {
		$lines[] = "\t" . str_pad( "'" . $key . "'", $width + 2 ) . ' => ' . "'" . $path . "'," ;
	}

	$count  = count( $map );
	$header = <<<'HEAD'
<?php
/**
 * Class map: lowercased class/interface/trait name => file, relative to KARMCP_DIR.
 *
 * GENERATED FILE — do not edit. Regenerate with:
 *
 *     php bin/generate-classmap.php
 *
 * Committed on purpose, the way Composer commits its classmap: building it at
 * runtime would mean stat-ing the whole tree on every request, which is the
 * cost this replaces. ClassmapTest fails if it drifts from the tree.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

HEAD;

	// Always LF, never PHP_EOL: the file is committed, and a CRLF host would
	// otherwise rewrite every line and make --check fail on the next machine.
	return $header . "
" . '// ' . $count . ' symbols.' . "
"
		. 'return array(' . "
"
		. implode( "
", $lines ) . "
"
		. ');' . "
";
}

$karmcp_root  = dirname( __DIR__ );
$karmcp_map   = karmcp_build_map( $karmcp_root );
$karmcp_out   = karmcp_render_map( $karmcp_map );
$karmcp_dest  = $karmcp_root . '/' . KARMCP_MAP_FILE;
$karmcp_check = in_array( '--check', $argv, true );

if ( $karmcp_check ) {
	$current = is_readable( $karmcp_dest ) ? (string) file_get_contents( $karmcp_dest ) : '';
	if ( $current !== $karmcp_out ) {
		fwrite( STDERR, 'classmap is stale — run: php bin/generate-classmap.php' . PHP_EOL );
		exit( 1 );
	}
	echo 'classmap up to date (' . count( $karmcp_map ) . ' symbols).' . PHP_EOL;
	exit( 0 );
}

file_put_contents( $karmcp_dest, $karmcp_out );
echo 'Wrote ' . KARMCP_MAP_FILE . ' (' . count( $karmcp_map ) . ' symbols).' . PHP_EOL;
