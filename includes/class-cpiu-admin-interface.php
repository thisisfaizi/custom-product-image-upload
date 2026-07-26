<?php
/**
 * CPIU Admin Interface Class
 * 
 * Handles the admin interface for multi-product configuration
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class CPIU_Admin_Interface
{

    /**
     * Data manager instance
     */
    private $data_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->data_manager = CPIU_Data_Manager::instance();
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_cpiu_get_configurations', array($this, 'get_configurations_ajax'));
        add_action('wp_ajax_cpiu_get_configuration', array($this, 'get_configuration_ajax'));
        add_action('wp_ajax_cpiu_set_uninstall_preference', array($this, 'set_uninstall_preference_ajax'));
        add_action('wp_ajax_cpiu_export_for_uninstall', array($this, 'export_for_uninstall_ajax'));

        // WooCommerce Admin Hooks
        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hide_custom_order_meta'));
        add_action('woocommerce_after_order_itemmeta', array($this, 'display_uploaded_images_in_admin'), 10, 3);
    }
    /**
     * Registered admin tabs.
     *
     * Returns the core (free) tabs. Premium tabs — Bulk Operations,
     * Import/Export, Security Logs, CDN Cache, etc. — are registered by the
     * Pro add-on through the `cpiu_admin_tabs` filter. Each tab is an array:
     *   slug => array( 'label' => string, 'icon' => dashicon, 'callback' => callable )
     * Core tabs render inline in render_admin_page() and omit 'callback';
     * premium tabs supply a 'callback' that renders the tab body.
     *
     * @return array
     */
    public function get_registered_tabs()
    {
        $tabs = array(
            'global-settings' => array(
                'label' => __('Global Settings', 'nowdigiverse-product-image-upload'),
                'icon' => 'dashicons-admin-site-alt3',
            ),
            'default-settings' => array(
                'label' => __('Default Settings', 'nowdigiverse-product-image-upload'),
                'icon' => 'dashicons-admin-generic',
            ),
            'add-configuration' => array(
                'label' => __('Add Configuration', 'nowdigiverse-product-image-upload'),
                'icon' => 'dashicons-plus-alt',
            ),
            'existing-configurations' => array(
                'label' => __('Existing Configurations', 'nowdigiverse-product-image-upload'),
                'icon' => 'dashicons-list-view',
            ),
            'uninstall-preferences' => array(
                'label' => __('Uninstall Preferences', 'nowdigiverse-product-image-upload'),
                'icon' => 'dashicons-trash',
            ),
        );

        /**
         * Filters the admin tabs shown on the plugin settings page.
         *
         * The Pro add-on uses this filter to append premium tabs, each with
         * its own render callback (signature: function( array $context )).
         *
         * @param array $tabs Map of slug => array( label, icon, callback ).
         */
        return apply_filters('cpiu_admin_tabs', $tabs);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            esc_html__('NDV Product Image Upload', 'nowdigiverse-product-image-upload'),
            esc_html__('NDV Image Upload', 'nowdigiverse-product-image-upload'),
            'manage_options',
            'cpiu-settings',
            array($this, 'render_admin_page'),
            'dashicons-camera-alt',
            58
        );
    }

    /**
     * Get current tab slug from URL
     */
    private function get_current_tab_slug()
    {
        $tabs = $this->get_registered_tabs();
        $current_tab = isset($tabs['default-settings']) ? 'default-settings' : key($tabs);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['tab'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requested = sanitize_text_field(wp_unslash($_GET['tab']));
            if (isset($tabs[$requested])) {
                $current_tab = $requested;
            }
        }

        return $current_tab;
    }

    /**
     * Generate tab URL
     */
    private function get_tab_url($tab_slug)
    {
        return admin_url('admin.php?page=cpiu-settings&tab=' . $tab_slug);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        // Order edit screens (classic and HPOS) get a small stylesheet for the
        // uploaded-file cards rendered by display_uploaded_images_in_admin().
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';
        if (in_array($screen_id, array('shop_order', 'woocommerce_page_wc-orders'), true)) {
            wp_enqueue_style(
                'cpiu-admin-order',
                CPIU_PLUGIN_URL . 'assets/css/cpiu-admin-order.css',
                array(),
                $this->cpiu_asset_version('assets/css/cpiu-admin-order.css')
            );
        }

        if ('toplevel_page_cpiu-settings' !== $hook) {
            return;
        }

        // Enqueue Select2 for searchable dropdowns
        // Use cached CDN resources or fallback to original URLs
        wp_enqueue_style('select2', CPIU_PLUGIN_URL . 'assets/css/select2.min.css', array(), '4.1.0');
        wp_enqueue_script('select2', CPIU_PLUGIN_URL . 'assets/js/select2.min.js', array('jquery'), '4.1.0', true);

        // Enqueue color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Version the plugin's own admin assets by file modification time so
        // edits are served immediately (no stale cached CSS/JS), while still
        // producing a stable, cacheable version string between releases.
        $js_ver    = $this->cpiu_asset_version('assets/js/cpiu-admin-multi-product.js');
        $base_ver  = $this->cpiu_asset_version('assets/css/cpiu-admin-styles.css');
        $modern_ver = $this->cpiu_asset_version('assets/css/cpiu-admin-modern.css');

        // Enqueue custom admin scripts
        wp_enqueue_script(
            'cpiu-admin-multi-product',
            CPIU_PLUGIN_URL . 'assets/js/cpiu-admin-multi-product.js',
            array('jquery', 'select2', 'wp-color-picker'),
            $js_ver,
            true
        );

        // Enqueue custom admin styles
        wp_enqueue_style(
            'cpiu-admin-styles',
            CPIU_PLUGIN_URL . 'assets/css/cpiu-admin-styles.css',
            array(),
            $base_ver
        );

        // Modern UI skin — loaded AFTER the base sheet so it restyles cosmetics
        // without touching the base's functional (JS-driven) display rules.
        wp_enqueue_style(
            'cpiu-admin-modern',
            CPIU_PLUGIN_URL . 'assets/css/cpiu-admin-modern.css',
            array('cpiu-admin-styles'),
            $modern_ver
        );

        // Localize script
        $nonce = wp_create_nonce('cpiu_admin_nonce');

        $current_tab = $this->get_current_tab_slug();
        $tab_keys = array_keys($this->get_registered_tabs());

        wp_localize_script('cpiu-admin-multi-product', 'cpiu_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'current_tab' => $current_tab,
            'base_url' => admin_url('admin.php?page=cpiu-settings'),
            'tab_slugs' => array_combine($tab_keys, $tab_keys),
            'strings' => array(
                'search_placeholder' => esc_html__('Search products by name, ID, or SKU...', 'nowdigiverse-product-image-upload'),
                'no_products_found' => esc_html__('No products found.', 'nowdigiverse-product-image-upload'),
                'loading' => esc_html__('Loading...', 'nowdigiverse-product-image-upload'),
                'save_success' => esc_html__('Configuration saved successfully!', 'nowdigiverse-product-image-upload'),
                'delete_success' => esc_html__('Configuration deleted successfully!', 'nowdigiverse-product-image-upload'),
                'error' => esc_html__('An error occurred. Please try again.', 'nowdigiverse-product-image-upload'),
                'confirm_delete' => esc_html__('Are you sure you want to delete this configuration?', 'nowdigiverse-product-image-upload'),
                'confirm_bulk_delete' => esc_html__('Are you sure you want to delete the selected configurations?', 'nowdigiverse-product-image-upload'),
                'select_products' => esc_html__('Please select at least one product.', 'nowdigiverse-product-image-upload'),
                'invalid_config' => esc_html__('Please fill in all required fields correctly.', 'nowdigiverse-product-image-upload'),
                'export_success' => esc_html__('Settings exported successfully!', 'nowdigiverse-product-image-upload'),
                'import_success' => esc_html__('Settings imported successfully!', 'nowdigiverse-product-image-upload'),
                'invalid_file' => esc_html__('Please select a valid JSON file.', 'nowdigiverse-product-image-upload'),
                'cache_cleared' => esc_html__('CDN cache cleared successfully!', 'nowdigiverse-product-image-upload'),
                'cache_refreshed' => esc_html__('CDN cache refreshed successfully!', 'nowdigiverse-product-image-upload'),
                'uninstall_preference_saved' => esc_html__('Uninstall preference saved successfully!', 'nowdigiverse-product-image-upload'),
                'network_error' => esc_html__('Network error occurred. Please try again.', 'nowdigiverse-product-image-upload'),
                'select_preference' => esc_html__('Please select a preference before saving.', 'nowdigiverse-product-image-upload'),
                'failed_clear_cache' => esc_html__('Failed to clear cache.', 'nowdigiverse-product-image-upload'),
                'network_error_cache' => esc_html__('Network error while clearing cache.', 'nowdigiverse-product-image-upload')
            )
        ));
    }

    /**
     * Build a cache-busting version string for a bundled admin asset.
     *
     * Uses the file's modification time so saved edits are served immediately
     * (no stale cached CSS/JS during development and after updates), falling
     * back to the plugin version if the file can't be stat'd.
     *
     * @param string $relative_path Path relative to the plugin root.
     * @return string Version string for wp_enqueue_*().
     */
    private function cpiu_asset_version($relative_path)
    {
        $file = CPIU_PLUGIN_PATH . $relative_path;
        $mtime = is_readable($file) ? filemtime($file) : false;

        return $mtime ? (string) $mtime : CPIU_VERSION;
    }

    /**
     * Render admin page
     */
    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $configurations = $this->data_manager->get_all_configurations();
        $global_settings = $this->data_manager->get_global_settings();
        $default_settings = $this->data_manager->get_default_settings();
        $allowed_types = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
        $current_tab = $this->get_current_tab_slug();
        $tabs = $this->get_registered_tabs();

        // Shared data passed to premium tab render callbacks (Pro add-on).
        $cpiu_context = array(
            'data_manager' => $this->data_manager,
            'configurations' => $configurations,
            'global_settings' => $global_settings,
            'default_settings' => $default_settings,
            'allowed_types' => $allowed_types,
            'current_tab' => $current_tab,
        );
        ?>
        <div class="wrap cpiu-admin-page">

            <!-- Branded header bar -->
            <div class="cpiu-admin-header">
                <span class="cpiu-admin-header__icon dashicons dashicons-camera-alt"></span>
                <div class="cpiu-admin-header__titles">
                    <h1><?php esc_html_e('NDV Product Image Upload for WooCommerce', 'nowdigiverse-product-image-upload'); ?></h1>
                    <p><?php esc_html_e('Let customers upload & crop files for your WooCommerce products.', 'nowdigiverse-product-image-upload'); ?></p>
                </div>
                <span class="cpiu-admin-header__badge">v<?php echo esc_html(CPIU_VERSION); ?></span>
            </div>
            <div class="wp-header-end"></div>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper cpiu-tab-nav">
                <?php foreach ($tabs as $cpiu_tab_slug => $cpiu_tab) :
                    $cpiu_tab_icon = isset($cpiu_tab['icon']) ? $cpiu_tab['icon'] : 'dashicons-admin-generic';
                    ?>
                    <a href="<?php echo esc_url($this->get_tab_url($cpiu_tab_slug)); ?>"
                        class="nav-tab <?php echo esc_attr($current_tab === $cpiu_tab_slug ? 'nav-tab-active' : ''); ?>"
                        data-tab="<?php echo esc_attr($cpiu_tab_slug); ?>">
                        <span class="dashicons <?php echo esc_attr($cpiu_tab_icon); ?>"></span>
                        <?php echo esc_html($cpiu_tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Tab Content -->
            <div class="cpiu-tab-content">

                <!-- Global Settings Tab -->
                <div id="global-settings"
                    class="cpiu-tab-pane <?php echo esc_attr($current_tab === 'global-settings' ? 'active' : ''); ?>">
                    <div class="cpiu-section">
                        <h2><?php esc_html_e('Global Settings', 'nowdigiverse-product-image-upload'); ?></h2>
                        <p><?php esc_html_e('Configure settings that apply globally to the entire plugin and all products.', 'nowdigiverse-product-image-upload'); ?>
                        </p>

                        <form id="cpiu-global-settings-form" class="cpiu-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Disable Express Checkout', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="disable_express_checkout" value="1" <?php checked(!empty($global_settings['disable_express_checkout'])); ?>>
                                            <?php esc_html_e('Hide express checkout buttons', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Hide express checkout buttons (Apple Pay, Google Pay, PayPal Express, etc.) on product pages to prevent bypassing image upload.', 'nowdigiverse-product-image-upload'); ?>
                                            <br>
                                            <span
                                                class="cpiu-info-note"><strong><?php esc_html_e('Note:', 'nowdigiverse-product-image-upload'); ?></strong>
                                                <?php esc_html_e('This setting applies universally to all products where this plugin is active.', 'nowdigiverse-product-image-upload'); ?></span>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Auto-Cleanup Order Images', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="enable_order_image_cleanup" value="1" <?php checked(!empty($global_settings['enable_order_image_cleanup'])); ?>>
                                            <?php esc_html_e('Automatically delete uploaded images from completed orders', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('When enabled, uploaded images attached to completed, cancelled, or refunded orders will be automatically deleted after the specified number of days. Images from pending, processing, or on-hold orders will not be affected.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="cpiu-cleanup-days-row" data-show-when="enable_order_image_cleanup">
                                    <th scope="row">
                                        <label
                                            for="order_image_cleanup_days"><?php esc_html_e('Cleanup After (Days)', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="order_image_cleanup_days" name="order_image_cleanup_days"
                                            value="<?php echo esc_attr($global_settings['order_image_cleanup_days']); ?>"
                                            min="1" max="365" class="regular-text">
                                        <p class="description">
                                            <?php esc_html_e('Number of days after order completion before images are deleted (1–365). Default: 30 days.', 'nowdigiverse-product-image-upload'); ?>
                                            <br>
                                            <span
                                                class="cpiu-info-note"><strong><?php esc_html_e('Note:', 'nowdigiverse-product-image-upload'); ?></strong>
                                                <?php esc_html_e('The cleanup runs automatically once daily via WordPress cron.', 'nowdigiverse-product-image-upload'); ?></span>
                                        </p>
                                    </td>
                                </tr>
                                <?php
                                /**
                                 * Fires inside the Global Settings form table.
                                 *
                                 * Add-ons (e.g. the Pro Elementor integration) hook this to render
                                 * additional settings rows. Callbacks should echo `<tr>...</tr>`.
                                 *
                                 * @param array $global_settings Current global settings.
                                 */
                                do_action('cpiu_global_settings_fields', $global_settings);
                                ?>
                            </table>
                            <p class="submit">
                                <button type="submit"
                                    class="button button-primary"><?php esc_html_e('Save Global Settings', 'nowdigiverse-product-image-upload'); ?></button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Default Settings Tab -->
                <div id="default-settings"
                    class="cpiu-tab-pane <?php echo esc_attr($current_tab === 'default-settings' ? 'active' : ''); ?>">
                    <div class="cpiu-section">
                        <h2><?php esc_html_e('Default Settings', 'nowdigiverse-product-image-upload'); ?></h2>
                        <p><?php esc_html_e('Configure default settings that will be used as fallback for products without specific configurations.', 'nowdigiverse-product-image-upload'); ?>
                        </p>

                        <form id="cpiu-default-settings-form" class="cpiu-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="default_image_count"><?php esc_html_e('Default Required Files', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="default_image_count" name="image_count"
                                            value="<?php echo esc_attr($default_settings['image_count']); ?>" min="1" max="50"
                                            class="regular-text" required>
                                        <p class="description">
                                            <?php esc_html_e('Default number of files required for products.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="default_max_file_size"><?php esc_html_e('Default Max File Size (MB)', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="default_max_file_size" name="max_file_size"
                                            value="<?php echo esc_attr($default_settings['max_file_size'] / 1024 / 1024); ?>"
                                            min="1" step="0.1" class="regular-text" required>
                                        <p class="description">
                                            <?php esc_html_e('Default maximum file size in megabytes.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="default_button_text"><?php esc_html_e('Default Button Text', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="default_button_text" name="button_text"
                                            value="<?php echo esc_attr($default_settings['button_text']); ?>"
                                            class="regular-text">
                                        <p class="description">
                                            <?php esc_html_e('Default text for the upload button.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="default_button_color"><?php esc_html_e('Default Button Color', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="default_button_color" name="button_color"
                                            value="<?php echo esc_attr($default_settings['button_color']); ?>"
                                            class="cpiu-color-field">
                                        <p class="description">
                                            <?php esc_html_e('Default color for progress bars and buttons.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Default Allowed File Types', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        foreach ($allowed_types as $type) {
                                            $checked = in_array($type, $default_settings['allowed_types']) ? 'checked' : '';
                                            ?>
                                            <label class="cpiu-admin-checkbox-label">
                                                <input type="checkbox" class="cpiu-admin-checkbox" name="allowed_types[]"
                                                    value="<?php echo esc_attr($type); ?>" <?php echo esc_attr($checked); ?>>
                                                <?php echo esc_html(strtoupper($type)); ?>
                                            </label>
                                            <?php
                                        }
                                        ?>
                                        <p class="description">
                                            <?php esc_html_e('Default allowed file types for products.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Resolution Validation', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="resolution_validation" value="1" <?php checked(!empty($default_settings['resolution_validation'])); ?>>
                                            <?php esc_html_e('Enable resolution validation for uploaded images', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('When enabled, uploaded images must meet minimum and maximum dimension requirements.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="resolution-settings cpiu-admin-form-row" data-show-when="resolution_validation">
                                    <th scope="row">
                                        <label><?php esc_html_e('Minimum Dimensions', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <?php esc_html_e('Width:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" class="cpiu-admin-input cpiu-dimension-input" name="min_width"
                                                value="<?php echo esc_attr($default_settings['min_width']); ?>" min="0"
                                                max="10000">
                                            px
                                        </label>
                                        <label class="cpiu-dimension-label-spacing">
                                            <?php esc_html_e('Height:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" class="cpiu-admin-input cpiu-dimension-input" name="min_height"
                                                value="<?php echo esc_attr($default_settings['min_height']); ?>" min="0"
                                                max="10000">
                                            px
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Minimum required dimensions (0 = no minimum).', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="resolution-settings cpiu-admin-form-row" data-show-when="resolution_validation">
                                    <th scope="row">
                                        <label><?php esc_html_e('Maximum Dimensions', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label class="cpiu-dimension-label">
                                            <?php esc_html_e('Width:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" name="max_width"
                                                value="<?php echo esc_attr($default_settings['max_width']); ?>" min="0"
                                                max="10000" class="cpiu-admin-input cpiu-dimension-input">
                                            px
                                        </label>
                                        <label class="cpiu-dimension-label cpiu-dimension-label-spacing">
                                            <?php esc_html_e('Height:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" name="max_height"
                                                value="<?php echo esc_attr($default_settings['max_height']); ?>" min="0"
                                                max="10000" class="cpiu-admin-input cpiu-dimension-input">
                                            px
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Maximum allowed dimensions (0 = no maximum).', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Shape Cropping', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="enable_shape_cropping" value="1" <?php checked(!empty($default_settings['enable_shape_cropping'])); ?>>
                                            <?php esc_html_e('Enable shape cropping options', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Allow users to crop images using shape presets.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Cropping Ratio', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <select name="cropping_ratio" class="cpiu-admin-select">
                                            <option value="free" <?php selected($default_settings['cropping_ratio'], 'free'); ?>><?php esc_html_e('Free (No restrictions)', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                            <option value="1:1" <?php selected($default_settings['cropping_ratio'], '1:1'); ?>>
                                                <?php esc_html_e('Square (1:1)', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                            <option value="4:3" <?php selected($default_settings['cropping_ratio'], '4:3'); ?>>
                                                <?php esc_html_e('4:3', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                            <option value="16:9" <?php selected($default_settings['cropping_ratio'], '16:9'); ?>><?php esc_html_e('16:9', 'nowdigiverse-product-image-upload'); ?></option>
                                            <option value="2:3" <?php selected($default_settings['cropping_ratio'], '2:3'); ?>>
                                                <?php esc_html_e('2:3', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('Force a specific aspect ratio for cropping.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Quantity', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="disable_quantity" value="1" <?php checked(!empty($default_settings['disable_quantity'])); ?>>
                                            <?php esc_html_e('Lock quantity to 1', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Prevent customers from ordering more than one of a product they upload artwork for.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <button type="submit"
                                    class="button button-primary"><?php esc_html_e('Save Default Settings', 'nowdigiverse-product-image-upload'); ?></button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Add Configuration Tab -->
                <div id="add-configuration"
                    class="cpiu-tab-pane <?php echo esc_attr($current_tab === 'add-configuration' ? 'active' : ''); ?>">
                    <div class="cpiu-section">
                        <h2><?php esc_html_e('Add Product Configuration', 'nowdigiverse-product-image-upload'); ?></h2>
                        <p><?php esc_html_e('Configure image upload settings for individual products.', 'nowdigiverse-product-image-upload'); ?>
                        </p>

                        <form id="cpiu-add-config-form" class="cpiu-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="product_search"><?php esc_html_e('Select Product', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <select id="product_search" name="product_id"
                                            class="cpiu-product-select cpiu-admin-select" required>
                                            <option value="">
                                                <?php esc_html_e('Search for a product...', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('Start typing to search for products by name or ID.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="image_count"><?php esc_html_e('Required Files', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="image_count" name="image_count"
                                            value="<?php echo esc_attr($default_settings['image_count']); ?>" min="1" max="50"
                                            class="regular-text" required>
                                        <p class="description">
                                            <?php esc_html_e('Number of files required for this product.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="max_file_size"><?php esc_html_e('Max File Size (MB)', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="max_file_size" name="max_file_size"
                                            value="<?php echo esc_attr($default_settings['max_file_size'] / 1024 / 1024); ?>"
                                            min="1" step="0.1" class="regular-text" required>
                                        <p class="description">
                                            <?php esc_html_e('Maximum file size in megabytes.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="button_text"><?php esc_html_e('Button Text', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="button_text" name="button_text"
                                            value="<?php echo esc_attr($default_settings['button_text']); ?>"
                                            class="regular-text">
                                        <p class="description">
                                            <?php esc_html_e('Text for the upload button (leave empty to use default).', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="button_color"><?php esc_html_e('Button Color', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="button_color" name="button_color"
                                            value="<?php echo esc_attr($default_settings['button_color']); ?>"
                                            class="cpiu-color-field">
                                        <p class="description">
                                            <?php esc_html_e('Color for progress bars and buttons (leave empty to use default).', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Allowed File Types', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        foreach ($allowed_types as $type) {
                                            $checked = in_array($type, $default_settings['allowed_types']) ? 'checked' : '';
                                            ?>
                                            <label class="cpiu-admin-checkbox-label">
                                                <input type="checkbox" name="allowed_types[]" value="<?php echo esc_attr($type); ?>"
                                                    <?php echo esc_attr($checked); ?> class="cpiu-admin-checkbox">
                                                <?php echo esc_html(strtoupper($type)); ?>
                                            </label>
                                            <?php
                                        }
                                        ?>
                                        <p class="description">
                                            <?php esc_html_e('Allowed file types for this product.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Resolution Validation', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="resolution_validation" value="1" <?php checked(!empty($default_settings['resolution_validation'])); ?>>
                                            <?php esc_html_e('Enable resolution validation for uploaded images', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('When enabled, uploaded images must meet minimum and maximum dimension requirements.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="resolution-settings cpiu-admin-form-row" data-show-when="resolution_validation">
                                    <th scope="row">
                                        <label><?php esc_html_e('Minimum Dimensions', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label class="cpiu-dimension-label">
                                            <?php esc_html_e('Width:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" name="min_width"
                                                value="<?php echo esc_attr($default_settings['min_width']); ?>" min="0"
                                                max="10000" class="cpiu-admin-input cpiu-dimension-input">
                                            px
                                        </label>
                                        <label class="cpiu-dimension-label cpiu-dimension-label-spacing">
                                            <?php esc_html_e('Height:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" name="min_height"
                                                value="<?php echo esc_attr($default_settings['min_height']); ?>" min="0"
                                                max="10000" class="cpiu-admin-input cpiu-dimension-input">
                                            px
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Minimum required dimensions (0 = no minimum).', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="resolution-settings cpiu-conditional-row" data-condition="resolution_validation">
                                    <th scope="row">
                                        <label><?php esc_html_e('Maximum Dimensions', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label class="cpiu-dimension-label">
                                            <?php esc_html_e('Width:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" name="max_width"
                                                value="<?php echo esc_attr($default_settings['max_width']); ?>" min="0"
                                                max="10000" class="cpiu-dimension-input">
                                            px
                                        </label>
                                        <label class="cpiu-dimension-label cpiu-dimension-label-spaced">
                                            <?php esc_html_e('Height:', 'nowdigiverse-product-image-upload'); ?>
                                            <input type="number" name="max_height"
                                                value="<?php echo esc_attr($default_settings['max_height']); ?>" min="0"
                                                max="10000" class="cpiu-dimension-input">
                                            px
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Maximum allowed dimensions (0 = no maximum).', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Shape Cropping', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="enable_shape_cropping" value="1" <?php checked(!empty($default_settings['enable_shape_cropping'])); ?>>
                                            <?php esc_html_e('Enable shape cropping options', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Allow users to crop images using shape presets.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Cropping Ratio', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <select name="cropping_ratio" class="cpiu-admin-select">
                                            <option value="free" <?php selected($default_settings['cropping_ratio'], 'free'); ?>><?php esc_html_e('Free (No restrictions)', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                            <option value="1:1" <?php selected($default_settings['cropping_ratio'], '1:1'); ?>>
                                                <?php esc_html_e('Square (1:1)', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                            <option value="4:3" <?php selected($default_settings['cropping_ratio'], '4:3'); ?>>
                                                <?php esc_html_e('4:3', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                            <option value="16:9" <?php selected($default_settings['cropping_ratio'], '16:9'); ?>><?php esc_html_e('16:9', 'nowdigiverse-product-image-upload'); ?></option>
                                            <option value="2:3" <?php selected($default_settings['cropping_ratio'], '2:3'); ?>>
                                                <?php esc_html_e('2:3', 'nowdigiverse-product-image-upload'); ?>
                                            </option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('Force a specific aspect ratio for cropping.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Quantity', 'nowdigiverse-product-image-upload'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="disable_quantity" value="1" <?php checked(!empty($default_settings['disable_quantity'])); ?>>
                                            <?php esc_html_e('Lock quantity to 1', 'nowdigiverse-product-image-upload'); ?>
                                        </label>
                                        <p class="description">
                                            <?php esc_html_e('Prevent customers from ordering more than one of this product.', 'nowdigiverse-product-image-upload'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <?php
                            /**
                             * Fires in the Add Configuration form after the settings table and
                             * before the submit button. The Pro add-on hooks this to render the
                             * collapsible "Pricing Settings" section.
                             *
                             * @param array $args Context: form type and default settings.
                             */
                            do_action('cpiu_config_form_fields', array('context' => 'add', 'defaults' => $default_settings));
                            ?>
                            <p class="submit">
                                <button type="submit"
                                    class="button button-primary"><?php esc_html_e('Add Configuration', 'nowdigiverse-product-image-upload'); ?></button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Existing Configurations Tab -->
                <div id="existing-configurations"
                    class="cpiu-tab-pane <?php echo esc_attr($current_tab === 'existing-configurations' ? 'active' : ''); ?>">
                    <div class="cpiu-section">
                        <h2><?php esc_html_e('Existing Configurations', 'nowdigiverse-product-image-upload'); ?></h2>
                        <p><?php esc_html_e('Manage existing product configurations.', 'nowdigiverse-product-image-upload'); ?></p>

                        <div id="cpiu-configurations-table">
                            <?php $this->render_configurations_table($configurations); ?>
                        </div>
                    </div>
                </div>

                <!-- Uninstall Preferences Tab -->
                <div id="uninstall-preferences"
                    class="cpiu-tab-pane <?php echo esc_attr($current_tab === 'uninstall-preferences' ? 'active' : ''); ?>">
                    <div class="cpiu-section">
                        <h2><?php esc_html_e('Uninstall Preferences', 'nowdigiverse-product-image-upload'); ?></h2>
                        <p><?php esc_html_e('Configure what happens to your data when the plugin is uninstalled.', 'nowdigiverse-product-image-upload'); ?>
                        </p>

                        <?php
                        // Get the uninstall preference, with null as default to detect if it's been set
                        // Get the uninstall preference
                        $preference = get_option('cpiu_keep_data_on_uninstall', 'keep');

                        // Determine boolean state for radio button selection
                        // Only false if explicitly set to 'delete'
                        $keep_data = ($preference !== 'delete');
                        ?>

                        <div class="cpiu-uninstall-warning">
                            <h3 class="cpiu-warning-title">
                                <span class="dashicons dashicons-warning cpiu-warning-icon"></span>
                                <?php esc_html_e('Important: Data Protection Notice', 'nowdigiverse-product-image-upload'); ?>
                            </h3>
                            <p class="cpiu-warning-text">
                                <?php esc_html_e('This plugin follows WordPress best practices for data handling:', 'nowdigiverse-product-image-upload'); ?>
                            </p>
                            <ul class="cpiu-warning-list">
                                <li><strong><?php esc_html_e('Deactivation:', 'nowdigiverse-product-image-upload'); ?></strong>
                                    <?php esc_html_e('No data is deleted when you deactivate the plugin. All settings and uploaded images are preserved.', 'nowdigiverse-product-image-upload'); ?>
                                </li>
                                <li><strong><?php esc_html_e('Uninstallation:', 'nowdigiverse-product-image-upload'); ?></strong>
                                    <?php esc_html_e('You can choose whether to keep or delete your data during uninstallation.', 'nowdigiverse-product-image-upload'); ?>
                                </li>
                            </ul>
                        </div>

                        <div class="cpiu-uninstall-options">
                            <h3><?php esc_html_e('Uninstall Data Preference', 'nowdigiverse-product-image-upload'); ?></h3>
                            <p><?php esc_html_e('Choose what should happen to your plugin data when you uninstall the plugin:', 'nowdigiverse-product-image-upload'); ?>
                            </p>

                            <div class="cpiu-preference-options">
                                <label class="cpiu-preference-option">
                                    <input type="radio" name="cpiu_uninstall_preference" value="keep" <?php checked($keep_data, true); ?> class="cpiu-radio-input">
                                    <strong><?php esc_html_e('Keep all data (Recommended)', 'nowdigiverse-product-image-upload'); ?></strong>
                                    <p class="cpiu-preference-description">
                                        <?php esc_html_e('Preserve all settings, configurations, and uploaded images. You can reinstall the plugin later without losing any data.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </label>

                                <label class="cpiu-preference-option">
                                    <input type="radio" name="cpiu_uninstall_preference" value="delete" <?php checked($keep_data, false); ?> class="cpiu-radio-input">
                                    <strong><?php esc_html_e('Delete all data', 'nowdigiverse-product-image-upload'); ?></strong>
                                    <p class="cpiu-preference-description">
                                        <?php esc_html_e('Remove all plugin settings and configurations. Note: Uploaded images referenced in orders will be preserved for data integrity.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </label>
                            </div>

                            <div class="cpiu-save-preference">
                                <button type="button" id="cpiu-save-uninstall-preference" class="button button-primary">
                                    <?php esc_html_e('Save Preference', 'nowdigiverse-product-image-upload'); ?>
                                </button>
                            </div>
                        </div>

                        <div class="cpiu-export-before-uninstall">
                            <h3><?php esc_html_e('Export Settings Before Uninstall', 'nowdigiverse-product-image-upload'); ?></h3>
                            <p><?php esc_html_e('Before uninstalling, you can export your settings to a backup file. This allows you to restore your configuration if you reinstall the plugin later.', 'nowdigiverse-product-image-upload'); ?>
                            </p>

                            <div class="cpiu-export-action">
                                <button type="button" id="cpiu-export-for-uninstall" class="button button-secondary">
                                    <span class="dashicons dashicons-download cpiu-icon-spacing"></span>
                                    <?php esc_html_e('Export Settings Backup', 'nowdigiverse-product-image-upload'); ?>
                                </button>
                            </div>

                            <div class="cpiu-export-info">
                                <h4 class="cpiu-export-info-title">
                                    <?php esc_html_e('What gets exported:', 'nowdigiverse-product-image-upload'); ?>
                                </h4>
                                <ul class="cpiu-export-list">
                                    <li><?php esc_html_e('All plugin settings and configurations', 'nowdigiverse-product-image-upload'); ?>
                                    </li>
                                    <li><?php esc_html_e('Product-specific image upload configurations', 'nowdigiverse-product-image-upload'); ?>
                                    </li>
                                    <li><?php esc_html_e('Default settings and preferences', 'nowdigiverse-product-image-upload'); ?>
                                    </li>
                                    <li><?php esc_html_e('Export timestamp and plugin version', 'nowdigiverse-product-image-upload'); ?>
                                    </li>
                                </ul>
                                <p class="cpiu-export-note">
                                    <?php esc_html_e('Note: Uploaded images are not included in the export. Only configuration settings are backed up.', 'nowdigiverse-product-image-upload'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                // Premium tab panes registered by the Pro add-on via the
                // `cpiu_admin_tabs` filter (each provides a render callback).
                foreach ($tabs as $cpiu_tab_slug => $cpiu_tab) :
                    if (empty($cpiu_tab['callback']) || !is_callable($cpiu_tab['callback'])) {
                        continue;
                    }
                    ?>
                    <div id="<?php echo esc_attr($cpiu_tab_slug); ?>"
                        class="cpiu-tab-pane <?php echo esc_attr($current_tab === $cpiu_tab_slug ? 'active' : ''); ?>">
                        <?php call_user_func($cpiu_tab['callback'], $cpiu_context); ?>
                    </div>
                    <?php
                endforeach;
                ?>

            </div>
        </div>
        <?php
    }

    /**
     * Render configurations table
     */
    private function render_configurations_table($configurations)
    {
        if (empty($configurations)) {
            echo '<p>' . esc_html__('No product configurations found.', 'nowdigiverse-product-image-upload') . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column"><?php esc_html_e('Product ID', 'nowdigiverse-product-image-upload'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Product', 'nowdigiverse-product-image-upload'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Images Required', 'nowdigiverse-product-image-upload'); ?>
                    </th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Max File Size', 'nowdigiverse-product-image-upload'); ?>
                    </th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Allowed Types', 'nowdigiverse-product-image-upload'); ?>
                    </th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Status', 'nowdigiverse-product-image-upload'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Actions', 'nowdigiverse-product-image-upload'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($configurations as $product_id => $config): ?>
                    <?php
                    $product = wc_get_product($product_id);
                    /* translators: %1$d: Product ID */
                    $product_name = $product ? $product->get_name() : sprintf(esc_html__('Product #%1$d (deleted)', 'nowdigiverse-product-image-upload'), $product_id);
                    $product_exists = $product !== false;
                    ?>
                    <tr data-product-id="<?php echo esc_attr($product_id); ?>">
                        <td>
                            <strong><?php echo esc_html($product_id); ?></strong>
                        </td>
                        <td>
                            <strong><?php echo esc_html($product_name); ?></strong>
                            <?php if (!$product_exists): ?>
                                <span
                                    class="cpiu-deleted-product"><?php esc_html_e('(Product deleted)', 'nowdigiverse-product-image-upload'); ?></span>
                                <?php
                            endif; ?>
                        </td>
                        <td><?php echo esc_html($config['image_count']); ?></td>
                        <td><?php echo esc_html(size_format($config['max_file_size'])); ?></td>
                        <td><?php echo esc_html(implode(', ', array_map('strtoupper', $config['allowed_types']))); ?></td>
                        <td>
                            <?php if ($config['enabled']): ?>
                                <span class="cpiu-status-enabled"><?php esc_html_e('Enabled', 'nowdigiverse-product-image-upload'); ?></span>
                                <?php
                            else: ?>
                                <span
                                    class="cpiu-status-disabled"><?php esc_html_e('Disabled', 'nowdigiverse-product-image-upload'); ?></span>
                                <?php
                            endif; ?>
                        </td>
                        <td>
                            <button type="button" class="button cpiu-edit-config"
                                data-product-id="<?php echo esc_attr($product_id); ?>">
                                <?php esc_html_e('Edit', 'nowdigiverse-product-image-upload'); ?>
                            </button>
                            <button type="button" class="button button-link-delete cpiu-delete-config"
                                data-product-id="<?php echo esc_attr($product_id); ?>">
                                <?php esc_html_e('Delete', 'nowdigiverse-product-image-upload'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php
                endforeach; ?>
            </tbody>
        </table>

        <!-- Edit Configuration Modal -->
        <div id="cpiu-edit-modal" class="cpiu-modal cpiu-modal-hidden">
            <div class="cpiu-modal-content">
                <div class="cpiu-modal-header">
                    <h3><?php esc_html_e('Edit Configuration', 'nowdigiverse-product-image-upload'); ?></h3>
                    <button type="button" class="cpiu-modal-close">&times;</button>
                </div>
                <div class="cpiu-modal-body">
                    <form id="cpiu-edit-config-form" class="cpiu-form">
                        <input type="hidden" id="edit_product_id" name="product_id">

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Product', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <strong id="edit_product_name"></strong>
                                    <p class="description"><?php esc_html_e('Product ID:', 'nowdigiverse-product-image-upload'); ?>
                                        <span id="edit_product_id_display"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="edit_image_count"><?php esc_html_e('Required Files', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="edit_image_count" name="image_count" min="1" max="50"
                                        class="regular-text" required>
                                    <p class="description">
                                        <?php esc_html_e('Number of files required for this product.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="edit_max_file_size"><?php esc_html_e('Max File Size (MB)', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="edit_max_file_size" name="max_file_size" min="1" step="0.1"
                                        class="regular-text" required>
                                    <p class="description">
                                        <?php esc_html_e('Maximum file size in megabytes.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="edit_button_text"><?php esc_html_e('Button Text', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="edit_button_text" name="button_text" class="regular-text">
                                    <p class="description">
                                        <?php esc_html_e('Text for the upload button (leave empty to use default).', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="edit_button_color"><?php esc_html_e('Button Color', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="edit_button_color" name="button_color" class="cpiu-color-field">
                                    <p class="description">
                                        <?php esc_html_e('Color for progress bars and buttons (leave empty to use default).', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Allowed File Types', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <div id="edit_allowed_types">
                                        <?php
                                        $allowed_types = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
                                        foreach ($allowed_types as $type) {
                                            ?>
                                            <label class="cpiu-admin-checkbox-label">
                                                <input type="checkbox" name="allowed_types[]" value="<?php echo esc_attr($type); ?>"
                                                    class="cpiu-admin-checkbox">
                                                <?php echo esc_html(strtoupper($type)); ?>
                                            </label>
                                            <?php
                                        }
                                        ?>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Allowed file types for this product.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Resolution Validation', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="edit_resolution_validation" name="resolution_validation"
                                            value="1">
                                        <?php esc_html_e('Enable resolution validation for uploaded images', 'nowdigiverse-product-image-upload'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('When enabled, uploaded images must meet minimum and maximum dimension requirements.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr class="edit-resolution-settings cpiu-conditional-row" data-condition="edit-resolution">
                                <th scope="row">
                                    <label><?php esc_html_e('Minimum Dimensions', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <label class="cpiu-dimension-label">
                                        <?php esc_html_e('Width:', 'nowdigiverse-product-image-upload'); ?>
                                        <input type="number" id="edit_min_width" name="min_width" min="0" max="10000"
                                            class="cpiu-dimension-input">
                                        px
                                    </label>
                                    <label class="cpiu-dimension-label cpiu-dimension-label-spaced">
                                        <?php esc_html_e('Height:', 'nowdigiverse-product-image-upload'); ?>
                                        <input type="number" id="edit_min_height" name="min_height" min="0" max="10000"
                                            class="cpiu-dimension-input">
                                        px
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Minimum required dimensions (0 = no minimum).', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr class="edit-resolution-settings cpiu-conditional-row" data-condition="edit-resolution">
                                <th scope="row">
                                    <label><?php esc_html_e('Maximum Dimensions', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <label class="cpiu-dimension-label">
                                        <?php esc_html_e('Width:', 'nowdigiverse-product-image-upload'); ?>
                                        <input type="number" id="edit_max_width" name="max_width" min="0" max="10000"
                                            class="cpiu-dimension-input">
                                        px
                                    </label>
                                    <label class="cpiu-dimension-label cpiu-dimension-label-spaced">
                                        <?php esc_html_e('Height:', 'nowdigiverse-product-image-upload'); ?>
                                        <input type="number" id="edit_max_height" name="max_height" min="0" max="10000"
                                            class="cpiu-dimension-input">
                                        px
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Maximum allowed dimensions (0 = no maximum).', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Shape Cropping', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="edit_enable_shape_cropping" name="enable_shape_cropping"
                                            value="1">
                                        <?php esc_html_e('Enable shape cropping options', 'nowdigiverse-product-image-upload'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Allow users to crop images using shape presets.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Cropping Ratio', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <select id="edit_cropping_ratio" name="cropping_ratio" class="cpiu-admin-select">
                                        <option value="free">
                                            <?php esc_html_e('Free (No restrictions)', 'nowdigiverse-product-image-upload'); ?>
                                        </option>
                                        <option value="1:1"><?php esc_html_e('Square (1:1)', 'nowdigiverse-product-image-upload'); ?>
                                        </option>
                                        <option value="4:3"><?php esc_html_e('4:3', 'nowdigiverse-product-image-upload'); ?></option>
                                        <option value="16:9"><?php esc_html_e('16:9', 'nowdigiverse-product-image-upload'); ?>
                                        </option>
                                        <option value="2:3"><?php esc_html_e('2:3', 'nowdigiverse-product-image-upload'); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Force a specific aspect ratio for cropping.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Quantity', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="edit_disable_quantity" name="disable_quantity"
                                            value="1">
                                        <?php esc_html_e('Lock quantity to 1', 'nowdigiverse-product-image-upload'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Prevent customers from ordering more than one of this product.', 'nowdigiverse-product-image-upload'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label
                                        for="edit_enabled"><?php esc_html_e('Status', 'nowdigiverse-product-image-upload'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="edit_enabled" name="enabled" value="1">
                                        <?php esc_html_e('Enable image upload for this product', 'nowdigiverse-product-image-upload'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <?php
                        /**
                         * Fires in the Edit Configuration modal after the settings table.
                         * The Pro add-on hooks this to render the "Pricing Settings" section.
                         *
                         * @param array $args Context for the edit form.
                         */
                        do_action('cpiu_config_form_fields', array('context' => 'edit'));
                        ?>
                    </form>
                </div>
                <div class="cpiu-modal-footer">
                    <button type="button" id="cpiu-save-edit"
                        class="button button-primary"><?php esc_html_e('Save Changes', 'nowdigiverse-product-image-upload'); ?></button>
                    <button type="button" id="cpiu-cancel-edit"
                        class="button"><?php esc_html_e('Cancel', 'nowdigiverse-product-image-upload'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting configurations
     */
    public function get_configurations_ajax()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        $configurations = $this->data_manager->get_all_configurations();
        wp_send_json_success(array('configurations' => $configurations));
    }

    /**
     * AJAX handler for getting a single configuration
     */
    public function get_configuration_ajax()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product ID.', 'nowdigiverse-product-image-upload')));
        }

        $configuration = $this->data_manager->get_product_configuration($product_id);

        if (!$configuration) {
            wp_send_json_error(array('message' => __('Configuration not found.', 'nowdigiverse-product-image-upload')));
        }

        // Get product information
        $product = wc_get_product($product_id);
        /* translators: %1$d: Product ID */
        $product_name = $product ? $product->get_name() : sprintf(esc_html__('Product #%1$d (deleted)', 'nowdigiverse-product-image-upload'), $product_id);

        wp_send_json_success(array(
            'configuration' => $configuration,
            'product_name' => $product_name,
            'product_id' => $product_id
        ));
    }

    /**
     * AJAX handler for setting uninstall preference
     */
    public function set_uninstall_preference_ajax()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        $preference = isset($_POST['preference']) ? sanitize_text_field(wp_unslash($_POST['preference'])) : '';

        if ($preference === 'keep') {
            update_option('cpiu_keep_data_on_uninstall', 'keep');
            wp_send_json_success(array('message' => __('Data will be preserved during uninstallation.', 'nowdigiverse-product-image-upload')));
        } elseif ($preference === 'delete') {
            update_option('cpiu_keep_data_on_uninstall', 'delete');
            wp_send_json_success(array('message' => __('Data will be deleted during uninstallation.', 'nowdigiverse-product-image-upload')));
        } else {
            wp_send_json_error(array('message' => __('Invalid preference.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * AJAX handler for exporting settings before uninstall
     */
    public function export_for_uninstall_ajax()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        // Get all plugin settings
        $settings = array(
            'cpiu_settings' => get_option('cpiu_settings', array()),
            'cpiu_default_settings' => get_option('cpiu_default_settings', array()),
            'cpiu_global_settings' => get_option('cpiu_global_settings', array()),
            'cpiu_multi_product_configs' => get_option('cpiu_multi_product_configs', array()),
            'export_date' => current_time('mysql'),
            'plugin_version' => CPIU_VERSION
        );

        $filename = 'cpiu-settings-backup-' . gmdate('Y-m-d-H-i-s') . '.json';

        wp_send_json_success(array(
            'data' => wp_json_encode($settings, JSON_PRETTY_PRINT),
            'filename' => $filename,
            'message' => esc_html__('Settings exported successfully for backup before uninstall.', 'nowdigiverse-product-image-upload')
        ));
    }

    /**
     * Hide custom meta from native WooCommerce display in Admin
     */
    public function hide_custom_order_meta($hidden_meta)
    {
        $hidden_meta[] = '_cpiu_uploaded_images';
        $hidden_meta[] = 'cpiu_uploaded_images';
        $hidden_meta[] = esc_html__('Uploaded files', 'nowdigiverse-product-image-upload');
        return $hidden_meta;
    }

    /**
     * Display uploaded file thumbnails in the admin order edit screen.
     * Renders images as thumbnails and PDFs as a document icon with a download link.
     */
    public function display_uploaded_images_in_admin($item_id, $item, $product)
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $uploaded_images_str = $item->get_meta('_cpiu_uploaded_images');

        if ($uploaded_images_str) {
            // Layout lives in assets/css/cpiu-admin-order.css (enqueued on order screens).
            echo '<div class="cpiu-admin-order-item-images">';
            echo '<strong class="cpiu-admin-order-images-title">' . esc_html__('Uploaded files:', 'nowdigiverse-product-image-upload') . '</strong>';

            echo '<div class="cpiu-admin-order-files-grid">';
            $images = explode(', ', $uploaded_images_str);
            $original_names_str = $item->get_meta('_cpiu_original_filenames');
            $original_names = $original_names_str ? explode('|', $original_names_str) : array();

            foreach ($images as $index => $image_url) {
                $trimmed_url = trim($image_url);
                if (filter_var($trimmed_url, FILTER_VALIDATE_URL)) {
                    $filename = wp_basename(wp_parse_url($trimmed_url, PHP_URL_PATH));
                    $token = cpiu_generate_public_token($filename);

                    $secured_url = add_query_arg(array(
                        'cpiu_file' => $filename,
                        'cpiu_token' => $token
                    ), home_url('/'));

                    $download_url = add_query_arg('download', '1', $secured_url);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    $display_name = isset($original_names[$index]) ? $original_names[$index] : $filename;

                    echo '<div class="cpiu-admin-file-card">';

                    if ($ext === 'pdf') {
                        // PDF: clickable icon (opens in new tab), no filename text (as requested for "clean UI")
                        echo '<a href="' . esc_url($secured_url) . '" target="_blank" rel="noopener noreferrer" class="cpiu-admin-file-preview">';
                        echo '<span class="cpiu-admin-pdf-box"><span aria-hidden="true">📄</span></span>';
                        echo '</a>';
                    } else {
                        // Image: Thumbnail preview
                        echo '<a href="' . esc_url($secured_url) . '" target="_blank" rel="noopener noreferrer" class="cpiu-admin-file-preview">';
                        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Customer-uploaded file served via HMAC token URL, not a media-library attachment.
                        echo '<img src="' . esc_url($secured_url) . '" alt="' . esc_attr__('Uploaded file', 'nowdigiverse-product-image-upload') . '" loading="lazy">';
                        echo '</a>';
                        // Show original filename for images
                        echo '<div class="cpiu-admin-file-name" title="' . esc_attr($display_name) . '">' . esc_html($display_name) . '</div>';
                    }

                    // Download Button
                    echo '<a href="' . esc_url($download_url) . '" class="button button-small cpiu-download-file-btn" target="_blank" rel="noopener noreferrer">' . esc_html__('Download file', 'nowdigiverse-product-image-upload') . '</a>';

                    echo '</div>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
    }
}
