<?php
/**
 * Independent Analytics, read from its own tables. Read only.
 *
 * Up to 0.1.6 this plugin read Independent Analytics (IA) only through its three
 * public PHP functions. Those cannot answer devices, cities, referrers or AI
 * referrals, and iawp_top_posts() cannot list pages, posts and projects
 * together (top_pages moved here in 0.1.9). On November 25, 2026 Jason ruled that the collector may read IA's
 * own tables, read only, to fill those cards. Accessibility Checker's issues
 * table, read since 0.1.5, is the precedent.
 *
 * What this class promises:
 *
 * - It never writes. Every statement is a SELECT or SHOW TABLES, and it calls no
 *   IA code at all, so no IA cache or option is touched.
 * - It counts the way IA's own report screens count, so a figure in the payload
 *   equals the figure IA shows for the same date range. The SQL is a transcription
 *   of IA's own queries, named against each method below.
 * - It refuses rather than guesses. A DB version it was not written against, a
 *   migration in progress, a pruning setting that has deleted part of the window,
 *   a missing table or a failed query each give available:false with a reason.
 *   Never a partial number, never a zero standing in for "could not read".
 *
 * Written against Independent Analytics Pro 2.16.2 (the copy in Biscuit Dev) and
 * compared against the free 2.16.4 source, IA DB version 53 in both. The counting
 * code in IAWP/Rows and IAWP/Statistics is identical between them once php-scoper's
 * formatting is stripped. 2.16.3 and 2.16.4 only added Microsoft Ads to the known
 * referrer list.
 *
 * IA drops bots before anything is stored (REST_API::track_view() returns early on
 * Device::is_bot()), so there is no bot row here to exclude. These figures count
 * people, which is why IA is read before Kinsta, which counts bots.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads devices, cities, referrers and AI referrals off IA's tables for one window.
 */
class Bsrep_Analytics_Tables {

	/**
	 * The iawp_db_version values this was written and checked against.
	 *
	 * Add one only after reading that version's migrations and its IAWP/Rows and
	 * IAWP/Statistics classes, and after a screen comparison on a real site.
	 */
	const DB_VERSIONS = array( '53' );

	/**
	 * The referrer type IA files AI platforms under. IA's own value, from its
	 * referrer_types table, not a list of domains kept here.
	 */
	const AI_TYPE = 'AI';

	/**
	 * IA's pruning settings that keep at least a year, so cannot cut into a window
	 * that starts on the first of the month eleven months back.
	 * From IAWP/Data_Pruning/Pruning_Scheduler.php, $cutoff_options.
	 */
	const SAFE_PRUNING = array( 'disabled', 'one-year', 'eighteen-months', 'two-years', 'three-years', 'four-years' );

	/**
	 * Post types the Most read pages card covers.
	 *
	 * Jason, November 25, 2026: "every page, post, and project, minus 404s,
	 * drafts, and deleted pages." A client site with other types is a one-line
	 * change here. A type the site does not register is skipped quietly.
	 */
	const TOP_PAGE_TYPES = array( 'page', 'post', 'project' );

	/**
	 * Rows sent in top_pages.
	 */
	const TOP_PAGE_ROWS = 10;

	/**
	 * First day of the window, site timezone.
	 *
	 * @var DateTimeImmutable
	 */
	protected $from;

	/**
	 * Last day of the window, site timezone.
	 *
	 * @var DateTimeImmutable
	 */
	protected $to;

	/**
	 * Window bounds in UTC, which is how IA stores views.viewed_at and sessions.created_at.
	 *
	 * @var string[]
	 */
	protected $range;

	/**
	 * Visitor totals, cached across blocks. False until read.
	 *
	 * @var array|false
	 */
	protected $totals = false;

