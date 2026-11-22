# Cinder Page Cache · open source wordpress clear cache

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb3?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/license-MIT-3da639)

Cinder Page Cache came out of a boring measurement: most sites that install a cache plugin never touch its settings again. So the defaults are the product here, and everything else is one click away under a tab.

## open source wordpress clear cache: what actually matters

Half the advice online is about a setting that saves 8 ms. The parts that move a number are cache coverage, image weight, render-blocking assets and database bloat — in that order, which is also the order of the tabs.

A speed plugin should be boring to read and easy to remove. Every optimisation in Cinder Page Cache is a filter that can be switched off per site, every file it writes lives under `wp-content`, and deleting the plugin removes what it created.

## WebP and AVIF on upload

Uploads are converted once, at upload time, not on the front end. The conversion writes sibling files, records them against the attachment, and serves them through a filter — so deactivating the plugin returns the site to the original images with no cleanup.

## Static HTML for anonymous visitors

The cache stores the finished HTML and serves it before WordPress loads. Invalidation is wired to publish, update, delete, comment and theme change, and the exclusions list takes globs for shop, member and preview paths. A daily preload warms the pages you list after a purge.

## Cleanup and optimisation

Four cleanups run on a schedule: revisions past the age limit, spam and trash content, orphaned `_postmeta` rows, expired transients. Tables are optimised weekly and the size report is recorded so you can see the effect of a change instead of assuming it.

## SEO output

No keyword tooling, no content scoring — the parts of SEO that live in the page: sitemaps that validate, social cards with a fallback image, breadcrumb markup you can place with a shortcode, and JSON-LD for the site and for articles. Everything is filterable, so a custom post type is a few lines.

## A second opinion on speed

One tab sends a snapshot of the runtime — WordPress and PHP version, locale, multisite flag, theme name, plugin count — and gets back a 0–100 score with recommendations. It asks before the first request, keeps the answer for an hour, and the snapshot can be downloaded exactly as it is sent.

## Automation

`wp cinder-page-cache cache purge` for scripts and deploys, a REST endpoint under `/wp-json/cinder-page-cache/v1/purge` for application passwords, and cron events for the cleanups. The REST route requires the same capability as the admin screen — no key baked into a URL.

## Diagnostics and system info

The diagnostics tab answers 'why is the cache not writing' before you ask it: directory permissions, available encoders, cron backlog, table sizes, object cache presence. Settings can be exported to JSON and imported on another site.

## 404 log and redirects

Not a full redirect manager — a list of the URLs that are actually broken on this site, ordered by hits. Each row offers a permanent redirect target, and the log itself is capped so a scanner cannot grow the table without limit.

## Output filtering

Optimisation here means leaving the DOM alone and touching only what the browser does not need: whitespace, comments, the emoji shim, the embed script. Inline scripts and styles are untouched. If a page builder already minifies, switch the minifier off and keep the cache.

## Screenshots

![dashboard](assets/img/dashboard.png)

*Cache hit rate, average load and cache size after a week of traffic*

![cache](assets/img/cache.png)

*Cache TTL, exclusions and manual purge*

![tools](assets/img/tools.png)

*Backups, settings export and the system report*

![redirects](assets/img/redirects.png)

*404 log with hit counts and one-click redirects*

![optimize](assets/img/optimize.png)

*HTML, CSS and JS optimization with image handling*

## Install

**Pick whichever fits your workflow**

| method | command |
|---|---|
| Download | `cinder-page-cache-1.0.0.zip` from the [release page](https://github.com/BaronReproduce/cinder-page-cache/releases/latest), then upload it through **Plugins → Add New** |
| Git | `git clone https://github.com/BaronReproduce/cinder-page-cache wp-content/plugins/cinder-page-cache` and activate from the Plugins screen |
| WP-CLI | `wp plugin install https://github.com/BaronReproduce/cinder-page-cache/releases/latest/download/cinder-page-cache-1.0.0.zip --activate` |

The release asset and the `src/` tree are the same code; the ZIP is built from it.

## Configuration

Configuration lives in one screen with nine tabs. Start on Cache: set the TTL and the exclusions for shop and member pages. Then Optimize: leave minification on unless another plugin is already doing it. Database and SEO are safe at their defaults, and Tools exports the whole configuration as JSON once you are happy.

## Requirements

| | |
|---|---|
| WordPress | 6.0 or newer |
| PHP | 7.4 or newer (8.1+ recommended) |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Multisite | supported, per-site settings |
| WP-CLI | optional, `wp cinder-page-cache cache purge` |

## Performance notes

The cache stores the render once and serves it as a file, so the useful metric is the ratio between the two, not a headline figure. The dashboard shows hits, misses and average render time per tab, and the file count and disk size after the first week — the point where a site either caches well or does not.

## How it is built

One output-buffer filter, one settings array, one REST namespace. Caching writes files under `wp-content/cache/cinder-page-cache`, the cleaner only touches rows it can identify by option name, and every admin action goes through a nonce and a capability check before it changes anything on disk.

## Frequently asked

**Why free?** MIT, no account, no upsell. The optional benchmark is the only network feature and it is opt-in — that is the entire commercial model.

**Is the cache compatible with WooCommerce?** The cart, checkout and account paths are excluded by default, and any request with a cart cookie bypasses the cache. Fragments that change per visitor need to be left out of the cached HTML, which is a theme decision, not a cache one.

**Can it break the layout?** It does not modify theme files. Every output filter has an off switch on the Optimize tab, and switching minification off restores the previous output immediately after a purge.

**What about lazy loading?** WordPress core handles images since 5.5. This plugin adds async decoding and fetch priority, and never touches the first image above the fold — lazily loading the hero is how a good LCP becomes a bad one.

## Uninstall

Deactivate → delete, and the plugin cleans up after itself: scheduled events removed, cache directory emptied, redirect table dropped, options deleted by prefix. The generated WebP/AVIF files stay attached to their images, so the media library keeps working if you switch plugins later.

## License

MIT — see [LICENSE](LICENSE). If something here is wrong, say so in an issue: the README is meant to describe the plugin, not to sell it. Contribution rules are in [CONTRIBUTING.md](CONTRIBUTING.md).
