<?php
/**
 * Plugin Name:    NDV Product Image Upload for WooCommerce
 * Plugin URI:     https://nowdigiverse.com/products/
 * Description:    Let WooCommerce customers upload and crop images and PDFs for products before checkout, with per-product rules and secure file handling.
 * Version:        1.1.0
 * Author:         Nowdigiverse
 * Author URI:     https://nowdigiverse.com/
 * License:        GPL v2 or later
 * License URI:    https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:    nowdigiverse-product-image-upload
 * Domain Path:    /i18n/languages
 * Requires at least: 5.9
 * Requires PHP:   7.2
 * WC requires at least: 3.5
 * WC tested up to:   10.9
 * Requires Plugins: woocommerce
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// =========================================================================
// 1. Constants
// =========================================================================
define('CPIU_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CPIU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CPIU_VERSION', '1.1.0');
define('CPIU_OPTIONS_GROUP', 'cpiu_options_group');
define('CPIU_OPTIONS_NAME', 'cpiu_settings');
define('CPIU_UPLOAD_DIR_NAME', 'custom_product_images');

// =========================================================================
// 2. Licensing / SDK (reserved)
//    The free edition bundles no marketplace SDK or "phone home" code, in
//    line with the WordPress.org plugin guidelines. Premium licensing and
//    updates live in the separate "NDV Product Image Upload — Pro"
//    add-on, which loads its own SDK and hooks into this base plugin.
// =========================================================================

// =========================================================================
// 3. Include Class Files
// =========================================================================
require_once CPIU_PLUGIN_PATH . 'includes/class-cpiu-data-manager.php';
require_once CPIU_PLUGIN_PATH . 'includes/class-cpiu-secure-upload.php';
require_once CPIU_PLUGIN_PATH . 'includes/class-cpiu-ajax-handler.php';
require_once CPIU_PLUGIN_PATH . 'includes/class-cpiu-admin-interface.php';
require_once CPIU_PLUGIN_PATH . 'includes/class-cpiu-frontend-manager.php';

// =========================================================================
// 4. WooCommerce HPOS Compatibility
//    MUST be hooked at file scope — before_woocommerce_init fires BEFORE plugins_loaded.
// =========================================================================
function cpiu_declare_hpos_compatibility()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
}
add_action('before_woocommerce_init', 'cpiu_declare_hpos_compatibility');

// =========================================================================
// 6. Plugin Initialization (after all plugins are loaded)
// =========================================================================
function cpiu_initialize_plugin()
{

    // WooCommerce dependency check
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'cpiu_woocommerce_inactive_notice');
        return;
    }

    // Initialize core classes
    CPIU_Data_Manager::instance();
    new CPIU_Ajax_Handler();

    // Admin-only
    if (is_admin()) {
        new CPIU_Admin_Interface();
        add_action('admin_enqueue_scripts', 'cpiu_enqueue_admin_notices_script');
    }

    // Always instantiate Frontend Manager to ensure cart hooks are registered
    new CPIU_Frontend_Manager();

}
add_action('plugins_loaded', 'cpiu_initialize_plugin');

// =========================================================================
// 7. Admin Notices
// =========================================================================
function cpiu_woocommerce_inactive_notice()
{
    echo '<div class="notice notice-error is-dismissible">';
    echo '<p>' . esc_html__('NDV Product Image Upload requires WooCommerce to be installed and active.', 'nowdigiverse-product-image-upload') . '</p>';
    echo '</div>';
}

function cpiu_enqueue_admin_notices_script()
{
    wp_enqueue_script(
        'cpiu-admin-notices',
        CPIU_PLUGIN_URL . 'assets/js/cpiu-admin-notices.js',
        array('jquery'),
        CPIU_VERSION,
        true
    );
    wp_localize_script('cpiu-admin-notices', 'cpiu_admin_notices', array(
        'dismiss_nonce' => wp_create_nonce('cpiu_dismiss_notice'),
        'data_preference_nonce' => wp_create_nonce('cpiu_data_preference'),
        'dismiss_data_nonce' => wp_create_nonce('cpiu_dismiss_data_notice'),
    ));
}

// =========================================================================
// 8. Installation / Activation / Deactivation
// =========================================================================
function cpiu_activate_single_site()
{
    // Create upload directory
    $upload_dir_info = wp_upload_dir();
    $custom_upload_path = $upload_dir_info['basedir'] . '/' . CPIU_UPLOAD_DIR_NAME;

    if (!file_exists($custom_upload_path)) {
        wp_mkdir_p($custom_upload_path);
    }

    // Always (re)write the protection files, even if the directory already
    // existed, so a pre-existing or tampered directory is re-secured.
    if (is_dir($custom_upload_path) && wp_is_writable($custom_upload_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing static protection files into the plugin's own uploads subdirectory; WP_Filesystem is unavailable this early on some hosts.
        file_put_contents($custom_upload_path . '/index.php', '<?php // Silence is golden.');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing static protection files into the plugin's own uploads subdirectory; WP_Filesystem is unavailable this early on some hosts.
        file_put_contents(
            $custom_upload_path . '/.htaccess',
            "Options -Indexes\n\n<FilesMatch \"\.(php|phar)$\">\n    Deny from all\n</FilesMatch>\n"
        );
    }

    // Set default options on fresh install
    $is_fresh_install = false === get_option(CPIU_OPTIONS_NAME);
    if ($is_fresh_install) {
        add_option(CPIU_OPTIONS_NAME, cpiu_get_default_options());
        add_option('cpiu_default_settings', cpiu_get_default_multi_product_options());
        // Grows with every configured product - do not autoload on every request.
        add_option('cpiu_multi_product_configs', array(), '', 'no');
        add_option('cpiu_installation_date', current_time('mysql'));
    } else {
        // Existing install: make sure the per-product config blob is stored
        // WITHOUT autoload (it grows with every configured product). Re-adding it
        // with the autoload flag disabled works on the plugin's minimum
        // WordPress (5.9), unlike the dedicated 6.4+ autoload helper.
        $existing_configs = get_option('cpiu_multi_product_configs', array());
        delete_option('cpiu_multi_product_configs');
        add_option('cpiu_multi_product_configs', $existing_configs, '', 'no');
    }

    // Schedule cleanup cron
    if (!wp_next_scheduled('cpiu_cleanup_guest_uploads')) {
        wp_schedule_event(time(), 'daily', 'cpiu_cleanup_guest_uploads');
    }

    // Show installation notice
    add_option('cpiu_show_installation_notice', true);
}

function cpiu_activate($network_wide = false)
{
    if (is_multisite() && $network_wide) {
        $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
        foreach ($site_ids as $site_id) {
            switch_to_blog($site_id);
            cpiu_activate_single_site();
            restore_current_blog();
        }
        return;
    }

    cpiu_activate_single_site();
}
register_activation_hook(__FILE__, 'cpiu_activate');

/**
 * Set up the plugin on a site created after network activation.
 */
