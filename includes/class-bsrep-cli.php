<?php
/**
 * WP-CLI commands.
 *
 * This is the route Jason actually uses. Kinsta gives every site SSH and WP-CLI,
 * so the payload can be pulled off a site without opening wp-admin and without
 * the plugin ever serving anything over HTTP.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Biscuit Site Report commands.
 */
class Bsrep_CLI {

	/**
	 * Print the payload as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--pretty]
	 * : Indent the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bsrep payload --pretty > report.json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function payload( $args, $assoc_args ) {
		unset( $args );

		$flags = ! empty( $assoc_args['pretty'] )
			? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			: JSON_UNESCAPED_SLASHES;

		WP_CLI::line( wp_json_encode( ( new Bsrep_Collector() )->build(), $flags ) );
	}

	/**
	 * Run the structure scan to completion, in the foreground.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bsrep scan
	 *
	 * @return void
	 */
	public function scan() {
		$result = ( new Bsrep_Scanner() )->run_now();

		if ( empty( $result ) ) {
			WP_CLI::warning( 'The scan produced no result. Nothing was reachable.' );

			return;
		}

		$totals = $result['totals'];

		WP_CLI::success(
			sprintf(
				'%d pages scanned, %d unreachable. %d of %d have headings in order. %d carry structured data. %d images with no alt attribute.',
				$totals['pages_scanned'],
				$totals['pages_unreachable'],
				$totals['pages_headings_in_order'],
				$totals['pages_scanned'],
				$totals['pages_with_jsonld'],
				$totals['images_no_alt']
			)
		);

		if ( $result['urls_dropped'] > 0 ) {
			WP_CLI::warning( sprintf( '%d URLs were dropped by the %d URL cap.', $result['urls_dropped'], $result['cap'] ) );
		}
	}

	/**
	 * Compare installed versions against the stored map and log any change.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bsrep sweep
	 *
	 * @return void
	 */
	public function sweep() {
		$written = ( new Bsrep_Watcher() )->sweep();

		WP_CLI::success( sprintf( '%d events written.', $written ) );
	}

	/**
	 * Send the payload to the configured receiver.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bsrep push
	 *
	 * @return void
	 */
	public function push() {
		$result = ( new Bsrep_Push() )->run();

		if ( $result['sent'] ) {
			WP_CLI::success( $result['message'] );

			return;
		}

		WP_CLI::error( $result['message'] );
	}

	/**
	 * Show what the log covers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bsrep coverage
	 *
	 * @return void
	 */
	public function coverage() {
		$first = Bsrep_Log::first_event_date();
		$total = Bsrep_Log::total();

		if ( ! $first ) {
			WP_CLI::warning( 'Nothing logged yet. The log starts the day this plugin is installed and cannot recover earlier history.' );

			return;
		}

		WP_CLI::line( sprintf( 'Oldest event: %s UTC', $first ) );
		WP_CLI::line( sprintf( 'Events: %d', $total ) );

		foreach ( Bsrep_Log::recent( 10 ) as $row ) {
			WP_CLI::line(
				sprintf(
					'  %s  %-18s %s %s',
					$row['occurred_at'],
					$row['event_type'],
					$row['object_name'],
					( '' !== $row['version_from'] && '' !== $row['version_to'] )
						? $row['version_from'] . ' to ' . $row['version_to']
						: $row['version_to']
				)
			);
		}
	}
}
