<?php
/**
 * Static scanner for the ability surface, shared by the parity gates.
 *
 * The tests that use this cannot enumerate abilities at runtime: the harness
 * requires seven ability files on purpose, and making it require all 76 would
 * change what every existing test sees — the bootstrap loads first, so a stub
 * it declares wins over one a test file declares. That trap is documented in
 * CLAUDE.md and it already cost a debugging session over `wp_get_object_terms()`.
 *
 * So this reads the source, the way AjaxActionContractTest does. What it must
 * not do is guess: a scanner that silently misses a registration turns a red
 * gate green, which is the failure this repo keeps having. It therefore
 * accounts for every `karmcp_register_ability(` call it finds — see
 * `unattributed()`. A fifth registration idiom fails the gates and names the
 * file, rather than quietly dropping the tools it registers.
 *
 * @package KarMCP
 */

/**
 * Reads ability names, catalog slugs and seed slugs out of the tree.
 */
class KarMCP_Ability_Scan {

	/** Plugin root. */
	private const ROOT = __DIR__ . '/..';

	/** @var array<string,array{file:string,readonly:?bool,destructive:?bool}>|null */
	private static $abilities = null;

	/** @var array<string,string>|null file => why the scanner could not read it. */
	private static $unattributed = null;

	/**
	 * Every ability name the tree registers, keyed by name.
	 *
	 * The value carries the file it came from and its two MCP annotations, or
	 * null for an annotation the idiom does not expose to a static read.
	 *
	 * @return array<string,array{file:string,readonly:?bool,destructive:?bool}>
	 */
	public static function abilities(): array {
		self::scan();
		return self::$abilities;
	}

	/**
	 * Registration calls the scanner could not resolve to a name.
	 *
	 * Empty is the only acceptable value. Anything here is a registration idiom
	 * added after this scanner was written, and every gate built on it is
	 * under-reporting until the idiom is taught here.
	 *
	 * @return array<string,string> "file:line" => the source line.
	 */
	public static function unattributed(): array {
		self::scan();
		return self::$unattributed;
	}

	/**
	 * Slugs that appear as keys in KarMCP_Admin::get_tool_catalog().
	 *
	 * @return string[]
	 */
	public static function catalog_slugs(): array {
		$admin = self::read( 'includes/admin/class-admin.php' );
		if ( ! preg_match( '/private function get_tool_catalog\(\): array \{(.*?)\n\t\}/s', $admin, $m ) ) {
			throw new RuntimeException( 'get_tool_catalog() no longer matches the shape this scanner reads.' );
		}
		preg_match_all( '/\'(karmcp\/[a-z0-9-]+)\'\s*=>\s*array\(/', $m[1], $hits );
		return array_values( array_unique( $hits[1] ) );
	}

	/**
	 * The catalog split into its categories.
	 *
	 * Two shapes carry a category and both have to be read: the literal that
	 * opens the method (`'query' => array( … )`, three tabs in) and the
	 * assignments that follow it (`$tools['seo'] = array( … )`, inside a bare
	 * block). Reading only the first silently treats everything after it as one
	 * category, which is a scanner that lies rather than one that fails.
	 *
	 * @return array<string,array{pro:bool,slugs:string[]}>
	 */
	public static function catalog_categories(): array {
		$admin = self::read( 'includes/admin/class-admin.php' );
		if ( ! preg_match( '/private function get_tool_catalog\(\): array \{(.*?)\n\t\}/s', $admin, $m ) ) {
			throw new RuntimeException( 'get_tool_catalog() no longer matches the shape this scanner reads.' );
		}
		$body = $m[1];

		// Cut the body at every `key => array(` / `$tools['key'] = array(`
		// opener, keeping the key. The inner `'tools' => array(` opener matches
		// too — it is not a category, it is where a category's slugs begin, so
		// its chunk is folded back into the category that opened it.
		$parts = preg_split(
			'/\n\t+(?:\$tools\[)?\'([a-z0-9_]+)\'\]?\s*(?:=>|=)\s*array\(\n/',
			$body,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);
		array_shift( $parts );

		$raw     = array();
		$current = null;
		for ( $i = 0; $i + 1 < count( $parts ); $i += 2 ) {
			$key   = $parts[ $i ];
			$chunk = $parts[ $i + 1 ];
			if ( 'tools' === $key ) {
				if ( null !== $current ) {
					$raw[ $current ] .= $chunk;
				}
				continue;
			}
			$current         = $key;
			$raw[ $current ] = $chunk;
		}

		$out = array();
		foreach ( $raw as $key => $chunk ) {
			// A category always declares a label. Anything else the splitter
			// caught is an inner array and carries no slugs anyway.
			if ( false === strpos( $chunk, "'label'" ) ) {
				continue;
			}
			preg_match_all( '/\'(karmcp\/[a-z0-9-]+)\'\s*=>\s*array\(/', $chunk, $slugs );
			$out[ $key ] = array(
				'pro'   => (bool) preg_match( '/\'pro\'\s*=>\s*true/', $chunk ),
				'slugs' => array_values( array_unique( $slugs[1] ) ),
			);
		}
		return $out;
	}

