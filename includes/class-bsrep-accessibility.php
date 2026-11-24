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
	 * The two Accessibility Checker rules that mean an image has no alt text.
	 *
	 * Slugs from includes/classes/Rules/Rule/ImgAltMissingRule.php:26 and
	 * ImgLinkedAltMissingRule.php:26, checked identical in 1.52.0 and 1.52.1.
	 */
	const ALT_RULES = array( 'img_alt_missing', 'img_linked_alt_missing' );

	/**
	 * The Accessibility Checker rule for an image with alt="".
	 *
	 * From includes/classes/Rules/Rule/ImgAltEmptyRule.php:26, identical in
	 * 1.52.0 and 1.52.1. Its scanner selector is img[alt=""], linked images
	 * included, so img_linked_alt_empty is left out: it flags the <a> around
	 * an image this rule has already flagged, and adding it would count one
	 * linked image twice.
	 */
	const EMPTY_ALT_RULE = 'img_alt_empty';

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
			'images_missing_alt' => $this->images_missing_alt(),
		) + $this->images_empty_alt();
	}

	/**
	 * Open "image missing alt text" issues, plain and linked together.
	 *
	 * Counted the way Accessibility Checker's own site summary counts them:
	 * Scans_Stats::get_all_issues_found_on_site() in admin/class-scans-stats.php
	 * takes COUNT(id) from its issues table for this site with ignre = 0 and
	 * ignre_global = 0, grouped by rule. This is the same WHERE, narrowed to the
	 * two alt rules.
	 *
	 * A direct query rather than Scans_Stats::summary(). summary() runs without a
	 * user, but on a cache miss it recomputes every stat on the site and writes a
	 * 24 hour transient, and this plugin never writes into another plugin's
	 * state. The method that builds the per-rule list is private, so there is no
	 * narrower call. The table is Equalize Digital's, read and never written.
	 *
	 * @return array
	 */
	protected function images_missing_alt() {
		global $wpdb;

		$table = $wpdb->prefix . 'accessibility_checker';

		// Their own existence check, from admin/class-update-database.php.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $exists ) {
			return array(
				'available' => false,
				'value'     => null,
				'reason'    => 'Accessibility Checker\'s issues table was not found, so there is no alt text count. Report as not measured, never as zero.',
			);
		}

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(id) FROM %i WHERE siteid = %d AND ignre = 0 AND ignre_global = 0 AND rule IN (%s, %s)',
				$table,
				get_current_blog_id(),
				self::ALT_RULES[0],
				self::ALT_RULES[1]
			)
		);

		// A failed query and an unreadable answer both come back as null here,
		// and either one is a schema change rather than a site with no issues.
		if ( null === $count || '' !== (string) $wpdb->last_error || ! is_numeric( $count ) ) {
			return array(
				'available' => false,
				'value'     => null,
				'reason'    => 'The query on Accessibility Checker\'s issues table failed or returned nothing readable, so its schema may have changed. Report as not measured.',
			);
		}

		return array(
			'available' => true,
			'value'     => (int) $count,
			'rules'     => self::ALT_RULES,
			'source'    => 'Equalize Digital Accessibility Checker, ' . $table . ' table. Open issues only, ignored ones excluded, counted as its own site summary counts them. Read only. Not a supported API.',
		);
	}

	/**
	 * Open "image empty alternative text" issues, all of them and the ones an
	 * editor placed from the media library.
	 *
	 * Same table, same WHERE as images_missing_alt(), for the one rule. The
	 * second count keeps only rows whose stored HTML carries a wp-image-N class,
	 * which WordPress adds to an image inserted into content from the media
	 * library and which theme markup (wp_get_attachment_image(), a logo, a
	 * background) does not carry. The object column holds the element's full
	 * outerHTML, passed through esc_attr() on insert, so it is decoded and its
	 * class attributes are read in PHP rather than matched with LIKE, which
	 * would take "not-wp-image-x" for a match.
	 *
	 * @return array Two keys: images_empty_alt and images_empty_alt_in_content.
	 */
	protected function images_empty_alt() {
		global $wpdb;

		$table = $wpdb->prefix . 'accessibility_checker';
		$rule  = self::EMPTY_ALT_RULE;

		$unmeasured = function ( $reason ) {
			return array(
				'available' => false,
				'value'     => null,
				'reason'    => $reason,
			);
		};

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $exists ) {
			$reason = 'Accessibility Checker\'s issues table was not found, so there is no empty alt count. Report as not measured, never as zero.';

			return array(
				'images_empty_alt'            => $unmeasured( $reason ),
				'images_empty_alt_in_content' => $unmeasured( $reason ),
			);
		}

		$where = $wpdb->prepare( 'siteid = %d AND ignre = 0 AND ignre_global = 0 AND rule = %s', get_current_blog_id(), $rule );

		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE ', $table ) . $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared above.

		if ( null === $count || '' !== (string) $wpdb->last_error || ! is_numeric( $count ) ) {
			$reason = 'The query on Accessibility Checker\'s issues table failed or returned nothing readable, so its schema may have changed. Report as not measured.';

			return array(
				'images_empty_alt'            => $unmeasured( $reason ),
				'images_empty_alt_in_content' => $unmeasured( $reason ),
			);
		}

		$source = 'Equalize Digital Accessibility Checker, ' . $table . ' table. Open issues only, ignored ones excluded. Read only. Not a supported API.';

		$all = array(
			'available' => true,
			'value'     => (int) $count,
			'rules'     => array( $rule ),
			'source'    => $source,
		);

		// LIKE narrows the rows fetched; the class check below decides.
		$objects = $wpdb->get_col( $wpdb->prepare( 'SELECT object FROM %i WHERE ', $table ) . $where . $wpdb->prepare( ' AND object LIKE %s', '%' . $wpdb->esc_like( 'wp-image-' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- every part is prepared.

		if ( '' !== (string) $wpdb->last_error || ! is_array( $objects ) ) {
			return array(
				'images_empty_alt'            => $all,
				'images_empty_alt_in_content' => $unmeasured( 'The stored HTML of the empty alt issues could not be read, so they cannot be split into content and theme images. Report as not measured.' ),
			);
		}

		$in_content = 0;

		foreach ( $objects as $object ) {
			if ( self::has_media_library_class( (string) $object ) ) {
				++$in_content;
			}
		}

		return array(
			'images_empty_alt'            => $all,
			'images_empty_alt_in_content' => array(
				'available' => true,
				'value'     => $in_content,
				'rules'     => array( $rule ),
				'match'     => 'The stored HTML has a class attribute containing a wp-image-<number> class, the class WordPress adds to an image inserted into content from the media library.',
				'source'    => $source,
			),
		);
	}

	/**
	 * Whether stored issue HTML carries a wp-image-<number> class.
	 *
	 * Reads every class attribute in the decoded HTML and checks whole class
	 * tokens, so "not-wp-image-5", "wp-image-x" and wp-image-5 in an alt or src
	 * do not count.
	 *
	 * @param string $object The object column as stored.
	 * @return bool
	 */
	public static function has_media_library_class( $object ) {
		$html = html_entity_decode( $object, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! preg_match_all( '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $m ) {
			$classes = preg_split( '/\s+/', trim( ( $m[1] ?? '' ) . ' ' . ( $m[2] ?? '' ) ) );

			foreach ( $classes as $class ) {
				if ( 1 === preg_match( '/^wp-image-\d+$/', $class ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
