<?php
/**
 * Turns hardening findings into changes that can actually be applied.
 *
 * The audit next door has always known what is wrong and said so; this is the
 * half that does something about it. Two constraints shape the whole design:
 *
 * 1. **`wp-config.php` is off limits.** KarMCP_Filesystem_Guard refuses to read
 *    it, let alone write it, and that is not an oversight to route around here.
 *    So a fix is only offered when it can be achieved from inside WordPress —
 *    which rules out `WP_DEBUG_DISPLAY` and the HTTPS move, and turns
 *    `DISALLOW_FILE_EDIT` into a constant this plugin defines early rather than
 *    a line someone adds to a file.
 *
 * 2. **Some things a machine must not decide.** Renaming the `admin` account
 *    breaks whatever authenticates as it, and buying a certificate is not a
 *    button. Those stay reported and unfixed, and the plan says so rather than
 *    quietly omitting them.
 *
 * plan() is pure: findings in, plan out, no options and no side effects. It is
 * what the tests exercise and what the dry-run returns.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hardening fixes.
 *
 * @since 1.5.0
 */
class KarMCP_Security_Hardening_Fixer {

	/** Which hardening fixes are switched on. */
	const OPTION_APPLIED = 'karmcp_hardening_applied';

	/**
	 * Every fix this class knows, keyed by the finding id it answers.
	 *
	 * `breaks` is not decoration: it is what the dry-run shows before anyone
	 * confirms, because two of these have real victims.
	 *
	 * @since 1.5.0
	 * @return array<string,array{id:string,label:string,does:string,breaks:string}>
	 */
	public static function catalog(): array {
		return array(
			'harden_file_edit'          => array(
				'id'     => 'disallow_file_edit',
				'label'  => __( 'Disable the theme/plugin file editor', 'karmcp' ),
				'does'   => __( 'Defines DISALLOW_FILE_EDIT early on every request, so the dashboard editor disappears. A compromised administrator account can no longer edit PHP from the browser.', 'karmcp' ),
				'breaks' => __( 'Nothing, unless you actually edit theme files from the dashboard.', 'karmcp' ),
			),
			'harden_xmlrpc'             => array(
				'id'     => 'disable_xmlrpc',
				'label'  => __( 'Disable XML-RPC', 'karmcp' ),
				'does'   => __( 'Turns off xmlrpc.php and drops system.multicall, which batches hundreds of password guesses into a single request.', 'karmcp' ),
				'breaks' => __( 'The WordPress mobile app and Jetpack, which both sign in over XML-RPC.', 'karmcp' ),
			),
			'harden_version_disclosure' => array(
				'id'     => 'hide_version',
				'label'  => __( 'Stop disclosing the WordPress version', 'karmcp' ),
				'does'   => __( 'Removes the generator meta tag and the version query string from asset URLs, so a scanner cannot fingerprint the exact release. Does not delete readme.html — that is a file, and deleting files is not this tool\'s job.', 'karmcp' ),
				'breaks' => __( 'Nothing. Cache busting keeps working; the plugin substitutes a stable hash for the version.', 'karmcp' ),
			),
			'harden_security_headers'   => array(
				'id'     => 'security_headers',
				'label'  => __( 'Send the missing security headers', 'karmcp' ),
				'does'   => __( 'Adds X-Frame-Options: SAMEORIGIN, X-Content-Type-Options: nosniff and a conservative Referrer-Policy, plus HSTS when the site is already on HTTPS. Content-Security-Policy is deliberately NOT set: a generated CSP breaks page builders, and a broken CSP is worse than none.', 'karmcp' ),
				'breaks' => __( 'Embedding your pages in an iframe on another domain.', 'karmcp' ),
			),
		);
	}

	/**
	 * Findings this class deliberately will not fix, and why. Surfaced in the
	 * plan so the answer to "why is this still red" is in the output rather
	 * than in someone's memory.
	 *
	 * @since 1.5.0
	 * @return array<string,string>
	 */
	public static function unfixable(): array {
		return array(
			'harden_debug_display' => __( 'WP_DEBUG_DISPLAY is read before any plugin loads, so no plugin can change it. Edit wp-config.php by hand.', 'karmcp' ),
			'harden_admin_user'    => __( 'Renaming or removing the "admin" account breaks whatever authenticates as it. A person decides this one.', 'karmcp' ),
			'harden_https'         => __( 'Serving over HTTPS needs a certificate on the server. Nothing inside WordPress can arrange that.', 'karmcp' ),
		);
	}

	/**
	 * Builds the plan. Pure.
	 *
	 * @since 1.5.0
	 *
	 * @param array $findings Findings from the hardening audit.
	 * @param array $applied  Fix ids already switched on.
	 * @return array{fixable:array,already_applied:array,manual:array,passing:array}
	 */
	public static function plan( array $findings, array $applied ): array {
		$catalog   = self::catalog();
		$unfixable = self::unfixable();

		$out = array(
			'fixable'         => array(),
			'already_applied' => array(),
			'manual'          => array(),
			'passing'         => array(),
		);

		foreach ( $findings as $f ) {
			$fid    = (string) ( $f['id'] ?? '' );
			$status = (string) ( $f['status'] ?? '' );

			if ( 'hardening' !== ( $f['category'] ?? '' ) ) {
				continue;
			}
			if ( 'pass' === $status ) {
				$out['passing'][] = $fid;
				continue;
			}

			if ( isset( $catalog[ $fid ] ) ) {
				$entry = $catalog[ $fid ] + array( 'finding' => $fid );
				if ( in_array( $catalog[ $fid ]['id'], $applied, true ) ) {
					$out['already_applied'][] = $entry;
				} else {
					$out['fixable'][] = $entry;
				}
				continue;
			}

			if ( isset( $unfixable[ $fid ] ) ) {
				$out['manual'][] = array(
					'finding' => $fid,
					'label'   => (string) ( $f['label'] ?? $fid ),
					'why'     => $unfixable[ $fid ],
				);
			}
		}

		return $out;
	}

	/**
	 * The fix ids currently switched on.
	 *
	 * @since 1.5.0
	 * @return string[]
	 */
	public static function applied(): array {
		$v = get_option( self::OPTION_APPLIED, array() );
		return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ) ) ) : array();
	}

	/**
	 * Switches a set of fixes on. Returns the ids that changed.
	 *
	 * @since 1.5.0
	 *
	 * @param string[] $ids Fix ids.
	 * @return string[] Ids newly applied.
	 */
	public static function apply( array $ids ): array {
		$valid   = wp_list_pluck( self::catalog(), 'id' );
		$current = self::applied();
		$added   = array();

		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( in_array( $id, $valid, true ) && ! in_array( $id, $current, true ) ) {
				$current[] = $id;
				$added[]   = $id;
			}
		}

		if ( $added ) {
			update_option( self::OPTION_APPLIED, array_values( array_unique( $current ) ) );
		}
		return $added;
	}

	/**
	 * Switches fixes back off — the whole reason this is an option and not a
	 * file edit.
	 *
	 * @since 1.5.0
	 *
	 * @param string[] $ids Fix ids.
	 * @return string[] Ids removed.
	 */
	public static function revert( array $ids ): array {
		$current = self::applied();
		$removed = array_values( array_intersect( $current, array_map( 'strval', $ids ) ) );

		if ( $removed ) {
			update_option( self::OPTION_APPLIED, array_values( array_diff( $current, $removed ) ) );
		}
		return $removed;
	}
}
