=== ZeroPress Static ===
Contributors: laelbe
Tags: static site, publish, export, github, zeropress
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Publish WordPress content as a static site with ZeroPress and GitHub.

== Description ==

Keep WordPress as your editor and publish a static site with
[ZeroPress WXR Starter](https://github.com/zeropress-app/zeropress-starter-wxr).
ZeroPress Static prepares a WordPress export (WXR) and build settings. Review the
result, then download the files or publish them to GitHub in one commit. Your
configured build service builds and deploys the static site.

= Features =

* Publish posts and pages with selected classic menus and media references.
* Downloads for building without a GitHub connection.
* Media URL replacements, including the WordPress Site Icon.
* Search, RSS, archives, footer credits and newsletter signup links.
* Optional WordPress comments, with separate display and writing settings and Cloudflare Turnstile support.
* English and Korean admin screens, section navigation and independent save buttons.

All options are in ZeroPress → Settings. The Site section shows the title,
description, language, timezone, posts per page and icon read from WordPress.
Save settings, then open ZeroPress → Publish to prepare the files.

The plugin replaces wordpress-export.xml and wxr-import-base.json in the root of
your selected repository branch. Manage their settings in the plugin. Building
and hosting are configured in the WXR starter project.

Supports WordPress 6.8+, PHP 8.2+, and single-site installations.

= External services =

GitHub API (https://api.github.com) is used for connection tests, preparation with
a configured connection, and confirmed publication. A token authenticates the
requests. Publication sends the WXR content and generated build settings.
There is no telemetry or scheduled synchronization.

Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

Cloudflare Turnstile is used when selected for comment writing. The static theme
loads https://challenges.cloudflare.com/turnstile/v0/api.js. WordPress sends the
widget token and Secret Key to https://challenges.cloudflare.com/turnstile/v0/siteverify
for verification, without the optional visitor-IP parameter. PoW and read-only
comments do not use this service.

Terms: https://www.cloudflare.com/terms/
Privacy: https://www.cloudflare.com/privacypolicy/

Newsletter settings connect to signup pages from a service you choose, such as
Hostinger Reach, Omnisend or Mailchimp. The URLs are exported for the static theme;
the selected service handles subscriptions, mail delivery and submitted visitor data.

= Support =

See the [usage guide](https://github.com/zeropress-app/zeropress-static#readme)
for settings and API-access details. Use
[GitHub Issues](https://github.com/zeropress-app/zeropress-static/issues) for
questions, bug reports and feature requests. Include the plugin, WordPress and
PHP versions and steps to reproduce. Remove credentials and personal data from
attachments.

Report security vulnerabilities through
[private vulnerability reporting](https://github.com/zeropress-app/zeropress-static/security/advisories/new).

== Installation ==

= Automatic installation =

Install ZeroPress Static from the WordPress Plugin Directory:

1. In your WordPress admin, open **Plugins → Add New Plugin**.
2. Search for **ZeroPress Static**.
3. On the ZeroPress Static result, select **Install Now**, then **Activate**.
4. Open **ZeroPress → Settings** to configure the plugin.

= Manual installation =

1. Download the installation ZIP from the [GitHub releases](https://github.com/zeropress-app/zeropress-static/releases).
2. In your WordPress admin, open **Plugins → Add New Plugin → Upload Plugin**.
3. Select the downloaded ZIP, choose **Install Now**, then **Activate Plugin**.
4. Open **ZeroPress → Settings** to configure the plugin.

= First publication =

1. Set up a project from the WXR starter linked above. For GitHub publishing, keep wordpress-export.xml and wxr-import-base.json in the root of an existing branch.
2. Open ZeroPress → Settings, configure the sections you need, and save them.
3. To use **Publish to GitHub**, save the repository URL and branch under GitHub publishing. Register a fine-grained token for only that repository with Contents: Read and write permission, then test the connection. The branch field defaults to main.
4. Open ZeroPress → Publish, prepare publishing data, and review the result. Download the files or confirm Publish to GitHub.

== Frequently Asked Questions ==

= Is GitHub required? =

No. You can prepare and download both files, then use them in a WXR starter
project yourself. **Publish to GitHub** requires a repository, an existing
branch and a registered token.

= Is a static site URL required? =

It is required when comment writing is enabled. Otherwise, you can leave it
blank. The default build then omits sitemap.xml, feed.xml and the
`<link rel="canonical">` tag that identifies the page's preferred URL.
RSS also needs this URL even when its checkbox is enabled.

= Can WordPress and the static site use different addresses? =

Yes. With a static site URL set, the exported WXR uses it for the site addresses,
post and page links, and menu links within the WordPress site's address. Paths
relative to the WordPress home address are retained, removing any installation
subdirectory. Queries and fragments are preserved. With the setting blank, the
WXR retains the WordPress addresses.

Links within article bodies keep their saved addresses. Media URLs follow the
media replacement rules, and comments use the configured WordPress REST API URL.

= What does Allow search engine crawling do? =

When unchecked (the default), Disallow: / is written to the static site's
robots.txt. Enabling it writes Allow: /. A custom public/robots.txt in the build
project overrides the generated default. Access to a private site is managed
by your hosting.

= Which content is exported? =

Published posts and pages without passwords, their used categories and tags,
public author display names, selected classic menus and required media metadata.
Drafts, private or scheduled content, comment records, login names, user email
addresses and arbitrary post metadata are excluded.

Saved HTML and static block markup are exported. Shortcodes, dynamic blocks and
page builders are not executed. Custom post types and block navigation are not
converted. Excerpts and taxonomy descriptions use plain text.

= What limits apply to preparation and publication? =

Each preparation, download and publication is a separate request. Downloads and
publication regenerate the data. The plugin requests WordPress's administrator
memory allowance (`WP_MAX_MEMORY_LIMIT`) and checks the effective PHP
`memory_limit`, allowing headroom for data processing and GitHub transport.
Increase the server memory allowance if the export needs more memory.

A positive PHP `max_execution_time` gives each request a shared wall-clock budget
of that duration minus five seconds, including WordPress startup and GitHub
communication. A value of `0` leaves the overall budget unlimited. PHP-FPM,
web server and proxy limits apply separately.

GitHub requests use the remaining budget, or default to 30 seconds each when PHP
has no time limit. Developers can adjust that timeout using the
`zeropress_static_github_request_timeout` filter; a finite overall budget still
applies. See the repository README for an example.

Resource errors show the applicable limits. If a publication response is lost,
check GitHub before preparing again.

= How do menus work? =

In Settings → Menus, assign classic WordPress menus to primary, footer or custom
IDs used by your static theme. Only selected menus are exported. Create and edit
them with WordPress's classic menu editor; the plugin's assignments are
independent of WordPress theme locations.

= How are media URLs and the Site Icon handled? =

Media replacement rules change matching URL prefixes in the exported content
and media metadata. The longest matching source wins, and each value is replaced
once. Ordinary article links retain their addresses. Media files remain at their
existing locations; the replacement URLs must serve those files to site visitors.

The WordPress Site Icon supplies the favicon and Apple touch icon. Both URLs use
the saved replacement rules. With no Site Icon set, the build project's icon
defaults remain in place.

= Can visitors read comments without writing them? =

Yes. Enable comment display and leave writing disabled. The blog theme shows the
list without a form or reply buttons. Both options start off.

Writing requires a saved static site URL. It uses a proof-of-work check by
default, or Cloudflare Turnstile when selected and configured with keys for the
static site's hostname. WordPress's existing comment policies still apply.
The API must be accessible to visitors, even if the WordPress admin is private.

Comment display and API URL changes require republishing. Writing and Turnstile
settings apply when the theme next checks the writing policy. The
[usage guide](https://github.com/zeropress-app/zeropress-static#api-access)
lists the required API paths and origin checks.

Each IP address is limited to 60 challenge requests and 5 ZeroPress comment or
reply submission attempts per minute, across all posts. Failed verification
attempts count, and limits apply before PoW or Turnstile verification. Quotas
reset at each minute boundary. Requests over the limit receive HTTP 429 and a
retry delay; comment reading is unaffected. Behind a proxy, the trusted web
server must supply the visitor's IP address in PHP's REMOTE_ADDR to give each
visitor a separate quota. Temporary counters use a keyed hash of the address.

= Does the plugin send newsletters? =

No. Newsletter connection displays a signup prompt for a page you prepare with
a newsletter service. Supply a signup page URL, an embedded signup page URL or
both. The service handles subscriptions and mail delivery. The embedded page
must allow iframe display.

= Can I publish again without changing content? =

Yes. When the data matches the previous publication, no further publication is
needed. To publish intentionally, expand Republish options and select Publish to
GitHub again. This updates the WXR generation time and creates a new commit.
Post dates and build settings remain unchanged. This can trigger your
repository's build service.

= What happens after an uncertain GitHub response? =

The plugin checks the result once without repeating the write. If it reports
Publication needs verification, inspect the repository before preparing and
publishing again.

= How are credentials and settings stored? =

GitHub tokens and Turnstile secrets use authenticated encryption derived from
WordPress secrets. Their plaintext is not returned to the admin screen. If the
WordPress secrets change, register the credentials again. Deactivation retains
configuration; uninstall removes the plugin's settings, credentials and
temporary verification records.

== Screenshots ==

1. Prepare publishing data, review the result, and publish to GitHub.
2. Settings navigation and site information from WordPress.
3. Control WordPress comment display, writing, and bot protection.
4. Configure newsletter connection, display and credits.

== Changelog ==

= 0.7.0 =
* Initial WXR publication, media replacements and optional WordPress comments.
* Static site features: search, RSS, archives, menu selection, footer information and newsletter signup.
