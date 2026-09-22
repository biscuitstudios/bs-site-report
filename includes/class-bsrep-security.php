<?php
/**
 * Security facts that only exist inside WordPress.
 *
 * Who can log in, how many of them are administrators, and whether two-factor is
 * actually switched on for anybody. Kinsta cannot answer any of this.
 *
 * Nothing here identifies a user. Counts only, no names, no email addresses, no
 * last-login times. The report page goes to a client over a link in an email and
 * a list of their own staff accounts has no business travelling that way.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects login and account posture.
 */
class Bsrep_Security {

	/**
	 * Two-factor plugins worth recognising, by plugin file.
	 *
	 * @var array
	 */
	protected $two_factor_plugins = array(
		'wp-2fa/wp-2fa.php'                              => 'WP 2FA',
		'two-factor/two-factor.php'                      => 'Two Factor',
		'wordfence-login-security/wordfence-login-security.php' => 'Wordfence Login Security',
		'miniorange-2-factor-authentication/miniorange_2_factor_settings.php' => 'miniOrange 2FA',
	);

	/**
	 * Collect the security section.
	 *
	 * @return array
	 */
	public function collect() {
		// is_plugin_active() lives in an admin file that a cron request does not
		// load on its own.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$counts = count_users();
		$roles  = (array) ( $counts['avail_roles'] ?? array() );

		return array(
			'total_users'          => (int) ( $counts['total_users'] ?? 0 ),
			'by_role'              => $this->clean_roles( $roles ),
			'administrators'       => (int) ( $roles['administrator'] ?? 0 ),
			'accounts_that_can_edit' => $this->editors( $roles ),
			'two_factor'           => $this->two_factor(),
			'failed_logins_30_days' => $this->failed_logins(),
			'file_editor_disabled' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'ssl'                  => is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
		);
	}

	/**
	 * Role counts with zero-count roles dropped.
	 *
	 * @param array $roles Role counts.
	 * @return array
	 */
	protected function clean_roles( array $roles ) {
		$out = array();

		foreach ( $roles as $role => $count ) {
			if ( (int) $count > 0 ) {
				$out[ (string) $role ] = (int) $count;
			}
		}

		return $out;
	}

	/**
	 * How many accounts hold a role that can edit content.
	 *
	 * Derived from the capability rather than from a hardcoded role list, because
	 * Biscuit sites carry custom roles and Admin Menu Editor Pro reshapes them.
	 *
	 * @param array $role_counts Role counts from count_users().
	 * @return int
	 */
	protected function editors( array $role_counts ) {
		$wp_roles = wp_roles();
		$total    = 0;

		foreach ( $role_counts as $role => $count ) {
			$role_object = $wp_roles->get_role( (string) $role );

			if ( ! $role_object ) {
				continue;
			}

			if ( ! empty( $role_object->capabilities['edit_posts'] ) ) {
				$total += (int) $count;
			}
		}

		return $total;
	}

	/**
	 * Two-factor state.
	 *
	 * Reports the plugin if one is active, and how many users have actually
	 * configured it where that is readable. "Installed" and "in use" are different
	 * claims and the page must not print the first as though it were the second.
	 *
	 * @return array
	 */
	protected function two_factor() {
		$active = array();

		foreach ( $this->two_factor_plugins as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$active[ $file ] = $label;
			}
		}

		if ( empty( $active ) ) {
			return array(
				'plugin'          => null,
				'enabled'         => false,
				'users_configured' => 0,
				'note'            => 'No two-factor plugin is active on this site.',
			);
		}

		$file  = array_key_first( $active );
		$label = $active[ $file ];

		$configured = null;
		$note       = 'Plugin is active. Number of users who have finished setting it up could not be read from this plugin.';

		if ( 'wp-2fa/wp-2fa.php' === $file ) {
			$configured = $this->count_users_with_meta( 'wp_2fa_enabled_methods' );
			$note       = 'Counted from the wp_2fa_enabled_methods user meta key that WP 2FA writes when a user completes setup.';
		}

		if ( 'two-factor/two-factor.php' === $file ) {
			$configured = $this->count_users_with_meta( '_two_factor_enabled_providers' );
			$note       = 'Counted from the _two_factor_enabled_providers user meta key.';
		}

		return array(
			'plugin'           => $label,
			'enabled'          => true,
			'users_configured' => $configured,
			'note'             => $note,
		);
	}

	/**
	 * How many users carry a non-empty value for one meta key.
	 *
	 * WP_User_Query rather than a direct query.
	 *
	 * @param string $meta_key Meta key.
	 * @return int
	 */
	protected function count_users_with_meta( $meta_key ) {
		$query = new WP_User_Query(
			array(
				'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 1,
				'count_total'  => true,
			)
		);

		return (int) $query->get_total();
	}

	/**
	 * Failed logins over the last 30 days, where something on the site records them.
	 *
	 * WordPress core does not. If no plugin that logs them is present, this returns
	 * null and the report page must print nothing rather than a zero. A zero here
	 * would read as "nobody tried", which is the opposite of the truth.
	 *
	 * @return array
	 */
	protected function failed_logins() {
		if ( is_plugin_active( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ) ) {
			$total = get_option( 'limit_login_lockouts_total' );

			if ( false !== $total ) {
				return array(
					'available' => true,
					'value'     => (int) $total,
					'window'    => 'all time, not 30 days',
					'source'    => 'Limit Login Attempts Reloaded, limit_login_lockouts_total option. This is lockouts since install, not failed attempts in a window.',
				);
			}
		}

		return array(
			'available' => false,
			'value'     => null,
			'reason'    => 'Nothing on this site records failed logins. WordPress core does not keep them. Report this as not measured, never as zero.',
		);
	}
}
