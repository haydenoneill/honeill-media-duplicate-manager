<?php
/**
 * Plugin Name: Honeill Media Duplicate Manager
 * Description: Clean up a bloated media library in minutes. Finds exact (SHA-256) and visually similar duplicate images, lets you choose which copies to keep, and repoints every reference before deleting the rest.
 * Version: 0.4.1
 * Author: Hayden O'Neill
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: honeill-media-duplicate-manager
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * Media Duplicate Manager is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option) any
 * later version. It is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Honeill_Media_Duplicate_Manager
{
    public const VERSION = '0.4.1';

    private const SHA_META = '_honeill_sha256';
    private const VISUAL_META = '_honeill_visual_hash';
    private const VISUAL_SKIP_META = '_honeill_visual_skipped';
    private const SCANNED_META = '_honeill_scanned_at';

    private const OPT_DISTANCE = 'honeill_visual_distance';
    private const OPT_BATCH = 'honeill_batch_size';
    private const OPT_DEEP_USAGE = 'honeill_deep_usage';
    private const OPT_DELETE_MODE = 'honeill_delete_mode';
    private const OPT_IGNORED = 'honeill_ignored_signatures';

    private const DEFAULT_BATCH_SIZE = 75;
    private const DEFAULT_VISUAL_DISTANCE = 6;

    /** Hard cap on distinct visual hashes compared per render (keeps the O(n^2) pass bounded). */
    private const MAX_DISTINCT_HASHES = 4000;
    /** Max groups shown per tab. */
    private const MAX_GROUPS = 100;
    /** Soft time budget (seconds) for a single scan request. */
    private const SCAN_TIME_BUDGET = 20;

    /** @var array<int,int> Per-request usage memo. */
    private static $usage_memo = array();

    public static function boot(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_post_honeill_scan_exact', array(__CLASS__, 'scan_exact'));
        add_action('admin_post_honeill_scan_visual', array(__CLASS__, 'scan_visual'));
        add_action('admin_post_honeill_merge_duplicates', array(__CLASS__, 'merge_duplicates'));
        add_action('admin_post_honeill_dismiss_group', array(__CLASS__, 'dismiss_group'));
        add_action('admin_post_honeill_reset_ignored', array(__CLASS__, 'reset_ignored'));
        add_action('admin_post_honeill_save_settings', array(__CLASS__, 'save_settings'));
    }

    public static function register_menu(): void
    {
        add_media_page(
            __('Media Duplicate Manager', 'honeill-media-duplicate-manager'),
            __('Honeill Duplicate Manager', 'honeill-media-duplicate-manager'),
            'manage_options',
            'honeill-media-duplicate-manager',
            array(__CLASS__, 'render_page')
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'media_page_honeill-media-duplicate-manager') {
            return;
        }

        wp_enqueue_style(
            'honeill-media-duplicate-manager',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'honeill-media-duplicate-manager',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            array(),
            self::VERSION,
            true
        );

        wp_localize_script(
            'honeill-media-duplicate-manager',
            'honeill',
            array(
                /* translators: %d: number of files selected for deletion. */
                'confirmDelete' => __('You are about to permanently delete %d file(s). Their references will be repointed to the master first. This cannot be undone. Continue?', 'honeill-media-duplicate-manager'),
                /* translators: %d: number of files whose references will be repointed. */
                'confirmRepoint' => __('You are about to repoint references for %d file(s) to the master. Files will NOT be deleted. Continue?', 'honeill-media-duplicate-manager'),
                'confirmDismiss' => __('Mark this group as "not duplicates" so it stops appearing?', 'honeill-media-duplicate-manager'),
                'nothingPicked' => __('Tick at least one copy to delete, or use "Keep all".', 'honeill-media-duplicate-manager'),
                'masterLabel' => __('Master — kept', 'honeill-media-duplicate-manager'),
                'keepLabel' => __('Set as master', 'honeill-media-duplicate-manager'),
                'deleteMode' => self::get_delete_mode(),
            )
        );
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'honeill-media-duplicate-manager'));
        }

        $tab = self::get_key('tab', 'exact');

        if (!in_array($tab, array('exact', 'similar', 'settings'), true)) {
            $tab = 'exact';
        }

        echo '<div class="wrap honeill-wrap">';
        echo '<h1>' . esc_html__('Media Duplicate Manager', 'honeill-media-duplicate-manager') . '</h1>';
        echo '<p class="honeill-muted">' . esc_html__('Always back up your database and uploads and double-check results before and after merging. Use entirely at your own risk — no liability is accepted for any data loss.', 'honeill-media-duplicate-manager') . '</p>';
        self::render_notices();
        self::render_tabs($tab);

        if ($tab === 'similar') {
            self::render_similar_tab();
        } elseif ($tab === 'settings') {
            self::render_settings_tab();
        } else {
            self::render_exact_tab();
        }

        echo '</div>';
    }

    private static function render_notices(): void
    {
        $notice = self::get_key('honeill_notice');

        if ($notice === '') {
            return;
        }

        $count = self::get_int('honeill_count');

        $messages = array(
            'exact_batch' => __('Exact duplicate scan batch complete.', 'honeill-media-duplicate-manager'),
            'visual_batch' => __('Similar image scan batch complete.', 'honeill-media-duplicate-manager'),
            'scan_complete' => __('Scan complete — every image has been indexed.', 'honeill-media-duplicate-manager'),
            'settings_saved' => __('Settings saved.', 'honeill-media-duplicate-manager'),
            /* translators: %d: number of duplicates merged and deleted. */
            'merged' => sprintf(_n('%d duplicate was merged and deleted.', '%d duplicates were merged and deleted.', max(1, $count), 'honeill-media-duplicate-manager'), $count),
            /* translators: %d: number of files whose references were repointed. */
            'repointed' => sprintf(_n('References for %d file were repointed to the master. Files were kept.', 'References for %d files were repointed to the master. Files were kept.', max(1, $count), 'honeill-media-duplicate-manager'), $count),
            'nothing_selected' => __('Nothing was selected to delete.', 'honeill-media-duplicate-manager'),
            'group_dismissed' => __('Group marked as "not duplicates".', 'honeill-media-duplicate-manager'),
            'ignored_reset' => __('Dismissed groups have been cleared.', 'honeill-media-duplicate-manager'),
        );

        if (!isset($messages[$notice])) {
            return;
        }

        $type = in_array($notice, array('nothing_selected'), true) ? 'warning' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function render_tabs(string $active): void
    {
        $tabs = array(
            'exact' => __('Exact Duplicates', 'honeill-media-duplicate-manager'),
            'similar' => __('Similar Images', 'honeill-media-duplicate-manager'),
            'settings' => __('Settings', 'honeill-media-duplicate-manager'),
        );

        echo '<nav class="nav-tab-wrapper honeill-tabs">';

        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab %s">%s</a>',
                esc_url(self::admin_url($slug)),
                $active === $slug ? 'nav-tab-active' : '',
                esc_html($label)
            );
        }

        echo '</nav>';
    }

    private static function render_exact_tab(): void
    {
        echo '<section class="honeill-panel">';
        echo '<h2>' . esc_html__('Exact Duplicate Scan', 'honeill-media-duplicate-manager') . '</h2>';
        echo '<p>' . esc_html__('Exact duplicates are files with the same SHA-256 hash — byte-for-byte identical files. Their WordPress attachment IDs may still be used in different places, so references are repointed before deleting.', 'honeill-media-duplicate-manager') . '</p>';
        self::render_scan_form('honeill_scan_exact', self::SHA_META);
        echo '</section>';

        self::render_groups(self::get_exact_duplicate_groups(), 'exact');
    }

    private static function render_similar_tab(): void
    {
        $distance = self::get_visual_distance();

        echo '<section class="honeill-panel">';
        echo '<h2>' . esc_html__('Similar Image Scan', 'honeill-media-duplicate-manager') . '</h2>';
        echo '<p>' . esc_html__('Similar images are matched by visual hash distance. Always review these manually before deleting — different crops or edits can look alike.', 'honeill-media-duplicate-manager') . '</p>';
        self::render_scan_form('honeill_scan_visual', self::VISUAL_META, self::VISUAL_SKIP_META);
        echo '</section>';

        $capped = false;
        self::render_groups(self::get_similar_groups($distance, $capped), 'similar');

        if ($capped) {
            /* translators: %d: maximum number of distinct image signatures compared per pass. */
            echo '<section class="honeill-empty"><p>' . esc_html(sprintf(__('Note: only the first %d distinct image signatures were compared this pass. Tighten "Visual match strictness" to narrow results on very large libraries.', 'honeill-media-duplicate-manager'), self::MAX_DISTINCT_HASHES)) . '</p></section>';
        }
    }

    private static function render_settings_tab(): void
    {
        $distance = self::get_visual_distance();
        $batch = self::get_batch_size();
        $deep = self::is_deep_usage();
        $delete_mode = self::get_delete_mode();
        $ignored = self::get_ignored_signatures();

        echo '<section class="honeill-panel">';
        echo '<h2>' . esc_html__('Settings', 'honeill-media-duplicate-manager') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('honeill_save_settings');
        echo '<input type="hidden" name="action" value="honeill_save_settings">';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="honeill_visual_distance">' . esc_html__('Visual match strictness', 'honeill-media-duplicate-manager') . '</label></th><td>';
        echo '<input id="honeill_visual_distance" type="number" min="1" max="20" name="visual_distance" value="' . esc_attr((string) $distance) . '">';
        echo '<p class="description">' . esc_html__('Lower is stricter. Start at 6. Increase only if too few similar images are found.', 'honeill-media-duplicate-manager') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="honeill_batch_size">' . esc_html__('Scan batch size', 'honeill-media-duplicate-manager') . '</label></th><td>';
        echo '<input id="honeill_batch_size" type="number" min="10" max="300" name="batch_size" value="' . esc_attr((string) $batch) . '">';
        echo '<p class="description">' . esc_html__('Images processed per request. Lower this if scans time out on large images or slow hosting.', 'honeill-media-duplicate-manager') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('When deleting duplicates', 'honeill-media-duplicate-manager') . '</th><td>';
        echo '<fieldset>';
        echo '<label><input type="radio" name="delete_mode" value="delete"' . checked($delete_mode, 'delete', false) . '> ' . esc_html__('Repoint references, then permanently delete the duplicate files (default).', 'honeill-media-duplicate-manager') . '</label><br>';
        echo '<label><input type="radio" name="delete_mode" value="repoint"' . checked($delete_mode, 'repoint', false) . '> ' . esc_html__('Safe mode: repoint references only and keep the files (delete them yourself later).', 'honeill-media-duplicate-manager') . '</label>';
        echo '</fieldset>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Deep usage scan', 'honeill-media-duplicate-manager') . '</th><td>';
        echo '<label><input type="checkbox" name="deep_usage" value="1"' . checked($deep, true, false) . '> ' . esc_html__('Also scan options, user meta and term meta when counting usage — this includes ACF fields (image, gallery, repeater/flexible content sub-fields, and options pages). More accurate "recommended master", but slower on big sites.', 'honeill-media-duplicate-manager') . '</label>';
        echo '</td></tr>';

        echo '</table>';
        submit_button(__('Save Settings', 'honeill-media-duplicate-manager'));
        echo '</form>';
        echo '</section>';

        if (!empty($ignored)) {
            echo '<section class="honeill-panel">';
            echo '<h2>' . esc_html__('Dismissed groups', 'honeill-media-duplicate-manager') . '</h2>';
            /* translators: %d: number of duplicate groups currently hidden. */
            echo '<p>' . esc_html(sprintf(_n('%d group is hidden as "not duplicates".', '%d groups are hidden as "not duplicates".', count($ignored), 'honeill-media-duplicate-manager'), count($ignored))) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('honeill_reset_ignored');
            echo '<input type="hidden" name="action" value="honeill_reset_ignored">';
            submit_button(__('Show dismissed groups again', 'honeill-media-duplicate-manager'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</section>';
        }
    }

    private static function render_scan_form(string $action, string $done_meta, string $skip_meta = ''): void
    {
        $total = self::count_image_attachments();
        $done = self::count_meta_rows($done_meta);
        $skipped = $skip_meta ? self::count_meta_rows($skip_meta) : 0;
        $remaining = max(0, $total - $done - $skipped);
        $auto = self::get_flag('honeill_auto') && $remaining > 0;

        $pct = $total > 0 ? (int) floor((($done + $skipped) / $total) * 100) : 100;

        echo '<form class="honeill-scan-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($auto ? ' data-honeill-autosubmit="1"' : '') . '>';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        if ($auto) {
            echo '<input type="hidden" name="scan_all" value="1">';
        }

        echo '<div class="honeill-progress" aria-hidden="true"><span style="width:' . esc_attr((string) $pct) . '%"></span></div>';
        /* translators: 1: number of images indexed so far, 2: total number of images. */
        echo '<p><strong>' . esc_html(sprintf(__('Indexed %1$d of %2$d images', 'honeill-media-duplicate-manager'), min($done + $skipped, $total), $total)) . '</strong>';
        if ($skipped > 0) {
            /* translators: %d: number of images skipped because they could not be hashed. */
            echo ' <span class="honeill-muted">' . esc_html(sprintf(__('(%d skipped — not hashable)', 'honeill-media-duplicate-manager'), $skipped)) . '</span>';
        }
        echo '</p>';
        /* translators: %d: number of images still to be scanned. */
        echo '<p>' . esc_html(sprintf(__('Remaining: %d', 'honeill-media-duplicate-manager'), $remaining)) . '</p>';

        if ($remaining > 0) {
            submit_button(__('Scan next batch', 'honeill-media-duplicate-manager'), 'secondary', 'submit', false);
            echo ' ';
            submit_button(__('Scan all remaining', 'honeill-media-duplicate-manager'), 'primary', 'scan_all', false);
        } else {
            echo '<p class="honeill-muted">' . esc_html__('Nothing left to scan. Re-run after uploading new images.', 'honeill-media-duplicate-manager') . '</p>';
        }
        echo '</form>';
    }

    private static function render_groups(array $groups, string $mode): void
    {
        if (empty($groups)) {
            echo '<section class="honeill-empty"><p>' . esc_html__('No duplicate groups found. Run the scan above, then come back here.', 'honeill-media-duplicate-manager') . '</p></section>';
            return;
        }

        foreach ($groups as $signature => $ids) {
            $items = self::build_group_items($ids);

            if (count($items) < 2) {
                continue;
            }

            usort($items, array(__CLASS__, 'sort_items_by_keep_priority'));
            $recommended_master = (int) $items[0]['id'];

            echo '<section class="honeill-group">';
            echo '<div class="honeill-group__header"><div>';
            echo '<h2>' . esc_html($mode === 'exact' ? __('Exact duplicate group', 'honeill-media-duplicate-manager') : __('Possible duplicate group', 'honeill-media-duplicate-manager')) . '</h2>';
            echo '<p class="honeill-muted">' . esc_html__('Pick one master to keep. Tick only the copies you want deleted — leave the rest and they stay untouched.', 'honeill-media-duplicate-manager') . '</p>';
            echo '</div>';
            /* translators: %d: number of images in this duplicate group. */
            echo '<span class="honeill-badge">' . esc_html(sprintf(_n('%d image', '%d images', count($items), 'honeill-media-duplicate-manager'), count($items))) . '</span>';
            echo '</div>';

            // Merge form.
            echo '<form class="honeill-merge-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('honeill_merge_duplicates');
            echo '<input type="hidden" name="action" value="honeill_merge_duplicates">';
            echo '<input type="hidden" name="mode" value="' . esc_attr($mode) . '">';
            echo '<input type="hidden" name="signature" value="' . esc_attr((string) $signature) . '">';

            echo '<div class="honeill-grid">';
            foreach ($items as $item) {
                self::render_group_card($item, $recommended_master);
            }
            echo '</div>';

            echo '<div class="honeill-actions">';
            submit_button(__('Delete ticked copies', 'honeill-media-duplicate-manager'), 'primary', 'submit', false);
            echo '<button type="button" class="button button-link honeill-select-others">' . esc_html__('Tick all other copies', 'honeill-media-duplicate-manager') . '</button>';
            echo '<button type="button" class="button button-link honeill-clear">' . esc_html__('Clear ticks', 'honeill-media-duplicate-manager') . '</button>';
            echo '</div>';
            echo '</form>';

            // Dismiss ("keep all") form — separate so it never carries delete selections.
            echo '<form class="honeill-dismiss-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('honeill_dismiss_group');
            echo '<input type="hidden" name="action" value="honeill_dismiss_group">';
            echo '<input type="hidden" name="mode" value="' . esc_attr($mode) . '">';
            echo '<input type="hidden" name="signature" value="' . esc_attr((string) $signature) . '">';
            echo '<button type="submit" class="button button-link honeill-dismiss">' . esc_html__('Keep all — these are not duplicates', 'honeill-media-duplicate-manager') . '</button>';
            echo '</form>';

            echo '</section>';
        }
    }

    private static function render_group_card(array $item, int $recommended_master): void
    {
        $id = (int) $item['id'];
        $is_master = $id === $recommended_master;

        echo '<article class="honeill-card' . ($is_master ? ' is-master' : '') . '" data-id="' . esc_attr((string) $id) . '">';
        echo '<div class="honeill-card__image">' . wp_get_attachment_image($id, 'medium', false, array('loading' => 'lazy')) . '</div>';
        echo '<div class="honeill-card__body">';

        echo '<label class="honeill-radio"><input type="radio" name="keep_id" value="' . esc_attr((string) $id) . '"' . checked($is_master, true, false) . '> ';
        echo '<span class="honeill-radio__text">' . esc_html__('Set as master', 'honeill-media-duplicate-manager') . '</span></label>';

        echo '<label class="honeill-check"><input type="checkbox" name="merge_ids[]" value="' . esc_attr((string) $id) . '"' . disabled($is_master, true, false) . '> ';
        echo esc_html__('Delete this copy', 'honeill-media-duplicate-manager') . '</label>';

        echo '<dl>';
        echo '<dt>' . esc_html__('ID', 'honeill-media-duplicate-manager') . '</dt><dd>' . esc_html((string) $id) . '</dd>';
        echo '<dt>' . esc_html__('File', 'honeill-media-duplicate-manager') . '</dt><dd><code>' . esc_html($item['filename']) . '</code></dd>';
        echo '<dt>' . esc_html__('Size', 'honeill-media-duplicate-manager') . '</dt><dd>' . esc_html($item['size']) . '</dd>';
        echo '<dt>' . esc_html__('Uploaded', 'honeill-media-duplicate-manager') . '</dt><dd>' . esc_html($item['date']) . '</dd>';
        /* translators: %d: number of places this image is used. */
        $usage_text = sprintf(_n('%d place', '%d places', $item['usage'], 'honeill-media-duplicate-manager'), $item['usage']);
        echo '<dt>' . esc_html__('Used in', 'honeill-media-duplicate-manager') . '</dt><dd>' . esc_html($usage_text) . '</dd>';
        echo '</dl>';

        $edit = get_edit_post_link($id);
        if ($edit) {
            echo '<a href="' . esc_url($edit) . '" target="_blank" rel="noopener">' . esc_html__('Edit attachment', 'honeill-media-duplicate-manager') . '</a>';
        }
        echo '</div></article>';
    }

    /* ---------------------------------------------------------------------
     * Scanning
     * ------------------------------------------------------------------- */

    public static function scan_exact(): void
    {
        self::verify_action('honeill_scan_exact');

        $batch = self::get_batch_size();
        $ids = self::get_unscanned_image_ids(self::SHA_META, $batch);
        $deadline = time() + self::SCAN_TIME_BUDGET;
        $processed = 0;

        foreach ($ids as $attachment_id) {
            $file = get_attached_file($attachment_id);

            if ($file && is_readable($file)) {
                $hash = hash_file('sha256', $file);
                update_post_meta($attachment_id, self::SHA_META, $hash ?: '');
            } else {
                // Mark as visited so it is not retried forever.
                update_post_meta($attachment_id, self::SHA_META, '');
            }

            update_post_meta($attachment_id, self::SCANNED_META, current_time('mysql'));
            $processed++;

            if (time() >= $deadline) {
                break;
            }
        }

        self::redirect_after_scan('exact', self::SHA_META, '');
    }

    public static function scan_visual(): void
    {
        self::verify_action('honeill_scan_visual');

        $batch = self::get_batch_size();
        $ids = self::get_unscanned_image_ids(self::VISUAL_META, $batch, self::VISUAL_SKIP_META);
        $deadline = time() + self::SCAN_TIME_BUDGET;

        foreach ($ids as $attachment_id) {
            // Mark skipped BEFORE decoding: if an oversized or corrupt image kills the request,
            // the next batch moves past it instead of retrying it forever.
            update_post_meta($attachment_id, self::VISUAL_SKIP_META, 1);

            $file = self::visual_source_file($attachment_id);
            $hash = $file !== '' ? self::create_visual_hash($file) : null;

            if ($hash) {
                update_post_meta($attachment_id, self::VISUAL_META, $hash);
                delete_post_meta($attachment_id, self::VISUAL_SKIP_META);
            }
            // Otherwise un-hashable (SVG, corrupt, unsupported, too large) — it stays skipped.

            update_post_meta($attachment_id, self::SCANNED_META, current_time('mysql'));

            if (time() >= $deadline) {
                break;
            }
        }

        self::redirect_after_scan('similar', self::VISUAL_META, self::VISUAL_SKIP_META);
    }

    private static function redirect_after_scan(string $tab, string $done_meta, string $skip_meta): void
    {
        $total = self::count_image_attachments();
        $done = self::count_meta_rows($done_meta);
        $skipped = $skip_meta ? self::count_meta_rows($skip_meta) : 0;
        $remaining = max(0, $total - $done - $skipped);

        $auto = self::post_flag('scan_all');
        $args = array();

        if ($remaining <= 0) {
            $args['honeill_notice'] = 'scan_complete';
        } else {
            $args['honeill_notice'] = $tab === 'similar' ? 'visual_batch' : 'exact_batch';
            if ($auto) {
                $args['honeill_auto'] = 1;
            }
        }

        wp_safe_redirect(self::admin_url($tab, $args));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Settings actions
     * ------------------------------------------------------------------- */

    public static function save_settings(): void
    {
        self::verify_action('honeill_save_settings');

        $distance = self::post_int('visual_distance', self::DEFAULT_VISUAL_DISTANCE);
        update_option(self::OPT_DISTANCE, max(1, min(20, $distance)));

        $batch = self::post_int('batch_size', self::DEFAULT_BATCH_SIZE);
        update_option(self::OPT_BATCH, max(10, min(300, $batch)));

        $delete_mode = self::post_key('delete_mode') === 'repoint' ? 'repoint' : 'delete';
        update_option(self::OPT_DELETE_MODE, $delete_mode);

        update_option(self::OPT_DEEP_USAGE, self::post_flag('deep_usage') ? 1 : 0);

        wp_safe_redirect(self::admin_url('settings', array('honeill_notice' => 'settings_saved')));
        exit;
    }

    public static function reset_ignored(): void
    {
        self::verify_action('honeill_reset_ignored');
        delete_option(self::OPT_IGNORED);
        wp_safe_redirect(self::admin_url('settings', array('honeill_notice' => 'ignored_reset')));
        exit;
    }

    public static function dismiss_group(): void
    {
        self::verify_action('honeill_dismiss_group');

        $mode = self::post_key('mode', 'exact');
        $signature = self::post_text('signature');
        $tab = $mode === 'similar' ? 'similar' : 'exact';

        if ($signature !== '') {
            $ignored = self::get_ignored_signatures();
            $ignored[$signature] = time();
            update_option(self::OPT_IGNORED, $ignored);
        }

        wp_safe_redirect(self::admin_url($tab, array('honeill_notice' => 'group_dismissed')));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Merge / delete
     * ------------------------------------------------------------------- */

    public static function merge_duplicates(): void
    {
        self::verify_action('honeill_merge_duplicates');

        $mode = self::post_key('mode', 'exact');
        $tab = $mode === 'similar' ? 'similar' : 'exact';

        $keep_id = self::post_int('keep_id');
        $merge_ids = self::post_int_array('merge_ids');
        $merge_ids = array_values(array_filter(array_unique($merge_ids)));
        $merge_ids = array_values(array_diff($merge_ids, array($keep_id)));

        // Server-side validation: only delete attachments that really belong to this group.
        $merge_ids = self::filter_same_group($mode, $keep_id, $merge_ids);

        if (!$keep_id || get_post_type($keep_id) !== 'attachment' || empty($merge_ids)) {
            wp_safe_redirect(self::admin_url($tab, array('honeill_notice' => 'nothing_selected')));
            exit;
        }

        $delete_mode = self::get_delete_mode();
        $done = 0;
        $keep_file = (string) get_attached_file($keep_id);

        foreach ($merge_ids as $old_id) {
            if (get_post_type($old_id) !== 'attachment') {
                continue;
            }

            $old_file = (string) get_attached_file($old_id);

            // Stored hashes can be stale (a file replaced since the scan). Exact groups are only
            // merged when the files on disk are still byte-for-byte identical right now.
            if ($mode !== 'similar' && !self::files_still_identical($keep_id, $keep_file, $old_id, $old_file)) {
                continue;
            }

            self::replace_attachment_references($old_id, $keep_id);

            if ($delete_mode === 'delete') {
                // Two attachment records can point at the very same file (e.g. after an import).
                // Detach the duplicate record from it first so deleting it can't remove the
                // master's file or its generated sizes.
                if ($old_file !== '' && $keep_file !== '' && wp_normalize_path($old_file) === wp_normalize_path($keep_file)) {
                    delete_post_meta($old_id, '_wp_attached_file');
                    delete_post_meta($old_id, '_wp_attachment_metadata');
                    delete_post_meta($old_id, '_wp_attachment_backup_sizes');
                }
                wp_delete_attachment($old_id, true);
            }

            delete_transient('honeill_usage_' . $old_id);
            $done++;
        }

        // Kept image usage changed — drop its cached count.
        delete_transient('honeill_usage_' . $keep_id);

        wp_safe_redirect(self::admin_url($tab, array(
            'honeill_notice' => $delete_mode === 'delete' ? 'merged' : 'repointed',
            'honeill_count' => $done,
        )));
        exit;
    }

    /** Re-hash both files now; refresh the stored hash of any file that has changed since the scan. */
    private static function files_still_identical(int $keep_id, string $keep_file, int $old_id, string $old_file): bool
    {
        if ($keep_file === '' || $old_file === '' || !is_readable($keep_file) || !is_readable($old_file)) {
            return false;
        }

        $keep_hash = (string) hash_file('sha256', $keep_file);
        $old_hash = (string) hash_file('sha256', $old_file);

        foreach (array($keep_id => $keep_hash, $old_id => $old_hash) as $id => $hash) {
            if ($hash !== '' && $hash !== (string) get_post_meta($id, self::SHA_META, true)) {
                update_post_meta($id, self::SHA_META, $hash);
            }
        }

        return $keep_hash !== '' && $keep_hash === $old_hash;
    }

    /**
     * Keep only IDs that genuinely share the group with $keep_id.
     *
     * @param int[] $candidate_ids
     * @return int[]
     */
    private static function filter_same_group(string $mode, int $keep_id, array $candidate_ids): array
    {
        if (!$keep_id || empty($candidate_ids)) {
            return array();
        }

        if ($mode === 'similar') {
            $keep_hash = (string) get_post_meta($keep_id, self::VISUAL_META, true);
            if ($keep_hash === '') {
                return array();
            }
            $max = self::get_visual_distance();

            return array_values(array_filter($candidate_ids, static function (int $id) use ($keep_hash, $max): bool {
                $hash = (string) get_post_meta($id, self::VISUAL_META, true);
                return $hash !== '' && self::hamming_distance($keep_hash, $hash) <= $max;
            }));
        }

        $keep_hash = (string) get_post_meta($keep_id, self::SHA_META, true);
        if ($keep_hash === '') {
            return array();
        }

        return array_values(array_filter($candidate_ids, static function (int $id) use ($keep_hash): bool {
            return (string) get_post_meta($id, self::SHA_META, true) === $keep_hash;
        }));
    }

    /**
     * Repoint (or, with $dry_run, just count) every reference to $old_id.
     * Pass $new_id = 0 with $dry_run to count references without a target.
     *
     * @return int Number of rows (posts and meta/option rows) that reference $old_id.
     */
    private static function replace_attachment_references(int $old_id, int $new_id, bool $dry_run = false, bool $posts_only = false): int
    {
        $url_map = self::build_url_map($old_id, $new_id);
        $path_seed = self::path_seed($old_id);

        $count = self::replace_in_posts($old_id, $new_id, $url_map, $path_seed, $dry_run);

        if (!$posts_only) {
            foreach (self::meta_tables() as $t) {
                $count += self::replace_in_meta_table($t[0], $t[1], $t[2], $t[3], $t[4], $old_id, $new_id, $url_map, $path_seed, $dry_run);
            }
        }

        return $count;
    }

    /**
     * Fixed allowlist of core tables this plugin rewrites, with their id/key/value columns
     * plus the "owner id" column (the post/term/user this row's cache is keyed on; empty
     * string for options, which cache by option name instead).
     * All dynamic identifiers used in SQL below originate ONLY from this list, never from input.
     *
     * @return array<int,array{0:string,1:string,2:string,3:string,4:string}>
     */
    private static function meta_tables(): array
    {
        global $wpdb;

        return array(
            array($wpdb->postmeta, 'meta_id', 'meta_key', 'meta_value', 'post_id'),
            array($wpdb->termmeta, 'meta_id', 'meta_key', 'meta_value', 'term_id'),
            array($wpdb->usermeta, 'umeta_id', 'meta_key', 'meta_value', 'user_id'),
            array($wpdb->options, 'option_id', 'option_name', 'option_value', ''),
        );
    }

    private static function is_allowed_meta_table(string $table, string $id_column, string $key_column, string $value_column, string $owner_column): bool
    {
        foreach (self::meta_tables() as $t) {
            if ($t[0] === $table && $t[1] === $id_column && $t[2] === $key_column && $t[3] === $value_column && $t[4] === $owner_column) {
                return true;
            }
        }
        return false;
    }

    /**
     * Direct SQL updates to meta tables bypass update_post_meta()/update_option() etc.,
     * so WordPress's object cache is never told the value changed. Without this, ACF's
     * get_field() (and similar cached reads) can keep returning the old, now-deleted
     * attachment ID after a merge until something else happens to clear the cache.
     */
    private static function invalidate_meta_cache(string $table, ?int $owner_id, string $row_key): void
    {
        global $wpdb;

        if ($table === $wpdb->postmeta) {
            if ($owner_id) {
                clean_post_cache($owner_id);
            }
        } elseif ($table === $wpdb->termmeta) {
            if ($owner_id) {
                wp_cache_delete($owner_id, 'term_meta');
            }
        } elseif ($table === $wpdb->usermeta) {
            if ($owner_id) {
                clean_user_cache($owner_id);
            }
        } elseif ($table === $wpdb->options) {
            wp_cache_delete($row_key, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    private static function replace_in_posts(int $old_id, int $new_id, array $url_map, string $path_seed, bool $dry_run = false): int
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk content rewrite during a one-off merge; values are prepared and post caches are cleared.
        $like_path = $path_seed !== '' ? '%' . $wpdb->esc_like($path_seed) . '%' : '\0never\0';
        $like_path_esc = $path_seed !== '' ? '%' . $wpdb->esc_like(str_replace('/', '\\/', $path_seed)) . '%' : '\0never\0';
        $like_class = '%' . $wpdb->esc_like('wp-image-' . $old_id) . '%';
        $like_block = '%' . $wpdb->esc_like(':' . $old_id) . '%';

        // Revisions are history, not live content — leave them untouched.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                WHERE post_type <> 'revision'
                    AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR (post_content LIKE %s AND post_content LIKE %s))",
                $like_path,
                $like_path_esc,
                $like_class,
                '%' . $wpdb->esc_like('<!-- wp:') . '%',
                $like_block
            )
        );

        $count = 0;
        foreach ($rows as $row) {
            $content = self::replace_in_string((string) $row->post_content, $url_map, $old_id, $new_id);

            if ($content === $row->post_content) {
                continue;
            }

            $count++;
            if (!$dry_run) {
                $wpdb->update($wpdb->posts, array('post_content' => $content), array('ID' => (int) $row->ID));
                clean_post_cache((int) $row->ID);
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $count;
    }

    private static function replace_in_meta_table(string $table, string $id_column, string $key_column, string $value_column, string $owner_column, int $old_id, int $new_id, array $url_map, string $path_seed, bool $dry_run = false): int
    {
        global $wpdb;

        // Defence in depth: only ever operate on the fixed allowlist of core tables/columns.
        if (!self::is_allowed_meta_table($table, $id_column, $key_column, $value_column, $owner_column)) {
            return 0;
        }

        $like_path = $path_seed !== '' ? '%' . $wpdb->esc_like($path_seed) . '%' : '\0never\0';
        $like_path_esc = $path_seed !== '' ? '%' . $wpdb->esc_like(str_replace('/', '\\/', $path_seed)) . '%' : '\0never\0';
        $owner_select = $owner_column !== '' ? "{$owner_column} AS owner_id" : 'NULL AS owner_id';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$id_column/$key_column/$value_column/$owner_select come only from the is_allowed_meta_table() allowlist above (not user input); all values use prepared placeholders.
        // Candidate rows only: whether a bare ID is really an attachment reference is decided per row below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$id_column} AS row_id, {$key_column} AS row_key, {$value_column} AS row_value, {$owner_select}
                FROM {$table}
                WHERE {$key_column} NOT LIKE %s
                    AND (
                        {$value_column} = %s
                        OR {$value_column} LIKE %s
                        OR {$value_column} LIKE %s
                        OR {$value_column} LIKE %s
                        OR {$value_column} LIKE %s
                        OR {$value_column} LIKE %s
                        OR ({$key_column} = '_product_image_gallery' AND {$value_column} LIKE %s)
                    )",
                $wpdb->esc_like('_honeill_') . '%',
                (string) $old_id,
                '%i:' . $old_id . ';%',
                '%:"' . $old_id . '";%',
                $like_path,
                $like_path_esc,
                '%' . $wpdb->esc_like('wp-image-' . $old_id) . '%',
                '%' . $wpdb->esc_like((string) $old_id) . '%'
            )
        );

        $count = 0;
        foreach ($rows as $row) {
            $key = (string) $row->row_key;
            $owner_id = $row->owner_id !== null ? (int) $row->owner_id : null;

            // Transients are disposable caches — rewriting them is pointless.
            if ($table === $wpdb->options && (strpos($key, '_transient_') === 0 || strpos($key, '_site_transient_') === 0)) {
                continue;
            }

            $original = (string) $row->row_value;
            $value = maybe_unserialize($original);
            $mode = self::id_reference_mode($table, $key, $owner_id);
            $new_value = self::replace_in_value($value, $url_map, $old_id, $new_id, $mode);

            // Compare the final stored form against the raw DB value. This is correct even
            // for object-valued meta, where replace_in_value mutates in place (=== would miss it).
            $stored = is_serialized($original) ? maybe_serialize($new_value) : (is_scalar($new_value) ? (string) $new_value : maybe_serialize($new_value));

            if ($stored === $original) {
                continue;
            }

            $count++;
            if (!$dry_run) {
                $wpdb->update($table, array($value_column => $stored), array($id_column => (int) $row->row_id));
                self::invalidate_meta_cache($table, $owner_id, $key);
            }
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $count;
    }

    /**
     * Whether a meta/option row stores attachment IDs (and how). Bare numbers are only ever
     * rewritten for these known keys — anywhere else "42" may be a price, a count or a user ID.
     *
     * @return string 'none' | 'id' (single ID, or a list of IDs for ACF galleries) | 'csv' | 'theme_mods'
     */
    private static function id_reference_mode(string $table, string $key, ?int $owner_id): string
    {
        global $wpdb;

        if ($table === $wpdb->postmeta) {
            if ($key === '_thumbnail_id') {
                return 'id';
            }
            if ($key === '_product_image_gallery') {
                return 'csv';
            }
            $reference = $owner_id ? get_post_meta($owner_id, '_' . $key, true) : '';
        } elseif ($table === $wpdb->termmeta) {
            if ($key === 'thumbnail_id') {
                return 'id'; // WooCommerce category images.
            }
            $reference = $owner_id ? get_term_meta($owner_id, '_' . $key, true) : '';
        } elseif ($table === $wpdb->usermeta) {
            $reference = $owner_id ? get_user_meta($owner_id, '_' . $key, true) : '';
        } elseif ($table === $wpdb->options) {
            if ($key === 'site_icon' || $key === 'site_logo') {
                return 'id';
            }
            if (strpos($key, 'theme_mods_') === 0) {
                return 'theme_mods';
            }
            $reference = get_option('_' . $key, '');
        } else {
            return 'none';
        }

        return self::is_acf_attachment_field($reference) ? 'id' : 'none';
    }

    /** True when $reference is the key of an ACF image, gallery or file field (ACF stores it in the "_{name}" row). */
    private static function is_acf_attachment_field($reference): bool
    {
        static $memo = array();

        if (!is_string($reference) || strpos($reference, 'field_') !== 0 || !function_exists('acf_get_field')) {
            return false;
        }

        if (!isset($memo[$reference])) {
            $field = acf_get_field($reference);
            $memo[$reference] = is_array($field) && isset($field['type']) && in_array($field['type'], array('image', 'gallery', 'file'), true);
        }

        return $memo[$reference];
    }

    private static function replace_in_value($value, array $url_map, int $old_id, int $new_id, string $mode = 'none')
    {
        if ($mode === 'id') {
            if (self::is_id($value, $old_id)) {
                return is_int($value) ? $new_id : (string) $new_id;
            }
            if (is_array($value) && self::is_id_list($value)) {
                $list = array();
                foreach ($value as $item) {
                    $item = self::is_id($item, $old_id) ? (is_int($item) ? $new_id : (string) $new_id) : $item;
                    if (!in_array($item, $list, false)) {
                        $list[] = $item; // a gallery holding both copies keeps the master once
                    }
                }
                return $list;
            }
        } elseif ($mode === 'csv' && is_string($value)) {
            return self::replace_csv_id_string($value, $old_id, $new_id);
        } elseif ($mode === 'theme_mods' && is_array($value)) {
            if (isset($value['custom_logo']) && self::is_id($value['custom_logo'], $old_id)) {
                $value['custom_logo'] = is_int($value['custom_logo']) ? $new_id : (string) $new_id;
            }
            if (isset($value['header_image_data'])) {
                $header = $value['header_image_data'];
                if (is_object($header) && isset($header->attachment_id) && self::is_id($header->attachment_id, $old_id)) {
                    $header->attachment_id = $new_id;
                } elseif (is_array($header) && isset($header['attachment_id']) && self::is_id($header['attachment_id'], $old_id)) {
                    $value['header_image_data']['attachment_id'] = $new_id;
                }
            }
            // URLs inside theme mods (header image, background) are handled below.
        }

        return self::replace_urls_in_value($value, $url_map, $old_id, $new_id);
    }

    /** Recursively rewrite URLs and image markup in every string inside $value. Never touches bare numbers. */
    private static function replace_urls_in_value($value, array $url_map, int $old_id, int $new_id)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::replace_urls_in_value($item, $url_map, $old_id, $new_id);
            }
            return $value;
        }

        if (is_object($value)) {
            if ($value instanceof __PHP_Incomplete_Class) {
                return $value; // class not loaded — leave it exactly as stored
            }
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::replace_urls_in_value($item, $url_map, $old_id, $new_id);
            }
            return $value;
        }

        if (is_string($value)) {
            return self::replace_in_string($value, $url_map, $old_id, $new_id);
        }

        return $value;
    }

    private static function is_id($value, int $id): bool
    {
        return (is_int($value) && $value === $id) || (is_string($value) && $value === (string) $id);
    }

    private static function is_id_list(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                return false;
            }
        }
        return $value !== array() && array_keys($value) === range(0, count($value) - 1);
    }

    private static function replace_in_string(string $value, array $url_map, int $old_id, int $new_id): string
    {
        if (!empty($url_map)) {
            // strtr replaces longest keys first and never re-touches its own output,
            // so sized URLs (-WxH) are handled before the base URL.
            $value = strtr($value, $url_map);
        }

        // Exact class only: "wp-image-12" must never match inside "wp-image-123".
        $value = (string) preg_replace('/\bwp-image-' . $old_id . '(?!\d)/', 'wp-image-' . $new_id, $value);

        // [gallery ids="..."] only — other shortcodes' "ids" (e.g. WooCommerce products) are not images.
        $value = (string) preg_replace_callback('/\[gallery\b[^\]]*\]/i', static function (array $m) use ($old_id, $new_id): string {
            return (string) preg_replace_callback('/\bids=(["\'])([^"\']*)\1/', self::replace_gallery_ids_callback($old_id, $new_id), $m[0]);
        }, $value);

        // Block editor attributes, so the block still validates against its new wp-image-{id} markup.
        $value = (string) preg_replace_callback('/<!--\s+wp:(?:core\/)?(image|cover|media-text|gallery)\s+(\{.*?\})\s+(\/)?-->/s', static function (array $m) use ($old_id, $new_id): string {
            $json = (string) preg_replace('/"(id|mediaId)":' . $old_id . '(?!\d)/', '"$1":' . $new_id, $m[2]);
            $json = (string) preg_replace_callback('/"ids":\[([0-9,\s]*)\]/', static function (array $ids) use ($old_id, $new_id): string {
                $list = array_map('trim', explode(',', $ids[1]));
                $list = array_map(static function (string $id) use ($old_id, $new_id): string {
                    return $id === (string) $old_id ? (string) $new_id : $id;
                }, $list);
                return '"ids":[' . implode(',', array_values(array_unique($list))) . ']';
            }, $json);
            return str_replace($m[2], $json, $m[0]);
        }, $value);

        return $value;
    }

    private static function replace_csv_id_string(string $value, int $old_id, int $new_id): string
    {
        $ids = array_map('trim', explode(',', $value));
        $changed = false;

        foreach ($ids as $index => $id) {
            if ($id === (string) $old_id) {
                $ids[$index] = (string) $new_id;
                $changed = true;
            }
        }

        return $changed ? implode(',', array_values(array_unique($ids))) : $value;
    }

    private static function replace_gallery_ids_callback(int $old_id, int $new_id): callable
    {
        return static function (array $matches) use ($old_id, $new_id): string {
            $ids = array_map('trim', explode(',', $matches[2]));
            $ids = array_map(
                static function (string $id) use ($old_id, $new_id): string {
                    return $id === (string) $old_id ? (string) $new_id : $id;
                },
                $ids
            );

            return 'ids=' . $matches[1] . implode(',', array_values(array_unique($ids))) . $matches[1];
        };
    }

    /**
     * Build a strtr map covering every URL form WordPress and page builders store:
     * the full file plus all generated sizes (-WxH), in absolute, scheme-relative,
     * root-relative and JSON escaped-slash variants.
     *
     * @return array<string,string>
     */
    private static function build_url_map(int $old_id, int $new_id): array
    {
        $old_full = wp_get_attachment_url($old_id);
        // Counting only (no target yet): map every old URL to a sentinel so a change is detectable.
        $new_full = $new_id ? wp_get_attachment_url($new_id) : "\0";

        if (!$old_full || !$new_full) {
            return array();
        }

        $pairs = array();
        $pairs[$old_full] = $new_full;

        $old_meta = wp_get_attachment_metadata($old_id);
        $new_meta = $new_id ? wp_get_attachment_metadata($new_id) : array();
        $old_dir = self::url_dirname($old_full);
        $new_dir = $new_id ? self::url_dirname($new_full) : '';

        if (!empty($old_meta['sizes']) && is_array($old_meta['sizes'])) {
            foreach ($old_meta['sizes'] as $size_name => $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }
                $old_size_url = $old_dir . '/' . $size_data['file'];

                // Prefer the matching size on the new image; fall back to its full file.
                if ($new_id && !empty($new_meta['sizes'][$size_name]['file'])) {
                    $new_size_url = $new_dir . '/' . $new_meta['sizes'][$size_name]['file'];
                } else {
                    $new_size_url = $new_full;
                }

                $pairs[$old_size_url] = $new_size_url;
            }
        }

        // Large uploads are stored as "-scaled" copies; the untouched original is deleted with
        // the attachment too, so links to it must move as well.
        if (!empty($old_meta['original_image']) && is_string($old_meta['original_image'])) {
            $old_original = $old_dir . '/' . $old_meta['original_image'];
            if (!isset($pairs[$old_original])) {
                $new_original = ($new_id && !empty($new_meta['original_image']) && is_string($new_meta['original_image'])) ? $new_dir . '/' . $new_meta['original_image'] : $new_full;
                $pairs[$old_original] = $new_original;
            }
        }

        // Expand each old=>new pair into all stored variants.
        $map = array();
        foreach ($pairs as $old_url => $new_url) {
            if ($old_url === $new_url) {
                continue;
            }
            foreach (self::url_variants($old_url) as $variant => $old_variant) {
                $new_variant = $new_id ? self::url_variant($new_url, $variant) : "\0";
                if ($old_variant === '' || $new_variant === '' || $old_variant === $new_variant) {
                    continue;
                }
                $map[$old_variant] = $new_variant;
            }
        }

        // Longest keys first so sized URLs win over the base URL.
        uksort($map, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return $map;
    }

    /** @return array<string,string> variant-name => url */
    private static function url_variants(string $url): array
    {
        $variants = array();
        foreach (array('as_is', 'http', 'https', 'scheme_relative', 'root_relative', 'escaped', 'esc_url') as $name) {
            $v = self::url_variant($url, $name);
            if ($v !== '') {
                $variants[$name] = $v;
            }
        }
        return $variants;
    }

    private static function url_variant(string $url, string $variant): string
    {
        switch ($variant) {
            case 'as_is':
                return $url;
            case 'http':
                return set_url_scheme($url, 'http');
            case 'https':
                return set_url_scheme($url, 'https');
            case 'scheme_relative':
                return (string) preg_replace('#^https?://#i', '//', $url);
            case 'root_relative':
                return (string) wp_make_link_relative($url);
            case 'escaped':
                return str_replace('/', '\\/', $url);
            case 'esc_url':
                return esc_url_raw($url);
        }
        return '';
    }

    private static function url_dirname(string $url): string
    {
        $pos = strrpos($url, '/');
        return $pos === false ? $url : substr($url, 0, $pos);
    }

    /** Upload path without extension — matches the file and all of its sizes. */
    private static function path_seed(int $attachment_id): string
    {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return '';
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }
        return (string) preg_replace('/\.[^.\/]+$/', '', $path);
    }

    /* ---------------------------------------------------------------------
     * Group building / usage
     * ------------------------------------------------------------------- */

    private static function build_group_items(array $ids): array
    {
        $items = array();

        foreach ($ids as $id) {
            $id = (int) $id;
            $file = get_attached_file($id);
            $post = get_post($id);

            if (!$file || !$post) {
                continue;
            }

            $items[] = array(
                'id' => $id,
                'filename' => wp_basename($file),
                'size' => is_readable($file) ? size_format((int) filesize($file)) : '—',
                'date' => get_the_date('', $id) ?: '',
                'timestamp' => (int) strtotime((string) $post->post_date_gmt),
                'usage' => self::count_attachment_usage($id),
            );
        }

        return $items;
    }

    public static function sort_items_by_keep_priority(array $a, array $b): int
    {
        if ($a['usage'] !== $b['usage']) {
            return $b['usage'] <=> $a['usage'];
        }
        return $a['timestamp'] <=> $b['timestamp'];
    }

    private static function count_attachment_usage(int $attachment_id): int
    {
        if (isset(self::$usage_memo[$attachment_id])) {
            return self::$usage_memo[$attachment_id];
        }

        $cached = get_transient('honeill_usage_' . $attachment_id);
        if ($cached !== false) {
            self::$usage_memo[$attachment_id] = (int) $cached;
            return (int) $cached;
        }

        global $wpdb;

        // Count exactly what a merge would repoint (a dry run of the same logic), so the
        // "Used in" figure and the recommended master never disagree with what gets rewritten.
        $usage = self::replace_attachment_references($attachment_id, 0, true, !self::is_deep_usage());

        if (!self::is_deep_usage()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Usage tally for admin UI; result is cached in a transient below.
            $usage += (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
                    (string) $attachment_id
                )
            );
        }

        set_transient('honeill_usage_' . $attachment_id, $usage, HOUR_IN_SECONDS);
        self::$usage_memo[$attachment_id] = $usage;

        return $usage;
    }

    private static function get_exact_duplicate_groups(): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregation across postmeta for duplicate detection; cannot be served by object cache.
        // Avoid GROUP_CONCAT truncation when an image has very many copies.
        $wpdb->query('SET SESSION group_concat_max_len = 1000000');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value AS hash, GROUP_CONCAT(post_id) AS ids, COUNT(*) AS total
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s AND meta_value != ''
                GROUP BY meta_value
                HAVING total > 1
                ORDER BY total DESC
                LIMIT %d",
                self::SHA_META,
                self::MAX_GROUPS
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $ignored = self::get_ignored_signatures();
        $groups = array();

        foreach ($rows as $row) {
            $signature = 'sha:' . $row->hash;
            if (isset($ignored[$signature])) {
                continue;
            }
            $groups[$signature] = array_map('absint', explode(',', $row->ids));
        }

        return $groups;
    }

    private static function get_similar_groups(int $max_distance, bool &$capped = false): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregation across postmeta for duplicate detection; cannot be served by object cache.
        $wpdb->query('SET SESSION group_concat_max_len = 1000000');

        // Collapse identical visual hashes first — cheap, and handles exact-visual dupes.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value AS hash, GROUP_CONCAT(post_id) AS ids
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s AND meta_value != ''
                GROUP BY meta_value
                ORDER BY MIN(post_id) ASC
                LIMIT %d",
                self::VISUAL_META,
                self::MAX_DISTINCT_HASHES + 1
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $capped = count($rows) > self::MAX_DISTINCT_HASHES;
        if ($capped) {
            $rows = array_slice($rows, 0, self::MAX_DISTINCT_HASHES);
        }

        $count = count($rows);
        $hashes = array();
        $members = array();
        $nibbles = array();

        foreach ($rows as $i => $row) {
            $hashes[$i] = (string) $row->hash;
            $members[$i] = array_map('absint', explode(',', $row->ids));
            $nibbles[$i] = self::hash_nibbles((string) $row->hash);
        }

        // Union-find for correct transitive clustering across near-duplicate hashes.
        $parent = range(0, max(0, $count - 1));

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::find($parent, $i) === self::find($parent, $j)) {
                    continue;
                }
                if (self::nibble_distance($nibbles[$i], $nibbles[$j], $max_distance) <= $max_distance) {
                    $parent[self::find($parent, $i)] = self::find($parent, $j);
                }
            }
        }

        // Gather members per cluster root.
        $clusters = array();
        $anchor = array();
        for ($i = 0; $i < $count; $i++) {
            $root = self::find($parent, $i);
            if (!isset($clusters[$root])) {
                $clusters[$root] = array();
                $anchor[$root] = $hashes[$i];
            }
            foreach ($members[$i] as $id) {
                $clusters[$root][] = $id;
            }
        }

        $ignored = self::get_ignored_signatures();
        $groups = array();

        foreach ($clusters as $root => $ids) {
            $ids = array_values(array_unique(array_map('absint', $ids)));
            if (count($ids) < 2) {
                continue;
            }
            $signature = self::similar_signature($ids);
            if (isset($ignored[$signature])) {
                continue;
            }
            $groups[$signature] = $ids;
            if (count($groups) >= self::MAX_GROUPS) {
                break;
            }
        }

        return $groups;
    }

    private static function similar_signature(array $ids): string
    {
        sort($ids, SORT_NUMERIC);
        return 'sim:' . md5(implode(',', $ids));
    }

    private static function find(array &$parent, int $x): int
    {
        while ($parent[$x] !== $x) {
            $parent[$x] = $parent[$parent[$x]];
            $x = $parent[$x];
        }
        return $x;
    }

    /* ---------------------------------------------------------------------
     * Visual hashing (dHash) + distance
     * ------------------------------------------------------------------- */

    /** Pixel ceiling for decoding an image in memory (a 50 MP truecolor image needs ~200 MB in GD). */
    private const MAX_VISUAL_PIXELS = 50000000;

    /**
     * The file to fingerprint: the smallest generated size with the original's aspect ratio
     * (never a cropped thumbnail, which would hash a different picture), else the full file.
     */
    private static function visual_source_file(int $attachment_id): string
    {
        $file = (string) get_attached_file($attachment_id);
        if ($file === '' || !is_readable($file)) {
            return '';
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['width']) && !empty($meta['height']) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
            $ratio = $meta['width'] / $meta['height'];
            $best = null;
            foreach ($meta['sizes'] as $size) {
                if (empty($size['file']) || empty($size['width']) || empty($size['height']) || $size['width'] < 64) {
                    continue;
                }
                if (abs(($size['width'] / $size['height']) - $ratio) > $ratio * 0.02) {
                    continue;
                }
                if ($best === null || $size['width'] < $best['width']) {
                    $best = $size;
                }
            }
            if ($best !== null) {
                $candidate = path_join(dirname($file), wp_basename($best['file']));
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
        }

        $dims = @getimagesize($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable/unsupported files simply aren't hashed
        if (is_array($dims) && ((int) $dims[0] * (int) $dims[1]) > self::MAX_VISUAL_PIXELS) {
            return '';
        }

        return $file;
    }

    private static function create_visual_hash(string $file): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = self::create_image_resource($file);
        if (!$source) {
            return null;
        }

        $width = 9;
        $height = 8;
        $small = imagecreatetruecolor($width, $height);

        if (!$small) {
            imagedestroy($source);
            return null;
        }

        imagecopyresampled($small, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $bits = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width - 1; $x++) {
                $left = self::pixel_brightness($small, $x, $y);
                $right = self::pixel_brightness($small, $x + 1, $y);
                $bits .= $left > $right ? '1' : '0';
            }
        }

        imagedestroy($source);
        imagedestroy($small);

        return self::binary_string_to_hex($bits);
    }

    private static function create_image_resource(string $file)
    {
        $type = wp_check_filetype($file);
        $mime = isset($type['type']) ? $type['type'] : '';

        if ($mime === 'image/jpeg') {
            return @imagecreatefromjpeg($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt/unsupported files return false; GD's warning adds nothing.
        }
        if ($mime === 'image/png') {
            return @imagecreatefrompng($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt/unsupported files return false; GD's warning adds nothing.
        }
        if ($mime === 'image/gif') {
            return @imagecreatefromgif($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt/unsupported files return false; GD's warning adds nothing.
        }
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt/unsupported files return false; GD's warning adds nothing.
        }

        return null;
    }

    private static function pixel_brightness($image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;

        return (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
    }

    private static function binary_string_to_hex(string $bits): string
    {
        $hex = '';
        foreach (str_split($bits, 4) as $chunk) {
            $hex .= dechex(bindec(str_pad($chunk, 4, '0')));
        }
        return $hex;
    }

    /** @return int[] */
    private static function hash_nibbles(string $hash): array
    {
        $nibbles = array();
        $len = strlen($hash);
        for ($i = 0; $i < $len; $i++) {
            $nibbles[] = hexdec($hash[$i]);
        }
        return $nibbles;
    }

    /** Hamming distance over precomputed nibble arrays, with early exit past the threshold. */
    private static function nibble_distance(array $a, array $b, int $ceiling): int
    {
        static $lookup = array(0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4);

        $len_a = count($a);
        $len_b = count($b);
        $len = min($len_a, $len_b);
        $distance = abs($len_a - $len_b) * 4;

        for ($i = 0; $i < $len; $i++) {
            $distance += $lookup[$a[$i] ^ $b[$i]];
            if ($distance > $ceiling) {
                return $distance;
            }
        }

        return $distance;
    }

    private static function hamming_distance(string $hash_a, string $hash_b): int
    {
        return self::nibble_distance(self::hash_nibbles($hash_a), self::hash_nibbles($hash_b), PHP_INT_MAX);
    }

    /* ---------------------------------------------------------------------
     * Attachment queries / counts
     * ------------------------------------------------------------------- */

    /**
     * Image attachments that do not yet have $done_meta (and not $skip_meta).
     * Using NOT EXISTS makes scan progress monotonic — no offset drift or stalls.
     *
     * @return int[]
     */
    private static function get_unscanned_image_ids(string $done_meta, int $limit, string $skip_meta = ''): array
    {
        global $wpdb;

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND p.post_mime_type LIKE %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} md WHERE md.post_id = p.ID AND md.meta_key = %s
                )";
        $params = array('image/%', $done_meta);

        if ($skip_meta !== '') {
            $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} ms WHERE ms.post_id = p.ID AND ms.meta_key = %s
                )";
            $params[] = $skip_meta;
        }

        $sql .= ' ORDER BY p.ID ASC LIMIT %d';
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled from fixed string fragments only (no input) and executed through $wpdb->prepare() with $params.
        return array_map('absint', $wpdb->get_col($wpdb->prepare($sql, $params)));
    }

    private static function count_image_attachments(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static COUNT for scan progress; no user input, value reflects live data each call.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE 'image/%'"
        );
    }

    private static function count_meta_rows(string $meta_key): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- COUNT for scan progress; must reflect live data each call.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                $meta_key
            )
        );
    }

    /* ---------------------------------------------------------------------
     * Options / helpers
     * ------------------------------------------------------------------- */

    private static function get_visual_distance(): int
    {
        return (int) get_option(self::OPT_DISTANCE, self::DEFAULT_VISUAL_DISTANCE);
    }

    private static function get_batch_size(): int
    {
        return max(10, min(300, (int) get_option(self::OPT_BATCH, self::DEFAULT_BATCH_SIZE)));
    }

    private static function is_deep_usage(): bool
    {
        return (bool) get_option(self::OPT_DEEP_USAGE, 0);
    }

    private static function get_delete_mode(): string
    {
        return get_option(self::OPT_DELETE_MODE, 'delete') === 'repoint' ? 'repoint' : 'delete';
    }

    /** @return array<string,int> */
    private static function get_ignored_signatures(): array
    {
        $ignored = get_option(self::OPT_IGNORED, array());
        return is_array($ignored) ? $ignored : array();
    }

    private static function admin_url(string $tab, array $args = array()): string
    {
        if (!in_array($tab, array('exact', 'similar', 'settings'), true)) {
            $tab = 'exact';
        }

        return add_query_arg(
            array_merge(array('page' => 'honeill-media-duplicate-manager', 'tab' => $tab), $args),
            admin_url('upload.php')
        );
    }

    private static function verify_action(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'honeill-media-duplicate-manager'));
        }
        check_admin_referer($action);
    }

    /*
     * Centralised superglobal access. All admin-post handlers call verify_action()
     * (capability + check_admin_referer) before reading any POST value, and GET values
     * are only used for read-only UI routing/display. The phpcs:ignore annotations live
     * here so the justification is stated once instead of at every call site.
     */

    private static function get_key(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI routing/display value; not state-changing.
        return isset($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : $default;
    }

    private static function get_int(string $key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI display value; not state-changing.
        return isset($_GET[$key]) ? absint($_GET[$key]) : 0;
    }

    private static function get_flag(string $key): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI flag; not state-changing.
        return !empty($_GET[$key]);
    }

    private static function post_key(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by verify_action()/check_admin_referer() before this runs.
        return isset($_POST[$key]) ? sanitize_key(wp_unslash($_POST[$key])) : $default;
    }

    private static function post_int(string $key, int $default = 0): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by verify_action()/check_admin_referer() before this runs.
        return isset($_POST[$key]) ? absint($_POST[$key]) : $default;
    }

    private static function post_text(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by verify_action()/check_admin_referer() before this runs.
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
    }

    private static function post_flag(string $key): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by verify_action()/check_admin_referer() before this runs.
        return !empty($_POST[$key]);
    }

    /** @return int[] */
    private static function post_int_array(string $key): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified via verify_action(); every element is sanitized with absint() on the next line.
        $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : array();
        return array_map('absint', (array) $raw);
    }
}

Honeill_Media_Duplicate_Manager::boot();
