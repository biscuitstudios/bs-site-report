<?php
/**
 * Assembles the payload.
 *
 * One rule runs through every section: a number that was not measured is null
 * with a reason beside it, never a zero and never a plausible figure. A zero and
 * an unknown look identical on a page and only one of them is true. The report
 * page is read by a client, so a guess here is a rule 8 failure with a Biscuit
 * logo on it.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the site report payload.
 */
class Bsrep_Collector {

	/**
	 * Payload schema version. Bump when a consumer would have to change.
	 */
	const PAYLOAD_VERSION = 1;

	/**
	 * Build the whole payload.
	 *
	 * @return array
	 */
	public function build() {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );

		return array(
			'payload_version' => self::PAYLOAD_VERSION,
			'generated_at'    => gmdate( 'c' ),
			'site'            => $this->site(),
			'log_coverage'    => $this->log_coverage( $now ),
			'maintenance'     => $this->maintenance( $now ),
			'inventory'       => $this->inventory(),
			'analytics'       => ( new Bsrep_Analytics() )->collect(),
			'forms'           => ( new Bsrep_Forms() )->collect(),
			'security'        => ( new Bsrep_Security() )->collect(),
			'accessibility'   => ( new Bsrep_Accessibility() )->collect(),
			'structure'       => $this->structure(),
		);
	}

	/**
	 * Site identity.
	 *
	 * @return array
	 */
	protected function site() {
		return array(
			'name'      => get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'timezone'  => wp_timezone()->getName(),
			'language'  => get_bloginfo( 'language' ),
			'multisite' => is_multisite(),
			'collector_version' => BSREP_VERSION,
		);
	}

	/**
	 * How far back the event log can honestly speak.
	 *
	 * This is the guard on the headline number. A twelve month count read off a
	 * six week old log is a fabrication, so the payload carries the window and the
	 * report page has to use it.
	 *
	 * @param DateTimeImmutable $now Now, site timezone.
	 * @return array
	 */
	protected function log_coverage( DateTimeImmutable $now ) {
		$first = Bsrep_Log::first_event_date();
		$since = get_option( 'bsrep_installed_at' );

		if ( ! $first ) {
			return array(
				'has_history'     => false,
				'first_event'     => null,
				'collector_since' => $since ? (string) $since : null,
				'days_covered'    => 0,
				'covers_full_year' => false,
				'reason'          => 'Nothing has been logged yet. The collector records updates as they happen and cannot recover history from before it was installed. Say what the site is, not what was done to it, until this fills.',
			);
		}

		$start = new DateTimeImmutable( $first, new DateTimeZone( 'UTC' ) );
		$days  = (int) $start->diff( $now )->days;

		return array(
			'has_history'      => true,
			'first_event'      => $first,
			'collector_since'  => $since ? (string) $since : null,
			'days_covered'     => $days,
			'covers_full_year' => $days >= 365,
			'total_events'     => Bsrep_Log::total(),
			'note'             => $days >= 365
				? 'The log covers a full twelve months.'
				: 'The log is shorter than twelve months. Any figure presented to a client must be labelled with the actual window, not called a year.',
		);
	}

	/**
	 * The work Biscuit did, counted.
	 *
	 * @param DateTimeImmutable $now Now, site timezone.
	 * @return array
	 */
	protected function maintenance( DateTimeImmutable $now ) {
		$utc  = new DateTimeZone( 'UTC' );
		$to   = $now->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$from = $now->setTimezone( $utc )->modify( '-12 months' )->format( 'Y-m-d H:i:s' );

		$year     = Bsrep_Log::counts_between( $from, $to );
		$all_time = Bsrep_Log::counts_between( '1970-01-01 00:00:00', $to );

		$updates_year = ( $year['plugin_update'] ?? 0 ) + ( $year['theme_update'] ?? 0 ) + ( $year['core_update'] ?? 0 );
		$updates_all  = ( $all_time['plugin_update'] ?? 0 ) + ( $all_time['theme_update'] ?? 0 ) + ( $all_time['core_update'] ?? 0 );

		return array(
			'window_from'          => $from,
			'window_to'            => $to,
			'updates_last_12_months' => $updates_year,
			'updates_all_time'     => $updates_all,
			'by_type_last_12_months' => $year,
			'by_type_all_time'     => $all_time,
			'monthly_plugin_updates' => Bsrep_Log::monthly( 'plugin_update', $from, $to ),
			'security_releases'    => array(
				'available' => false,
				'value'     => null,
				'reason'    => 'Whether a given release closed a published security flaw is not in any WordPress or Kinsta source. It needs a vulnerability feed matched to the slug and version in this log. Until that exists the page must not claim a count.',
			),
			'backups'              => array(
				'available' => false,
				'value'     => null,
				'reason'    => 'Backups are taken by the host, not by WordPress. Take this from the Kinsta backups endpoint and merge it in on the report page.',
			),
		);
	}

	/**
	 * What is installed right now.
	 *
	 * @return array
	 */
	protected function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = 0;

		foreach ( array_keys( $all ) as $file ) {
			if ( is_plugin_active( $file ) ) {
				++$active;
			}
		}

		$updates = get_site_transient( 'update_plugins' );
		$pending = is_object( $updates ) && ! empty( $updates->response ) ? count( (array) $updates->response ) : 0;

		$theme_updates = get_site_transient( 'update_themes' );
		$theme_pending = is_object( $theme_updates ) && ! empty( $theme_updates->response ) ? count( (array) $theme_updates->response ) : 0;

		$theme = wp_get_theme();

		return array(
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'plugins_installed'   => count( $all ),
			'plugins_active'      => $active,
			'plugins_inactive'    => count( $all ) - $active,
			'plugin_updates_pending' => $pending,
			'theme_updates_pending'  => $theme_pending,
			'theme'               => array(
				'name'      => $theme->get( 'Name' ),
				'version'   => $theme->get( 'Version' ),
				'is_child'  => (bool) $theme->parent(),
				'parent'    => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			),
			'update_check_age'    => $this->update_check_age( $updates ),
		);
	}

	/**
	 * How stale the pending-update count is.
	 *
	 * A pending count read off a transient that last refreshed a week ago is a
	 * week-old claim. The page should say when it was checked.
	 *
	 * @param mixed $updates The update_plugins transient.
	 * @return array
	 */
	protected function update_check_age( $updates ) {
		$checked = is_object( $updates ) && ! empty( $updates->last_checked ) ? (int) $updates->last_checked : 0;

		if ( ! $checked ) {
			return array(
				'available' => false,
				'reason'    => 'WordPress has not recorded an update check on this site.',
			);
		}

		return array(
			'available'    => true,
			'last_checked' => gmdate( 'c', $checked ),
			'hours_ago'    => (int) floor( ( time() - $checked ) / HOUR_IN_SECONDS ),
		);
	}

	/**
	 * The last finished structure scan.
	 *
	 * @return array
	 */
	protected function structure() {
		$result = ( new Bsrep_Scanner() )->result();

		if ( null === $result ) {
			return array(
				'available' => false,
				'reason'    => 'No structure scan has finished on this site yet. Run one before the page claims anything about headings, alt text or structured data.',
			);
		}

		$result['available'] = true;
		$result['source']    = 'Biscuit Site Report structure scan. Fetches this site\'s own published URLs and parses the delivered HTML.';

		return $result;
	}
}
