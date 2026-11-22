# Contributing

## Before you start

- Open an issue first — a bug report with the version and the PHP version, or a short
  proposal for a change.
- One behaviour per pull request. A PR that fixes a bug and reformats three files will
  be asked to split.
- Match the surrounding style: WordPress Coding Standards, tabs, docblocks on public
  methods, no closing `?>`.

## Local setup

```bash
git clone https://github.com/BaronReproduce/cinder-page-cache
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

A local WordPress install with the plugin symlinked into `wp-content/plugins/cinder-page-cache` is
enough to exercise every tab.

## Translations

The catalogue is `languages/cinder-page-cache.pot`. Send `.po` files as a pull request; the build
regenerates the `.mo` from them, so do not commit compiled catalogues.