function cpiu_initialize_new_site($new_site)
{
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
        return;
    }

    switch_to_blog($new_site->blog_id);
    cpiu_activate_single_site();
    restore_current_blog();
}
add_action('wp_initialize_site', 'cpiu_initialize_new_site', 20);

function cpiu_deactivate()
{
    // Clear scheduled cron
    wp_clear_scheduled_hook('cpiu_cleanup_guest_uploads');

    // Remove installation notice
    delete_option('cpiu_show_installation_notice');
}
register_deactivation_hook(__FILE__, 'cpiu_deactivate');

register_uninstall_hook(__FILE__, 'cpiu_uninstall_plugin');

function cpiu_uninstall_plugin()
{
    // Actual uninstall logic is handled in uninstall.php
}

// =========================================================================
// 9. Default Options (used by activation and legacy compatibility)
// =========================================================================
function cpiu_get_default_options()
{
    return array(
        'product_id' => 0,
        'image_count' => 9,
        'button_text' => esc_html__('Upload Files', 'nowdigiverse-product-image-upload'),
        'button_color' => '#4CAF50',
    );
}

function cpiu_get_default_multi_product_options()
{
    // Must mirror CPIU_Data_Manager::$default_settings — that class is the
    // canonical reader of the 'cpiu_default_settings' option.
    return array(
        'image_count' => 9,
        'allowed_types' => array('jpg', 'jpeg', 'png', 'gif', 'webp'),
        'max_file_size' => 5242880, // 5MB in bytes
        'button_text' => esc_html__('Upload Files', 'nowdigiverse-product-image-upload'),
        'button_color' => '#4CAF50',
        'resolution_validation' => false,
        'min_width' => 0,
        'min_height' => 0,
        'max_width' => 0,
        'max_height' => 0,
        'enable_shape_cropping' => true,
        'cropping_ratio' => 'free',
        'disable_quantity' => false,
    );
}