	/**
	 * Every slug named by the default-disabled machinery: the `*_tool_slugs()`
	 * helpers and the inline `$add[]` lines.
	 *
	 * This is deliberately a superset of what actually ends up seeded. Some
	 * helpers feed only the drift guard's conditional list (`woo_tool_slugs()`),
	 * not the seeding steps. That is the right set for the gate built on it: a
	 * slug that matches nothing is a bug wherever it is named, because both
	 * users of these lists work by string comparison and both fail silently.
	 * It is the wrong set for answering "does this tool ship disabled" — read
	 * the seeding steps for that.
	 *
	 * The character class has to admit digits. It did not, and the single
	 * helper in the tree with a digit in its name — `seo_a11y_tool_slugs()` —
	 * is the one that seeds the page audits, so the gate was quietly skipping
	 * exactly the slugs the SEO group was drifting over.
	 *
	 * @return string[]
	 */
	public static function seeded_slugs(): array {
		$admin = self::read( 'includes/admin/class-admin.php' );
		$out   = array();

		preg_match_all( '/function [a-z0-9_]*_tool_slugs\(\): array \{(.*?)\n\t\}/s', $admin, $helpers );
		foreach ( $helpers[1] as $body ) {
			preg_match_all( '/\'(karmcp\/[a-z0-9-]+)\'/', $body, $hits );
			$out = array_merge( $out, $hits[1] );
		}

		if ( ! preg_match( '/function maybe_apply_default_disabled_tools\(\): void \{(.*?)\n\t\}/s', $admin, $m ) ) {
			throw new RuntimeException( 'maybe_apply_default_disabled_tools() no longer matches the shape this scanner reads.' );
		}
		preg_match_all( '/\'(karmcp\/[a-z0-9-]+)\'/', $m[1], $inline );

		return array_values( array_unique( array_merge( $out, $inline[1] ) ) );
	}