	/**
	 * Build a reader for whole days, the way IA's own date picker does.
	 *
	 * IA's Exact_Date_Range turns a picked range into the start of the first day
	 * and 23:59:59 on the last day in the site's timezone, then into UTC. This does
	 * the same, so the payload window and the IA screen window are the same window.
	 *
	 * @param DateTimeInterface $from First day, any time of day.
	 * @param DateTimeInterface $to   Last day, any time of day.
	 */
	public function __construct( DateTimeInterface $from, DateTimeInterface $to ) {
		$timezone   = wp_timezone();
		$utc        = new DateTimeZone( 'UTC' );
		$this->from = ( new DateTimeImmutable( $from->format( 'Y-m-d' ), $timezone ) )->setTime( 0, 0, 0 );
		$this->to   = ( new DateTimeImmutable( $to->format( 'Y-m-d' ), $timezone ) )->setTime( 23, 59, 59 );

		$this->range = array(
			$this->from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$this->to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Every block this class fills, keyed as they sit under payload analytics.
	 *
	 * @return array
	 */
	public function collect() {
		return array(
			'top_pages'    => $this->block( 'top_pages' ),
			'devices'      => $this->block( 'devices' ),
			'cities'       => $this->block( 'cities' ),
			'referrers'    => $this->block( 'referrers' ),
			'ai_referrals' => $this->block( 'ai_referrals' ),
		);
	}

	/**
	 * One block, guarded, with the window stamped on it either way.
	 *
	 * @param string $name devices, cities, referrers or ai_referrals.
	 * @return array
	 */
	public function block( $name ) {
		$tables = array(
			'top_pages'    => array( 'sessions', 'views', 'resources' ),
			'devices'      => array( 'sessions', 'views', 'device_types' ),
			'cities'       => array( 'sessions', 'views', 'cities', 'countries' ),
			'referrers'    => array( 'sessions', 'views', 'referrers', 'referrer_types' ),
			'ai_referrals' => array( 'sessions', 'views', 'referrers', 'referrer_types' ),
		);

		$head = array(
			'from' => $this->from->format( 'Y-m-d' ),
			'to'   => $this->to->format( 'Y-m-d' ),
		);

		try {
			$refusal = $this->refusal( $tables[ $name ] );

			if ( null === $refusal ) {
				$body = $this->{ 'read_' . $name }();
				$body = array_merge(
					array(
						'available' => true,
						'source'    => $this->source( $tables[ $name ] ),
					),
					$head,
					$body
				);
				return $body;
			}
		} catch ( \Throwable $e ) {
			$refusal = 'Reading Independent Analytics tables failed: ' . $e->getMessage() . '.';
		}

		return array_merge(
			array(
				'available' => false,
				'reason'    => $refusal . ' Nothing is reported for this block rather than reporting zero.',
			),
			$head
		);
	}

	/**
	 * Why this block cannot be read, or null when it can.
	 *
	 * @param string[] $tables Short names of the IA tables the block needs.
	 * @return string|null
	 */
	protected function refusal( array $tables ) {
		$db_version = (string) get_option( 'iawp_db_version', '' );

		if ( ! in_array( $db_version, self::DB_VERSIONS, true ) ) {
			return sprintf(
				'Independent Analytics database version is %s, and this collector was written against %s. Its tables are not read until the new version has been checked.',
				'' === $db_version ? 'not set' : $db_version,
				implode( ', ', self::DB_VERSIONS )
			);
		}

		if ( '1' === (string) get_option( 'iawp_is_migrating', '0' ) ) {
			return 'Independent Analytics is part way through a database migration, so its tables are not in a known shape.';
		}

		$pruning = (string) get_option( 'iawp_pruning_cutoff', 'disabled' );

		if ( ! in_array( $pruning, self::SAFE_PRUNING, true ) ) {
			return sprintf(
				'Independent Analytics is set to delete data older than its "%s" setting, so the twelve-month window cannot be read whole.',
				$pruning
			);
		}

		global $wpdb;

		foreach ( $tables as $short ) {
			$table  = $this->table( $short );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $exists !== $table ) {
				return 'The Independent Analytics table ' . $table . ' does not exist.';
			}
		}

		return null;
	}

	/**
	 * Most read pages: published pages, posts and projects, by views.
	 *
	 * Transcribes IAWP\Rows\Pages, which groups by views.resource_id and counts
	 * COUNT(DISTINCT views.id) views, distinct visitors and distinct sessions, on
	 * the same sessions-and-views spine as every other report. Limited to
	 * resources IA files as 'singular' (Resource_Identifier: is_singular()), which
	 * takes in a static front page and leaves out the posts page ('home'),
	 * archives, author pages, search and 404s.
	 *
	 * IA's resources table still holds posts that were deleted or unpublished,
	 * with their cached title (the red rows on its Pages screen). So whether a
	 * post counts is asked of WordPress now, not of IA's cache: one get_posts()
	 * call for every candidate id, published and of a covered type, and the
	 * title, type and permalink come from WordPress too.
	 *
	 * Ranked by views, then visitors, then title. The query cannot LIMIT, because
	 * the WordPress check comes after it; it returns one row per viewed singular
	 * resource in the window.
	 *
	 * @return array
	 */
	protected function read_top_pages() {
		$types = array_values( array_filter( self::TOP_PAGE_TYPES, 'post_type_exists' ) );

		$rows = $this->rows(
			'SELECT r.singular_id AS id, COUNT(DISTINCT v.id) AS views, COUNT(DISTINCT s.visitor_id) AS visitors, COUNT(DISTINCT s.session_id) AS sessions'
			. $this->base_from()
			. ' INNER JOIN %i AS r ON r.id = v.resource_id'
			. $this->base_where()
			. ' AND r.resource = %s AND r.singular_id IS NOT NULL'
			. ' GROUP BY r.id, r.singular_id',
			array( $this->table( 'resources' ) ),
			array( 'singular' )
		);

		$ids  = array_values( array_unique( array_map( fn( $row ) => (int) $row['id'], $rows ) ) );
		$live = array();

		if ( $ids && $types ) {
			$live = get_posts(
				array(
					'post__in'         => $ids,
					'post_type'        => $types,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => 'none',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
		}

		$live = array_flip( array_map( 'intval', (array) $live ) );
		$out  = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			if ( ! isset( $live[ $id ] ) ) {
				continue;
			}

			$out[] = array(
				'id'       => $id,
				'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'type'     => (string) get_post_type( $id ),
				'url'      => (string) get_permalink( $id ),
				'views'    => (int) $row['views'],
				'visitors' => (int) $row['visitors'],
				'sessions' => (int) $row['sessions'],
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return array( $b['views'], $b['visitors'], strtolower( $a['title'] ) ) <=> array( $a['views'], $a['visitors'], strtolower( $b['title'] ) );
			}
		);

		return array(
			'post_types' => $types,
			'rows'       => array_slice( $out, 0, self::TOP_PAGE_ROWS ),
		);
	}

	/**
	 * Visitors by device type.
	 *
	 * Transcribes IAWP\Rows\Device_Types: sessions joined to views, both bounded by
	 * the window, sessions with no device type left out, COUNT(DISTINCT visitor_id)
	 * per sessions.device_type_id.
	 *
	 * @return array
	 */
	protected function read_devices() {
		$rows = $this->rows(
			'SELECT dt.device_type AS type, COUNT(DISTINCT s.visitor_id) AS visitors'
			. $this->base_from()
			. ' LEFT JOIN %i AS dt ON dt.device_type_id = s.device_type_id'
			. $this->base_where()
			. ' AND s.device_type_id IS NOT NULL'
			. ' GROUP BY s.device_type_id, dt.device_type'
			. ' ORDER BY visitors DESC, dt.device_type ASC',
			array( $this->table( 'device_types' ) )
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'type'     => null === $row['type'] ? null : strtolower( (string) $row['type'] ),
				'visitors' => (int) $row['visitors'],
			);
		}

		return array(
			'total_visitors'            => $this->totals()['all'],
			'visitors_with_device_type' => $this->totals()['device_type'],
			'rows'                      => $out,
		);
	}

	/**
	 * Top 25 cities by visitors, ties broken by city name.
	 *
	 * 25, not the card's 5, from 0.1.8: the template filters data-center towns
	 * (Ashburn, Boardman, Council Bluffs and the like) out of the card, and may
	 * filter by country, so it needs rows to spare. Jason, November 25, 2026.
	 *
	 * Transcribes IAWP\Rows\Cities. total_visitors counts everyone in the window,
	 * including visitors IA could not place, so a percentage off it is honest.
	 * visitors_with_city is the Visitors figure IA's own Cities screen shows,
	 * because IAWP\Statistics\City_Statistics requires sessions.city_id.
	 *
	 * @return array
	 */
	protected function read_cities() {
		$rows = $this->rows(
			'SELECT c.city AS city, c.subdivision AS region, co.country_code AS country_code, COUNT(DISTINCT s.visitor_id) AS visitors'
			. $this->base_from()
			. ' LEFT JOIN %i AS c ON c.city_id = s.city_id'
			. ' LEFT JOIN %i AS co ON co.country_id = c.country_id'
			. $this->base_where()
			. ' AND s.city_id IS NOT NULL'
			. ' GROUP BY s.city_id, c.city, c.subdivision, co.country_code'
			. ' ORDER BY visitors DESC, c.city ASC'
			. ' LIMIT 25',
			array( $this->table( 'cities' ), $this->table( 'countries' ) )
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'city'         => null === $row['city'] ? null : (string) $row['city'],
				'region'       => null === $row['region'] ? null : (string) $row['region'],
				'country_code' => null === $row['country_code'] ? null : (string) $row['country_code'],
				'visitors'     => (int) $row['visitors'],
			);
		}

		return array(
			'total_visitors'     => $this->totals()['all'],
			'visitors_with_city' => $this->totals()['city'],
			'rows'               => $out,
		);
	}