// =========================================================================
// 10. Cleanup Cron
// =========================================================================
function cpiu_cleanup_abandoned_guest_uploads()
{
    $upload_dir_info = wp_upload_dir();
    $upload_dir = $upload_dir_info['basedir'] . '/' . CPIU_UPLOAD_DIR_NAME;

    if (!is_dir($upload_dir)) {
        return;
    }

    // Sweep any orphaned validation scratch files left in the tmp/ subfolder.
    // These are normally unlinked in-request; a mid-request fatal could skip that.
    $temp_files = glob($upload_dir . '/tmp/cpiu_temp_*');
    if (is_array($temp_files)) {
        $temp_cutoff = time() - (60 * 60); // 1 hour ago
        foreach ($temp_files as $temp_file) {
            if (is_file($temp_file) && filemtime($temp_file) < $temp_cutoff) {
                wp_delete_file($temp_file);
            }
        }
    }

    $files = glob($upload_dir . '/prod-*');
    $cutoff_time = time() - (48 * 60 * 60); // 48 hours ago

    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff_time) {
            $filename = basename($file);

            // Skip if file is associated with completed orders
            if (cpiu_is_file_in_completed_order($filename)) {
                continue;
            }

            wp_delete_file($file);
        }
    }
}
add_action('cpiu_cleanup_guest_uploads', 'cpiu_cleanup_abandoned_guest_uploads');
add_action('cpiu_cleanup_guest_uploads', 'cpiu_cleanup_completed_order_images');

/**
 * Cleanup images from completed/cancelled/refunded orders after configured days
 */
