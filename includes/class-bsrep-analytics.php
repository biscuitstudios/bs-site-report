<?php
/**
 * Independent Analytics adapter.
 *
 * Uses only the three documented PHP functions the plugin exposes. It does not
 * read Independent Analytics' own database tables, which would break silently
 * the first time they change their schema, and which cuts against "Never query
 * the database directly" in THEME-RULES.md.
 *
 * The consequence is that everything not covered by those three functions is
 * reported as unavailable with a reason, rather than guessed at. The two that
 * matter, AI referral traffic and traffic sources, are the reason to ask the
 * vendor for a supported route.
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

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );

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

		$year_start  = $now->modify( '-12 months' );
		$prior_start = $now->modify( '-24 months' );
		$prior_end   = $year_start->modify( '-1 day' );

		return array(
			'available'      => true,
			'source'         => 'Independent Analytics public PHP API, iawp_analytics() and iawp_top_posts()',
			'timezone'       => $timezone->getName(),
			'monthly'        => $series,
			'last_12_months' => $this->window( $year_start, $now ),
			'prior_12_months' => $this->window( $prior_start, $prior_end ),
			'totals_of_series' => $totals,
			'top_pages'      => $this->top_pages(),
			'ai_referrals'   => array(
				'available' => false,
				'reason'    => 'Independent Analytics attributes referrals to AI platforms in its own dashboard but does not expose referrers through iawp_analytics(), iawp_singular_analytics() or iawp_top_posts(). Reading their tables directly was rejected. Open with the vendor.',
			),
			'referrers'      => array(
				'available' => false,
				'reason'    => 'Same as ai_referrals. No supported route.',
			),
			'countries'      => array(
				'available' => false,
				'reason'    => 'Not in the public PHP API. Use the Kinsta top-countries endpoint instead, which is tested and works.',
			),
		);
	}

	/**
	 * Views, visitors and sessions for one window.
	 *
	 * @param DateTimeInterface $from Start.
	 * @param DateTimeInterface $to   End.
	 * @return array{views:int,visitors:int,sessions:int}
	 */
	protected function window( DateTimeInterface $from, DateTimeInterface $to ) {
		$empty = array(
			'views'    => 0,
			'visitors' => 0,
			'sessions' => 0,
		);

		if ( ! function_exists( 'iawp_analytics' ) ) {
			return $empty;
		}

		$result = iawp_analytics( $this->as_datetime( $from ), $this->as_datetime( $to ) );

		return $this->normalise( $result, $empty );
	}

	/**
	 * Top pages by views over the last twelve months.
	 *
	 * @return array
	 */
	protected function top_pages() {
		if ( ! function_exists( 'iawp_top_posts' ) ) {
			return array(
				'available' => false,
				'reason'    => 'iawp_top_posts() is not defined.',
			);
		}

		$timezone = wp_timezone();
		$to       = new DateTimeImmutable( 'now', $timezone );
		$from     = $to->modify( '-12 months' );

		$rows = iawp_top_posts(
			array(
				'limit' => 10,
				'from'  => $this->as_datetime( $from ),
				'to'    => $this->as_datetime( $to ),
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : (array) $row;

			$out[] = array(
				'id'       => (int) ( $row['id'] ?? 0 ),
				'title'    => (string) ( $row['title'] ?? '' ),
				'views'    => (int) ( $row['views'] ?? 0 ),
				'visitors' => (int) ( $row['visitors'] ?? 0 ),
				'sessions' => (int) ( $row['sessions'] ?? 0 ),
			);
		}

		return array(
			'available' => true,
			'rows'      => $out,
		);
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
	 * @param mixed $result   Vendor return value.
	 * @param array $fallback Value to use when nothing is readable.
	 * @return array{views:int,visitors:int,sessions:int}
	 */
	protected function normalise( $result, array $fallback ) {
		if ( is_object( $result ) ) {
			$result = get_object_vars( $result );
		}

		if ( ! is_array( $result ) ) {
			return $fallback;
		}

		return array(
			'views'    => (int) ( $result['views'] ?? 0 ),
			'visitors' => (int) ( $result['visitors'] ?? 0 ),
			'sessions' => (int) ( $result['sessions'] ?? 0 ),
		);
	}
}
