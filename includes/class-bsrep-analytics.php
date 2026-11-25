<?php
/**
 * Independent Analytics adapter.
 *
 * The monthly series and the two headline windows come from IA's documented
 * PHP function iawp_analytics().
 *
 * Devices, cities, referrers and AI referrals are not in that API. From 0.1.7
 * they are read off Independent Analytics' own tables, read only, by
 * Bsrep_Analytics_Tables. Jason changed the rule on November 25, 2026; until
 * then the tables were off limits and those blocks reported unavailable.
 * top_pages joined them in 0.1.9, replacing iawp_top_posts().
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads people-level analytics, where the plugin is present.
 */
class Bsrep_Analytics {

	/**
	 * Is Independent Analytics active and exposing its API?
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'iawp_analytics' );
	}

	/**
	 * Collect the analytics section.
	 *
	 * @param int $months How many whole months back to report.
	 * @return array
	 */
	public function collect( $months = 12 ) {
		if ( ! $this->available() ) {
			return array(
				'available' => false,
				'reason'    => 'Independent Analytics is not active on this site, so there is no measurement of people as distinct from requests.',
			);
		}

		// Everything below this point calls into another vendor's code. Independent
		// Analytics bundles its own Laravel database layer and opens its own PDO
		// connection rather than going through $wpdb, so it can throw where $wpdb
		// would not. A throw here used to take down Bsrep_Collector::build() and with
		// it the payload, the download button and the scheduled push, on a site where
		// every other section was fine. Seen on Biscuit Dev, September 22, 2026:
		// PDOException SQLSTATE[HY000] [2002] under WP-CLI.
		//
		// A third party's failure is an unmeasured section, not a dead payload, so it
		// degrades to available:false with the reason attached. Never to zeros.
		try {
			return $this->collect_from_vendor( $months );
		} catch ( \Throwable $e ) {
			return array(
				'available' => false,
				'reason'    => 'Independent Analytics is active but its API failed: '
					. get_class( $e ) . ': ' . $e->getMessage()
					. '. Nothing is reported for this section rather than reporting zero.',
			);
		}
	}

	/**
	 * The body of collect(), separated so one try/catch covers every vendor call.
	 *
	 *  int $months How many whole months back to report.
	 *  array
	 *  RuntimeException When the vendor returns a shape this cannot read.
	 */
	protected function collect_from_vendor( $months ) {
		$timezone = wp_timezone();
		$now      = $this->now( $timezone );

		$series  = array();
		$totals  = array(
			'views'    => 0,
			'visitors' => 0,
			'sessions' => 0,
		);

		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$start = $now->modify( "first day of -{$i} month" )->setTime( 0, 0, 0 );
			$end   = $start->modify( 'last day of this month' )->setTime( 23, 59, 59 );

			if ( $end > $now ) {
				$end = $now;
			}

			$month = $this->window( $start, $end );

			$series[] = array(
				'month'    => $start->format( 'Y-m' ),
				'views'    => $month['views'],
				'visitors' => $month['visitors'],
				'sessions' => $month['sessions'],
			);

			$totals['views']    += $month['views'];
			$totals['visitors'] += $month['visitors'];
			$totals['sessions'] += $month['sessions'];
		}

		// The window every 0.1.7 block reports on: the first of the month the
		// monthly series starts on, through today. Same window as `monthly`.
		$window_from = $now->modify( 'first day of -' . ( $months - 1 ) . ' month' )->setTime( 0, 0, 0 );
		$window_to   = $now;

		// From 0.1.8 last_12_months is that same window, and prior_12_months is the
		// matching window a year earlier. Up to 0.1.7 both were rolling (now minus
		// 12 months, and the 12 before), six days off the monthly series on
		// November 25, 2026. Jason, November 25, 2026: "Yes line them up."
		//
		// The prior end is today's date a year back, but never on or after the
		// current window's first day. On February 29 a year back lands on March 1,
		// which can be that first day, and the two windows would share it.
		$prior_from = $window_from->modify( '-1 year' );
		$prior_to   = $now->modify( '-1 year' );
		$last_day   = $window_from->modify( '-1 day' );

		if ( $prior_to->format( 'Y-m-d' ) > $last_day->format( 'Y-m-d' ) ) {
			$prior_to = $last_day;
		}

