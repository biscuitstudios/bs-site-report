<?php
/**
 * Equalize Digital Accessibility Checker adapter.
 *
 * Accessibility Checker is installed on seven of the forty-eight Kinsta
 * environments and active on none of them, so on almost every site this returns
 * unavailable. That is the honest answer and it is also the finding: the
 * accessibility offer is "install, license, audit", not "switch on what is
 * already there".
 *
 * Where it IS active, the per-post summary meta key is read rather than their
 * tables. That key belongs to Equalize Digital, so it is read and never written,
 * and the source is named in the payload so nobody downstream mistakes it for a
 * supported API. It will break when they change it, and the payload will say
 * unavailable rather than wrong.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads accessibility scan results, where the plugin is present and active.
 */
class Bsrep_Accessibility {

	/**
	 * The plugin file, free and Pro.
	 *
	 * @var array
	 */
	protected $plugin_files = array(
		'accessibility-checker/accessibility-checker.php'       => 'Accessibility Checker',
		'accessibility-checker-pro/accessibility-checker-pro.php' => 'Accessibility Checker Pro',
	);

	/**
	 * The per-post summary meta key the plugin writes.
	 */
	const SUMMARY_META = '_edac_summary';

	/**
	 * Collect the accessibility section.
	 *
	 * @return array
	 */
	public function collect() {
		$installed = array();
		$active    = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = get_plugins();

		foreach ( $this->plugin_files as $file => $label ) {
			if ( isset( $all[ $file ] ) ) {
				$installed[ $file ] = $label;
			}

			if ( is_plugin_active( $file ) ) {
				$active[ $file ] = $label;
			}
		}

		if ( empty( $active ) ) {
			return array(
				'available' => false,
				'installed' => ! empty( $installed ),
				'reason'    => empty( $installed )
					? 'Accessibility Checker is not installed on this site, so there is no score to report.'
					: 'Accessibility Checker is installed but not active, so its last scan may be stale and no new scan can run. Report as not measured.',
			);
		}

		return $this->summarise( reset( $active ) );
	}

	/**
	 * Aggregate the per-post summaries.
	 *
	 * @param string $label Plugin label.
	 * @return array
	 */
	protected function summarise( $label ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
				'meta_key'               => self::SUMMARY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'           => 'EXISTS',
			)
		);

		if ( empty( $query->posts ) ) {
			return array(
				'available' => false,
				'installed' => true,
				'reason'    => 'Accessibility Checker is active but has not scanned any published content yet.',
			);
		}

		$pages           = 0;
		$errors          = 0;
		$warnings        = 0;
		$contrast        = 0;
		$passed          = 0;
		$pages_with_issues = 0;

		foreach ( $query->posts as $post_id ) {
			$summary = get_post_meta( $post_id, self::SUMMARY_META, true );

			if ( ! is_array( $summary ) ) {
				continue;
			}

			++$pages;

			$page_errors   = (int) ( $summary['errors'] ?? 0 );
			$page_contrast = (int) ( $summary['contrast_errors'] ?? 0 );

			$errors   += $page_errors;
			$contrast += $page_contrast;
			$warnings += (int) ( $summary['warnings'] ?? 0 );
			$passed   += (int) ( $summary['passed_tests'] ?? 0 );

			if ( $page_errors > 0 || $page_contrast > 0 ) {
				++$pages_with_issues;
			}
		}

		if ( 0 === $pages ) {
			return array(
				'available' => false,
				'installed' => true,
				'reason'    => 'The summary meta key exists but held nothing readable. Treat as not measured.',
			);
		}

		return array(
			'available'         => true,
			'installed'         => true,
			'plugin'            => $label,
			'source'            => 'Equalize Digital Accessibility Checker, ' . self::SUMMARY_META . ' post meta. Read only. Not a supported API, so treat a future empty result as a schema change rather than as a clean site.',
			'standard'          => 'WCAG 2.1 Level AA',
			'pages_with_a_scan' => $pages,
			'pages_with_issues' => $pages_with_issues,
			'errors'            => $errors,
			'contrast_errors'   => $contrast,
			'warnings'          => $warnings,
			'passed_tests'      => $passed,
		);
	}
}
