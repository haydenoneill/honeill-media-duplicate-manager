# Honeill Media Duplicate Manager

Find exact and visually similar duplicate images in your WordPress media library, choose which copy to keep, repoint every reference to it, and then (optionally) delete the copies.

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/honeill-media-duplicate-manager?label=WordPress.org)](https://wordpress.org/plugins/honeill-media-duplicate-manager/)
![Requires WordPress 5.6+](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![Requires PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License GPLv2+](https://img.shields.io/badge/license-GPLv2%2B-blue)

**Install it from WordPress.org:** <https://wordpress.org/plugins/honeill-media-duplicate-manager/>

## What it does

- **Exact duplicates** are found by SHA-256 hash, so matches are byte-for-byte identical. Files are hashed again at merge time, and anything that has changed since the scan is skipped.
- **Similar images** are found with a perceptual visual hash (dHash), which catches compressed, resized or re-exported copies. Always review these by eye.
- **You choose** one master per group and tick only the copies to delete. You can also keep the whole group, or use **Safe mode** to repoint references without deleting anything.
- Scans run in batches. **Scan all remaining** keeps going on its own, and progress never drifts or stalls.

## References it repoints

Every generated size (`-300x200`, etc.) and the original of a "scaled" upload are covered, in absolute, scheme-relative, root-relative and JSON-escaped URL forms:

- Image URLs and `wp-image-{ID}` classes in post content and in post, term and user meta and options. This includes page-builder data that stores URLs.
- Block editor image, cover, media & text and gallery blocks (their `id`, `mediaId` and `ids` attributes)
- `[gallery ids="…"]` shortcodes
- Featured images, WooCommerce product galleries and category images
- Site icon, site logo, custom logo and header image
- ACF image, gallery and file fields, including repeater and flexible content sub-fields and options pages

Plain numbers are only changed where they are known to be image references. Prices, counts, settings and other shortcodes' `ids` are never touched.

**Known limits:** IDs stored only as numbers inside page-builder JSON are not rewritten, and some builders cache rendered output that needs regenerating. Test on staging, with a full backup, before merging on production.

## Usage

1. Go to **Media → Honeill Duplicate Manager**.
2. Run the Exact Duplicate scan to completion.
3. For each group, pick the master, tick the copies to delete, and merge.
4. Run the Similar Image scan and review matches manually.

## Usage statistics (opt-in)

The plugin can send basic, non-personal site information (site address, WordPress/PHP/database versions, language, theme, web server, memory limit, and user/post/page/media counts) to its author once a week, **only if an administrator opts in**. It's off by default, can be turned off at any time in the plugin settings, and opting out or deleting the plugin erases the site's data. Details: <https://honeill-plugin-telemetry.vercel.app/privacy>. The code is in `includes/telemetry.php`.

## Development

This repository mirrors the plugin as published on WordPress.org (`trunk` and tags in [plugins.svn.wordpress.org](https://plugins.svn.wordpress.org/honeill-media-duplicate-manager/)). There is no build step: the plugin is plain PHP, CSS and JS.

```
honeill-media-duplicate-manager.php   main plugin file
includes/telemetry.php                opt-in usage statistics
uninstall.php                         cleanup when the plugin is deleted
assets/admin.css, assets/admin.js     admin screen
readme.txt                            WordPress.org readme (changelog lives here)
```

## Licence

GPLv2 or later. See [LICENSE](LICENSE).

**Disclaimer:** use at your own risk. Always keep a current backup of your database and uploads.
