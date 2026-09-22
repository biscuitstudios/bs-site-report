<?php
/**
 * Plugin Name:       Biscuit Site Report
 * Plugin URI:        https://github.com/biscuitstudios/bs-site-report
 * Description:       Collects the site facts that only exist inside WordPress, logs every update as it happens, and pushes a signed summary to Biscuit. Nothing is shown to site users and nothing is served publicly.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Biscuit Studios
 * Author URI:        https://biscuitstudios.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bs-site-report
 * Update URI:        https://github.com/biscuitstudios/bs-site-report
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Update URI header above is deliberate and is only half a mechanism.
 *
 * A non-wordpress.org Update URI stops core checking wordpress.org for the
 * bs-site-report slug, which is the failure that would otherwise install a
 * stranger's plugin over this one. It does NOT deliver updates on its own: core
 * hands off to apply_filters( "update_plugins_github.com", false, ... ) and an
 * unhooked filter returns false and is discarded in silence. See THEME-RULES.md,
 * "Releases and self-updating".
 *
 * Bsrep_Updater, ported from bs-maintenance on September 22, 2026, is that missing
 * half. It is initialised outside every other gate in this plugin, because update
 * checks run under cron and cron is not an admin request.
 *
 * It answers against a repo that does not exist yet. Until biscuitstudios/bs-site-report
 * is created and has a release with a built zip attached, every check logs one
 * [BSREP] line and backs off for an hour. Create the repo before installing this
 * anywhere, or the log fills with a failure nobody needs to read.
 */

define( 'BSREP_VERSION', '0.1.0' );
define( 'BSREP_FILE', __FILE__ );
define( 'BSREP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSREP_SLUG', 'bs-site-report' );

require_once BSREP_DIR . 'includes/class-bsrep-log.php';
require_once BSREP_DIR . 'includes/class-bsrep-watcher.php';
require_once BSREP_DIR . 'includes/class-bsrep-scanner.php';
require_once BSREP_DIR . 'includes/class-bsrep-analytics.php';
require_once BSREP_DIR . 'includes/class-bsrep-forms.php';
require_once BSREP_DIR . 'includes/class-bsrep-security.php';
require_once BSREP_DIR . 'includes/class-bsrep-accessibility.php';
require_once BSREP_DIR . 'includes/class-bsrep-collector.php';
require_once BSREP_DIR . 'includes/class-bsrep-push.php';
require_once BSREP_DIR . 'includes/class-bsrep-admin.php';
require_once BSREP_DIR . 'includes/class-bsrep-updater.php';
require_once BSREP_DIR . 'includes/class-bsrep-plugin.php';

register_activation_hook( __FILE__, array( 'Bsrep_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bsrep_Plugin', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * Wired on plugins_loaded rather than at file scope so the version watcher can
 * see a fully loaded plugin list, and so WP-CLI and cron get the same wiring an
 * admin request gets.
 *
 * @return void
 */
function bsrep_boot() {
	Bsrep_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'bsrep_boot' );
