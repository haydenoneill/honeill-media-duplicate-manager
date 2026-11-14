<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all options, cached usage transients, and per-attachment bookkeeping meta — on every site of a multisite network.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up one site.
 */
function honeill_media_duplicate_manager_uninstall_site()
{
    global $wpdb;

    // Options.
    $honeill_options = array(
        'honeill_visual_distance',
        'honeill_batch_size',
        'honeill_deep_usage',
        'honeill_delete_mode',
        'honeill_ignored_signatures',
    );

    // If this site opted in to usage statistics, ask for its data to be erased.
    $honeill_install_id = (string) get_option('honeill_media_duplicate_manager_install_id', '');
    if ($honeill_install_id !== '' && get_option('honeill_media_duplicate_manager_telemetry') === 'yes') {
        wp_safe_remote_post('https://honeill-plugin-telemetry.vercel.app/api/v1/forget', array(
            'timeout' => 5,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('install_id' => $honeill_install_id, 'plugin' => 'honeill-media-duplicate-manager')),
        ));
    }

    foreach ($honeill_options as $honeill_option) {
        delete_option($honeill_option);
    }
    delete_option('honeill_media_duplicate_manager_telemetry');
    delete_option('honeill_media_duplicate_manager_install_id');
    wp_clear_scheduled_hook('honeill_media_duplicate_manager_telemetry_send');

    // Per-attachment meta written by the plugin.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall using fixed, hardcoded meta keys.
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta}
        WHERE meta_key IN ('_honeill_sha256', '_honeill_visual_hash', '_honeill_visual_skipped', '_honeill_scanned_at')"
    );

    // Cached usage transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall; deletes this plugin's transient option rows by fixed prefix.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '\_transient\_honeill\_usage\_%'
            OR option_name LIKE '\_transient\_timeout\_honeill\_usage\_%'"
    );
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $honeill_site_id) {
        switch_to_blog((int) $honeill_site_id);
        honeill_media_duplicate_manager_uninstall_site();
        restore_current_blog();
    }
} else {
    honeill_media_duplicate_manager_uninstall_site();
}
