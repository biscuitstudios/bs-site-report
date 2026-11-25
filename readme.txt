=== Biscuit Site Report ===
Contributors: biscuitstudios
Tags: reporting, maintenance, analytics, accessibility
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collects the site facts that only exist inside WordPress, logs every update as it happens, and pushes a signed summary to Biscuit.

== Description ==

See the README on GitHub for the full description, requirements, and known
limitations: https://github.com/biscuitstudios/bs-site-report

Built and maintained by Biscuit Studios for our own client sites. Published
as-is, with no support. Forks welcome.

Nothing is shown to site visitors and nothing is served over HTTP. The plugin
speaks outward only: it pushes to a URL you configure, and a site that is not
configured sends nothing anywhere.

== Installation ==

1. Download the zip from the Releases page on GitHub.
2. Plugins > Add New > Upload Plugin.
3. Activate.
4. Tools > Biscuit Site Report.

== Changelog ==

= 0.1.11 =
* Changed: the Receiver URL field is full width, and on a new install it is
  filled in with Biscuit's receiver and used straight away, so a site pushes
  without anyone typing the address. Clearing the field and saving still means
  nothing is sent. A site that already saved an address keeps it.

= 0.1.10 =
* Added: seo.search_engines_allowed. Reads WordPress's "Discourage search
  engines" setting (blog_public) and whether SEOPress sets the home page to
  noindex, with get_option() and get_post_meta() only. SEOPress's own rules are
  mirrored, not called: the global noindex, the noindex for the front page's
  post type, the front page's own setting, a password, or the posts page's
  setting when the front shows latest posts, and only while its Titles and Metas
  module is on. home_noindex is null when SEOPress is not active. blocked_by
  names each setting that stops indexing. Anything unreadable is
  available:false with a reason.

= 0.1.9 =
* Changed: analytics.top_pages is read from Independent Analytics' own tables,
  counted the way its Pages screen counts, and covers every published page,
  post and project, the static front page included. 404s, the posts page,
  archives, author pages, and anything deleted or not published are left out.
  Status, title, type and permalink are checked against WordPress at collect
  time in one query. Ranked by views, then visitors, then title; top 10. Rows
  gain type and url, and the block gains post_types. iawp_top_posts() is no
  longer called: it covers one post type per call and cannot do this.

= 0.1.8 =
* Changed: analytics.last_12_months covers the same window as monthly and the
  0.1.7 blocks, the first of the month eleven months back through today, and
  says so in from and to. It was a rolling year back from today, six days
  longer at the start on November 25, 2026.
* Changed: analytics.prior_12_months is the matching window one year earlier,
  with from and to, and never shares a day with the current window (on
  February 29 a year back lands on March 1).
* Changed: analytics.cities sends the top 25 cities, not 5, ties still broken
  by city name, so the report can drop data-center towns and still fill its
  card. Shape and visitors_with_city unchanged.

= 0.1.7 =
* New: analytics.devices, analytics.cities, analytics.referrers and
  analytics.ai_referrals are read off Independent Analytics' own tables, read
  only, and counted the way its own Devices, Cities, Referrers and Referrer
  Types screens count. Each block carries the window it covers as from and to.
* Each block is available:false with a reason, never zero, when the IA
  database version is not one this was written against (53), a migration is
  running, IA's data pruning has cut into the window, a table is missing or a
  query fails.
* Changed: analytics.top_pages now covers the same window as the monthly
  series (first of the month eleven months back, through today) and says so
  in from and to. It used a rolling twelve months before, unlabelled.

= 0.1.6 =
* New: the accessibility section carries images_empty_alt, the open
  Accessibility Checker "Image Empty Alternative Text" issues (rule
  img_alt_empty), with the same filter as images_missing_alt: this site only,
  ignored and globally ignored left out, null with a reason when it cannot be
  measured. WordPress writes alt="" when alt text is cleared, so this, not the
  missing count, is where an image with no description shows up.
  img_linked_alt_empty is left out on purpose: it flags the link around an
  image img_alt_empty has already flagged.
* New: images_empty_alt_in_content, the same count limited to images carrying
  a wp-image-<number> class, which WordPress adds to images inserted from the
  media library and theme markup does not. Whole class tokens are matched, so
  a look-alike such as not-wp-image-5 does not count.