	/**
	 * Top six referrers by visitors, named as IA names them.
	 *
	 * Transcribes IAWP\Rows\Referrers. IA folds every domain of a known referrer
	 * into one referrers row at tracking time, so Google is one row and direct
	 * traffic is the row IA names Direct.
	 *
	 * @return array
	 */
	protected function read_referrers() {
		$rows = $this->rows(
			'SELECT r.referrer AS name, rt.referrer_type AS type, COUNT(DISTINCT s.visitor_id) AS visitors'
			. $this->base_from()
			. ' LEFT JOIN %i AS r ON r.id = s.referrer_id'
			. ' LEFT JOIN %i AS rt ON rt.id = r.referrer_type_id'
			. $this->base_where()
			. ' AND s.referrer_id IS NOT NULL'
			. ' GROUP BY s.referrer_id, r.referrer, rt.referrer_type'
			. ' ORDER BY visitors DESC, r.referrer ASC'
			. ' LIMIT 6',
			array( $this->table( 'referrers' ), $this->table( 'referrer_types' ) )
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'name'     => null === $row['name'] ? null : (string) $row['name'],
				'type'     => null === $row['type'] ? null : (string) $row['type'],
				'visitors' => (int) $row['visitors'],
			);
		}

		return array(
			'total_visitors'         => $this->totals()['all'],
			'visitors_with_referrer' => $this->totals()['referrer'],
			'rows'                   => $out,
		);
	}

	/**
	 * Visitors IA attributes to AI platforms.
	 *
	 * The AI row of IA's own Referrer Types report, IAWP\Rows\Referrer_Types: the
	 * classification is IA's referrer_types table, set from its Known_Referrers
	 * list when a session is tracked. No list of AI domains is kept here.
	 *
	 * A zero here is measured. The tables read cleanly and IA filed nobody as AI.
	 *
	 * @return array
	 */
	protected function read_ai_referrals() {
		$value = $this->scalar(
			'SELECT COUNT(DISTINCT s.visitor_id)'
			. $this->base_from()
			. ' INNER JOIN %i AS r ON r.id = s.referrer_id'
			. ' INNER JOIN %i AS rt ON rt.id = r.referrer_type_id'
			. $this->base_where()
			. ' AND rt.referrer_type = %s',
			array( $this->table( 'referrers' ), $this->table( 'referrer_types' ) ),
			array( self::AI_TYPE )
		);

		return array(
			'value' => $value,
			'note'  => 'Independent Analytics 2.16.4 (November 23, 2026) says ChatGPT traffic is being tracked again. It does not say since when it was missed, so ChatGPT may be undercounted for part of this window.',
		);
	}

	/**
	 * Distinct visitors in the window, overall and for each block's own filter.
	 *
	 * 'all' is the Visitors figure on IA's Overview, IAWP\Statistics\Statistics with
	 * no required column. The others add the required column of each report's own
	 * Statistics subclass, which is what that report's Visitors figure shows.
	 *
	 * @return array{all:int,device_type:int,city:int,referrer:int}
	 */
	protected function totals() {
		if ( false !== $this->totals ) {
			return $this->totals;
		}

		$this->totals = array(
			'all'         => $this->scalar( 'SELECT COUNT(DISTINCT s.visitor_id)' . $this->base_from() . $this->base_where() ),
			'device_type' => $this->scalar( 'SELECT COUNT(DISTINCT s.visitor_id)' . $this->base_from() . $this->base_where() . ' AND s.device_type_id IS NOT NULL' ),
			'city'        => $this->scalar( 'SELECT COUNT(DISTINCT s.visitor_id)' . $this->base_from() . $this->base_where() . ' AND s.city_id IS NOT NULL' ),
			'referrer'    => $this->scalar( 'SELECT COUNT(DISTINCT s.visitor_id)' . $this->base_from() . $this->base_where() . ' AND s.referrer_id IS NOT NULL' ),
		);

		return $this->totals;
	}

	/**
	 * Sessions joined to their views, the spine of every IA report query.
	 *
	 * Takes two %i placeholders, sessions then views.
	 *
	 * @return string
	 */
	protected function base_from() {
		return ' FROM %i AS s INNER JOIN %i AS v ON v.session_id = s.session_id';
	}

	/**
	 * IA bounds both the session start and the view time by the window, on every
	 * report screen for any range of an hour or more. Takes four %s placeholders.
	 *
	 * @return string
	 */
	protected function base_where() {
		return ' WHERE s.created_at BETWEEN %s AND %s AND v.viewed_at BETWEEN %s AND %s';
	}

	/**
	 * Run a grouped query and hand back rows, or throw.
	 *
	 * Placeholders go in the order they appear: sessions, views, the block's own
	 * tables, the four window bounds, then any trailing values.
	 *
	 * @param string $sql    SQL with placeholders.
	 * @param array  $tables The block's own table names, after sessions and views.
	 * @param array  $tail   Values after the window bounds.
	 * @return array[]
	 * @throws RuntimeException When the query fails.
	 */
	protected function rows( $sql, array $tables = array(), array $tail = array() ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$this->args( $tables, $tail ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared here, every value a placeholder.

		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'query failed: ' . ( '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'no result' ) );
		}

		return $rows;
	}

	/**
	 * Run a COUNT query and hand back an int, or throw.
	 *
	 * @param string $sql    SQL with placeholders.
	 * @param array  $tables The block's own table names, after sessions and views.
	 * @param array  $tail   Values after the window bounds.
	 * @return int
	 * @throws RuntimeException When the query fails or returns something that is not a count.
	 */
	protected function scalar( $sql, array $tables = array(), array $tail = array() ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( $sql, ...$this->args( $tables, $tail ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared here, every value a placeholder.

		if ( '' !== (string) $wpdb->last_error || null === $value || ! is_numeric( $value ) ) {
			throw new RuntimeException( 'query failed: ' . ( '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'no count returned' ) );
		}

		return (int) $value;
	}

	/**
	 * Placeholder values in query order.
	 *
	 * @param array $tables Block tables.
	 * @param array $tail   Trailing values.
	 * @return array
	 */
	protected function args( array $tables, array $tail ) {
		return array_merge(
			array( $this->table( 'sessions' ), $this->table( 'views' ) ),
			$tables,
			array( $this->range[0], $this->range[1], $this->range[0], $this->range[1] ),
			$tail
		);
	}

	/**
	 * Full table name, the way IAWP\Tables builds it.
	 *
	 * @param string $short Short name.
	 * @return string
	 */
	protected function table( $short ) {
		global $wpdb;
		return $wpdb->prefix . 'independent_analytics_' . $short;
	}

	/**
	 * Source line for a block.
	 *
	 * @param string[] $tables Short table names.
	 * @return string
	 */
	protected function source( array $tables ) {
		return sprintf(
			'Independent Analytics %s tables, read only (%s), database version %s, counted the way its own report screens count',
			defined( 'IAWP_VERSION' ) ? IAWP_VERSION : '(version unknown)',
			implode( ', ', $tables ),
			(string) get_option( 'iawp_db_version', '' )
		);
	}
}
