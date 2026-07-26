<?php
/**
 * CPIU AJAX Handler Class
 * 
 * Handles all AJAX operations for multi-product functionality
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class CPIU_Ajax_Handler
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
        $this->init_cache_groups();
    }

    /**
     * Initialize cache groups
     */
    private function init_cache_groups()
    {
        wp_cache_add_non_persistent_groups('cpiu_product_search');
    }

    /**
     * Initialize AJAX hooks
     */
    private function init_hooks()
    {
        // Product search
        add_action('wp_ajax_cpiu_search_products', array($this, 'search_products'));

        // Configuration management
        add_action('wp_ajax_cpiu_save_configuration', array($this, 'save_configuration'));
        add_action('wp_ajax_cpiu_delete_configuration', array($this, 'delete_configuration'));

        // Default settings
        add_action('wp_ajax_cpiu_save_default_settings', array($this, 'save_default_settings'));
        add_action('wp_ajax_cpiu_save_global_settings', array($this, 'save_global_settings'));

        // Enhanced image upload (existing functionality updated)
        add_action('wp_ajax_cpiu_upload_cropped_images', array($this, 'handle_upload_cropped_images'));
        add_action('wp_ajax_nopriv_cpiu_upload_cropped_images', array($this, 'handle_upload_cropped_images'));

        // PDF upload handler
        add_action('wp_ajax_cpiu_upload_pdf_files', array($this, 'handle_upload_pdf_files'));
        add_action('wp_ajax_nopriv_cpiu_upload_pdf_files', array($this, 'handle_upload_pdf_files'));

        // Unified mixed file upload handler (Images + PDFs via FormData Blobs)
        add_action('wp_ajax_cpiu_upload_mixed_files', array($this, 'handle_upload_mixed_files'));
        add_action('wp_ajax_nopriv_cpiu_upload_mixed_files', array($this, 'handle_upload_mixed_files'));
    }

    /**
     * Search products for admin interface
     */
    public function search_products()
    {
        // Verify nonce (accept from POST or GET to match Select2 transport)
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        // Validate and sanitize search term (accept from POST or GET)
        $raw_search_term = isset($_REQUEST['search_term']) ? wp_unslash($_REQUEST['search_term']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $search_term = sanitize_text_field($raw_search_term);
        $page = isset($_REQUEST['page']) ? max(1, absint($_REQUEST['page'])) : 1;
        $per_page = 20;

        if (empty($search_term)) {
            wp_send_json_error(array('message' => esc_html__('Search term is required.', 'nowdigiverse-product-image-upload')));
        }

        // Check if search term is a numeric ID
        $is_id_search = is_numeric($search_term);

        if ($is_id_search) {
            // Search by ID with caching
            $cache_key = 'cpiu_product_search_id_' . md5($search_term . '_' . $page . '_' . $per_page);
            $cached_result = wp_cache_get($cache_key, 'cpiu_product_search');

            if ($cached_result !== false) {
                wp_send_json_success($cached_result);
                return;
            }

            // Optimized ID search query
            $args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'p' => intval($search_term),
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids', // Only get IDs for better performance
                'no_found_rows' => false, // We need found_posts for pagination
                'update_post_meta_cache' => false, // Don't cache meta
                'update_post_term_cache' => false  // Don't cache terms
            );
        } else {
            // Use WordPress search with caching for better performance
            $cache_key = 'cpiu_product_search_' . md5($search_term . '_' . $page . '_' . $per_page);
            $cached_result = wp_cache_get($cache_key, 'cpiu_product_search');

            if ($cached_result !== false) {
                wp_send_json_success($cached_result);
                return;
            }

            // Optimized search query
            $args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                's' => $search_term,
                'orderby' => 'relevance',
                'order' => 'DESC',
                'fields' => 'ids', // Only get IDs for better performance
                'no_found_rows' => false, // We need found_posts for pagination
                'update_post_meta_cache' => false, // Don't cache meta
                'update_post_term_cache' => false  // Don't cache terms
            );

            $query = new WP_Query($args);
            $products = array();

            if ($query->have_posts()) {
                foreach ($query->posts as $product_id) {
                    $product = wc_get_product($product_id);

                    if ($product) {
                        $products[] = array(
                            'id' => $product_id,
                            'title' => $product->get_name(),
                            'type' => $product->get_type(),
                            'sku' => $product->get_sku()
                        );
                    }
                }
            }
            wp_reset_postdata();

            $result = array(
                'products' => $products,
                'total' => $query->found_posts,
                'pages' => $query->max_num_pages,
                'current' => $page
            );

            // Cache for 5 minutes
            wp_cache_set($cache_key, $result, 'cpiu_product_search', 300);

            wp_send_json_success($result);
            return;
        }

        $query = new WP_Query($args);
        $products = array();

        if ($query->have_posts()) {
            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);

                if ($product) {
                    $products[] = array(
                        'id' => $product_id,
                        'title' => $product->get_name(),
                        'type' => $product->get_type(),
                        'sku' => $product->get_sku()
                    );
                }
            }
        }

        wp_reset_postdata();

        $result = array(
            'products' => $products,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
            'current' => $page
        );

        // Cache for 5 minutes
        wp_cache_set($cache_key, $result, 'cpiu_product_search', 300);

        wp_send_json_success($result);
    }

    /**
     * Fuzzy search products using multiple algorithms
     */
    private function fuzzy_search_products($products, $search_term)
    {
        $search_term = strtolower(trim($search_term));
        $results = array();

        foreach ($products as $product) {
            $score = 0;
            $title = strtolower($product['title']);
            $sku = strtolower($product['sku'] ?: '');

            // Exact match (highest priority)
            if ($title === $search_term || $sku === $search_term) {
                $score = 100;
            }
            // Starts with search term
            elseif (strpos($title, $search_term) === 0 || strpos($sku, $search_term) === 0) {
                $score = 90;
            }
            // Contains search term
            elseif (strpos($title, $search_term) !== false || strpos($sku, $search_term) !== false) {
                $score = 80;
            }
            // Levenshtein distance for fuzzy matching
            else {
                $title_distance = levenshtein($search_term, $title);
                $sku_distance = $sku ? levenshtein($search_term, $sku) : 999;

                // Calculate similarity percentage
                $title_similarity = $this->calculate_similarity($search_term, $title, $title_distance);
                $sku_similarity = $sku ? $this->calculate_similarity($search_term, $sku, $sku_distance) : 0;

                $max_similarity = max($title_similarity, $sku_similarity);

                // Lower threshold for fuzzy matching (40%)
                if ($max_similarity >= 40) {
                    $score = $max_similarity;
                }
            }

            // Add to results if score is above threshold (lowered to 40%)
            if ($score >= 40) {
                $product['fuzzy_score'] = $score;
                $results[] = $product;
            }
        }

        // Sort by score (highest first)
        usort($results, function ($a, $b) {
            return $b['fuzzy_score'] - $a['fuzzy_score'];
        });

        return $results;
    }

    /**
     * Calculate similarity percentage based on Levenshtein distance
     */
    private function calculate_similarity($str1, $str2, $distance)
    {
        $max_length = max(strlen($str1), strlen($str2));
        if ($max_length === 0)
            return 100;

        // Prevent division by zero and handle very short strings
        if ($max_length < 3) {
            return $distance === 0 ? 100 : 0;
        }

        $similarity = (1 - ($distance / $max_length)) * 100;
        return max(0, $similarity); // Ensure non-negative
    }

    /**
     * Save individual product configuration
     */
    public function save_configuration()
    {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        // Sanitize and validate input data
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $config_data = array();

        // Sanitize configuration data
        if (isset($_POST['config']) && is_array($_POST['config'])) {
            $raw_config = wp_unslash($_POST['config']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

            // Sanitize allowed types
            if (isset($raw_config['allowed_types'])) {
                if (is_string($raw_config['allowed_types'])) {
                    $decoded = json_decode(stripslashes($raw_config['allowed_types']), true);
                    if (is_array($decoded)) {
                        $config_data['allowed_types'] = array_map('sanitize_text_field', $decoded);
                    }
                } elseif (is_array($raw_config['allowed_types'])) {
                    $config_data['allowed_types'] = array_map('sanitize_text_field', $raw_config['allowed_types']);
                }
            }

            // Sanitize other fields
            $config_data['image_count'] = isset($raw_config['image_count']) ? absint($raw_config['image_count']) : 9;
            $config_data['max_file_size'] = isset($raw_config['max_file_size']) ? absint($raw_config['max_file_size']) : 5242880;
            $config_data['button_text'] = isset($raw_config['button_text']) ? sanitize_text_field($raw_config['button_text']) : '';

            if (empty($config_data['button_text'])) {
                wp_send_json_error(array('message' => esc_html__('Button text cannot be empty.', 'nowdigiverse-product-image-upload')));
            }
            $config_data['button_color'] = isset($raw_config['button_color']) ? sanitize_hex_color($raw_config['button_color']) : '#4CAF50';
            $config_data['enabled'] = isset($raw_config['enabled']) ? (bool) $raw_config['enabled'] : true;
            $config_data['resolution_validation'] = isset($raw_config['resolution_validation']) ? (bool) $raw_config['resolution_validation'] : false;
            $config_data['min_width'] = isset($raw_config['min_width']) ? absint($raw_config['min_width']) : 0;
            $config_data['min_height'] = isset($raw_config['min_height']) ? absint($raw_config['min_height']) : 0;
            $config_data['max_width'] = isset($raw_config['max_width']) ? absint($raw_config['max_width']) : 0;
            $config_data['max_height'] = isset($raw_config['max_height']) ? absint($raw_config['max_height']) : 0;

            // Shape cropping settings
            $config_data['enable_shape_cropping'] = isset($raw_config['enable_shape_cropping']) ? (bool) $raw_config['enable_shape_cropping'] : true;
            $config_data['cropping_ratio'] = isset($raw_config['cropping_ratio']) ? sanitize_text_field($raw_config['cropping_ratio']) : 'free';

            // Lock the WooCommerce quantity selector to 1 for this product/variation.
            $config_data['disable_quantity'] = isset($raw_config['disable_quantity']) ? (bool) $raw_config['disable_quantity'] : false;

            // Shape validation settings
            $config_data['shape_validation_enabled'] = isset($raw_config['shape_validation_enabled']) ? (bool) $raw_config['shape_validation_enabled'] : false;

            // Parse shape requirements (sent as JSON string from frontend)
            $config_data['shape_requirements'] = array();
            if (isset($raw_config['shape_requirements'])) {
                $shape_req = $raw_config['shape_requirements'];
                if (is_string($shape_req)) {
                    $decoded = json_decode(stripslashes($shape_req), true);
                    if (is_array($decoded)) {
                        $shape_req = $decoded;
                    }
                }
                if (is_array($shape_req)) {
                    $config_data['shape_requirements'] = map_deep($shape_req, 'sanitize_text_field');
                }
            }
        }

        if ($product_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid product ID.', 'nowdigiverse-product-image-upload')));
        }

        // Verify product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => esc_html__('Product not found.', 'nowdigiverse-product-image-upload')));
        }

        // Sanitize configuration data
        $sanitized_config = $this->data_manager->sanitize_configuration($config_data);
        $sanitized_config['product_id'] = $product_id;

        // Save configuration
        $result = $this->data_manager->save_product_configuration($product_id, $sanitized_config);

        if ($result) {
            wp_send_json_success(array(
                'message' => esc_html__('Configuration saved successfully.', 'nowdigiverse-product-image-upload'),
                'config' => $this->data_manager->get_product_configuration($product_id)
            ));
        } else {
            wp_send_json_error(array('message' => esc_html__('Failed to save configuration.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Delete product configuration
     */
    public function delete_configuration()
    {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if ($product_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid product ID.', 'nowdigiverse-product-image-upload')));
        }

        $result = $this->data_manager->delete_product_configuration($product_id);

        if ($result) {
            wp_send_json_success(array('message' => esc_html__('Configuration deleted successfully.', 'nowdigiverse-product-image-upload')));
        } else {
            wp_send_json_error(array('message' => esc_html__('Failed to delete configuration.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Save default settings
     */
    public function save_default_settings()
    {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        // Sanitize and validate settings data
        $settings_data = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (!is_array($settings_data)) {
            wp_send_json_error(array('message' => esc_html__('Invalid settings data.', 'nowdigiverse-product-image-upload')));
        }



        // Sanitize via the Data Manager — single source of truth (also runs as
        // the registered sanitize callback for the option).
        $sanitized_settings = $this->data_manager->sanitize_default_settings($settings_data);

        $result = $this->data_manager->save_default_settings($sanitized_settings);

        if ($result) {
            wp_send_json_success(array(
                'message' => esc_html__('Default settings saved successfully.', 'nowdigiverse-product-image-upload'),
                'settings' => $this->data_manager->get_default_settings()
            ));
        } else {
            wp_send_json_error(array('message' => esc_html__('Failed to save default settings.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Save global settings
     */
    public function save_global_settings()
    {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'cpiu_admin_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions.', 'nowdigiverse-product-image-upload')));
        }

        // Sanitize and validate settings data
        $settings_data = isset($_POST['settings']) ? map_deep(wp_unslash($_POST['settings']), 'sanitize_text_field') : array();

        if (!is_array($settings_data)) {
            wp_send_json_error(array('message' => esc_html__('Invalid settings data.', 'nowdigiverse-product-image-upload')));
        }

        // Sanitize via the Data Manager — single source of truth. The
        // 'cpiu_save_global_settings' extension filter for add-ons is applied
        // inside sanitize_global_settings().
        $sanitized_settings = $this->data_manager->sanitize_global_settings($settings_data);

        $result = $this->data_manager->save_global_settings($sanitized_settings);

        if ($result) {
            wp_send_json_success(array(
                'message' => esc_html__('Global settings saved successfully.', 'nowdigiverse-product-image-upload'),
                'settings' => $this->data_manager->get_global_settings()
            ));
        } else {
            wp_send_json_error(array('message' => esc_html__('Failed to save global settings.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Enhanced secure image upload handler for multi-product support
     */
    public function handle_upload_cropped_images()
    {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'cpiu_image_upload_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        // Handle both authenticated and guest users
        $is_logged_in = is_user_logged_in();
        $user_id = $is_logged_in ? get_current_user_id() : 0;
        $ip_address = $this->get_client_ip_address();

        // For guest users, use WooCommerce session for tracking
        if (!$is_logged_in && WC()->session) {
            $guest_id = WC()->session->get('cpiu_guest_id');
            if (!$guest_id) {
                $guest_id = 'guest_' . wp_generate_password(12, false);
                WC()->session->set('cpiu_guest_id', $guest_id);
            }
        }

        // Enhanced security validation for guest uploads
        $this->validate_guest_upload_security($ip_address, $user_id, $is_logged_in);

        // Sanitize and validate product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if ($product_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid product ID.', 'nowdigiverse-product-image-upload')));
        }

        // Verify product exists and is supported type
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => esc_html__('Product not found.', 'nowdigiverse-product-image-upload')));
        }

        // Check if product type is supported (exclude external/affiliate products)
        if (in_array($product->get_type(), array('external', 'affiliate'))) {
            wp_send_json_error(array('message' => esc_html__('Image upload is not supported for this product type.', 'nowdigiverse-product-image-upload')));
        }

        // Get product configuration
        $config = $this->data_manager->get_frontend_configuration($product_id);

        if (!$config) {
            wp_send_json_error(array('message' => esc_html__('Product not configured for image upload.', 'nowdigiverse-product-image-upload')));
        }

        // Validate and sanitize image data
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if (!isset($_POST['images_data']) || empty($_POST['images_data'])) {
            wp_send_json_error(array('message' => esc_html__('No image data received.', 'nowdigiverse-product-image-upload')));
        }

        $raw_images_data = sanitize_textarea_field(wp_unslash($_POST['images_data']));
        $images_base64_data = json_decode(stripslashes($raw_images_data), true);

        if (!is_array($images_base64_data) || count($images_base64_data) !== $config['image_count']) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %1$d: Number of allowed images */
                    esc_html__('Invalid data or image count. Expected %1$d images.', 'nowdigiverse-product-image-upload'),
                    $config['image_count']
                )
            ));
        }

        // Initialize secure upload handler
        try {
            $secure_upload = new CPIU_Secure_Upload();
            $secure_upload->set_max_file_size($config['max_file_size']);

            // Set resolution validation if enabled
            if (!empty($config['resolution_validation'])) {
                $secure_upload->set_resolution_validation(
                    true,
                    $config['min_width'] ?? 0,
                    $config['min_height'] ?? 0,
                    $config['max_width'] ?? 0,
                    $config['max_height'] ?? 0
                );
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }

        $saved_image_urls = array();
        $errors = array();
        $uploaded_files = array();

        foreach ($images_base64_data as $index => $base64_image_string) {
            try {
                $upload_result = $secure_upload->process_base64_image(
                    $base64_image_string,
                    $index,
                    $product_id,
                    $user_id,
                    $ip_address
                );

                $saved_image_urls[] = $upload_result['url'];
                $uploaded_files[] = $upload_result['path'];

            } catch (Exception $e) {
                $errors[] = $e->getMessage();

                // Clean up any files uploaded so far
                if (!empty($uploaded_files)) {
                    $secure_upload->cleanup_files($uploaded_files);
                }

                wp_send_json_error(array('message' => implode(' ', $errors)));
            }
        }

        if (count($saved_image_urls) !== $config['image_count']) {
            // Clean up any files uploaded
            if (!empty($uploaded_files)) {
                $secure_upload->cleanup_files($uploaded_files);
            }
            wp_send_json_error(array('message' => esc_html__('Unexpected error: Not all images processed.', 'nowdigiverse-product-image-upload')));
        }

        // Add to cart with guest session tracking
        $cart_item_data = array('cpiu_uploaded_images' => $saved_image_urls);

        // For guest users, add session identifier to cart data
        if (!$is_logged_in && WC()->session) {
            $guest_id = WC()->session->get('cpiu_guest_id');
            if ($guest_id) {
                $cart_item_data['cpiu_guest_id'] = $guest_id;
            }
        }

        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        if ($variation_id > 0) {
            $cart_item_data['cpiu_variation_id'] = $variation_id;
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, $variation_id, array(), $cart_item_data);

        if ($cart_item_key) {

            wp_send_json_success(array('message' => esc_html__('Product added to cart!', 'nowdigiverse-product-image-upload')));
        } else {
            // Clean up files if cart addition fails
            if (!empty($uploaded_files)) {
                $secure_upload->cleanup_files($uploaded_files);
            }
            wp_send_json_error(array('message' => esc_html__('Failed to add product to cart.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Handle PDF file uploads via multipart form-data.
     * PDFs are not base64-encoded; they arrive as standard $_FILES entries.
     */
    public function handle_upload_pdf_files()
    {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'cpiu_image_upload_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        $is_logged_in = is_user_logged_in();
        $user_id = $is_logged_in ? get_current_user_id() : 0;
        $ip_address = $this->get_client_ip_address();

        // Guest session tracking
        if (!$is_logged_in && WC()->session) {
            $guest_id = WC()->session->get('cpiu_guest_id');
            if (!$guest_id) {
                $guest_id = 'guest_' . wp_generate_password(12, false);
                WC()->session->set('cpiu_guest_id', $guest_id);
            }
        }

        $this->validate_guest_upload_security($ip_address, $user_id, $is_logged_in);

        // Validate product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid product ID.', 'nowdigiverse-product-image-upload')));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => esc_html__('Product not found.', 'nowdigiverse-product-image-upload')));
        }

        if (in_array($product->get_type(), array('external', 'affiliate'))) {
            wp_send_json_error(array('message' => esc_html__('File upload is not supported for this product type.', 'nowdigiverse-product-image-upload')));
        }

        $config = $this->data_manager->get_frontend_configuration($product_id);
        if (!$config) {
            wp_send_json_error(array('message' => esc_html__('Product not configured for file upload.', 'nowdigiverse-product-image-upload')));
        }

        // Verify PDF is actually an allowed type for this product
        if (!in_array('pdf', $config['allowed_types'])) {
            wp_send_json_error(array('message' => esc_html__('PDF uploads are not enabled for this product.', 'nowdigiverse-product-image-upload')));
        }

        // Check that files were received
        if (empty($_FILES['pdf_files'])) {
            wp_send_json_error(array('message' => esc_html__('No PDF files received.', 'nowdigiverse-product-image-upload')));
        }

        // Normalise $_FILES['pdf_files'] into an array of single-file entries
        $files_raw = $_FILES['pdf_files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $file_count = is_array($files_raw['name']) ? count($files_raw['name']) : 1;

        // Build per-file entries
        $file_entries = array();
        if (is_array($files_raw['name'])) {
            for ($i = 0; $i < $file_count; $i++) {
                $file_entries[] = array(
                    'name' => $files_raw['name'][$i],
                    'tmp_name' => $files_raw['tmp_name'][$i],
                    'size' => $files_raw['size'][$i],
                    'error' => $files_raw['error'][$i],
                );
            }
        } else {
            $file_entries[] = array(
                'name' => $files_raw['name'],
                'tmp_name' => $files_raw['tmp_name'],
                'size' => $files_raw['size'],
                'error' => $files_raw['error'],
            );
        }

        if (count($file_entries) !== intval($config['image_count'])) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %1$d: Number of required files */
                    esc_html__('Invalid file count. Expected %1$d file(s).', 'nowdigiverse-product-image-upload'),
                    intval($config['image_count'])
                )
            ));
        }

        // Initialize secure upload handler
        try {
            $secure_upload = new CPIU_Secure_Upload();
            $secure_upload->set_max_file_size($config['max_file_size']);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }

        $saved_urls = array();
        $original_filenames = array();
        $errors = array();
        $uploaded_files = array();

        foreach ($file_entries as $index => $file_entry) {
            try {
                $result = $secure_upload->process_raw_pdf(
                    $file_entry,
                    $index,
                    $product_id,
                    $user_id,
                    $ip_address
                );
                $saved_urls[] = $result['url'];
                $uploaded_files[] = $result['path'];
                $original_filenames[] = $result['original_name'] ?? basename($result['url']);
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                if (!empty($uploaded_files)) {
                    $secure_upload->cleanup_files($uploaded_files);
                }
                wp_send_json_error(array('message' => implode(' ', $errors)));
            }
        }

        // Add to cart
        $cart_item_data = array(
            'cpiu_uploaded_images' => $saved_urls,
            'cpiu_original_filenames' => $original_filenames
        );

        if (!$is_logged_in && WC()->session) {
            $guest_id = WC()->session->get('cpiu_guest_id');
            if ($guest_id) {
                $cart_item_data['cpiu_guest_id'] = $guest_id;
            }
        }

        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        if ($variation_id > 0) {
            $cart_item_data['cpiu_variation_id'] = $variation_id;
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, $variation_id, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array('message' => esc_html__('Product added to cart!', 'nowdigiverse-product-image-upload')));
        } else {
            if (!empty($uploaded_files)) {
                $secure_upload->cleanup_files($uploaded_files);
            }
            wp_send_json_error(array('message' => esc_html__('Failed to add product to cart.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Unified handler for generic file uploads via FormData (Blobs for images, Files for PDFs).
     */
    public function handle_upload_mixed_files()
    {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'cpiu_image_upload_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'nowdigiverse-product-image-upload')));
        }

        $is_logged_in = is_user_logged_in();
        $user_id = $is_logged_in ? get_current_user_id() : 0;
        $ip_address = $this->get_client_ip_address();

        // Guest session tracking
        if (!$is_logged_in && WC()->session) {
            $guest_id = WC()->session->get('cpiu_guest_id');
            if (!$guest_id) {
                $guest_id = 'guest_' . wp_generate_password(12, false);
                WC()->session->set('cpiu_guest_id', $guest_id);
            }
        }

        $this->validate_guest_upload_security($ip_address, $user_id, $is_logged_in);

        // Validate product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid product ID.', 'nowdigiverse-product-image-upload')));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => esc_html__('Product not found.', 'nowdigiverse-product-image-upload')));
        }

        if (in_array($product->get_type(), array('external', 'affiliate'))) {
            wp_send_json_error(array('message' => esc_html__('File upload is not supported for this product type.', 'nowdigiverse-product-image-upload')));
        }

        $config = $this->data_manager->get_frontend_configuration($product_id);
        if (!$config) {
            wp_send_json_error(array('message' => esc_html__('Product not configured for file upload.', 'nowdigiverse-product-image-upload')));
        }

        // Check if files were received
        if (empty($_FILES['cpiu_files'])) {
            wp_send_json_error(array('message' => esc_html__('No files received.', 'nowdigiverse-product-image-upload')));
        }

        // Normalize $_FILES['cpiu_files'] into an array of single-file entries
        $files_raw = $_FILES['cpiu_files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $file_count = is_array($files_raw['name']) ? count($files_raw['name']) : 1;

        $file_entries = array();
        if (is_array($files_raw['name'])) {
            for ($i = 0; $i < $file_count; $i++) {
                $file_entries[] = array(
                    'name' => $files_raw['name'][$i],
                    'tmp_name' => $files_raw['tmp_name'][$i],
                    'size' => $files_raw['size'][$i],
                    'error' => $files_raw['error'][$i],
                );
            }
        } else {
            $file_entries[] = array(
                'name' => $files_raw['name'],
                'tmp_name' => $files_raw['tmp_name'],
                'size' => $files_raw['size'],
                'error' => $files_raw['error'],
            );
        }

        // Determine the expected file count (shape-based total or standard image_count).
        // IMPORTANT: intval() is required because WP stores option values as strings,
        // so $config['image_count'] may be "3" (string) while count() returns 3 (int).
        $expected_count = intval($config['image_count']);
        if (!empty($config['shape_validation_enabled']) && !empty($config['shape_requirements'])) {
            $expected_count = intval(array_sum(array_column($config['shape_requirements'], 'quantity_required')));
        }

        if (count($file_entries) !== $expected_count) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %1$d: Number of required files */
                    esc_html__('Invalid file count. Expected %1$d file(s).', 'nowdigiverse-product-image-upload'),
                    $expected_count
                )
            ));
        }

        // Initialize secure upload handler
        try {
            $secure_upload = new CPIU_Secure_Upload();
            $secure_upload->set_max_file_size($config['max_file_size']);
            // Set resolution validation if enabled (will only apply to images inside process_raw_file)
            if (!empty($config['resolution_validation'])) {
                $secure_upload->set_resolution_validation(
                    true,
                    $config['min_width'] ?? 0,
                    $config['min_height'] ?? 0,
                    $config['max_width'] ?? 0,
                    $config['max_height'] ?? 0
                );
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }

        $saved_urls = array();
        $original_filenames = array();
        $errors = array();
        $uploaded_files = array();

        foreach ($file_entries as $index => $file_entry) {
            try {
                $result = $secure_upload->process_raw_file(
                    $file_entry,
                    $index,
                    $product_id,
                    $user_id,
                    $ip_address
                );
                $saved_urls[] = $result['url'];
                $uploaded_files[] = $result['path'];
                $original_filenames[] = $result['original_name'] ?? basename($result['url']);
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                if (!empty($uploaded_files)) {
                    $secure_upload->cleanup_files($uploaded_files);
                }
                wp_send_json_error(array('message' => implode(' ', $errors)));
            }
        }

        // Add to cart
        $cart_item_data = array(
            'cpiu_uploaded_images' => $saved_urls,
            'cpiu_original_filenames' => $original_filenames
        );

        if (!$is_logged_in && WC()->session) {
            $guest_id = WC()->session->get('cpiu_guest_id');
            if ($guest_id) {
                $cart_item_data['cpiu_guest_id'] = $guest_id;
            }
        }

        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        if ($variation_id > 0) {
            $cart_item_data['cpiu_variation_id'] = $variation_id;
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, $variation_id, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array('message' => esc_html__('Product added to cart!', 'nowdigiverse-product-image-upload')));
        } else {
            if (!empty($uploaded_files)) {
                $secure_upload->cleanup_files($uploaded_files);
            }
            wp_send_json_error(array('message' => esc_html__('Failed to add product to cart.', 'nowdigiverse-product-image-upload')));
        }
    }

    /**
     * Enhanced security validation for guest uploads
     */
    private function validate_guest_upload_security($ip_address, $user_id, $is_logged_in)
    {
        // Rate limiting disabled - no upload limits
        // Only keeping guest behavior validation for security
        if (!$is_logged_in) {
            // Additional validation for suspicious patterns
            $this->validate_guest_behavior($ip_address);
        }


    }

    /**
     * Validate guest user behavior patterns
     */
    private function validate_guest_behavior($ip_address)
    {
        // Check for rapid successive requests (potential bot behavior)
        $last_request_key = 'cpiu_last_request_' . md5($ip_address);
        $last_request_time = get_transient($last_request_key);

        if ($last_request_time !== false && (time() - $last_request_time) < 5) {
            wp_send_json_error(array('message' => esc_html__('Please wait a moment before uploading again.', 'nowdigiverse-product-image-upload')));
        }

        set_transient($last_request_key, time(), 300); // 5 minutes
    }

    /**
     * Get client IP address for logging
     */
    private function get_client_ip_address()
    {
        // Only trust REMOTE_ADDR: proxy headers such as X-Forwarded-For are
        // client-controlled and would let an attacker rotate identities to
        // bypass the guest rate limit. Sites behind a trusted reverse proxy
        // can supply the real client IP via the filter below.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '0.0.0.0';
        }

        /**
         * Filters the client IP address used for rate limiting and logging.
         *
         * @param string $ip The detected client IP (REMOTE_ADDR).
         */
        return apply_filters('cpiu_client_ip', $ip);
    }
}