		return array(
			'available'      => true,
			'source'         => 'Independent Analytics public PHP API, iawp_analytics(), for monthly, last_12_months and prior_12_months. The other blocks carry their own source.',
			'timezone'       => $timezone->getName(),
			'monthly'        => $series,
			'last_12_months' => $this->dated_window( $window_from, $window_to ),
			'prior_12_months' => $this->dated_window( $prior_from, $prior_to ),
			'totals_of_series' => $totals,
		) + $this->tables( $window_from, $window_to ) + array(
			'countries'      => array(
				'available' => false,
				'reason'    => 'Not in the public PHP API. Use the Kinsta top-countries endpoint instead, which is tested and works.',
			),
		);
	}

	/**
	 * The current time in the site's timezone. A method so tests can fix it.
	 *
	 * @param DateTimeZone $timezone Site timezone.
	 * @return DateTimeImmutable
	 */
	protected function now( DateTimeZone $timezone ) {
		return new DateTimeImmutable( 'now', $timezone );
	}

	/**
	 * window(), with the days it covers stated as from and to.
	 *
	 * iawp_analytics() widens both ends to whole days in the site's timezone
	 * (Exact_Date_Range), so these dates are the dates IA counted.
	 *
	 * @param DateTimeInterface $from First day.
	 * @param DateTimeInterface $to   Last day.
	 * @return array{from:string,to:string,views:int,visitors:int,sessions:int}
	 */
	protected function dated_window( DateTimeInterface $from, DateTimeInterface $to ) {
		return array(
			'from' => $from->format( 'Y-m-d' ),
			'to'   => $to->format( 'Y-m-d' ),
		) + $this->window( $from, $to );
	}

	/**
	 * Views, visitors and sessions for one window.
	 *
	 * @param DateTimeInterface $from Start.
	 * @param DateTimeInterface $to   End.
	 * @return array{views:int,visitors:int,sessions:int}
	 */
	protected function window( DateTimeInterface $from, DateTimeInterface $to ) {
		// No $empty fallback here on purpose. An all-zero month is indistinguishable
		// from a real quiet month once it reaches the page, and only one of the two is
		// true. Anything unreadable throws and the whole section reports unavailable.
		if ( ! function_exists( 'iawp_analytics' ) ) {
			throw new RuntimeException( 'iawp_analytics() is not defined.' );
		}

		$result = iawp_analytics( $this->as_datetime( $from ), $this->as_datetime( $to ) );

		return $this->normalise( $result );
	}

	/**
	 * Top pages, devices, cities, referrers and AI referrals off IA's own tables.
	 *
	 * top_pages moved here in 0.1.9. iawp_top_posts() returns one post type per
	 * call and only singular items, sorted by views with no tie-break, and the
	 * card needs pages, posts and projects together with deleted and unpublished
	 * posts left out.
	 *
	 * Bsrep_Analytics_Tables guards and degrades each block on its own, so one bad
	 * table costs one card, not the section.
	 *
	 * @param DateTimeInterface $from First day.
	 * @param DateTimeInterface $to   Last day.
	 * @return array
	 */
	protected function tables( DateTimeInterface $from, DateTimeInterface $to ) {
		// Contained here too, so a failure building the reader costs these four
		// blocks and never the views and visitors that came from the PHP API.
		try {
			return ( new Bsrep_Analytics_Tables( $from, $to ) )->collect();
		} catch ( \Throwable $e ) {
			$down = array(
				'available' => false,
				'reason'    => 'Reading Independent Analytics tables failed: ' . get_class( $e ) . ': ' . $e->getMessage()
					. '. Nothing is reported for this block rather than reporting zero.',
				'from'      => $from->format( 'Y-m-d' ),
				'to'        => $to->format( 'Y-m-d' ),
			);
			return array(
				'top_pages'    => $down,
				'devices'      => $down,
				'cities'       => $down,
				'referrers'    => $down,
				'ai_referrals' => $down,
			);
		}
	}

	/**
	 * The vendor signature takes DateTime, so hand it one.
	 *
	 * @param DateTimeInterface $date Date.
	 * @return DateTime
	 */
	protected function as_datetime( DateTimeInterface $date ) {
		return new DateTime( $date->format( 'Y-m-d H:i:s' ), $date->getTimezone() );
	}

	/**
	 * Read views, visitors and sessions off whatever shape the vendor returned.
	 *
	 * Throws rather than falling back to zeros. If Independent Analytics changes
	 * its return shape, that is a schema change this cannot silently absorb, and
	 * absorbing it would print "0 visitors" on a client's page as though measured.
	 *
	 * @param mixed $result Vendor return value.
	 * @return array{views:int,visitors:int,sessions:int}
	 * @throws RuntimeException When the shape is unreadable.
	 */
	protected function normalise( $result ) {
		if ( is_object( $result ) ) {
			$result = get_object_vars( $result );
		}

		if ( ! is_array( $result ) ) {
			throw new RuntimeException(
				'iawp_analytics() returned ' . gettype( $result ) . ', which this cannot read.'
			);
		}

		return array(
			'views'    => (int) ( $result['views'] ?? 0 ),
			'visitors' => (int) ( $result['visitors'] ?? 0 ),
			'sessions' => (int) ( $result['sessions'] ?? 0 ),
		);
	}
}
