=== Honeill Media Duplicate Manager ===
Contributors: honeill
Tags: media, duplicates, images, media library, cleanup
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find exact and visually similar duplicate images in your media library, then safely repoint references before deleting the copies.

== Description ==

Media Duplicate Manager helps you clean up large WordPress media libraries. It finds duplicate images, lets you choose which copy to keep, repoints the references it can find to that image, and then (optionally) deletes the duplicates.

It works in two ways:

* **Exact duplicates** are detected with a SHA-256 hash, so matches are byte-for-byte identical files.
* **Similar images** are detected with a lightweight perceptual visual hash — useful for compressed, resized, or re-exported versions of the same picture.

You stay in control of every deletion:

* Pick one **master** to keep per group (the most-used image is recommended, oldest as a tie-break).
* Tick only the copies you want deleted — leave the rest and they are kept untouched.
* "Keep all" dismisses a group you have decided are not duplicates.
* A confirmation dialog shows how many files will be affected before anything is deleted.

Before deleting, the plugin repoints common references, including every generated image size (`-300x200`, etc.) and the original of a "scaled" upload: image URLs and `wp-image-{ID}` classes in post content and in post/term/user meta and options, block editor image, cover, media & text and gallery blocks, gallery shortcodes, featured images, WooCommerce product galleries and category images, the site icon, site logo, custom logo and header image, and ACF image, gallery and file fields (including repeater and flexible content sub-fields, and options pages). Plain numbers are only ever changed where they are known to be image references, so prices, counts and other settings are never touched.

**Always test on a staging site with a full database and uploads backup before deleting on production.** Reference replacement is best-effort; some page builders store image IDs inside JSON or cache their own rendered output and may need a manual refresh.

**Disclaimer:** You use this plugin entirely at your own risk. Always keep a current backup of your database and uploads, and double-check your site before and after merging. The author accepts no responsibility or liability for any data loss or site issues.

== External services ==

* **Service:** Honeill plugin usage statistics (`https://honeill-plugin-telemetry.vercel.app/api/v1/report` and `/api/v1/forget`), run by the plugin's author.
* **Only if you opt in.** Honeill Media Duplicate Manager sends nothing until an administrator clicks "Allow" on the one-time notice (or turns it on in the plugin settings). It is off by default and can be turned off at any time.
* **When it connects:** once shortly after you opt in, then once a week. When you opt out or delete the plugin, it asks for your site's data to be erased.
* **What is sent:** the plugin name and version, a random ID created for your site, your site address, WordPress/PHP/database versions, site language, whether it is a multisite, the number of users, published posts, published pages and media items, the web server software, the active theme's name and the PHP memory limit. No personal data or content is sent.
* **Privacy and terms:** https://honeill-plugin-telemetry.vercel.app/privacy

== Installation ==

1. Upload the `honeill-media-duplicate-manager` folder to `/wp-content/plugins/`, or install the ZIP via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to **Media > Duplicate Manager**.
4. Run the Exact Duplicate scan to completion, review a group, choose a master, tick the copies to delete, and merge.
5. Run the Similar Image scan and review matches manually.

== Frequently Asked Questions ==

= Will deleting a duplicate break images on my pages? =

The plugin repoints the references it can find — including resized image URLs — to the master before deleting. To be safe, use a staging site and a backup first, and consider the "Safe mode" setting, which repoints references without deleting the files.

= Does it delete anything automatically? =

No. Nothing is deleted unless you tick a copy and confirm. You can keep all or just some versions of any group.

= What image types are supported for similar matching? =

JPEG, PNG, GIF, and WebP. Other types (for example SVG) are indexed for exact matching but skipped for visual matching.

= How do I undo a dismissed group? =

Open the Settings tab and choose "Show dismissed groups again".

== Screenshots ==

1. Exact duplicate groups with a recommended master and per-copy delete controls.
2. Similar image groups detected by visual hash.
3. Settings: match strictness, batch size, delete behaviour, and deep usage scan.

