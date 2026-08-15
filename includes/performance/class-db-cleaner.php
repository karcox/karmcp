<?php
/**
 * Database housekeeping: what is safe to remove, and removing it.
 *
 * The performance audit has always measured this — database size, autoloaded
 * options, accumulated revisions, a stalled cron — and, like the hardening
 * audit before `harden-site`, only ever said so. This is the half that acts.
 *
 * Two rules shape every task here.
 *
 * **WordPress APIs where correctness depends on them.** Deleting a revision with
 * SQL leaves its postmeta behind, so the row count drops and the orphan count
 * rises: cleanup that creates the mess it is meant to remove. Revisions, trashed
 * posts and comments go through core's own delete functions, which is slower and
 * correct. Only genuinely orphaned rows — the ones whose parent is already gone
 * — are removed with SQL, because there is no API for a row whose owner does not
 * exist.
 *
 * **Honest about reversibility.** Some of this is undoable and some is not, and a
 * cleaner that implies otherwise is worse than one that refuses. Each task says
 * which it is, before the button rather than after.
 *
 * It deliberately does not cache anything. It removes work the database is doing
 * for nothing, which is a different kind of speed and survives a cache flush.
 *
 * @package KarMCP
 * @since   1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database cleanup tasks.
 *
 * @since 1.11.0
 */
class KarMCP_DB_Cleaner {

	/** Revisions kept per post, so history is trimmed rather than erased. */
	const KEEP_REVISIONS = 3;

	/** Deletions per task per run: bounded so a huge site cannot time out. */
	const BATCH = 300;

	/**
	 * Every task, with what it does and what it costs.
	 *
	 * `breaks` and `reversible` are not documentation — they are shown beside the
	 * checkbox, because "clean my database" is a thing people ask for without
	 * knowing what is in it.
	 *
	 * @since 1.11.0
	 * @return array<string,array<string,mixed>>
	 */
	public static function tasks(): array {
		return array(
			'revisions'     => array(
				'label'      => __( 'Old post revisions', 'karmcp' ),
				'does'       => sprintf(
					/* translators: %d: revisions kept per post. */
					__( 'Deletes revisions beyond the most recent %d of each post, through the WordPress API so their metadata goes with them. Revisions are the largest avoidable thing in most wp_posts tables.', 'karmcp' ),
					self::KEEP_REVISIONS
				),
				'breaks'     => __( 'Older editing history for those posts is gone.', 'karmcp' ),
				'reversible' => false,
			),
			'transients'    => array(
				'label'      => __( 'Expired transients', 'karmcp' ),
				'does'       => __( 'Removes transients whose expiry has passed. WordPress only clears these lazily, so on a busy site they pile up in wp_options — and an autoloaded one is read on every single page load.', 'karmcp' ),
				'breaks'     => __( 'Nothing. They are expired caches; whatever needs them builds them again.', 'karmcp' ),
				'reversible' => true,
			),
			'orphan_meta'   => array(
				'label'      => __( 'Orphaned metadata', 'karmcp' ),
				'does'       => __( 'Removes rows in postmeta, commentmeta and term_relationships whose post, comment or term no longer exists — left behind by plugins that delete their content without their meta.', 'karmcp' ),
				'breaks'     => __( 'Nothing. Their owner is already gone, so nothing can read them.', 'karmcp' ),
				'reversible' => false,
			),
			'spam_comments' => array(
				'label'      => __( 'Spam and trashed comments', 'karmcp' ),
				'does'       => __( 'Permanently deletes comments already marked as spam or sitting in the trash, with their metadata.', 'karmcp' ),
				'breaks'     => __( 'Anything wrongly marked as spam is gone for good.', 'karmcp' ),
				'reversible' => false,
			),
			'trashed_posts' => array(
				'label'      => __( 'Trashed posts', 'karmcp' ),
				'does'       => __( 'Permanently deletes posts already in the trash, with their metadata and terms.', 'karmcp' ),
				'breaks'     => __( 'The trash is emptied. Everything in it was one click from being restored, and will not be.', 'karmcp' ),
				'reversible' => false,
			),
			'optimize'      => array(
				'label'      => __( 'Reclaim table overhead', 'karmcp' ),
				'does'       => __( 'Runs OPTIMIZE TABLE on tables carrying free space left by deleted rows. Removes no data — it compacts what is there, which is what actually returns the space the tasks above free up.', 'karmcp' ),
				'breaks'     => __( 'Nothing, but tables lock briefly. Not during a traffic peak.', 'karmcp' ),
				'reversible' => true,
			),
		);
	}