function cpiu_cleanup_completed_order_images()
{
    $data_manager = CPIU_Data_Manager::instance();
    $global_settings = $data_manager->get_global_settings();

    // Check if auto-cleanup is enabled
    if (empty($global_settings['enable_order_image_cleanup'])) {
        return;
    }

    $cleanup_days = isset($global_settings['order_image_cleanup_days']) ? absint($global_settings['order_image_cleanup_days']) : 30;
    if ($cleanup_days < 1) {
        $cleanup_days = 30;
    }

    $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$cleanup_days} days"));

    // Get upload directory info
    $upload_dir_info = wp_upload_dir();
    $upload_dir = $upload_dir_info['basedir'] . '/' . CPIU_UPLOAD_DIR_NAME;

    if (!is_dir($upload_dir)) {
        return;
    }

    // Query completed/cancelled/refunded orders older than cutoff
    $statuses_to_clean = array('wc-completed', 'wc-cancelled', 'wc-refunded');

    global $wpdb;

    // HPOS-aware: query the authoritative order storage only. When the custom
    // orders table (HPOS) is enabled, wp_posts rows may not exist or be stale;
    // when it is disabled, wc_orders may not exist. OrderUtil tells us which
    // storage WooCommerce is actually using.
    $hpos_enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
        && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    // $statuses_to_clean is a fixed 3-element list, so we use literal %s
    // placeholders (no interpolation) to keep the query fully prepared.
    list($s1, $s2, $s3) = $statuses_to_clean;

    if ($hpos_enabled) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $order_items = $wpdb->get_results($wpdb->prepare(
            "SELECT om.order_item_id, om.meta_value as image_urls
             FROM {$wpdb->prefix}woocommerce_order_itemmeta om
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON om.order_item_id = oi.order_item_id
             INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id
             WHERE om.meta_key = '_cpiu_uploaded_images'
             AND om.meta_value != '_cpiu_images_cleaned'
             AND o.status IN (%s, %s, %s)
             AND o.date_created_gmt < %s
             LIMIT 50",
            $s1,
            $s2,
            $s3,
            $cutoff_date
        ));
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $order_items = $wpdb->get_results($wpdb->prepare(
            "SELECT om.order_item_id, om.meta_value as image_urls
             FROM {$wpdb->prefix}woocommerce_order_itemmeta om
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON om.order_item_id = oi.order_item_id
             INNER JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
             WHERE om.meta_key = '_cpiu_uploaded_images'
             AND om.meta_value != '_cpiu_images_cleaned'
             AND p.post_type = 'shop_order'
             AND p.post_status IN (%s, %s, %s)
             AND p.post_date < %s
             LIMIT 50",
            $s1,
            $s2,
            $s3,
            $cutoff_date
        ));
    }

    if (empty($order_items)) {
        return;
    }

    foreach ($order_items as $item) {
        $image_urls = maybe_unserialize($item->image_urls);

        if (!is_array($image_urls)) {
            continue;
        }

        $files_deleted = 0;

        foreach ($image_urls as $image_url) {
            // Extract filename from URL
            $filename = basename(wp_parse_url($image_url, PHP_URL_PATH));

            if (empty($filename)) {
                // Try query parameter format
                $query_string = wp_parse_url($image_url, PHP_URL_QUERY);
                if ($query_string) {
                    parse_str($query_string, $params);
                    if (isset($params['cpiu_file'])) {
                        $filename = sanitize_file_name($params['cpiu_file']);
                    }
                }
            }

            if (empty($filename)) {
                continue;
            }

            $file_path = $upload_dir . '/' . $filename;

            if (file_exists($file_path)) {
                wp_delete_file($file_path);
                $files_deleted++;
            }
        }

        // Mark this order item's images as cleaned
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $wpdb->update(
            $wpdb->prefix . 'woocommerce_order_itemmeta',
            array('meta_value' => '_cpiu_images_cleaned'), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            array(
                'order_item_id' => $item->order_item_id,
                'meta_key' => '_cpiu_uploaded_images' // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ),
            array('%s'),
            array('%d', '%s')
        );
    }
}

function cpiu_is_file_in_completed_order($filename)
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    $orders = $wpdb->get_results($wpdb->prepare(
        "SELECT om.order_item_id
         FROM {$wpdb->prefix}woocommerce_order_itemmeta om
         INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON om.order_item_id = oi.order_item_id
         WHERE om.meta_key = '_cpiu_uploaded_images'
         AND om.meta_value LIKE %s
         LIMIT 1",
        '%' . $wpdb->esc_like($filename) . '%'
    ));

    return !empty($orders);
}

