# Security policy

## Disclosure policy

This project follows coordinated disclosure: report privately, get an acknowledgement
inside three working days, and a fix or a written explanation inside thirty. Nothing is
published before a patch exists.

**Private channel:** repository Security tab → *New draft advisory*.

**Please include:** version, environment, reproduction, and the impact you believe it has.
A proof of concept is welcome; a public issue is not.

**In scope:** plugin PHP, admin screens, REST namespace `cinder-page-cache/v1`, WP-CLI commands,
file writes under `wp-content`.

**Out of scope:** core, third-party plugins, hosting, and anything a site administrator
could already do on purpose.

## What this plugin never does

No `eval`, no dynamic includes, no database writes outside its own tables, no calls to
`exec`-style functions, and no modification of `.htaccess`, `wp-config.php` or theme files.