* images_missing_alt is unchanged. payload_version stays 1.

= 0.1.5 =
* New: the accessibility section carries images_missing_alt, the open
  Accessibility Checker issues for "Image Missing Alternative Text" and
  "Linked Image Missing Alternative Text" together (rules img_alt_missing
  and img_linked_alt_missing). Counted the way Accessibility Checker's own
  site summary counts them: this site only, ignored and globally ignored
  issues left out. Read only, from its issues table. When the table is
  missing or the query fails, the value is null with a reason, never 0.
* New: tests/test-accessibility.php runs the real query on an SQLite copy of
  the table, one fixture either side of each line. Added to CI.

= 0.1.4 =
* A Git working copy is never offered an update. "Update now" replaces the
  whole plugin folder with the release zip, .git included, and WordPress's
  background updates skip a checkout but a manual click does not. The
  updater now checks for a .git folder or file beside the main file and
  offers nothing, and asks GitHub nothing, when it finds one. Client sites
  install from the release zip, which has no .git, so they are unaffected.
* tests/test-updater.php drives the guard through the
  update_plugins_github.com filter both ways: with .git, as a .git file,
  through a symlinked folder, and without .git as the control.

= 0.1.3 =
* The structure scan now shares its 600 URL cap across post types instead of
  keeping the first 600 of one list. The old order gave every slot to posts on
  a site with more than 600 of them, so pages, events and products were never
  scanned, while the result still read as a sample of the whole site. Types
  now take turns: a type with few URLs is scanned in full and the room it
  leaves goes to the types with more.
* The published count per type is now the real total, not the length of a
  list that stops at 600, so the dropped count is no longer understated.
* The scan result carries "sampling": "shared" and "sampled_by_type", the
  published and queued count for every post type. A result without them came
  from the old slice and must not be read as a sample of the site.

= 0.1.2 =
* Security hardening: the updater now pins its download URL to this plugin's
  own GitHub repository. find_zip_asset() used to hand the WordPress upgrader
  whatever browser_download_url the GitHub API returned, and that URL becomes
  code the upgrader installs. Not a reachable bug, because GitHub only ever
  returns repo-hosted asset URLs, but it is the one path where a wrong
  assumption about a response would be arbitrary code. The fallback branch is
  pinned too, because it was a second way in.
* sslverify is now explicit on the GitHub API call. WordPress defaults it to
  true, so nothing changes today, but a filter on a site could flip it and
  nothing here would notice.

= 0.1.1 =
* Fix: a throwing Independent Analytics no longer kills the whole payload.
  Its bundled database layer opens its own connection rather than going
  through $wpdb, so it can throw where $wpdb would not. Nothing caught it,
  and one vendor being down meant no report at all rather than a report with
  one section marked unavailable. The analytics section now degrades to
  available:false with the reason attached.
* Fix: the analytics adapter no longer falls back to zeros. An all-zero month
  is indistinguishable from a real quiet month by the time it reaches a
  client's page, and a vendor returning an unreadable shape is a schema
  change rather than a site with no visitors. Both now report unavailable.
* New: tests/test-analytics.php, 17 assertions against a stubbed vendor that
  throws, returns garbage, returns null and behaves. Added to CI.

= 0.1.0 =
* First build. Logs every plugin, theme and core version change as it happens,
  with the version before and after, into its own table.
* Two observers on updates, not one. The WordPress upgrader hooks are precise
  and know who did it; a twice-daily version sweep catches anything applied
  over SSH, by WP-CLI, or by the host's own tooling. A twelve hour window drops
  the double when both see the same update.
* The first sweep records nothing and stores a baseline instead, so activation
  day does not report an install event for every plugin already on the site.
* Reads Independent Analytics through its three documented PHP functions only,
  never its tables.
* Counts Gravity Forms entries through GFAPI, last twelve months against the
  prior twelve.
* Counts accounts by capability rather than by role name, because Biscuit sites
  carry custom roles.
* Structure scan over the site's own published URLs: heading order, landmarks,
  alt text, structured data, page descriptions. Its user agent says "bot" so
  Independent Analytics ignores it and the scan cannot inflate the site's own
  visitor numbers.
* Anything not measured is reported as null with a reason, never as zero. A
  zero and an unknown render identically and only one of them is true.
* Self-updating from GitHub Releases, ported from bs-maintenance.
