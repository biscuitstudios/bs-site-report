<?php
/**
 * The structure scan.
 *
 * Fetches published URLs on this site and reads the HTML the way a screen reader
 * and an AI crawler read it: heading order, landmarks, alt text, structured data,
 * page description. One pass, three answers. This is the measurement behind
 * "structure is the product".
 *
 * It runs in chunks on cron because a site with 400 pages is 400 HTTP requests
 * and no single request should carry that.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crawls this site's own published URLs and measures their structure.
 */
class Bsrep_Scanner {

	/**
	 * Option holding the finished result of the last complete scan.
	 */
	const RESULT_OPTION = 'bsrep_scan_result';

	/**
	 * Option holding the scan in progress.
	 */
	const STATE_OPTION = 'bsrep_scan_state';

	/**
	 * URLs fetched per cron tick.
	 */
	const CHUNK = 20;

	/**
	 * Hard ceiling on URLs in one scan.
	 *
	 * A bounded scan that does not say it was bounded reads as full coverage. The
	 * result records both the cap and how many URLs were dropped by it.
	 */
	const MAX_URLS = 600;

	/**
	 * Register the cron handler.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'bsrep_scan_tick', array( $this, 'tick' ) );
	}

	/**
	 * The user agent every scan request carries.
	 *
	 * It says "bot" on purpose. Independent Analytics ignores self-identifying
	 * bots, and a scan that quietly added several hundred visits to a client's own
	 * visitor count would corrupt the one number on the report page the client
	 * cares most about.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'BiscuitSiteReport/' . BSREP_VERSION . ' bot (+https://biscuitstudios.com/)';
	}

	/**
	 * Start a scan. Clears any scan already in progress.
	 *
	 * @return array{queued:int,capped:int}
	 */
	public function start() {
		$urls   = $this->collect_urls();
		$total  = count( $urls );
		$capped = 0;

		if ( $total > self::MAX_URLS ) {
			$capped = $total - self::MAX_URLS;
			$urls   = array_slice( $urls, 0, self::MAX_URLS );
		}

		update_option(
			self::STATE_OPTION,
			array(
				'started_at' => current_time( 'mysql', true ),
				'queue'      => $urls,
				'done'       => array(),
				'capped'     => $capped,
				'found'      => $total,
			),
			false
		);

		if ( ! wp_next_scheduled( 'bsrep_scan_tick' ) ) {
			wp_schedule_single_event( time() + 10, 'bsrep_scan_tick' );
		}

		return array(
			'queued' => count( $urls ),
			'capped' => $capped,
		);
	}

	/**
	 * Build the URL list from published content.
	 *
	 * Front page first, then every public post type. WP_Query rather than a direct
	 * query, and no query inside the loop.
	 *
	 * @return array List of array{ url: string, post_type: string, post_id: int }.
	 */
	protected function collect_urls() {
		$urls = array();

		$urls[] = array(
			'url'       => home_url( '/' ),
			'post_type' => 'front_page',
			'post_id'   => 0,
		);

		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);

		$types['page'] = 'page';
		unset( $types['attachment'] );

		foreach ( $types as $type ) {
			$query = new WP_Query(
				array(
					'post_type'              => $type,
					'post_status'            => 'publish',
					'posts_per_page'         => self::MAX_URLS,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
				)
			);

			foreach ( $query->posts as $post_id ) {
				$permalink = get_permalink( $post_id );

				if ( ! $permalink ) {
					continue;
				}

				$urls[] = array(
					'url'       => $permalink,
					'post_type' => $type,
					'post_id'   => (int) $post_id,
				);
			}
		}

		$seen = array();
		$out  = array();

		foreach ( $urls as $item ) {
			if ( isset( $seen[ $item['url'] ] ) ) {
				continue;
			}

			$seen[ $item['url'] ] = true;
			$out[]                = $item;
		}

