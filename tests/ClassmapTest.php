<?php
/**
 * The invariants the classmap autoloader rests on.
 *
 * `KarMCP_Bootstrap::load_classes()` used to require 153 files on every request.
 * It now requires two, and `KarMCP_Autoloader` resolves the rest on use. Three
 * things have to stay true for that to be safe, and none of them fails visibly
 * anywhere else — the first ships a class nothing can load, the second silently
 * unhooks a feature, the third makes a global function vanish. So they are
 * pinned here.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class ClassmapTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	/**
	 * The only files allowed to declare a global function next to their class,
	 * with the function that forces it.
	 *
	 * Both are required eagerly by load_classes(). A third entry here means a
	 * third eager require — decide that deliberately, do not just extend the
	 * list.
	 *
	 * @var array<string,string>
	 */
	private const EAGER = array(
		'includes/class-schema-compat.php'                   => 'karmcp_register_ability',
		'includes/themer/class-themer-render-controller.php' => 'karmcp_themer_location',
	);

	/**
	 * Calls that are safe at the top level of a class file: they read state and
	 * cannot register a hook or mutate anything. Everything else at that depth
	 * is work done at include time, which is what the autoloader stops happening
	 * on a predictable schedule.
	 *
	 * @var string[]
	 */
	private const INERT = array(
		'defined',
		'define',
		'function_exists',
		'class_exists',
		'interface_exists',
		'trait_exists',
		'extension_loaded',
		'version_compare',
		'constant',
		'dirname',
		'exit',
	);

	/**
	 * Every file under includes/ that declares a class, interface or trait.
	 *
	 * That is exactly the set the autoloader can ever reach: it resolves a name,
	 * and a file declaring no name is unreachable by it. The rest — the admin
	 * view partials, the themer templates, the module settings fields — are
	 * include-and-run by design and out of scope for every rule here.
	 *
	 * @return string[] Paths relative to the plugin root, forward slashes.
	 */
	private function class_files(): array {
		$out = array();
		$dir = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::ROOT . '/includes', FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $dir as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$path = str_replace( DIRECTORY_SEPARATOR, '/', $file->getPathname() );
			$rel  = substr( $path, strpos( $path, '/includes/' ) + 1 );
			if ( $this->inspect( $rel )['symbols'] ) {
				$out[] = $rel;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Walks a file's tokens, reporting what it declares and what it does.
	 *
	 * Brace depth counts T_CURLY_OPEN and T_DOLLAR_OPEN_CURLY_BRACES as opens:
	 * a "{$var}" interpolation emits one of those and a plain `}`, so without
	 * them the depth goes negative and every method body reads as top level.
	 *
	 * @param string $rel Path relative to the plugin root.
	 * @return array{symbols:string[],functions:string[],calls:string[]}
	 */
	private function inspect( string $rel ): array {
		$tokens    = token_get_all( (string) file_get_contents( self::ROOT . '/' . $rel ) );
		$count     = count( $tokens );
		$opens     = array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES );
		$depth     = 0;
		$pending   = false;
		$in_class  = null;
		$symbols   = array();
		$functions = array();
		$calls     = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '{' === $token ) {
				++$depth;
				if ( $pending ) {
					$in_class = $depth;
					$pending  = false;
				}
				continue;
			}
			if ( '}' === $token ) {
				--$depth;
				if ( null !== $in_class && $depth < $in_class ) {
					$in_class = null;
				}
				continue;
			}
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( in_array( $token[0], $opens, true ) ) {
				++$depth;
				continue;
			}

			if ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
				$prev = $tokens[ $i - 1 ] ?? null;
				if ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
					continue;
				}
				$pending = true;
				$name    = $this->next_name( $tokens, $i );
				if ( '' !== $name ) {
					$symbols[] = $name;
				}
				continue;
			}

			if ( T_FUNCTION === $token[0] && null === $in_class ) {
				$name = $this->next_name( $tokens, $i );
				if ( '' !== $name ) {
					$functions[] = $name;
				}
				continue;
			}

			if ( 0 === $depth && T_STRING === $token[0] ) {
				$name = strtolower( $token[1] );
				if ( $this->is_call( $tokens, $i ) && ! in_array( $name, self::INERT, true ) ) {
					$calls[] = $name;
				}
			}
		}

		return array(
			'symbols'   => $symbols,
			'functions' => array_values( array_unique( $functions ) ),
			'calls'     => array_values( array_unique( $calls ) ),
		);
	}

	/**
	 * The identifier following a declaration keyword, '' for anonymous ones.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $i      Index of the keyword.
	 * @return string
	 */
	private function next_name( array $tokens, int $i ): string {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}
			return ( is_array( $token ) && T_STRING === $token[0] ) ? $token[1] : '';
		}
		return '';
	}

	/**
	 * Whether the identifier at $i is followed by an opening parenthesis.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $i      Index of the identifier.
	 * @return bool
	 */
	private function is_call( array $tokens, int $i ): bool {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return '(' === $token;
		}
		return false;
	}

	/**
	 * A class the map does not list is a class nothing can load — the failure is
	 * a fatal on a live site and nothing at all in this suite, because the suite
	 * requires its fixtures by hand.
	 */
	public function test_the_committed_map_matches_the_tree(): void {
		$map = require self::ROOT . '/includes/classmap.php';
		$this->assertIsArray( $map );

		$expected = array();
		foreach ( $this->class_files() as $rel ) {
			foreach ( $this->inspect( $rel )['symbols'] as $symbol ) {
				$expected[ strtolower( $symbol ) ] = $rel;
			}
		}
		ksort( $map );
		ksort( $expected );

		$this->assertSame(
			$expected,
			$map,
			'includes/classmap.php is stale — run: php bin/generate-classmap.php'
		);
	}

	/**
	 * The load-bearing one. Dropping the require list is only safe because
	 * loading a file does nothing: the hooks are wired by name in wire_hooks(),
	 * and a string callable resolves through the autoloader when the hook fires.
	 * A file that called add_action() at the top level would stop being wired the
	 * moment nothing else happened to name its class, and the feature would go
	 * quiet with no error anywhere.
	 */
	public function test_no_class_file_does_work_when_it_is_included(): void {
		foreach ( $this->class_files() as $rel ) {
			if ( array_key_exists( $rel, self::EAGER ) ) {
				continue;
			}
			$calls = $this->inspect( $rel )['calls'];
			$this->assertSame(
				array(),
				$calls,
				"$rel calls " . implode( ', ', $calls ) . '() at include time. The autoloader loads a'
					. ' file only when something names its class, so that call now runs at an'
					. ' unpredictable moment or not at all. Move it into a method wire_hooks() calls.'
			);
		}
	}

	/**
	 * PHP autoloads classes, never functions. A file that declares a global
	 * function has to be required eagerly or the function is simply undefined
	 * for any caller that gets there first — a theme calling the Themer template
	 * tag from its own header.php, for one.
	 */
	public function test_only_the_known_files_declare_global_functions(): void {
		$declaring = array();
		foreach ( $this->class_files() as $rel ) {
			$functions = $this->inspect( $rel )['functions'];
			if ( $functions ) {
				$declaring[ $rel ] = $functions;
			}
		}
		ksort( $declaring );

		$expected = self::EAGER;
		ksort( $expected );

		$this->assertSame(
			array_keys( $expected ),
			array_keys( $declaring ),
			'A class file grew a global function. Either move it into a class, or require the file'
				. ' eagerly in load_classes() and add it to EAGER with the reason.'
		);

		foreach ( self::EAGER as $rel => $function ) {
			$this->assertContains( $function, $declaring[ $rel ], "$rel still declares $function()" );
		}
	}

	/**
	 * And those two files are actually required, in the one place that runs on
	 * every request. Listing them in EAGER without requiring them would pass the
	 * test above and still ship the bug.
	 */
	public function test_load_classes_requires_exactly_the_eager_files(): void {
		$src  = (string) file_get_contents( self::ROOT . '/includes/class-bootstrap.php' );
		$from = strpos( $src, 'private static function load_classes' );
		$to   = strpos( $src, 'public static function load_ability_classes' );
		$this->assertIsInt( $from );
		$this->assertIsInt( $to );

		$body = substr( $src, $from, $to - $from );
		preg_match_all( "#require_once KARMCP_DIR . '([^']+)';#", $body, $m );

		$this->assertSame( array_keys( self::EAGER ), $m[1] );
	}
}
