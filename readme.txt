=== Biscuit Site Report ===
Contributors: biscuitstudios
Tags: reporting, maintenance, analytics, accessibility
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
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