		return $out;
	}

	/**
	 * Fetch and measure the next chunk, then reschedule or finish.
	 *
	 * @return void
	 */
	public function tick() {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) || empty( $state['queue'] ) ) {
			$this->finish();

			return;
		}

		$chunk          = array_splice( $state['queue'], 0, self::CHUNK );
		$state['done']  = array_merge( $state['done'], $this->measure_many( $chunk ) );

		update_option( self::STATE_OPTION, $state, false );

		if ( ! empty( $state['queue'] ) ) {
			wp_schedule_single_event( time() + 30, 'bsrep_scan_tick' );

			return;
		}

		$this->finish();
	}

	/**
	 * Run the whole scan now, without cron. Used by WP-CLI.
	 *
	 * @return array The finished result.
	 */
	public function run_now() {
		$this->start();

		$guard = 0;

		while ( $guard < 100 ) {
			$state = get_option( self::STATE_OPTION );

			if ( ! is_array( $state ) || empty( $state['queue'] ) ) {
				break;
			}

			$chunk         = array_splice( $state['queue'], 0, self::CHUNK );
			$state['done'] = array_merge( $state['done'], $this->measure_many( $chunk ) );

			update_option( self::STATE_OPTION, $state, false );

			++$guard;
		}

		$this->finish();

		return (array) get_option( self::RESULT_OPTION, array() );
	}

	/**
	 * Measure a list of URLs.
	 *
	 * @param array $chunk List of URL records.
	 * @return array List of measurements.
	 */
	protected function measure_many( array $chunk ) {
		$out = array();

		foreach ( $chunk as $item ) {
			$out[] = $this->measure( $item );
		}

		return $out;
	}

	/**
	 * Fetch one URL and measure its structure.
	 *
	 * @param array $item URL record.
	 * @return array Measurement.
	 */
	protected function measure( array $item ) {
		$response = wp_remote_get(
			$item['url'],
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => self::user_agent(),
				'headers'     => array( 'X-Biscuit-Site-Report' => BSREP_VERSION ),
				'sslverify'   => true,
			)
		);

		$record = array(
			'url'       => $item['url'],
			'post_type' => $item['post_type'],
			'post_id'   => $item['post_id'],
			'status'    => 0,
			'error'     => '',
		);

		if ( is_wp_error( $response ) ) {
			$record['error'] = $response->get_error_message();

			return $record;
		}

		$record['status'] = (int) wp_remote_retrieve_response_code( $response );
		$body             = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $record['status'] || '' === $body ) {
			return $record;
		}

		return array_merge( $record, $this->parse( $body ) );
	}

	/**
	 * Parse one HTML document.
	 *
	 * Public and free of any WordPress call on purpose, so it can be run against
	 * real fetched HTML outside WordPress. A lint pass proves this file parses. It
	 * does not prove that the heading rule counts what it claims to count, and the
	 * only way to know that is to point it at pages whose answer is known.
	 *
	 * @param string $html Document.
	 * @return array Measurements.
	 */
	public function parse( $html ) {
		$previous = libxml_use_internal_errors( true );

		$doc = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $doc );

		$out = array(
			'h1_count'         => 0,
			'heading_count'    => 0,
			'heading_skips'    => 0,
			'empty_headings'   => 0,
			'images'           => 0,
			'images_no_alt'    => 0,
			'images_empty_alt' => 0,
			'jsonld_blocks'    => 0,
			'jsonld_types'     => array(),
			'has_description'  => false,
			'has_title'        => false,
			'has_lang'         => false,
			'has_main'         => false,
			'has_nav'          => false,
			'has_header'       => false,
			'has_footer'       => false,
			'nav_unlabelled'   => 0,
		);

		$headings = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		$last     = 0;

		foreach ( $headings as $heading ) {
			$level = (int) substr( $heading->nodeName, 1 );
			++$out['heading_count'];

			if ( 1 === $level ) {
				++$out['h1_count'];
			}

			if ( '' === trim( (string) $heading->textContent ) ) {
				++$out['empty_headings'];
			}

			// A skip is a jump of more than one level going down the document, which
			// is the failure a screen reader user actually hits. Going back up any
			// number of levels is normal and is not counted.
			if ( $last > 0 && $level > $last + 1 ) {
				++$out['heading_skips'];
			}

			$last = $level;
		}

		$images = $xpath->query( '//img' );

		foreach ( $images as $image ) {
			++$out['images'];

			if ( ! $image->hasAttribute( 'alt' ) ) {
				++$out['images_no_alt'];

				continue;
			}

			if ( '' === trim( (string) $image->getAttribute( 'alt' ) ) ) {
				++$out['images_empty_alt'];
			}
		}

		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );

		foreach ( $scripts as $script ) {
			++$out['jsonld_blocks'];

			$decoded = json_decode( (string) $script->textContent, true );

			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $this->jsonld_types( $decoded ) as $type ) {
				$out['jsonld_types'][ $type ] = true;
			}
		}

		$out['jsonld_types'] = array_keys( $out['jsonld_types'] );

		$description = $xpath->query( '//meta[@name="description"]/@content' );
		$out['has_description'] = $description->length > 0 && '' !== trim( (string) $description->item( 0 )->nodeValue );

		$title             = $xpath->query( '//title' );
		$out['has_title']  = $title->length > 0 && '' !== trim( (string) $title->item( 0 )->textContent );

		$lang            = $xpath->query( '//html/@lang' );
		$out['has_lang'] = $lang->length > 0 && '' !== trim( (string) $lang->item( 0 )->nodeValue );

		$out['has_main']   = $xpath->query( '//main|//*[@role="main"]' )->length > 0;
		$out['has_header'] = $xpath->query( '//header|//*[@role="banner"]' )->length > 0;
		$out['has_footer'] = $xpath->query( '//footer|//*[@role="contentinfo"]' )->length > 0;

		$navs            = $xpath->query( '//nav|//*[@role="navigation"]' );
		$out['has_nav']  = $navs->length > 0;

		foreach ( $navs as $nav ) {
			if ( ! $nav->hasAttribute( 'aria-label' ) && ! $nav->hasAttribute( 'aria-labelledby' ) ) {
				++$out['nav_unlabelled'];
			}
		}

		return $out;
	}

	/**
	 * Pull every @type out of a decoded JSON-LD block, at any depth.
	 *
	 * @param mixed $node Decoded JSON.
	 * @return array List of type strings.
	 */
	protected function jsonld_types( $node ) {
		$types = array();

		if ( ! is_array( $node ) ) {
			return $types;
		}

		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$types[] = $type;
				}
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$types = array_merge( $types, $this->jsonld_types( $value ) );
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Aggregate the finished scan and store it.
	 *
	 * @return void
	 */
	protected function finish() {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) || empty( $state['done'] ) ) {
			delete_option( self::STATE_OPTION );

			return;
		}

		$result = self::aggregate(
			$state['done'],
			array(
				'started_at' => (string) ( $state['started_at'] ?? '' ),
				'urls_found' => (int) ( $state['found'] ?? 0 ),
				'urls_dropped' => (int) ( $state['capped'] ?? 0 ),
			)
		);

		$result['completed_at'] = current_time( 'mysql', true );

		update_option( self::RESULT_OPTION, $result, false );

		delete_option( self::STATE_OPTION );
	}

	/**
	 * Roll a list of per-page measurements into the portfolio figures.
	 *
	 * Static and WordPress-free for the same reason parse() is.
	 *
	 * @param array $pages List of measurements from parse(), each with url,
	 *                     post_type and status merged in.
	 * @param array $meta  Optional started_at, urls_found, urls_dropped.
	 * @return array
	 */
	public static function aggregate( array $pages, array $meta = array() ) {
		$totals = array(
			'pages_scanned'          => 0,
			'pages_unreachable'      => 0,
			'pages_one_h1'           => 0,
			'pages_no_h1'            => 0,
			'pages_multiple_h1'      => 0,
			'pages_headings_in_order' => 0,
			'pages_heading_skips'    => 0,
			'pages_with_jsonld'      => 0,
			'pages_no_description'   => 0,
			'pages_no_main'          => 0,
			'images'                 => 0,
			'images_no_alt'          => 0,
			'images_empty_alt'       => 0,
			'pages_with_missing_alt' => 0,
			'pages_mostly_empty_alt' => 0,
			'unlabelled_navs'        => 0,
		);

		$by_type = array();
		$schema  = array();

		foreach ( $pages as $page ) {
			$type = (string) ( $page['post_type'] ?? 'unknown' );

			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array(
					'pages'             => 0,
					'with_jsonld'       => 0,
					'headings_in_order' => 0,
					'no_description'    => 0,
				);
			}

			if ( 200 !== (int) ( $page['status'] ?? 0 ) ) {
				++$totals['pages_unreachable'];

				continue;
			}

			++$totals['pages_scanned'];
			++$by_type[ $type ]['pages'];

			$h1 = (int) ( $page['h1_count'] ?? 0 );

			if ( 1 === $h1 ) {
				++$totals['pages_one_h1'];
			} elseif ( 0 === $h1 ) {
				++$totals['pages_no_h1'];
			} else {
				++$totals['pages_multiple_h1'];
			}

			if ( 0 === (int) ( $page['heading_skips'] ?? 0 ) ) {
				++$totals['pages_headings_in_order'];
				++$by_type[ $type ]['headings_in_order'];
			} else {
				++$totals['pages_heading_skips'];
			}

			if ( (int) ( $page['jsonld_blocks'] ?? 0 ) > 0 ) {
				++$totals['pages_with_jsonld'];
				++$by_type[ $type ]['with_jsonld'];
			}

			if ( empty( $page['has_description'] ) ) {
				++$totals['pages_no_description'];
				++$by_type[ $type ]['no_description'];
			}

			if ( empty( $page['has_main'] ) ) {
				++$totals['pages_no_main'];
			}

			$images     = (int) ( $page['images'] ?? 0 );
			$empty_alt  = (int) ( $page['images_empty_alt'] ?? 0 );
			$no_alt     = (int) ( $page['images_no_alt'] ?? 0 );

			$totals['images']           += $images;
			$totals['images_no_alt']    += $no_alt;
			$totals['images_empty_alt'] += $empty_alt;
			$totals['unlabelled_navs']  += (int) ( $page['nav_unlabelled'] ?? 0 );

			if ( $no_alt > 0 ) {
				++$totals['pages_with_missing_alt'];
			}

			// alt="" is correct for a decorative image and wrong for a content one,
			// and the HTML cannot tell you which this is. A page where most of the
			// images are empty-alt is usually a gallery or a portfolio grid where
			// the alt text was never written, and a zero in images_no_alt on that
			// page reads as a clean bill of health. Flag it for a person to look at
			// rather than calling it either way.
			if ( $images >= 5 && $empty_alt > ( $images / 2 ) ) {
				++$totals['pages_mostly_empty_alt'];
			}

			foreach ( (array) ( $page['jsonld_types'] ?? array() ) as $type_name ) {
				$schema[ $type_name ] = ( $schema[ $type_name ] ?? 0 ) + 1;
			}
		}

		arsort( $schema );

		return array(
			'started_at'   => (string) ( $meta['started_at'] ?? '' ),
			'urls_found'   => (int) ( $meta['urls_found'] ?? count( $pages ) ),
			'urls_dropped' => (int) ( $meta['urls_dropped'] ?? 0 ),
			'cap'          => self::MAX_URLS,
			'totals'       => $totals,
			'by_post_type' => $by_type,
			'schema_types' => $schema,
		);
	}

	/**
	 * The last finished scan, or null when none has finished.
	 *
	 * @return array|null
	 */
	public function result() {
		$result = get_option( self::RESULT_OPTION );

		return is_array( $result ) && ! empty( $result ) ? $result : null;
	}
}
