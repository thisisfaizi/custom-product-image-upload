<?php
/**
 * Uninstall script for NDV Product Image Upload for WooCommerce
 *
 * This file handles the uninstallation process, respecting the user's data-retention
 * choice and following WordPress best practices.
 *
 * @package Custom_Product_Image_Upload
 * @since 1.1
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Only allow this to run during uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * Remove plugin settings for the current site.
 *
 * Respects the user's data-retention preference. Order data is NEVER touched:
 * uploaded files and the order item meta referencing them are always preserved
 * for data integrity, as promised in the plugin's admin UI.
 */
function cpiu_enhanced_uninstall()
{
    // Get the uninstall preference
    $keep_data_option = get_option('cpiu_keep_data_on_uninstall', 'keep');

    // Only proceed with deletion if explicitly set to 'delete'
    if ($keep_data_option !== 'delete') {
        // User chose to keep data or no preference set
        // We do not delete the option here so it persists if they reinstall
        return;
    }

    // User explicitly chose to delete plugin settings - proceed with cleanup

    // Delete all plugin options
    $options_to_delete = array(
        'cpiu_multi_product_configs',
        'cpiu_default_settings',
        'cpiu_global_settings',
        'cpiu_settings',
        'cpiu_keep_data_on_uninstall',
        'cpiu_installation_date',
        'cpiu_show_installation_notice',
        'cpiu_data_notice_dismissed'
    );

    foreach ($options_to_delete as $option) {
        delete_option($option);
    }

    // Clean up any transients
    delete_transient('cpiu_cdn_cache_last_refresh');

    // Clean up plugin-related user meta (per-user UI preferences only)
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaDelete, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'cpiu_%'");

    // Remove the scheduled cleanup event, if any remains
    wp_clear_scheduled_hook('cpiu_cleanup_guest_uploads');

    // Clear any cached data for plugin options
    wp_cache_delete('cpiu_settings', 'options');
    wp_cache_delete('cpiu_default_settings', 'options');
    wp_cache_delete('cpiu_global_settings', 'options');
    wp_cache_delete('cpiu_multi_product_configs', 'options');
    wp_cache_delete('cpiu_keep_data_on_uninstall', 'options');

    // Note: We intentionally do NOT delete uploaded files, order item meta, or
    // post meta referencing uploads. Orders must keep their upload references
    // for data integrity ("Uploaded images referenced in orders will be
    // preserved"), and the files they point to must remain readable.
}

// Run the uninstall routine on every site when network-uninstalled on multisite.
if (is_multisite()) {
    $cpiu_site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($cpiu_site_ids as $cpiu_site_id) {
        switch_to_blog($cpiu_site_id);
        cpiu_enhanced_uninstall();
        restore_current_blog();
    }
} else {
    cpiu_enhanced_uninstall();
}