	/**
	 * Slugs the seeding routine deliberately *removes* from the stored option.
	 *
	 * These name tools that no longer exist — the v5 widget consolidation and
	 * the v14 ACF layout change strip them so they stop lingering. A gate that
	 * demanded they resolve to an ability would have the fact exactly backwards.
	 *
	 * @return string[]
	 */
	public static function retired_slugs(): array {
		$admin = self::read( 'includes/admin/class-admin.php' );
		$out   = array();
		foreach ( array( 'removed_widget_tool_slugs', 'legacy_acf_operation_slugs' ) as $fn ) {
			if ( preg_match( '/function ' . $fn . '\(\): array \{(.*?)\n\t\}/s', $admin, $m ) ) {
				preg_match_all( '/\'(karmcp\/[a-z0-9-]+)\'/', $m[1], $hits );
				$out = array_merge( $out, $hits[1] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every PHP file under includes/.
	 *
	 * @return string[] Absolute paths.
	 */
	public static function php_files(): array {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::ROOT . '/includes' ) );
		foreach ( $it as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Path relative to the plugin root, forward slashes, for messages.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function relative( string $path ): string {
		$root = str_replace( '\\', '/', realpath( self::ROOT ) ) . '/';
		return str_replace( $root, '', str_replace( '\\', '/', $path ) );
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Reads a file relative to the plugin root.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private static function read( string $relative ): string {
		$path = self::ROOT . '/' . $relative;
		$src  = file_get_contents( $path );
		if ( false === $src ) {
			throw new RuntimeException( "Could not read $relative." );
		}
		return $src;
	}

	/**
	 * Walks the tree once, resolving every registration idiom in use.
	 */
	private static function scan(): void {
		if ( null !== self::$abilities ) {
			return;
		}
		self::$abilities    = array();
		self::$unattributed = array();

		foreach ( self::php_files() as $path ) {
			$src = self::strip_comments( (string) file_get_contents( $path ) );
			$rel = self::relative( $path );

			// Idiom 1 — the name is a literal at the call site, on the same
			// line or the next one.
			// The lookbehind skips the shim's own declaration in
			// class-schema-compat.php, which is a definition, not a call.
			$chunks = preg_split( '/(?<!function )karmcp_register_ability\(\s*/', $src );
			$calls  = count( $chunks ) - 1;
			array_shift( $chunks );
			$resolved = 0;
			foreach ( $chunks as $chunk ) {
				if ( preg_match( '/^\'(karmcp\/[a-z0-9-]+)\'/', $chunk, $n ) ) {
					self::remember( $n[1], $rel, $chunk );
					++$resolved;
					continue;
				}
				// Idiom 2 — the name was put in a local first. Both variables
				// in use are assigned a literal a few lines above the call.
				if ( preg_match( '/^\$(name|full_name)\s*,/', $chunk, $v ) ) {
					++$resolved;
					continue;
				}
				// Idiom 6 — an integration base registers `$this->read_tool()`
				// / `$this->write_tool()`. The base itself contributes no name:
				// either the subclass returns a literal from those methods (see
				// below) or the name is composed from its id() (idiom 5). Both
				// are resolved from the subclass, so the base's call sites are
				// accounted for without adding anything here.
				if ( preg_match( '/^\$this->(read|write)_tool\(\)\s*,/', $chunk ) ) {
					++$resolved;
					continue;
				}
				$head                                        = trim( strtok( $chunk, "\n" ) );
				self::$unattributed[ $rel . ': ' . $head ] = $head;
			}
			unset( $resolved, $calls );

			// Idiom 2, resolved from the assignments themselves.
			if ( preg_match_all( '/\$(?:name|full_name)\s*=\s*\'(karmcp\/[a-z0-9-]+)\'/', $src, $locals ) ) {
				foreach ( $locals[1] as $name ) {
					self::remember( $name, $rel, self::body_after( $src, $name ) );
				}
			}

			// Idiom 3 — the atomic convenience wrapper takes the bare slug and
			// composes the name at run time (`'karmcp/' . $name`), so the full
			// name appears nowhere in the source. Its annotations live once, in
			// the wrapper's own registration, and every slug it registers
			// inherits them — read them from there rather than hunting for a
			// literal that does not exist. Passing an empty body here instead
			// reported eight correctly-annotated tools as unannotated, which is
			// a scanner that invents work.
			if ( preg_match_all( '/register_atomic_convenience\(\s*\n\s*\'([a-z0-9-]+)\'/', $src, $conv ) ) {
				$wrapper = '';
				if ( preg_match( '/function register_atomic_convenience\(.*?\n\t\}/s', $src, $w ) ) {
					$wrapper = $w[0];
				}
				foreach ( $conv[1] as $slug ) {
					self::remember( 'karmcp/' . $slug, $rel, $wrapper );
				}
			}

			// Idiom 4 — the `ability()` helper the database and WP-CLI groups
			// factor their registrations through.
			if ( preg_match_all( '/\$this->ability\(\s*\n?\s*\'(karmcp\/[a-z0-9-]+)\'(.*?)\)\;/s', $src, $helper, PREG_SET_ORDER ) ) {
				foreach ( $helper as $hit ) {
					// The helper's last argument is its $readonly flag.
					$ro = null;
					if ( preg_match( '/,\s*(true|false)\s*$/s', rtrim( $hit[2] ), $flag ) ) {
						$ro = ( 'true' === $flag[1] );
					}
					self::$abilities[ $hit[1] ] = array(
						'file'        => $rel,
						'readonly'    => $ro,
						'destructive' => null,
					);
				}
			}

			// Idiom 6, resolved — a subclass that overrides read_tool() /
			// write_tool() with a literal instead of leaning on id().
			foreach ( array( 'read' => true, 'write' => false ) as $verb => $is_read ) {
				if ( preg_match( '/function ' . $verb . '_tool\(\)\s*:\s*string\s*\{\s*return\s*\'(karmcp\/[a-z0-9-]+)\'/', $src, $lit ) ) {
					self::$abilities[ $lit[1] ] = array(
						'file'        => $rel,
						'readonly'    => $is_read,
						'destructive' => $is_read ? false : null,
					);
				}
			}

			// Idiom 5 — an integration base composes `<id>-read` / `<id>-write`
			// from the subclass's id().
			if ( preg_match( '/function id\(\)\s*:\s*string\s*\{\s*return\s*\'([a-z0-9-]+)\'/', $src, $id ) ) {
				self::$abilities[ 'karmcp/' . $id[1] . '-read' ]  = array(
					'file'        => $rel,
					'readonly'    => true,
					'destructive' => false,
				);
				self::$abilities[ 'karmcp/' . $id[1] . '-write' ] = array(
					'file'        => $rel,
					'readonly'    => false,
					'destructive' => null,
				);
			}
		}

		ksort( self::$abilities );
		ksort( self::$unattributed );
	}

	/**
	 * Blanks out comments, preserving byte offsets.
	 *
	 * Without this the scanner matches the prose: CLAUDE.md-style commentary in
	 * class-bootstrap.php and class-schema-compat.php names
	 * `karmcp_register_ability()` in running text, and every one of those read
	 * as an unresolved registration. Tokenizing rather than pattern-matching is
	 * the same call tools/make-pot.php makes, for the same reason — a regex
	 * cannot tell code from a sentence about code.
	 *
	 * Newlines survive so line-oriented patterns still behave.
	 *
	 * @param string $src PHP source.
	 * @return string
	 */
	private static function strip_comments( string $src ): string {
		$out = '';
		foreach ( token_get_all( $src ) as $token ) {
			if ( is_string( $token ) ) {
				$out .= $token;
				continue;
			}
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}
			$out .= $token[1];
		}
		return $out;
	}

	/**
	 * Records one ability and the annotations its registration body declares.
	 *
	 * @param string $name  Ability name.
	 * @param string $file  Relative file it was found in.
	 * @param string $chunk Source following the name, where the args live.
	 */
	private static function remember( string $name, string $file, string $chunk ): void {
		$readonly    = null;
		$destructive = null;
		if ( preg_match( '/\'readonly\'\s*=>\s*(true|false)/', $chunk, $a ) ) {
			$readonly = ( 'true' === $a[1] );
		}
		if ( preg_match( '/\'destructive\'\s*=>\s*(true|false)/', $chunk, $b ) ) {
			$destructive = ( 'true' === $b[1] );
		}
		self::$abilities[ $name ] = array(
			'file'        => $file,
			'readonly'    => $readonly,
			'destructive' => $destructive,
		);
	}

	/**
	 * The source that follows a local-variable assignment, so the annotations
	 * belonging to that registration can be read off it.
	 *
	 * @param string $src  File source.
	 * @param string $name Ability name assigned to the local.
	 * @return string
	 */
	private static function body_after( string $src, string $name ): string {
		$at = strpos( $src, "'" . $name . "'" );
		return false === $at ? '' : substr( $src, $at, 4000 );
	}
}
