<?php
/**
 * Uninstall.
 *
 * The event log is deliberately NOT dropped. It is the only thing this plugin
 * holds that cannot be rebuilt, it accumulates from install day, and a client
 * site that gets the plugin removed and reinstalled would otherwise lose every
 * update ever recorded with nothing in any log to say it happened.
 *
 * To remove the data as well, define BSREP_UNINSTALL_REMOVE_DATA as true in
 * wp-config.php before deleting the plugin.
 *
 * @package BiscuitSiteReport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'BSREP_UNINSTALL_REMOVE_DATA' ) || true !== BSREP_UNINSTALL_REMOVE_DATA ) {
	return;
}

global $wpdb;

$bsrep_table = $wpdb->prefix . 'bsrep_events';

$wpdb->query( "DROP TABLE IF EXISTS {$bsrep_table}" ); // phpcs:ignore WordPress.DB

foreach (
	array(
		'bsrep_schema_version',
		'bsrep_version_map',
		'bsrep_scan_result',
		'bsrep_scan_state',
		'bsrep_endpoint_url',
		'bsrep_shared_secret',
		'bsrep_last_push',
		'bsrep_installed_at',
	) as $bsrep_option
) {
	delete_option( $bsrep_option );
}

foreach ( array( 'bsrep_sweep_versions', 'bsrep_push', 'bsrep_weekly_scan', 'bsrep_scan_tick' ) as $bsrep_hook ) {
	wp_clear_scheduled_hook( $bsrep_hook );
}
