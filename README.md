# Biscuit Site Report

Collects the facts about a WordPress site that live inside WordPress and nowhere
else, logs every plugin, theme and core update as it happens, and pushes a signed
summary to a URL you choose.

Built and maintained by [Biscuit Studios](https://biscuitstudios.com/) for our
own client sites. Published because it may be useful to others, not because it is
a supported product. See [Support](#support).

## Why it exists

A managed host's API reports the current state of a site. It cannot tell you what
was done to the site over the last year, how many enquiries the contact form
produced, how many accounts can log in, or whether the headings on the pages are
in an order a screen reader or a crawler can follow. All of that is inside
WordPress. This plugin reads it and sends it out.

## What it collects

| Section | Source |
|---|---|
| Updates applied, with the version before and after | Its own table, from install day forward |
| Current plugins, themes, core, pending updates | `get_plugins()` and the update transients |
| People, sessions and top pages | Independent Analytics' three documented PHP functions |
| Form submissions, this year against last | `GFAPI::count_entries()` |
| Accounts that can edit, administrators, two-factor state | `count_users()` and `WP_User_Query` |
| Accessibility issues | Accessibility Checker's summary meta, where it is active |
| Heading order, landmarks, alt text, structured data | Its own scan of the site's published URLs |

## The one rule it will not bend

**Anything not measured is reported as `null` with a reason beside it, never as
zero.** A zero and an unknown render identically on a page and only one of them
is true.

Failed logins are the worked example. WordPress core does not record them, so the
payload says nothing records them on this site. A zero there would read as
"nobody tried", which is the opposite of what is known.

Every payload also carries `log_coverage`, giving the date of the oldest logged
event and whether the log covers a full year, so a twelve month figure can never
be presented off a six week log.

## How updates are counted

Two observers, on purpose.

The WordPress upgrader hooks are precise and know who applied the update, but
only fire when the update went through WordPress's own upgrader. A twice-daily
version sweep diffs a stored map against reality and catches everything else:
WP-CLI, SFTP, a host's own tooling, a plugin swapped by hand.

Both see a single update, so a twelve hour window drops the duplicate. Without it
every count would be exactly double.

The first sweep after activation records nothing and stores a baseline instead.
Otherwise day one reports an install event for every plugin already on the site.

## What it deliberately does not do

**Nothing is served.** There is no endpoint that hands the payload to whoever
asks. The plugin speaks outward only, to a URL you configure, and a site with no
URL configured sends nothing anywhere. The payload describes a site's login
posture, and a pull route would be one new piece of attack surface per install.

**Nothing is shown to visitors.** No front end output, no dashboard widget, no
admin notice. One screen under Tools, administrators only.

**It does not read another vendor's database tables.** Independent Analytics is
read through its published PHP functions only, so a schema change there makes
this plugin go quiet rather than wrong. The consequence is that referrer and AI
traffic data, which that plugin shows in its own dashboard and does not expose,
is reported as unavailable rather than guessed at.

**Its scan identifies itself as a bot.** Independent Analytics ignores
self-identifying bots. A weekly scan that quietly added several hundred visits to
a site's own visitor count would corrupt the number the report exists to show.

## Requirements

WordPress 6.4 or later, PHP 8.2 or later. Gravity Forms, Independent Analytics
and Accessibility Checker are all optional; each section reports itself
unavailable when its plugin is not there.

## Installation

Download the zip from the Releases page, then Plugins > Add New > Upload Plugin.

Updates come from GitHub Releases from then on, but the first install on any site
is manual: a site without this plugin has no code to ask GitHub anything.

## Usage

Tools > Biscuit Site Report. Or over WP-CLI:

    wp bsrep payload --pretty    # print the payload as JSON
    wp bsrep scan                # run the structure scan to completion
    wp bsrep sweep               # check for version changes now
    wp bsrep coverage            # what the log covers
    wp bsrep push                # send to the configured receiver

## Support

None. This is published as-is, with no support commitment, and only the latest
release is maintained. Security reports are the exception: see
[SECURITY.md](SECURITY.md).

Forks welcome.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