// =========================================================================
// 11. Installation & Data Management Notices
// =========================================================================
function cpiu_show_installation_notice()
{
    if (!get_option('cpiu_show_installation_notice')) {
        return;
    }
    // Show only on the plugin's own page or the Plugins screen — not on every admin page.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = $screen ? $screen->id : '';
    if ('toplevel_page_cpiu-settings' !== $screen_id && 'plugins' !== $screen_id) {
        return;
    }
    ?>
    <div class="notice notice-success is-dismissible" id="cpiu-installation-notice">
        <h3><?php esc_html_e('NDV Product Image Upload - Installation Complete!', 'nowdigiverse-product-image-upload'); ?>
        </h3>
        <p><?php esc_html_e('Thank you for installing NDV Product Image Upload. The plugin has been successfully activated.', 'nowdigiverse-product-image-upload'); ?>
        </p>
        <p>
            <strong><?php esc_html_e('🔒 Data Protection:', 'nowdigiverse-product-image-upload'); ?></strong>
            <?php esc_html_e('Your data is safe - the plugin does not delete any settings or uploaded images when deactivated. You control data removal from the Uninstall Preferences tab.', 'nowdigiverse-product-image-upload'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cpiu-settings')); ?>"
                class="button button-primary">
                <?php esc_html_e('Configure Plugin', 'nowdigiverse-product-image-upload'); ?>
            </a>
            <button type="button" class="button" data-cpiu-action="dismiss-installation">
                <?php esc_html_e('Dismiss', 'nowdigiverse-product-image-upload'); ?>
            </button>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'cpiu_show_installation_notice');

function cpiu_show_data_management_notice()
{
    if (!current_user_can('delete_plugins')) {
        return;
    }

    $preference = get_option('cpiu_keep_data_on_uninstall');
    if (($preference === 'keep' || $preference === 'delete') || get_option('cpiu_data_notice_dismissed')) {
        return;
    }
    // A plugin-data question — show only on the plugin's own page, never as a global nag.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || 'toplevel_page_cpiu-settings' !== $screen->id) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible" id="cpiu-data-management-notice">
        <h3><?php esc_html_e('NDV Product Image Upload - Data Management', 'nowdigiverse-product-image-upload'); ?></h3>
        <p><?php esc_html_e('Before uninstalling this plugin, you can choose whether to keep or delete your plugin data:', 'nowdigiverse-product-image-upload'); ?>
        </p>
        <p>
            <button type="button" class="button button-secondary" data-cpiu-action="set-data-preference"
                data-preference="keep">
                <?php esc_html_e('Keep Data When Uninstalling', 'nowdigiverse-product-image-upload'); ?>
            </button>
            <button type="button" class="button button-secondary" data-cpiu-action="set-data-preference"
                data-preference="delete">
                <?php esc_html_e('Delete Data When Uninstalling', 'nowdigiverse-product-image-upload'); ?>
            </button>
            <button type="button" class="button" data-cpiu-action="dismiss-data-notice">
                <?php esc_html_e('Dismiss', 'nowdigiverse-product-image-upload'); ?>
            </button>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'cpiu_show_data_management_notice');

// =========================================================================
// 12. AJAX Handlers for Admin Notices
// =========================================================================
function cpiu_dismiss_installation_notice_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_dismiss_notice')) {
        wp_die(esc_html__('Security check failed.', 'nowdigiverse-product-image-upload'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload'));
    }
    delete_option('cpiu_show_installation_notice');
    wp_send_json_success();
}
add_action('wp_ajax_cpiu_dismiss_installation_notice', 'cpiu_dismiss_installation_notice_ajax');

function cpiu_set_data_preference_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_data_preference')) {
        wp_die(esc_html__('Security check failed.', 'nowdigiverse-product-image-upload'));
    }
    if (!current_user_can('delete_plugins')) {
        wp_die(esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload'));
    }
    $preference = isset($_POST['preference']) ? sanitize_text_field(wp_unslash($_POST['preference'])) : '';
    if (in_array($preference, array('keep', 'delete'), true)) {
        update_option('cpiu_keep_data_on_uninstall', $preference);
    }
    wp_send_json_success();
}
add_action('wp_ajax_cpiu_set_data_preference', 'cpiu_set_data_preference_ajax');

