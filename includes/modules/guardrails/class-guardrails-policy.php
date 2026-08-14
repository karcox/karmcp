<?php
/**
 * Guardrails policy — the decision layer.
 *
 * Deliberately pure: every method takes plain data and returns plain data, with
 * no calls to get_option(), current_time() or the ability registry. The module
 * gathers those facts and hands them over. That keeps the rules unit-testable
 * without a WordPress install, which matters because this class is what stands
 * between an agent and a client's production site.
 *
 * The same policy drives two surfaces:
 *   - evaluate()      : the `karmcp_before_write` veto (enforcement, after the fact)
 *   - context_block() : the `karmcp_discovery_memory` seam (prevention, up front)
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure rule evaluation for the Guardrails module.
 *
 * @since 1.0.0
 */
class KarMCP_Guardrails_Policy {

	/**
	 * Policy shape with everything switched off — the "no guardrails" baseline.
	 *
	 * @return array{read_only:bool,block_destructive:bool,freeze:bool,freeze_from:string,freeze_to:string,freeze_days:int[],protected_posts:int[],protected_types:string[],notes:string}
	 */
	public static function defaults(): array {
		return array(
			'read_only'         => false,
			'block_destructive' => false,
			'freeze'            => false,
			'freeze_from'       => '09:00',
			'freeze_to'         => '19:00',
			'freeze_days'       => array( 1, 2, 3, 4, 5 ),
			'protected_posts'   => array(),
			'protected_types'   => array(),
			'notes'             => '',
		);
	}

	/**
	 * Decide whether a write may proceed.
	 *
	 * Rules are checked cheapest-and-broadest first, and the first match wins —
	 * so the returned error names the rule an admin is most likely to recognise.
	 *
	 * The message is written for the agent that will read it: it says which rule
	 * fired and what would make the call succeed, because an agent that only
	 * learns "denied" retries the same call.
	 *
	 * @param array $policy  Policy settings; missing keys fall back to defaults().
	 * @param array $request {
	 *     The write being attempted.
	 *
	 *     @type string   $tool        Ability name, e.g. `karmcp/update-post`.
	 *     @type bool     $destructive Whether the ability is annotated destructive.
	 *     @type int[]    $post_ids    Post IDs the input refers to.
	 *     @type string[] $post_types  Post types those IDs resolve to.
	 *     @type string   $time        Site-local time as `HH:MM`.
	 *     @type int      $day         ISO-8601 day of week (1 = Monday).
	 * }
	 * @return WP_Error|null WP_Error to block the write; null to allow it.
	 */
	public static function evaluate( array $policy, array $request ) {
		$policy = array_merge( self::defaults(), $policy );

		if ( $policy['read_only'] ) {
			return self::deny(
				'karmcp_policy_read_only',
				__( 'Blocked by site policy: this site is in read-only mode, so no MCP tool may create, update or delete anything. Reading is unaffected. Only an administrator can lift this, from KarMCP → Modules → Guardrails.', 'karmcp' )
			);
		}

		if ( $policy['block_destructive'] && ! empty( $request['destructive'] ) ) {
			return self::deny(
				'karmcp_policy_destructive',
				sprintf(
					/* translators: %s: ability name, e.g. karmcp/delete-post */
					__( 'Blocked by site policy: `%s` is a destructive tool and destructive tools are disabled on this site. Use a non-destructive alternative (edit instead of delete, draft instead of trash), or ask an administrator.', 'karmcp' ),
					(string) ( $request['tool'] ?? '' )
				)
			);
		}

		$hit = array_values( array_intersect( self::ints( $request['post_ids'] ?? array() ), $policy['protected_posts'] ) );
		if ( array() !== $hit ) {
			return self::deny(
				'karmcp_policy_protected_post',
				sprintf(
					/* translators: %s: comma-separated list of post IDs */
					__( 'Blocked by site policy: post %s is protected and cannot be modified through MCP. Work on a copy, or ask an administrator to unprotect it.', 'karmcp' ),
					implode( ', ', $hit )
				)
			);
		}

		$types = array_values( array_intersect( self::slugs( $request['post_types'] ?? array() ), $policy['protected_types'] ) );
		if ( array() !== $types ) {
			return self::deny(
				'karmcp_policy_protected_type',
				sprintf(
					/* translators: %s: comma-separated list of post type slugs */
					__( 'Blocked by site policy: content of type `%s` is protected and cannot be modified through MCP. Ask an administrator to make this change.', 'karmcp' ),
					implode( '`, `', $types )
				)
			);
		}

		if ( $policy['freeze'] && self::in_freeze( $policy, (string) ( $request['time'] ?? '' ), (int) ( $request['day'] ?? 0 ) ) ) {
			return self::deny(
				'karmcp_policy_freeze',
				sprintf(
					/* translators: 1: start time, 2: end time, 3: comma-separated day names */
					__( 'Blocked by site policy: writes are frozen between %1$s and %2$s (%3$s), site time. Reading works normally. Retry outside the freeze window, or ask an administrator.', 'karmcp' ),
					$policy['freeze_from'],
					$policy['freeze_to'],
					self::day_names( $policy['freeze_days'] )
				)
			);
		}

		return null;
	}

