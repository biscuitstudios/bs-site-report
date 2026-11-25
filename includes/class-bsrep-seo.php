<?php
/**
 * Search engine facts that only exist inside WordPress.
 *
 * Whether search engines are allowed to index the home page. Two things can
 * stop them: WordPress's own "Discourage search engines from indexing this
 * site" setting, and a noindex set on the home page in SEOPress.
 *
 * Read only. Core options and post meta are read with get_option() and
 * get_post_meta(). SEOPress's own functions are never called: its decision
 * function, seopress_titles_noindex_bypass(), depends on the main query
 * (is_singular(), is_home()) and adds hreflang filters as it goes, and its
 * content analysis writes. So its rules are mirrored here instead, from
 * SEOPress 10.4, inc/functions/options-titles-metas.php and
 * src/Services/Options/TitleOption.php. Added in 0.1.10, Jason, November 25,
 * 2026 ("Yes build them").
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects whether search engines may index the site.
 */
class Bsrep_Seo {

	/**
	 * SEOPress's Titles and Metas option. Options::KEY_OPTION_TITLE in SEOPress.
	 */
	const SEOPRESS_TITLES = 'seopress_titles_option_name';

	/**
	 * SEOPress's module switches. Titles and Metas must be on for SEOPress to
	 * print a robots tag at all.
	 */
	const SEOPRESS_TOGGLE = 'seopress_toggle';

	/**
	 * SEOPress's per-post noindex. 'yes' means noindex.
	 */
	const SEOPRESS_POST_NOINDEX = '_seopress_robots_index';

	/**
	 * Collect the seo section.
	 *
	 * @return array
	 */
	public function collect() {
		return array(
			'search_engines_allowed' => $this->search_engines_allowed(),
		);
	}

	/**
	 * The search_engines_allowed block.
	 *
	 * @return array
	 */
	public function search_engines_allowed() {
		$checked_at = gmdate( 'c' );

		try {
			$blog_public = $this->blog_public();
			$home        = $this->home_noindex();
		} catch ( \Throwable $e ) {
			return array(
				'available'  => false,
				'reason'     => 'Could not read whether search engines are allowed: ' . $e->getMessage()
					. ' Nothing is reported for this block rather than guessing.',
				'checked_at' => $checked_at,
			);
		}

		$causes = array();
		if ( ! $blog_public ) {
			$causes[] = 'WordPress: Settings, Reading, "Discourage search engines from indexing this site" is on.';
		}
		if ( true === $home['value'] ) {
			$causes = array_merge( $causes, $home['causes'] );
		}

		return array(
			'available'    => true,
			'source'       => $home['source'],
			'allowed'      => $blog_public && true !== $home['value'],
			'blog_public'  => $blog_public,
			'home_noindex' => $home['value'],
			'blocked_by'   => $causes,
			'checked_at'   => $checked_at,
		);
	}

	/**
	 * WordPress's blog_public setting, as a bool.
	 *
	 * '1' is public and '0' is "Discourage". Anything else is not a value
	 * WordPress writes, so it is refused rather than read as either.
	 *
	 * @return bool
	 * @throws RuntimeException When the value is missing or unknown.
	 */
	protected function blog_public() {
		$value = get_option( 'blog_public', null );

		if ( null === $value || false === $value ) {
			throw new RuntimeException( 'The blog_public option is missing.' );
		}

		$value = (string) $value;

		if ( '1' === $value ) {
			return true;
		}
		if ( '0' === $value ) {
			return false;
		}

		throw new RuntimeException( 'The blog_public option holds "' . $value . '", which is not 0 or 1.' );
	}

	/**
	 * Whether SEOPress sets the home page to noindex.
	 *
	 * Mirrors seopress_titles_noindex_bypass() for the home page only:
	 * - Static front page (is_singular): password protected, the global noindex,
	 *   the noindex for its post type, or its own _seopress_robots_index.
	 * - Latest posts (is_home): the global noindex, or _seopress_robots_index on
	 *   the page set as the posts page, if one is set.
	 * SEOPress prints none of this unless its Titles and Metas module is on.
	 * The seopress_titles_noindex_bypass filter cannot be read outside a page
	 * request and is not covered.
	 *
	 * @return array{value:bool|null,causes:string[],source:string}
	 * @throws RuntimeException When the front page setting points nowhere.
	 */
	protected function home_noindex() {
		$core = 'WordPress blog_public option, get_option().';

		if ( ! defined( 'SEOPRESS_VERSION' ) ) {
			return array(
				'value'  => null,
				'causes' => array(),
				'source' => $core . ' SEOPress is not active, so home_noindex is not read.',
			);
		}

		$source = $core . ' SEOPress ' . SEOPRESS_VERSION . ' settings and post meta, read with get_option() and get_post_meta(), never SEOPress functions.';

		$toggle = get_option( self::SEOPRESS_TOGGLE );
		if ( ! is_array( $toggle ) || '1' !== (string) ( $toggle['toggle-titles'] ?? '' ) ) {
			return array(
				'value'  => false,
				'causes' => array(),
				'source' => $source . ' Its Titles and Metas module is off, so it prints no robots tag.',
			);
		}

		$titles = get_option( self::SEOPRESS_TITLES );
		$titles = is_array( $titles ) ? $titles : array();
		$causes = array();

		if ( ! empty( $titles['seopress_titles_noindex'] ) ) {
			$causes[] = 'SEOPress: Titles and Metas, Advanced, noindex is on for the whole site.';
		}

		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0 ) {
			$id   = (int) get_option( 'page_on_front' );
			$post = get_post( $id );

			if ( ! $post ) {
				throw new RuntimeException( 'The static front page is set to post ' . $id . ', which does not exist.' );
			}

			if ( '' !== (string) $post->post_password ) {
				$causes[] = 'SEOPress: the front page is password protected, which SEOPress marks noindex.';
			}

			$single = $titles['seopress_titles_single_titles'] ?? array();
			$type   = (string) $post->post_type;
			if ( is_array( $single ) && ! empty( $single[ $type ]['noindex'] ) ) {
				$causes[] = 'SEOPress: Titles and Metas, Single post types, noindex is on for ' . $type . '.';
			}

			if ( 'yes' === get_post_meta( $id, self::SEOPRESS_POST_NOINDEX, true ) ) {
				$causes[] = 'SEOPress: the front page ("' . $post->post_title . '") has "Do not display this page in search engine results" ticked.';
			}
		} else {
			$posts_page = (int) get_option( 'page_for_posts' );

			if ( $posts_page > 0 && ! empty( get_post_meta( $posts_page, self::SEOPRESS_POST_NOINDEX, true ) ) ) {
				$causes[] = 'SEOPress: the posts page has "Do not display this page in search engine results" ticked.';
			}
		}

		return array(
			'value'  => ! empty( $causes ),
			'causes' => $causes,
			'source' => $source,
		);
	}
}
