<?php
/**
 * The admin screen.
 *
 * One page under Tools, administrators only. Clients hold the Editor role on a
 * Biscuit build, so they will not see it. Nothing here is shown on the front end
 * and the plugin adds no dashboard widget, no notice and no menu item a client
 * would come across.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings and manual controls.
 */
class Bsrep_Admin {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'bs-site-report';

	/**
	 * Hook the screen up.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_bsrep_action', array( $this, 'handle' ) );
	}

	/**
	 * Add the page.
	 *
	 * @return void
	 */
	public function menu() {
		add_management_page(
			__( 'Biscuit Site Report', 'bs-site-report' ),
			__( 'Biscuit Site Report', 'bs-site-report' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the form posts: save settings, run a scan, push now, download JSON.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bs-site-report' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bsrep_action' );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';

		if ( 'save' === $task ) {
			$url = isset( $_POST['bsrep_endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['bsrep_endpoint_url'] ) ) : '';

			update_option( Bsrep_Push::URL_OPTION, $url, false );

			$this->redirect( 'saved' );
		}

		if ( 'scan' === $task ) {
			( new Bsrep_Scanner() )->start();

			$this->redirect( 'scanning' );
		}

		if ( 'sweep' === $task ) {
			( new Bsrep_Watcher() )->sweep();

			$this->redirect( 'swept' );
		}

		if ( 'push' === $task ) {
			( new Bsrep_Push() )->run();

			$this->redirect( 'pushed' );
		}

		if ( 'download' === $task ) {
			$this->download();
		}

		$this->redirect( 'unknown' );
	}

	/**
	 * Send the payload to the browser as a file.
	 *
	 * @return void
	 */
	protected function download() {
		$payload = ( new Bsrep_Collector() )->build();
		$body    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$name    = 'bs-site-report-' . sanitize_file_name( (string) $host ) . '-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body, not markup.
		exit;
	}

	/**
	 * Back to the screen with a message key.
	 *
	 * @param string $notice Message key.
	 * @return void
	 */
	protected function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$coverage = Bsrep_Log::first_event_date();
		$total    = Bsrep_Log::total();
		$scan     = ( new Bsrep_Scanner() )->result();
		$last     = get_option( Bsrep_Push::LAST_OPTION );
		$secret   = defined( 'BSREP_SECRET' ) ? null : Bsrep_Push::secret();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Biscuit Site Report', 'bs-site-report' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $this->notice_text( $notice ) ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'What has been recorded', 'bs-site-report' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events logged', 'bs-site-report' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Oldest event', 'bs-site-report' ); ?></th>
						<td>
							<?php
							echo $coverage
								? esc_html( $coverage . ' UTC' )
								: esc_html__( 'Nothing yet. The log starts from the day this plugin was installed and cannot recover history from before it.', 'bs-site-report' );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last structure scan', 'bs-site-report' ); ?></th>
						<td>
							<?php
							echo $scan
								? esc_html( $scan['completed_at'] . ' UTC, ' . (int) $scan['totals']['pages_scanned'] . ' pages' )
								: esc_html__( 'Never run.', 'bs-site-report' );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last push', 'bs-site-report' ); ?></th>
						<td>
							<?php
							echo is_array( $last )
								? esc_html( $last['at'] . ' — ' . $last['message'] )
								: esc_html__( 'Never.', 'bs-site-report' );
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Where the payload goes', 'bs-site-report' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bsrep_action' ); ?>
				<input type="hidden" name="action" value="bsrep_action">
				<input type="hidden" name="task" value="save">
				<p>
					<label for="bsrep_endpoint_url"><?php esc_html_e( 'Receiver URL', 'bs-site-report' ); ?></label><br>
					<input type="url" class="regular-text code" id="bsrep_endpoint_url" name="bsrep_endpoint_url"
						value="<?php echo esc_attr( get_option( Bsrep_Push::URL_OPTION, '' ) ); ?>"
						placeholder="https://biscuitstudios.com/wp-json/bsrep-receiver/v1/report">
				</p>
				<p class="description">
					<?php esc_html_e( 'Leave this empty and nothing is sent anywhere. The payload can still be downloaded below.', 'bs-site-report' ); ?>
				</p>
				<?php submit_button( __( 'Save', 'bs-site-report' ) ); ?>
			</form>

			<?php if ( null !== $secret ) : ?>
				<h2><?php esc_html_e( 'Shared secret', 'bs-site-report' ); ?></h2>
				<p><code><?php echo esc_html( $secret ); ?></code></p>
				<p class="description">
					<?php esc_html_e( 'This site signs every payload with this string. The receiver needs the same one. It is stored in the options table, which means it is in every database backup. Define BSREP_SECRET in wp-config.php instead and the constant wins.', 'bs-site-report' ); ?>
				</p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Shared secret', 'bs-site-report' ); ?></h2>
				<p><?php esc_html_e( 'Taken from the BSREP_SECRET constant in wp-config.php. Not shown here.', 'bs-site-report' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Run something now', 'bs-site-report' ); ?></h2>
			<?php foreach ( $this->tasks() as $task => $label ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
					<?php wp_nonce_field( 'bsrep_action' ); ?>
					<input type="hidden" name="action" value="bsrep_action">
					<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
					<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The manual task buttons.
	 *
	 * @return array
	 */
	protected function tasks() {
		return array(
			'scan'     => __( 'Start a structure scan', 'bs-site-report' ),
			'sweep'    => __( 'Check for version changes now', 'bs-site-report' ),
			'push'     => __( 'Push the payload now', 'bs-site-report' ),
			'download' => __( 'Download the payload as JSON', 'bs-site-report' ),
		);
	}

	/**
	 * Text for one notice key.
	 *
	 * @param string $notice Key.
	 * @return string
	 */
	protected function notice_text( $notice ) {
		$map = array(
			'saved'    => __( 'Saved.', 'bs-site-report' ),
			'scanning' => __( 'Structure scan started. It runs in the background and takes a few minutes.', 'bs-site-report' ),
			'swept'    => __( 'Version check finished.', 'bs-site-report' ),
			'pushed'   => __( 'Push attempted. The result is in the table above.', 'bs-site-report' ),
		);

		return $map[ $notice ] ?? __( 'Done.', 'bs-site-report' );
	}
}