	/**
	 * Whether a moment falls inside the configured freeze window.
	 *
	 * @param array  $policy Policy settings (freeze_from, freeze_to, freeze_days).
	 * @param string $time   Site-local time as `HH:MM`.
	 * @param int    $day    ISO-8601 day of week (1 = Monday).
	 * @return bool
	 */
	public static function in_freeze( array $policy, string $time, int $day ): bool {
		$policy = array_merge( self::defaults(), $policy );
		$days   = self::ints( $policy['freeze_days'] );

		// An empty day list means every day, not "no days" — an admin who clears
		// the checkboxes is narrowing the schedule, not silently disabling the
		// whole rule they just switched on.
		if ( array() !== $days && ! in_array( $day, $days, true ) ) {
			return false;
		}

		return self::in_window( (string) $policy['freeze_from'], (string) $policy['freeze_to'], $time );
	}

	/**
	 * Whether `HH:MM` falls in [$from, $to), wrapping over midnight when $to is
	 * earlier than $from (a 22:00 → 06:00 overnight freeze).
	 *
	 * A window whose ends are equal covers nothing: zero length is the only
	 * reading that does not silently turn a mis-typed field into a 24h block.
	 *
	 * @param string $from Window start, `HH:MM`.
	 * @param string $to   Window end, `HH:MM`.
	 * @param string $now  Moment to test, `HH:MM`.
	 * @return bool
	 */
	public static function in_window( string $from, string $to, string $now ): bool {
		$a = self::minutes( $from );
		$b = self::minutes( $to );
		$n = self::minutes( $now );

		if ( null === $a || null === $b || null === $n || $a === $b ) {
			return false;
		}

		return ( $a < $b ) ? ( $n >= $a && $n < $b ) : ( $n >= $a || $n < $b );
	}

	/**
	 * Render the active policy as a discovery-context block, so an agent reads
	 * the rules before it plans rather than discovering them through errors.
	 *
	 * Returns an empty string when nothing is configured, leaving the
	 * `karmcp_discovery_memory` seam exactly as it was.
	 *
	 * @param array $policy Policy settings.
	 * @return string Markdown block, or '' when there is nothing to say.
	 */
	public static function context_block( array $policy ): string {
		$policy = array_merge( self::defaults(), $policy );
		$rules  = array();

		if ( $policy['read_only'] ) {
			$rules[] = __( 'This site is in **read-only mode**. Every create, update and delete tool is blocked. Read freely; do not plan any write.', 'karmcp' );
		}
		if ( $policy['block_destructive'] ) {
			$rules[] = __( 'Destructive tools are blocked. Prefer reversible edits: draft instead of trash, update instead of delete.', 'karmcp' );
		}
		if ( $policy['freeze'] ) {
			$rules[] = sprintf(
				/* translators: 1: start time, 2: end time, 3: comma-separated day names */
				__( 'Writes are frozen between %1$s and %2$s (%3$s), site time. Reads are unaffected.', 'karmcp' ),
				$policy['freeze_from'],
				$policy['freeze_to'],
				self::day_names( $policy['freeze_days'] )
			);
		}
		if ( array() !== $policy['protected_posts'] ) {
			$rules[] = sprintf(
				/* translators: %s: comma-separated list of post IDs */
				__( 'These posts are protected and cannot be modified: %s.', 'karmcp' ),
				implode( ', ', $policy['protected_posts'] )
			);
		}
		if ( array() !== $policy['protected_types'] ) {
			$rules[] = sprintf(
				/* translators: %s: comma-separated list of post type slugs */
				__( 'Content of these types is protected and cannot be modified: `%s`.', 'karmcp' ),
				implode( '`, `', $policy['protected_types'] )
			);
		}

		$notes = trim( (string) $policy['notes'] );
		if ( array() === $rules && '' === $notes ) {
			return '';
		}

		$out = array( '## Site policy' );
		if ( array() !== $rules ) {
			$out[] = __( 'Enforced server-side on every write — a call that breaks one of these comes back as an error, so plan around them instead of retrying.', 'karmcp' );
			$out[] = '';
			foreach ( $rules as $rule ) {
				$out[] = '- ' . $rule;
			}
		}
		if ( '' !== $notes ) {
			if ( array() !== $rules ) {
				$out[] = '';
			}
			$out[] = $notes;
		}

		return implode( "\n", $out );
	}

