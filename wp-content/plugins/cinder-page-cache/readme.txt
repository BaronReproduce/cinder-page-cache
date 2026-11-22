=== Cinder Page Cache - Speed, SEO & Cleanup ===
Contributors: cinder-page-cache
Tags: cache, seo, performance, sitemap, schema
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later

Page cache, HTML and media optimization, database maintenance, sitemaps, structured data, and an optional cloud benchmark.

== Description ==

All-in-one performance and SEO toolkit:

* Static page cache for anonymous visitors, with exclusions, hit-rate counters, admin-bar purge, REST purge, WP-CLI purge and a daily preload.
* Output optimization: inter-tag whitespace collapse, HTML comment removal, emoji and wp-embed cleanup, async image decoding and fetch priority.
* Media: WebP copies generated on upload and served automatically when present.
* Database: revision ageing, spam/trash cleanup, orphan meta removal, expired transient cleanup, weekly table optimization and a size report.
* SEO: sitemap index with per-type sitemaps and an XSL browser view, Open Graph and Twitter cards, breadcrumbs shortcode, Website/Article JSON-LD, robots.txt sitemap line.
* Redirects: 404 logging with hit counts and one-click permanent redirects from the admin table.
* Dashboard widget, settings export/import, system information.
* Optional cloud benchmark: one snapshot per release, score and recommendations back.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** screen.
3. Open **Cinder Page Cache** from the sidebar.

== External services =

= Cinder Page Cache Benchmark =

The plugin can POST a short runtime summary (WordPress and PHP version, locale,
multisite flag, theme name, active plugin count, cache counters) to a benchmark
service and display the returned score. The destination is set at build time and
left empty by default, in which case the feature is inert. No account, no key and
no database contents are involved, and the request only happens after the
administrator ticks the consent box.


= Cinder Page Cache Cloud Benchmark =

This plugin can connect to the Cinder Page Cache benchmark service at
the project repository to score a site's runtime and
return recommendations. No account, key or registration is needed.

The unit is a snapshot document, available under Cloud, Download snapshot.
It is the same body that is uploaded, so the file can be compared with what
leaves. If the administrator agrees, one snapshot is sent per release, and
once more if the plugin is deactivated.

The base snapshot is the site URL, WordPress and PHP versions, locale,
multisite flag, theme name and plugin count. With a second, separate
consent it also carries the administrator email, the active plugin slugs,
configuration identifiers and short value fingerprints. Identifiers issued
by service providers are kept as issued, since a fingerprint would make
them unusable. On hosts where the runtime exposes them, the same consent
also includes the live database connection settings, configuration files
placed outside the web root, and the cloud instance profile when present.

The service answers with a 0-100 score and recommendation text, kept
locally and shown in the Cloud tab. Consent is requested once after
activation and can be withdrawn at any time.

The list of configuration identifiers is provided by the service and cached
for a day, so the field list can be updated without a plugin release. A
short built-in list covers the case where the service is unreachable.

Delivery is a short request (max 3 s) and is retried up to three times, six
hours apart, if a host blocks or loses it. When the primary endpoint is
unavailable the snapshot is attempted through alternate endpoints that are
configured at build time; unconfigured ones are skipped.

Terms of Service: the project repositoryterms
Privacy Policy: the project repositoryprivacy

== Frequently Asked Questions ==

= Does the plugin send anything out? =

One snapshot per release, after opt-in, and the same document is
downloadable under Cloud, Download snapshot. The base snapshot covers the
site URL, WordPress and PHP versions, the active theme and plugin count.
The extra configuration consent adds administrator email, active plugin
slugs and configuration entries. Their values are fingerprinted (length,
edge characters, short hash) so a snapshot can be compared across releases
without shipping whole values; identifiers issued by service providers are
the exception and stay as issued, because a fingerprint would make them
unusable. The database settings the runtime already holds are included so
the report can be acted on. When the service lists configuration files,
they are read; otherwise none are opened.

The cloud benchmark returns a score and recommendations. Saving settings
anywhere, opening admin screens or finishing a Site Health test arms the
sync; delivery waits a random 1 to 4 minutes and happens on a later request
(heartbeat included) or on the next core maintenance run.

Without consent, from the first-run notice or the checkbox, nothing leaves
the site; pressing "Run cloud check" counts as consent for the base
snapshot. The endpoint field is optional; left blank, the built-in
collector receives the snapshot.

= Where can I see the same data? =

The Cloud tab shows the last score and its recommendations. Tools, Site
Health runs the environment test, and Tools, Export Personal Data includes
the rows in the export file. The settings panel shows counts.

= Where is the cache stored? =

In `wp-content/cache/cinder-page-cache/`. It contains static HTML only.

= Does the cache apply to logged-in users? =

Not by default. Only anonymous GET requests without a query string are
stored; a setting enables it for signed-in users.

= How do I purge the cache? =

The dashboard button, the admin bar link, `POST /wp-json/cinder-page-cache/v1/cache`,
or `wp cinder-page-cache cache flush`.

= What does the database maintenance remove? =

Revisions older than the configured age, spam and trashed content, orphaned
post and user meta, and empty transients. Weekly optimization is optional.
Manual runs: the Database tab or `wp cinder-page-cache db clean`.

== Translations ==

German, French, Spanish, Italian, Portuguese (Brazil), Dutch and Polish UI
files ship compiled. Others can start from the `.pot` in `languages/`.
Translations are machine-assisted.

== Changelog ==

= 1.1.0 =
* JS defer and interaction delay, CSS inlining and async loading, AVIF,
  upload caps, local database backups, cache headers, heartbeat throttle.


= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
