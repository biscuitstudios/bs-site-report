<?php
/**
 * Bootstrap and scheduling.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
class Bsrep_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Bsrep_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return Bsrep_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 *
	 * @return void
	 */
	public function init() {
		// The updater goes first and sits outside the gate below, deliberately.
		// Update checks run under cron, which is not an admin request, and a gate
		// that excluded cron would leave this plugin silently unable to update
		// itself. That is the documented constraint in THEME-RULES.md and it is the
		// failure the whole class exists to fix, so it must not be re-created here.
		( new Bsrep_Updater( BSREP_FILE ) )->init();

		// Registering an action is cheap and these hooks only ever fire in admin,
		// cron or CLI, so they cost a front end request nothing.
		( new Bsrep_Watcher() )->init();
		( new Bsrep_Scanner() )->init();
		( new Bsrep_Push() )->init();

		// Everything below is not cheap. The admin plugin file is a large include
		// and the schema check is an uncached option read, and a visitor loading
		// the home page should pay for neither. This plugin reports on the site and
		// must not be a reason the site got slower.
		if ( ! self::is_working_request() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		Bsrep_Log::install();

		if ( is_admin() ) {
			( new Bsrep_Admin() )->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once BSREP_DIR . 'includes/class-bsrep-cli.php';
			WP_CLI::add_command( 'bsrep', 'Bsrep_CLI' );
		}
	}

	/**
	 * Is this a request where the plugin actually has work to do?
	 *
	 * Admin, cron and WP-CLI. Not a front end page view, and not a REST or AJAX
	 * request from the front end.
	 *
	 * @return bool
	 */
	public static function is_working_request() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( wp_doing_cron() ) {
			return true;
		}

		return is_admin();
	}

	/**
	 * On activation: create the table, take a baseline, schedule the crons.
	 *
	 * The baseline matters. Without it the first sweep would see every installed
	 * plugin as new and write thirty-one install events on day one, which would
	 * then read as thirty-one pieces of work on a client's page.
	 *
	 * @return void
	 */
	public static function activate() {
		Bsrep_Log::install();

		if ( ! get_option( 'bsrep_installed_at' ) ) {
			add_option( 'bsrep_installed_at', current_time( 'mysql', true ), '', false );
		}

		( new Bsrep_Watcher() )->sweep();

		if ( ! wp_next_scheduled( 'bsrep_sweep_versions' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'bsrep_sweep_versions' );
		}

		if ( ! wp_next_scheduled( 'bsrep_push' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'bsrep_push' );
		}

		if ( ! wp_next_scheduled( 'bsrep_weekly_scan' ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'weekly', 'bsrep_weekly_scan' );
		}
	}

	/**
	 * On deactivation: clear the schedule. The log stays.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( array( 'bsrep_sweep_versions', 'bsrep_push', 'bsrep_weekly_scan', 'bsrep_scan_tick' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}

/**
 * WordPress ships no weekly schedule of its own.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function bsrep_add_weekly_schedule( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'bs-site-report' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'bsrep_add_weekly_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

/**
 * Start the weekly structure scan.
 *
 * @return void
 */
function bsrep_run_weekly_scan() {
	( new Bsrep_Scanner() )->start();
}
add_action( 'bsrep_weekly_scan', 'bsrep_run_weekly_scan' );