	/**
	 * Normalise raw option values into the typed policy shape.
	 *
	 * Settings are stored as strings (the Settings API idiom the other modules
	 * use), so this is the single place that knows how to read them back.
	 *
	 * @param array $raw Option values keyed by short name.
	 * @return array Typed policy.
	 */
	public static function from_options( array $raw ): array {
		return array(
			'read_only'         => '1' === (string) ( $raw['read_only'] ?? '0' ),
			'block_destructive' => '1' === (string) ( $raw['block_destructive'] ?? '0' ),
			'freeze'            => '1' === (string) ( $raw['freeze'] ?? '0' ),
			'freeze_from'       => self::time_or( (string) ( $raw['freeze_from'] ?? '' ), '09:00' ),
			'freeze_to'         => self::time_or( (string) ( $raw['freeze_to'] ?? '' ), '19:00' ),
			'freeze_days'       => self::ints( self::split( (string) ( $raw['freeze_days'] ?? '' ) ) ),
			'protected_posts'   => self::ints( self::split( (string) ( $raw['protected_posts'] ?? '' ) ) ),
			'protected_types'   => self::slugs( self::split( (string) ( $raw['protected_types'] ?? '' ) ) ),
			'notes'             => (string) ( $raw['notes'] ?? '' ),
		);
	}

	/**
	 * Split a comma-separated option value into trimmed, non-empty parts.
	 *
	 * @param string $value Raw option value.
	 * @return string[]
	 */
	public static function split( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
	}

	/**
	 * `HH:MM` if the string parses as one, otherwise the fallback.
	 *
	 * @param string $value    Candidate time.
	 * @param string $fallback Value to use when $value is not a time.
	 * @return string
	 */
	public static function time_or( string $value, string $fallback ): string {
		$m = self::minutes( $value );
		return ( null === $m ) ? $fallback : sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
	}

	// -----------------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------------

	/**
	 * Minutes since midnight, or null when the string is not `H:MM`/`HH:MM`.
	 *
	 * @param string $time Time string.
	 * @return int|null
	 */
	private static function minutes( string $time ): ?int {
		if ( 1 !== preg_match( '/^\s*(\d{1,2}):(\d{2})\s*$/', $time, $m ) ) {
			return null;
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ( $h > 23 || $i > 59 ) {
			return null;
		}
		return ( $h * 60 ) + $i;
	}

	/**
	 * Build the blocking error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Agent-facing explanation.
	 * @return WP_Error
	 */
	private static function deny( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}

	/**
	 * Cast a list to unique positive integers.
	 *
	 * @param mixed $list Candidate list.
	 * @return int[]
	 */
	private static function ints( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $item ) {
			$n = (int) $item;
			if ( $n > 0 && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * Cast a list to unique lowercase slug-ish strings.
	 *
	 * @param mixed $list Candidate list.
	 * @return string[]
	 */
	private static function slugs( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $item ) {
			if ( ! is_string( $item ) && ! is_numeric( $item ) ) {
				continue;
			}
			$slug = strtolower( trim( (string) $item ) );
			if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Human day list for a set of ISO-8601 day numbers.
	 *
	 * @param mixed $days Day numbers (1 = Monday).
	 * @return string
	 */
	private static function day_names( $days ): string {
		$names = array(
			1 => __( 'Mon', 'karmcp' ),
			2 => __( 'Tue', 'karmcp' ),
			3 => __( 'Wed', 'karmcp' ),
			4 => __( 'Thu', 'karmcp' ),
			5 => __( 'Fri', 'karmcp' ),
			6 => __( 'Sat', 'karmcp' ),
			7 => __( 'Sun', 'karmcp' ),
		);

		$days = self::ints( $days );
		if ( array() === $days ) {
			return __( 'every day', 'karmcp' );
		}

		sort( $days );
		$out = array();
		foreach ( $days as $day ) {
			if ( isset( $names[ $day ] ) ) {
				$out[] = $names[ $day ];
			}
		}
		return implode( ', ', $out );
	}
}