function cpiu_dismiss_data_notice_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_dismiss_data_notice')) {
        wp_die(esc_html__('Security check failed.', 'nowdigiverse-product-image-upload'));
    }
    if (!current_user_can('delete_plugins')) {
        wp_die(esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload'));
    }
    update_option('cpiu_data_notice_dismissed', true);
    wp_send_json_success();
}
add_action('wp_ajax_cpiu_dismiss_data_notice', 'cpiu_dismiss_data_notice_ajax');

// =========================================================================
// 13. Plugin Action Links
// =========================================================================
function cpiu_add_plugin_action_links($links)
{
    if (class_exists('WooCommerce')) {
        $settings_link = '<a href="' . admin_url('admin.php?page=cpiu-settings') . '">' . esc_html__('Settings', 'nowdigiverse-product-image-upload') . '</a>';
    } else {
        $settings_link = '<span style="color: #999; cursor: not-allowed;" title="' . esc_attr__('Activate WooCommerce plugin to configure settings', 'nowdigiverse-product-image-upload') . '">' . esc_html__('Settings', 'nowdigiverse-product-image-upload') . '</span>';
    }
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cpiu_add_plugin_action_links');

// =========================================================================
// 14. Secure File Serving Endpoint
// =========================================================================
function cpiu_generate_public_token($filename)
{
    return hash_hmac('sha256', $filename, wp_salt('auth'));
}

function cpiu_serve_uploaded_file()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public file endpoint authenticated by an HMAC token (cpiu_token), not a nonce, so order/email download links work for guests. The token is verified below with hash_equals().
    if (!isset($_GET['cpiu_file'])) {
        return;
    }

    $filename = sanitize_file_name(wp_unslash($_GET['cpiu_file']));

    // STRICT VALIDATION: HMAC token is ALWAYS required — reject immediately if missing or invalid
    if (!isset($_GET['cpiu_token'])) {
        wp_die(esc_html__('Access denied. You do not have permission to view this file.', 'nowdigiverse-product-image-upload'), esc_html__('Security Check Failed', 'nowdigiverse-product-image-upload'), array('response' => 403));
    }

    $expected_token = cpiu_generate_public_token($filename);
    $provided_token = sanitize_text_field(wp_unslash($_GET['cpiu_token']));

    if (!hash_equals($expected_token, $provided_token)) {
        wp_die(esc_html__('Access denied. You do not have permission to view this file.', 'nowdigiverse-product-image-upload'), esc_html__('Security Check Failed', 'nowdigiverse-product-image-upload'), array('response' => 403));
    }

    // Validate filename format - must start with prod- and end with a supported extension
    if (!preg_match('/^prod-.*?\.(jpg|jpeg|png|gif|webp|pdf)$/i', $filename)) {
        wp_die(esc_html__('Invalid file request format.', 'nowdigiverse-product-image-upload'), esc_html__('Invalid File', 'nowdigiverse-product-image-upload'), array('response' => 400));
    }

    $upload_dir_info = wp_upload_dir();
    $file_path = $upload_dir_info['basedir'] . '/' . CPIU_UPLOAD_DIR_NAME . '/' . $filename;

    if (!file_exists($file_path)) {
        wp_die(esc_html__('File not found.', 'nowdigiverse-product-image-upload'));
    }

    $mime_type = wp_check_filetype($filename)['type'];
    if (!$mime_type) {
        wp_die(esc_html__('Invalid file type.', 'nowdigiverse-product-image-upload'));
    }

    // Clean ALL output buffers to prevent stray whitespace/HTML from
    // being prepended to the binary file data (corrupts images).
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Disable any output compression that may interfere
    if (function_exists('apache_setenv')) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Required so binary file output is not gzip-corrupted while streaming.

    // Check if download is forced
    $disposition = isset($_GET['download']) && sanitize_text_field(wp_unslash($_GET['download'])) === '1' ? 'attachment' : 'inline';

    // Serve the file
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file_path));
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=3600');
    header('Pragma: cache');
    header('X-Content-Type-Options: nosniff');
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    readfile($file_path);
    exit;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
}
add_action('init', 'cpiu_serve_uploaded_file', 1);