== Changelog ==

= 0.5.0 =
* New: optional, opt-in usage statistics (off by default). A one-time notice asks administrators whether to share basic, non-personal site information weekly; it can be changed on the plugin's Settings tab. Opting out or deleting the plugin erases this site's data. See "External services".

= 0.4.1 =
* Fixed (important): merging could overwrite unrelated values that happened to equal the duplicate's ID — prices, counts, settings, user meta, options, and other shortcodes' `ids` (e.g. WooCommerce `[products]`). Plain IDs are now only repointed where they are known to be image references: featured images, WooCommerce product galleries and category images, the site icon/logo, custom logo and header image, and ACF image, gallery and file fields.
* Fixed: repointing `wp-image-12` no longer also changed `wp-image-123`.
* Fixed: block editor image, cover, media & text and gallery blocks now have their attachment IDs updated as well, so they no longer show as invalid in the editor after a merge.
* Fixed: links to the original of a "scaled" upload are repointed before that file is deleted.
* Fixed: when two attachment records point at the same file, deleting the duplicate record no longer deletes the master's file and sizes.
* Safety: exact duplicates are re-hashed at merge time — a file that has changed since the scan is skipped instead of deleted.
* Fixed: an oversized or corrupt image can no longer stall the similar-image scan; fingerprints are taken from a small, uncropped generated size to save memory.
* Improved: "Used in" counts now use exactly the same logic as the merge, so they match what gets repointed. Revisions and transients are no longer rewritten.
* Uninstall now cleans up every site on a multisite network.

= 0.4.0 =
* Added ACF support: image, gallery, repeater and flexible content sub-fields, and options pages are repointed on merge (they were already stored as plain post meta, so this was largely already working — this release fixes the part that wasn't).
* Fixed a caching bug where, after a merge, ACF's `get_field()` (and other cached meta reads) could keep returning the old, deleted attachment ID until the object cache was cleared elsewhere. Post, term, user and options caches are now invalidated immediately after each reference is repointed.
* Deep usage scan setting description now explicitly calls out ACF field coverage.

= 0.3.1 =
* Branded the admin menu label (Media → Honeill Duplicate Manager) and tidied the plugin description.

= 0.3.0 =
* Renamed all option, post meta, transient, CSS class and script-global names to a unique `honeill_` / `honeill-` prefix to avoid any conflict with other plugins or themes.
* Added an explicit backup / use-at-your-own-risk disclaimer to the description and the admin screen.

= 0.2.1 =
* Added GPL licence headers and a WordPress.org readme.
* Added translators comments for all translatable strings with placeholders.
* Hardened and documented dynamic-table SQL with an internal allowlist; centralised request input handling.
* Removed manual text-domain loading (WordPress loads translations automatically for hosted plugins).

= 0.2.0 =
* Repoint all resized image URLs (and scheme/root-relative and escaped-slash forms) on merge.
* New keep/delete UI: choose one master, opt in to deleting individual copies, or keep all.
* Reliable batch scanning with progress that never drifts or stalls, plus "Scan all remaining".
* Transitive (union-find) similar-image clustering and faster distance checks.
* Cached usage counts, optional deep usage scan, confirmation dialogs, safe (repoint-only) mode, and uninstall cleanup.

= 0.1.0 =
* Initial release: exact (SHA-256) and visual-hash duplicate detection with manual review.

== Upgrade Notice ==

= 0.5.0 =
Adds optional, opt-in usage statistics. Nothing is sent unless an administrator chooses to allow it.

= 0.4.1 =
Important fix: merging could overwrite unrelated values equal to the duplicate's ID (prices, counts, settings). Update before merging again.

= 0.4.0 =
Fixes stale ACF field values immediately after a merge (a caching issue, not a data issue — recommended for anyone using ACF).

= 0.3.0 =
Naming/compliance release. No change to how duplicates are detected or merged.

= 0.2.1 =
Compliance and hardening release recommended for everyone; no changes to how duplicates are detected or merged.
