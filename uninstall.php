<?php
/**
 * Uninstall script for Custom Product Image Upload plugin
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
 * Enhanced uninstall function that respects user preferences and provides confirmation
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

    // User explicitly chose to delete data - proceed with cleanup

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

    // Clean up any user meta related to the plugin
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaDelete, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'cpiu_%'");

    // Clean up any post meta related to the plugin (including order item meta)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaDelete, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'cpiu_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaDelete, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_cpiu_%'");

    // Clean up WooCommerce order item meta
    if (class_exists('WooCommerce')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaDelete, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE 'cpiu_%'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaDelete, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE '_cpiu_%'");
    }

    // Clear any cached data for plugin options
    wp_cache_delete('cpiu_settings', 'options');
    wp_cache_delete('cpiu_default_settings', 'options');
    wp_cache_delete('cpiu_global_settings', 'options');
    wp_cache_delete('cpiu_multi_product_configs', 'options');
    wp_cache_delete('cpiu_keep_data_on_uninstall', 'options');

    // Note: We intentionally do NOT delete uploaded files as they might be referenced in orders
    // This is a safer approach for e-commerce plugins to maintain data integrity
}

// Run the enhanced uninstall function
cpiu_enhanced_uninstall();

