<?php
/**
 * Gravity Forms adapter.
 *
 * Form submissions are the number a client actually values: every one of them is
 * a person who found the site and got in touch. Biscuit has had this data on
 * every build for years and has never once shown it to anyone.
 *
 * Counts come from GFAPI, which is Gravity Forms' supported public interface, not
 * from their tables.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts form submissions, where Gravity Forms is present.
 */
class Bsrep_Forms {

	/**
	 * Is Gravity Forms active and exposing GFAPI?
	 *
	 * @return bool
	 */
	public function available() {
		return class_exists( 'GFAPI' );
	}

	/**
	 * Collect the forms section.
	 *
	 * @return array
	 */
	public function collect() {
		if ( ! $this->available() ) {
			return array(
				'available' => false,
				'reason'    => 'Gravity Forms is not active on this site.',
			);
		}

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );

		$this_year_from  = $now->modify( '-12 months' );
		$last_year_from  = $now->modify( '-24 months' );
		$last_year_to    = $this_year_from->modify( '-1 second' );

		$forms = GFAPI::get_forms( true );
		$rows  = array();

		$total_this = 0;
		$total_last = 0;

		foreach ( (array) $forms as $form ) {
			$form_id = (int) ( $form['id'] ?? 0 );

			if ( $form_id <= 0 ) {
				continue;
			}

			$this_year = $this->count( $form_id, $this_year_from, $now );
			$last_year = $this->count( $form_id, $last_year_from, $last_year_to );

			$total_this += $this_year;
			$total_last += $last_year;

			$rows[] = array(
				'id'             => $form_id,
				'title'          => (string) ( $form['title'] ?? '' ),
				'last_12_months' => $this_year,
				'prior_12_months' => $last_year,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['last_12_months'] <=> $a['last_12_months'];
			}
		);

		return array(
			'available'       => true,
			'source'          => 'Gravity Forms GFAPI::count_entries()',
			'forms'           => $rows,
			'total_last_12_months'  => $total_this,
			'total_prior_12_months' => $total_last,
			'note'            => 'Active forms only. Entries deleted from the site are not counted, because they are not there to count.',
		);
	}

	/**
	 * Entry count for one form between two dates.
	 *
	 * Gravity Forms stores entry dates in UTC and its search criteria expect UTC,
	 * so the site timezone is converted rather than passed through.
	 *
	 * @param int               $form_id Form id.
	 * @param DateTimeInterface $from    Start.
	 * @param DateTimeInterface $to      End.
	 * @return int
	 */
	protected function count( $form_id, DateTimeInterface $from, DateTimeInterface $to ) {
		$utc = new DateTimeZone( 'UTC' );

		$criteria = array(
			'status'     => 'active',
			'start_date' => ( new DateTimeImmutable( $from->format( 'Y-m-d H:i:s' ), $from->getTimezone() ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_date'   => ( new DateTimeImmutable( $to->format( 'Y-m-d H:i:s' ), $to->getTimezone() ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);

		$count = GFAPI::count_entries( $form_id, $criteria );

		return is_wp_error( $count ) ? 0 : (int) $count;
	}
}