	/**
	 * Counts what each task would remove, removing nothing.
	 *
	 * @since 1.11.0
	 * @return array<string,int>
	 */
	public static function counts(): array {
		global $wpdb;

		$counts = array();

		$counts['revisions'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT r.ID, ROW_NUMBER() OVER ( PARTITION BY r.post_parent ORDER BY r.post_date DESC ) AS rn
					FROM {$wpdb->posts} r WHERE r.post_type = %s
				) ranked WHERE ranked.rn > %d",
				'revision',
				self::KEEP_REVISIONS
			)
		);

		$counts['transients'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		$counts['orphan_meta'] =
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
			)
			+ (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm
				 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL"
			)
			+ (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL"
			);

		$counts['spam_comments'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ( 'spam', 'trash' )"
		);

		$counts['trashed_posts'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'trash' )
		);

		// Overhead in whole megabytes: below one there is nothing worth locking a
		// table for.
		$counts['optimize'] = (int) round(
			(float) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'SELECT ROUND( SUM( data_free ) / 1048576, 1 ) FROM information_schema.TABLES
					 WHERE table_schema = %s AND data_free > 0 AND table_name LIKE %s',
					DB_NAME,
					$wpdb->esc_like( $wpdb->prefix ) . '%'
				)
			)
		);

		return $counts;
	}

	/**
	 * Builds the plan. Pure given the counts.
	 *
	 * @since 1.11.0
	 *
	 * @param array $counts From counts().
	 * @return array<int,array<string,mixed>>
	 */
	public static function plan( array $counts ): array {
		$out = array();
		foreach ( self::tasks() as $id => $task ) {
			$n     = (int) ( $counts[ $id ] ?? 0 );
			$out[] = array_merge(
				$task,
				array(
					'id'            => $id,
					'count'         => $n,
					'worth_running' => $n > 0,
				)
			);
		}
		return $out;
	}

	/**
	 * Runs the given tasks.
	 *
	 * Bounded per task per run, so a site with a hundred thousand revisions
	 * cannot turn one click into a request the host kills halfway. Run it again
	 * and it carries on where it stopped.
	 *
	 * @since 1.11.0
	 *
	 * @param string[] $tasks Task ids.
	 * @return array<string,int> Rows removed per task.
	 */
	public static function run( array $tasks ): array {
		$done  = array();
		$known = array_keys( self::tasks() );

		foreach ( $tasks as $task ) {
			$task = (string) $task;
			if ( ! in_array( $task, $known, true ) ) {
				continue;
			}
			$method = 'clean_' . $task;
			if ( method_exists( __CLASS__, $method ) ) {
				$done[ $task ] = (int) self::$method();
			}
		}

		return $done;
	}

	// ---------------------------------------------------------------------
	// The tasks
	// ---------------------------------------------------------------------

	/**
	 * Through wp_delete_post_revision(), not SQL: deleting the row alone leaves
	 * the revision's postmeta behind, which is exactly the mess the orphan task
	 * exists to clear up.
	 *
	 * @since 1.11.0
	 * @return int
	 */
	private static function clean_revisions(): int {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ranked.ID FROM (
					SELECT r.ID, ROW_NUMBER() OVER ( PARTITION BY r.post_parent ORDER BY r.post_date DESC ) AS rn
					FROM {$wpdb->posts} r WHERE r.post_type = %s
				) ranked WHERE ranked.rn > %d LIMIT %d",
				'revision',
				self::KEEP_REVISIONS,
				self::BATCH
			)
		);

		$n = 0;
		foreach ( (array) $ids as $id ) {
			if ( wp_delete_post_revision( (int) $id ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @since 1.11.0
	 * @return int
	 */
	private static function clean_transients(): int {
		global $wpdb;

		$timeouts = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time(),
				self::BATCH
			)
		);

		$n = 0;
		foreach ( (array) $timeouts as $timeout ) {
			$name = substr( (string) $timeout, strlen( '_transient_timeout_' ) );
			// delete_transient() rather than deleting the rows: it clears the
			// object cache too, so a persistent cache does not carry on serving
			// what was just removed from the database.
			delete_transient( $name );
			++$n;
		}
		return $n;
	}

	/**
	 * @since 1.11.0
	 * @return int
	 */
	private static function clean_orphan_meta(): int {
		global $wpdb;
		$n = 0;

		$n += (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE pm FROM {$wpdb->postmeta} pm
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.ID IS NULL LIMIT %d",
				self::BATCH
			)
		);
		$n += (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE cm FROM {$wpdb->commentmeta} cm
				 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				 WHERE c.comment_ID IS NULL LIMIT %d",
				self::BATCH
			)
		);
		$n += (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE tr FROM {$wpdb->term_relationships} tr
				 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE p.ID IS NULL LIMIT %d",
				self::BATCH
			)
		);

		return $n;
	}

	/**
	 * @since 1.11.0
	 * @return int
	 */
	private static function clean_spam_comments(): int {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments}
				 WHERE comment_approved IN ( 'spam', 'trash' ) LIMIT %d",
				self::BATCH
			)
		);

		$n = 0;
		foreach ( (array) $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @since 1.11.0
	 * @return int
	 */
	private static function clean_trashed_posts(): int {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT %d",
				'trash',
				self::BATCH
			)
		);

		$n = 0;
		foreach ( (array) $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Compacts tables carrying free space.
	 *
	 * Only tables of this install, resolved from information_schema and filtered
	 * by the table prefix — never a blanket optimise of the whole schema, which
	 * on shared hosting is not only this site's.
	 *
	 * @since 1.11.0
	 * @return int Tables optimised.
	 */
	private static function clean_optimize(): int {
		global $wpdb;

		$tables = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT table_name FROM information_schema.TABLES
				 WHERE table_schema = %s AND data_free > 0 AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$n = 0;
		foreach ( (array) $tables as $table ) {
			// An identifier, so it cannot be a placeholder. It came from
			// information_schema filtered by this install's prefix, and any
			// backtick is stripped before quoting.
			$safe = '`' . str_replace( '`', '', (string) $table ) . '`';
			// phpcs:ignore WordPress.DB
			$wpdb->query( "OPTIMIZE TABLE {$safe}" );
			++$n;
		}
		return $n;
	}
}
