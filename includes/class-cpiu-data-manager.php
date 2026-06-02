<?php
/**
 * CPIU Data Manager Class
 * 
 * Handles data storage, retrieval, and management for multi-product configurations
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class CPIU_Data_Manager
{

    /**
     * Option name for storing multi-product configurations
     */
    const MULTI_PRODUCT_OPTION = 'cpiu_multi_product_configs';

    /**
     * Option name for storing default settings
     */
    const DEFAULT_SETTINGS_OPTION = 'cpiu_default_settings';

    /**
     * Option name for storing global settings
     */
    const GLOBAL_SETTINGS_OPTION = 'cpiu_global_settings';

    /**
     * Cache group for configurations
     */
    const CACHE_GROUP = 'cpiu_configs';

    /**
     * Default configuration structure
     */
    private $default_config = array(
        'product_id' => 0,
        'image_count' => 9,
        'allowed_types' => array('jpg', 'jpeg', 'png', 'gif', 'webp'),
        'max_file_size' => 5242880, // 5MB in bytes
        'button_text' => '',
        'button_color' => '#4CAF50',
        'enabled' => true,
        'resolution_validation' => false,
        'min_width' => 0,
        'min_height' => 0,
        'max_width' => 0,
        'max_height' => 0,
        'enable_shape_cropping' => true,
        'cropping_ratio' => 'free',
        'created_at' => '',
        'updated_at' => ''
    );

    /**
     * Default settings structure
     */
    private $default_settings = array(
        'image_count' => 9,
        'allowed_types' => array('jpg', 'jpeg', 'png', 'gif', 'webp'),
        'max_file_size' => 5242880, // 5MB in bytes
        'button_text' => 'Upload Files',
        'button_color' => '#4CAF50',
        'resolution_validation' => false,
        'min_width' => 0,
        'min_height' => 0,
        'max_width' => 0,
        'max_height' => 0,
        'enable_shape_cropping' => true,
        'cropping_ratio' => 'free'
    );

    /**
     * Default global settings structure
     */
    private $default_global_settings = array(
        'disable_express_checkout' => false,
        'enable_order_image_cleanup' => false,
        'order_image_cleanup_days' => 30
    );

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    /**
     * Initialize the data manager
     */
    public function init()
    {
        // Initialize cache
        wp_cache_add_non_persistent_groups(self::CACHE_GROUP);
    }

    /**
     * Get all product configurations
     * 
     * @return array Array of product configurations
     */
    public function get_all_configurations()
    {
        $cache_key = 'all_configs';
        $configs = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $configs) {
            $configs = get_option(self::MULTI_PRODUCT_OPTION, array());
            // Merge each configuration with defaults for backward compatibility
            if (is_array($configs)) {
                foreach ($configs as $pid => $cfg) {
                    if (is_array($cfg)) {
                        $configs[$pid] = wp_parse_args($cfg, $this->default_config);
                    }
                }
            }
            wp_cache_set($cache_key, $configs, self::CACHE_GROUP, 3600);
        }

        return $configs;
    }

    /**
     * Get configuration for a specific product
     * 
     * @param int $product_id Product ID
     * @return array|false Product configuration or false if not found
     */
    public function get_product_configuration($product_id)
    {
        $configs = $this->get_all_configurations();
        return isset($configs[$product_id]) ? $configs[$product_id] : false;
    }

    /**
     * Save product configuration
     * 
     * @param int $product_id Product ID
     * @param array $config Configuration data
     * @return bool Success status
     */
    public function save_product_configuration($product_id, $config)
    {
        $product_id = absint($product_id);

        if ($product_id <= 0) {
            return false;
        }

        // Merge with defaults
        $config = wp_parse_args($config, $this->default_config);
        $config['product_id'] = $product_id;
        $config['updated_at'] = current_time('mysql');

        if (empty($config['created_at'])) {
            $config['created_at'] = current_time('mysql');
        }

        // Validate configuration
        if (!$this->validate_configuration($config)) {
            return false;
        }

        // Get existing configurations
        $configs = $this->get_all_configurations();

        // Check if the configuration is actually different
        $existing_config = isset($configs[$product_id]) ? $configs[$product_id] : null;

        // If the configuration is identical, return true (no change needed)
        if ($existing_config && $existing_config === $config) {
            return true;
        }

        $configs[$product_id] = $config;

        // Save to database
        $result = update_option(self::MULTI_PRODUCT_OPTION, $configs);

        // Always return true if we reach this point, as update_option can return false
        // even when the operation was successful (when the value didn't change)
        if ($result) {
            // Clear cache
            wp_cache_delete('all_configs', self::CACHE_GROUP);
            wp_cache_delete("product_{$product_id}", self::CACHE_GROUP);
        }

        return true; // Always return true for successful operations
    }

    /**
     * Delete product configuration
     * 
     * @param int $product_id Product ID
     * @return bool Success status
     */
    public function delete_product_configuration($product_id)
    {
        $product_id = absint($product_id);

        if ($product_id <= 0) {
            return false;
        }

        $configs = $this->get_all_configurations();

        if (!isset($configs[$product_id])) {
            return false;
        }

        unset($configs[$product_id]);

        $result = update_option(self::MULTI_PRODUCT_OPTION, $configs);

        if ($result) {
            // Clear cache
            wp_cache_delete('all_configs', self::CACHE_GROUP);
            wp_cache_delete("product_{$product_id}", self::CACHE_GROUP);
        }

        // Always return true if we reach this point, as update_option can return false
        // even when the operation was successful (when the value didn't change)
        return true;
    }

    /**
     * Save multiple product configurations (bulk operation)
     * 
     * @param array $product_ids Array of product IDs
     * @param array $config Configuration data to apply to all products
     * @return array Results array with success/failure for each product
     */
    public function save_bulk_configurations($product_ids, $config)
    {
        $results = array();

        foreach ($product_ids as $product_id) {
            $product_id = absint($product_id);
            if ($product_id > 0) {
                $results[$product_id] = $this->save_product_configuration($product_id, $config);
            } else {
                $results[$product_id] = false;
            }
        }

        return $results;
    }

    /**
     * Get default settings
     * 
     * @return array Default settings
     */
    public function get_default_settings()
    {
        $settings = get_option(self::DEFAULT_SETTINGS_OPTION, array());
        return wp_parse_args($settings, $this->default_settings);
    }

    /**
     * Save default settings
     * 
     * @param array $settings Settings to save
     * @return bool Success status
     */
    public function save_default_settings($settings)
    {
        $settings = wp_parse_args($settings, $this->default_settings);

        // Validate settings
        if (!$this->validate_settings($settings)) {
            return false;
        }

        // Get existing settings to check if they're different
        $existing_settings = get_option(self::DEFAULT_SETTINGS_OPTION, array());

        // If the settings are identical, return true (no change needed)
        if ($existing_settings && $existing_settings === $settings) {
            return true;
        }

        $result = update_option(self::DEFAULT_SETTINGS_OPTION, $settings);

        // Always return true if we reach this point, as update_option can return false
        // even when the operation was successful (when the value didn't change)
        return true;
    }

    /**
     * Get global settings
     * 
     * @return array Global settings
     */
    public function get_global_settings()
    {
        $settings = get_option(self::GLOBAL_SETTINGS_OPTION, array());
        return wp_parse_args($settings, $this->default_global_settings);
    }

    /**
     * Save global settings
     * 
     * @param array $settings Settings to save
     * @return bool Success status
     */
    public function save_global_settings($settings)
    {
        $settings = wp_parse_args($settings, $this->default_global_settings);

        // Get existing settings to check if they're different
        $existing_settings = get_option(self::GLOBAL_SETTINGS_OPTION, array());

        // If the settings are identical, return true (no change needed)
        if ($existing_settings && $existing_settings === $settings) {
            return true;
        }

        return update_option(self::GLOBAL_SETTINGS_OPTION, $settings);
    }

    /**
     * Check if a product has upload configuration
     * 
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return bool True if product has configuration
     */
    public function has_product_configuration($product_id, $variation_id = 0)
    {
        if ($variation_id > 0) {
            $config = $this->get_product_configuration($variation_id);
            if ($config && $config['enabled']) {
                return true;
            }
        }
        $config = $this->get_product_configuration($product_id);
        return $config && $config['enabled'];
    }

    /**
     * Get products with configurations
     * 
     * @return array Array of product IDs that have configurations
     */
    public function get_configured_products()
    {
        $configs = $this->get_all_configurations();
        $configured_products = array();

        foreach ($configs as $product_id => $config) {
            if ($config['enabled']) {
                $configured_products[] = $product_id;
            }
        }

        return $configured_products;
    }

    /**
     * Validate configuration data
     * 
     * @param array $config Configuration to validate
     * @return bool True if valid
     */
    private function validate_configuration($config)
    {
        // Check required fields
        if (empty($config['product_id']) || $config['product_id'] <= 0) {
            return false;
        }

        // Validate image count
        if (!isset($config['image_count']) || $config['image_count'] < 1 || $config['image_count'] > 50) {
            return false;
        }

        // Validate allowed types
        if (!is_array($config['allowed_types']) || empty($config['allowed_types'])) {
            return false;
        }

        // Validate file size (minimum 1KB, no maximum limit)
        if (!isset($config['max_file_size']) || $config['max_file_size'] < 1024) {
            return false;
        }

        // Validate color
        if (!empty($config['button_color']) && !preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $config['button_color'])) {
            return false;
        }

        // Validate resolution settings if enabled
        if (!empty($config['resolution_validation'])) {
            // Validate minimum dimensions
            if (isset($config['min_width']) && ($config['min_width'] < 0 || $config['min_width'] > 10000)) {
                return false;
            }
            if (isset($config['min_height']) && ($config['min_height'] < 0 || $config['min_height'] > 10000)) {
                return false;
            }

            // Validate maximum dimensions
            if (isset($config['max_width']) && ($config['max_width'] < 0 || $config['max_width'] > 10000)) {
                return false;
            }
            if (isset($config['max_height']) && ($config['max_height'] < 0 || $config['max_height'] > 10000)) {
                return false;
            }

            // Validate min/max relationships
            if (isset($config['min_width']) && isset($config['max_width']) && $config['min_width'] > $config['max_width']) {
                return false;
            }
            if (isset($config['min_height']) && isset($config['max_height']) && $config['min_height'] > $config['max_height']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate settings data
     * 
     * @param array $settings Settings to validate
     * @return bool True if valid
     */
    private function validate_settings($settings)
    {
        // Validate image count
        if (!isset($settings['image_count']) || $settings['image_count'] < 1 || $settings['image_count'] > 50) {
            return false;
        }

        // Validate allowed types
        if (!is_array($settings['allowed_types']) || empty($settings['allowed_types'])) {
            return false;
        }

        // Validate file size (minimum 1KB, no maximum limit)
        if (!isset($settings['max_file_size']) || $settings['max_file_size'] < 1024) {
            return false;
        }

        // Validate color
        if (!empty($settings['button_color']) && !preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $settings['button_color'])) {
            return false;
        }

        // Validate resolution settings if enabled
        if (!empty($settings['resolution_validation'])) {
            // Validate minimum dimensions
            if (isset($settings['min_width']) && ($settings['min_width'] < 0 || $settings['min_width'] > 10000)) {
                return false;
            }
            if (isset($settings['min_height']) && ($settings['min_height'] < 0 || $settings['min_height'] > 10000)) {
                return false;
            }

            // Validate maximum dimensions
            if (isset($settings['max_width']) && ($settings['max_width'] < 0 || $settings['max_width'] > 10000)) {
                return false;
            }
            if (isset($settings['max_height']) && ($settings['max_height'] < 0 || $settings['max_height'] > 10000)) {
                return false;
            }

            // Validate min/max relationships
            if (isset($settings['min_width']) && isset($settings['max_width']) && $settings['min_width'] > $settings['max_width']) {
                return false;
            }
            if (isset($settings['min_height']) && isset($settings['max_height']) && $settings['min_height'] > $settings['max_height']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize configuration data
     * 
     * @param array $config Raw configuration data
     * @return array Sanitized configuration
     */
    public function sanitize_configuration($config)
    {
        $sanitized = array();

        $sanitized['product_id'] = absint($config['product_id']);
        $sanitized['image_count'] = max(1, min(50, absint($config['image_count'])));
        $sanitized['max_file_size'] = max(1024, absint($config['max_file_size']));
        $sanitized['button_text'] = sanitize_text_field($config['button_text']);
        $sanitized['button_color'] = sanitize_hex_color($config['button_color']);
        // If 'enabled' not provided, default to true
        if (array_key_exists('enabled', $config)) {
            $sanitized['enabled'] = !empty($config['enabled']);
        }

        // Sanitize allowed types
        $sanitized['allowed_types'] = array();
        if (is_array($config['allowed_types'])) {
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
            foreach ($config['allowed_types'] as $type) {
                $type = sanitize_text_field($type);
                if (in_array(strtolower($type), $allowed_extensions)) {
                    $sanitized['allowed_types'][] = strtolower($type);
                }
            }
        }

        // Ensure at least one type is allowed
        if (empty($sanitized['allowed_types'])) {
            $sanitized['allowed_types'] = array('jpg', 'jpeg', 'png');
        }

        // Sanitize resolution validation settings
        $sanitized['resolution_validation'] = !empty($config['resolution_validation']);
        $sanitized['min_width'] = max(0, min(10000, absint($config['min_width'] ?? 0)));
        $sanitized['min_height'] = max(0, min(10000, absint($config['min_height'] ?? 0)));
        $sanitized['max_width'] = max(0, min(10000, absint($config['max_width'] ?? 0)));
        $sanitized['max_height'] = max(0, min(10000, absint($config['max_height'] ?? 0)));

        // Sanitize Enable Shape Cropping
        $sanitized['enable_shape_cropping'] = isset($config['enable_shape_cropping']) ? (bool) $config['enable_shape_cropping'] : true;

        // Sanitize Cropping Ratio
        $allowed_ratios = array('free', '1:1', '4:3', '16:9', '2:3');
        $sanitized['cropping_ratio'] = isset($config['cropping_ratio']) && in_array($config['cropping_ratio'], $allowed_ratios)
            ? $config['cropping_ratio']
            : 'free';



        // Merge with defaults so any missing fields (including enabled) get defaults (enabled=true)
        $sanitized = wp_parse_args($sanitized, $this->default_config);

        /**
         * Filters a sanitized product configuration before it is saved.
         *
         * Add-ons (e.g. the Pro pricing system) hook this to sanitize and attach
         * their own keys (such as a nested `pricing` array) from the raw config.
         *
         * @param array $sanitized Sanitized configuration.
         * @param array $config    Raw configuration submitted by the form.
         */
        return apply_filters('cpiu_sanitize_configuration', $sanitized, $config);
    }

    /**
     * Get configuration for frontend display
     * 
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return array|false Configuration optimized for frontend or false if not found
     */
    public function get_frontend_configuration($product_id, $variation_id = 0)
    {
        $config = false;

        if ($variation_id > 0) {
            $config = $this->get_product_configuration($variation_id);
        }

        // Fall back to product configuration if variation config is not found or disabled
        if (!$config || !$config['enabled']) {
            $config = $this->get_product_configuration($product_id);
        }

        if (!$config || !$config['enabled']) {
            return false;
        }

        // Return only necessary data for frontend
        return array(
            'image_count' => $config['image_count'],
            'allowed_types' => $config['allowed_types'],
            'max_file_size' => $config['max_file_size'],
            'button_text' => $config['button_text'] ?: $this->get_default_settings()['button_text'],
            'button_color' => $config['button_color'] ?: $this->get_default_settings()['button_color'],
            'resolution_validation' => $config['resolution_validation'],
            'min_width' => $config['min_width'],
            'min_height' => $config['min_height'],
            'max_width' => $config['max_width'],
            'max_height' => $config['max_height'],
            'enable_shape_cropping' => $config['enable_shape_cropping'],
            'cropping_ratio' => $config['cropping_ratio']
        );
    }
}

