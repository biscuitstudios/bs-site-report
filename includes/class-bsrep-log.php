<?php
/**
 * The event log.
 *
 * This table is the only thing in the plugin that cannot be rebuilt. Everything
 * else is a snapshot of current state and can be recollected at any time. The
 * update history accumulates from install day and there is no other copy of it,
 * so this class never deletes and uninstall leaves the table alone by default.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads maintenance events.
 */
class Bsrep_Log {

	/**
	 * Schema version, bumped when the table changes.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	const SCHEMA_OPTION = 'bsrep_schema_version';

	/**
	 * Window in seconds inside which a duplicate event is treated as the same event.
	 *
	 * The upgrader hook and the version sweeper both see a single update. Without
	 * this, every update logs twice and the headline number on the report page is
	 * exactly double. Twelve hours because the sweeper runs twice a day.
	 */
	const DEDUPE_WINDOW = 12 * HOUR_IN_SECONDS;

	/**
	 * Table name, with the site prefix applied.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'bsrep_events';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(32) NOT NULL DEFAULT '',
			object_slug varchar(191) NOT NULL DEFAULT '',
			object_name varchar(191) NOT NULL DEFAULT '',
			version_from varchar(32) NOT NULL DEFAULT '',
			version_to varchar(32) NOT NULL DEFAULT '',
			context varchar(32) NOT NULL DEFAULT '',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occurred_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY event_type (event_type),
			KEY object_slug (object_slug)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Record one event, unless an equivalent one was already recorded recently.
	 *
	 * @param array $event {
	 *     Event fields. Anything missing takes a safe default.
	 *
	 *     @type string $event_type   plugin_update, theme_update, core_update, plugin_install,
	 *                                plugin_activate, plugin_deactivate, plugin_delete.
	 *     @type string $object_slug  Plugin file, theme stylesheet, or 'wordpress' for core.
	 *     @type string $object_name  Human readable name.
	 *     @type string $version_from Version before, where it is known.
	 *     @type string $version_to   Version after.
	 *     @type string $context      upgrader, automatic, observed, cli.
	 * }
	 * @return int The inserted row id, or 0 when the event was a duplicate or failed.
	 */
	public static function record( array $event ) {
		global $wpdb;

		$row = array(
			'event_type'   => substr( (string) ( $event['event_type'] ?? '' ), 0, 32 ),
			'object_slug'  => substr( (string) ( $event['object_slug'] ?? '' ), 0, 191 ),
			'object_name'  => substr( (string) ( $event['object_name'] ?? '' ), 0, 191 ),
			'version_from' => substr( (string) ( $event['version_from'] ?? '' ), 0, 32 ),
			'version_to'   => substr( (string) ( $event['version_to'] ?? '' ), 0, 32 ),
			'context'      => substr( (string) ( $event['context'] ?? 'observed' ), 0, 32 ),
			'actor_id'     => get_current_user_id(),
			'occurred_at'  => current_time( 'mysql', true ),
		);

		if ( '' === $row['event_type'] || '' === $row['object_slug'] ) {
			return 0;
		}

		// An update that did not change the version is not an update. The upgrader
		// hook fires during a core update while the running process still holds the
		// old $wp_version, so it writes 7.1.1 to 7.1.1, and the sweep then writes the
		// real 7.1.1 to 7.1.2 later. Both survive the dedupe window, because it keys
		// on version_to, and the headline count comes out one too high.
		// Seen on Biscuit Dev, September 22, 2026, on the first real install.
		if ( str_ends_with( $row['event_type'], '_update' )
			&& '' !== $row['version_from']
			&& $row['version_from'] === $row['version_to'] ) {
			return 0;
		}

		if ( self::is_duplicate( $row ) ) {
			return 0;
		}

		$inserted = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Has an equivalent event already been recorded inside the dedupe window?
	 *
	 * Equivalent means same type, same object and same resulting version. Two
	 * genuine updates of one plugin to the same version inside twelve hours is not
	 * a thing that happens; the same update seen twice by two observers is.
	 *
	 * @param array $row Prepared row.
	 * @return bool
	 */
	protected static function is_duplicate( array $row ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - self::DEDUPE_WINDOW );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE event_type = %s AND object_slug = %s AND version_to = %s AND occurred_at >= %s
				 LIMIT 1",
				$row['event_type'],
				$row['object_slug'],
				$row['version_to'],
				$since
			)
		);
		// phpcs:enable

		return null !== $found;
	}

	/**
	 * Count events by type between two dates.
	 *
	 * @param string $from MySQL datetime, UTC, inclusive.
	 * @param string $to   MySQL datetime, UTC, inclusive.
	 * @return array Map of event_type => count.
	 */
	public static function counts_between( $from, $to ) {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS n FROM {$table}
				 WHERE occurred_at BETWEEN %s AND %s
				 GROUP BY event_type",
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['event_type'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Monthly counts of one event type, oldest month first.
	 *
	 * @param string $event_type Event type.
	 * @param string $from       MySQL datetime, UTC.
	 * @param string $to         MySQL datetime, UTC.
	 * @return array List of array{ month: string, count: int }.
	 */
	public static function monthly( $event_type, $from, $to ) {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT( occurred_at, '%%Y-%%m' ) AS month, COUNT(*) AS n
				 FROM {$table}
				 WHERE event_type = %s AND occurred_at BETWEEN %s AND %s
				 GROUP BY month
				 ORDER BY month ASC",
				$event_type,
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'month' => $row['month'],
				'count' => (int) $row['n'],
			);
		}

		return $out;
	}

	/**
	 * The most recent events, newest first.
	 *
	 * @param int $limit How many.
	 * @return array List of rows.
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY occurred_at DESC, id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		// phpcs:enable

		return (array) $rows;
	}

	/**
	 * Date of the oldest event, which is how far back the log can honestly speak.
	 *
	 * A report page must never present a twelve month count from a log that is six
	 * weeks old. This value is what lets the page say "since August 4" instead.
	 *
	 * @return string|null MySQL datetime in UTC, or null when the log is empty.
	 */
	public static function first_event_date() {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( "SELECT MIN( occurred_at ) FROM {$table}" );
		// phpcs:enable

		return $value ? (string) $value : null;
	}

	/**
	 * Total rows in the log.
	 *
	 * @return int
	 */
	public static function total() {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable
	}
}
