<?php
/**
 * CPIU Frontend Manager Class
 * 
 * Handles frontend display and validation for multi-product functionality
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class CPIU_Frontend_Manager
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
        $this->data_manager = new CPIU_Data_Manager();
        $this->init_hooks();
        $this->init_blocks_support();
    }

    /**
     * Initialize WooCommerce blocks support
     */
    private function init_blocks_support()
    {
        // Support for WooCommerce blocks
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_integration'));

        // Support for block themes
        add_action('wp_enqueue_scripts', array($this, 'enqueue_blocks_assets'));

        // Add WooCommerce Blocks cart/checkout support
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'blocks_save_uploaded_files_to_order'), 10, 2);

        // Register blocks integration for cart item data
        add_action('init', array($this, 'register_blocks_cart_integration'));
    }

    /**
     * Register blocks integration
     */
    public function register_blocks_integration()
    {
        // This method can be extended to add custom block support
        // For now, we ensure our hooks work with blocks
        if (function_exists('woocommerce_blocks_loaded')) {
            // Add support for WooCommerce blocks
            add_action('woocommerce_single_product_summary', array($this, 'display_upload_interface'), 25);
        }
    }

    /**
     * Register WooCommerce Blocks cart integration
     */
    public function register_blocks_cart_integration()
    {
        // Primary method: Use Store API if available
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
                'namespace' => 'cpiu',
                'data_callback' => array($this, 'blocks_extend_cart_item_data'),
                'schema_callback' => array($this, 'blocks_extend_cart_item_schema'),
                'schema_type' => ARRAY_A,
            ));
        }

        // Register Store API extension for cart items with multiple compatibility checks
        $this->register_store_api_integration();

        // Use cart item name hook instead to avoid colon formatting
        add_filter('woocommerce_cart_item_name', array($this, 'add_upload_confirmation_to_cart_item_name'), 10, 3);

        // Additional hook for WooCommerce Blocks
        add_filter('woocommerce_blocks_cart_item_data', array($this, 'blocks_cart_item_data'), 10, 3);

        // Enhanced blocks integration with multiple fallbacks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_enhanced_blocks_scripts'));
    }

    /**
     * Register Store API integration with multiple compatibility methods
     */
    private function register_store_api_integration()
    {
        // Method 1: Try the standard function (WooCommerce 8.0+)
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            try {
                woocommerce_store_api_register_endpoint_data(
                    array(
                        'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
                        'namespace' => 'cpiu_uploaded_images',
                        'data_callback' => array($this, 'store_api_cart_item_data'),
                        'schema_callback' => array($this, 'store_api_cart_item_schema'),
                    )
                );
                return; // Success, exit early
            } catch (Exception $e) {
                // Store API registration failed, continue to fallback methods
            }
        }

        // Method 2: Try direct ExtendSchema registration (older versions)
        if (class_exists('\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema')) {
            try {
                add_action('rest_api_init', function () {
                    if (class_exists('\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema')) {
                        // Alternative registration method
                        $this->register_alternative_store_api();
                    }
                });
            } catch (Exception $e) {
                // Alternative Store API registration failed
            }
        }

        // Method 3: Enhanced JavaScript-based fallback
        add_action('wp_footer', array($this, 'output_cart_data_for_blocks'));
    }

    /**
     * Alternative Store API registration method
     */
    private function register_alternative_store_api()
    {
        // This method provides compatibility with different WooCommerce versions
        add_filter('woocommerce_store_api_cart_item_data', array($this, 'store_api_cart_item_data'), 10, 3);
    }

    /**
     * Output cart data directly for JavaScript consumption
     */
    public function output_cart_data_for_blocks()
    {
        if (is_cart() || is_checkout()) {
            $cart_data = array();

            if (WC()->cart && !WC()->cart->is_empty()) {
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    if (isset($cart_item['cpiu_uploaded_images']) && !empty($cart_item['cpiu_uploaded_images'])) {
                        $cart_data[$cart_item_key] = array(
                            'has_uploaded_images' => true,
                            'message' => esc_html__('Your files are uploaded', 'custom-product-image-upload'),
                            'product_id' => $cart_item['product_id'],
                            'variation_id' => isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0
                        );
                    }
                }
            }

            if (!empty($cart_data)) {
                echo '<script type="text/javascript">
                    window.cpiuCartData = ' . wp_json_encode($cart_data) . ';
                </script>';
            }
        }
    }

    /**
     * Enqueue enhanced blocks scripts with fallback support
     */
    public function enqueue_enhanced_blocks_scripts()
    {
        if (is_cart() || is_checkout()) {
            wp_enqueue_script(
                'cpiu-blocks-integration',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/cpiu-blocks-integration.js',
                array('wp-blocks', 'wp-element', 'wp-components', 'wc-settings', 'wc-blocks-data-store'),
                '1.3.0',
                true
            );

            // Pass additional data to JavaScript
            wp_localize_script('cpiu-blocks-integration', 'cpiuBlocksData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cpiu_blocks_nonce'),
                'confirmationMessage' => esc_html__('Your files are uploaded', 'custom-product-image-upload')
            ));
        }
    }

    /**
     * Store API cart item data callback
     */
    public function store_api_cart_item_data($cart_item)
    {
        if (isset($cart_item['cpiu_uploaded_images']) && is_array($cart_item['cpiu_uploaded_images']) && !empty($cart_item['cpiu_uploaded_images'])) {
            // Return simple confirmation data instead of image URLs
            return array(
                'has_uploaded_images' => true,
                'message' => esc_html__('Your files are uploaded', 'custom-product-image-upload')
            );
        }

        // Always return data matching the declared object schema. Returning an
        // empty array() serializes to a JSON array ([]) and fails Store API
        // schema validation on WooCommerce 10.x, which 500s the /cart request
        // and leaves the block cart/checkout button spinning.
        return array(
            'has_uploaded_images' => false,
            'message' => '',
        );
    }

    /**
     * Store API cart item schema callback
     */
    public function store_api_cart_item_schema()
    {
        return array(
            'type' => 'object',
            'description' => esc_html__('Uploaded files confirmation for this product', 'custom-product-image-upload'),
            'context' => array('view', 'edit'),
            'readonly' => true,
            'properties' => array(
                'has_uploaded_images' => array(
                    'type' => 'boolean',
                    'description' => esc_html__('Whether files are uploaded', 'custom-product-image-upload'),
                ),
                'message' => array(
                    'type' => 'string',
                    'description' => esc_html__('Confirmation message', 'custom-product-image-upload'),
                ),
            ),
        );
    }

    /**
     * Extend cart item data for WooCommerce Blocks
     */
    public function blocks_extend_cart_item_data($cart_item)
    {
        if (isset($cart_item['cpiu_uploaded_images']) && is_array($cart_item['cpiu_uploaded_images']) && !empty($cart_item['cpiu_uploaded_images'])) {
            // Show checkmark and text instead of images for cart/checkout blocks
            $checkmark_html = '<div class="cpiu-cart-confirmation" style="display: flex; align-items: center; color: #28a745; font-weight: 500;">
                <span style="margin-right: 8px; font-size: 16px;">✓</span>
                <span>' . esc_html__('Your files are uploaded', 'custom-product-image-upload') . '</span>
            </div>';

            return array(
                'uploaded_images' => $checkmark_html
            );
        }
        // Return a schema-conformant string (not an empty array) so the Store API
        // response validates on WooCommerce 10.x.
        return array(
            'uploaded_images' => '',
        );
    }

    /**
     * Extend cart item schema for WooCommerce Blocks
     */
    public function blocks_extend_cart_item_schema()
    {
        return array(
            'uploaded_images' => array(
                'description' => esc_html__('Uploaded files for this product', 'custom-product-image-upload'),
                'type' => 'string',
                'context' => array('view', 'edit'),
                'readonly' => true,
            ),
        );
    }

    /**
     * Save uploaded images to order for WooCommerce Blocks checkout
     */
    public function blocks_save_uploaded_files_to_order($order, $request)
    {
        $cart = WC()->cart->get_cart();

        foreach ($cart as $cart_item_key => $cart_item) {
            if (isset($cart_item['cpiu_uploaded_images']) && is_array($cart_item['cpiu_uploaded_images'])) {
                // Find the corresponding order item
                foreach ($order->get_items() as $item_id => $item) {
                    if ($item->get_product_id() == $cart_item['product_id']) {
                        // Ensure variation_id matches
                        $item_variation_id = $item->get_variation_id();
                        $cart_variation_id = isset($cart_item['cpiu_variation_id']) ? $cart_item['cpiu_variation_id'] : (isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0);
                        if ($item_variation_id != $cart_variation_id) {
                            continue;
                        }

                        // Ensure raw urls are deleted if Store API copied them over
                        $item->delete_meta_data('cpiu_uploaded_images');
                        $item->delete_meta_data(esc_html__('Uploaded files', 'custom-product-image-upload'));

                        $uploaded_images_str = implode(', ', $cart_item['cpiu_uploaded_images']);
                        $item->add_meta_data('_cpiu_uploaded_images', $uploaded_images_str, true);

                        if (isset($cart_item['cpiu_original_filenames']) && is_array($cart_item['cpiu_original_filenames'])) {
                            $original_filenames_str = implode('|', $cart_item['cpiu_original_filenames']);
                            $item->add_meta_data('_cpiu_original_filenames', $original_filenames_str, true);
                        }
                        break;
                    }
                }
            }
        }
    }

    /**
     * Enqueue assets for block themes
     */
    public function enqueue_blocks_assets()
    {
        // Ensure our assets are loaded on block themes
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $this->enqueue_frontend_scripts();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Frontend display
        add_action('wp', array($this, 'maybe_remove_express_checkout'));
        add_action('woocommerce_after_add_to_cart_button', array($this, 'display_upload_interface'), 20);
        add_action('woocommerce_after_add_to_cart_button', array($this, 'display_image_preview'), 25);

        // Archive page add to cart button control
        add_filter('woocommerce_loop_add_to_cart_link', array($this, 'modify_archive_add_to_cart_button'), 10, 2);

        // Script and style enqueuing
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_cropper_js'));

        // Cart and order integration (existing functionality)
        // Use cart item name hook instead to avoid colon formatting
        add_filter('woocommerce_cart_item_name', array($this, 'add_upload_confirmation_to_cart_item_name'), 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_uploaded_images_to_order_meta'), 10, 4);
        add_action('woocommerce_order_item_meta_end', array($this, 'display_uploaded_images_on_order_pages'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_uploaded_images_in_cart'), 10, 2);

        // Restore cart item data from session
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_add_cart_item', array($this, 'add_cart_item'), 10, 1);
        add_filter('woocommerce_get_cart_item_from_session', array($this, 'get_cart_item_from_session'), 10, 2);

        // Hide custom order meta

    }

    /**
     * Display upload interface for configured products
     */
    public function display_upload_interface()
    {
        global $post;

        if (!is_product() || !isset($post)) {
            return;
        }

        $product_id = $post->ID;
        $product = wc_get_product($product_id);

        // Check if product type is supported (exclude external/affiliate products)
        if (!$product || in_array($product->get_type(), array('external', 'affiliate'))) {
            return;
        }

        $all_configs = $this->get_all_product_configurations($product_id, $product);

        if (!$all_configs) {
            return;
        }

        // Use parent config or the first available variation config for the initial wrapper UI
        $config = $all_configs['parent'] ? $all_configs['parent'] : current($all_configs['variations']);

        // Get default settings for fallback
        $default_settings = $this->data_manager->get_default_settings();

        // Use product-specific settings or fall back to defaults
        $button_text = $config['button_text'] ?: $default_settings['button_text'];
        $button_color = $config['button_color'] ?: $default_settings['button_color'];
        $image_count = $config['image_count'];
        $allowed_types = $config['allowed_types'];
        $max_file_size = $config['max_file_size'];

        // Build accept attribute for file input
        $accept_types = array();
        foreach ($allowed_types as $type) {
            if ($type === 'pdf') {
                $accept_types[] = 'application/pdf';
            } else {
                $accept_types[] = "image/{$type}";
            }
        }
        $accept_attr = implode(',', $accept_types);

        // Determine if PDF is included in the allowed types
        $has_pdf = in_array('pdf', $allowed_types);

        ?>
        <!-- Upload Modal (Button moved to preview section) -->
        <div id="cpiu-upload-modal" class="cpiu-modal">
            <div class="cpiu-modal-content">
                <button type="button" id="cpiu-close-upload-modal" class="cpiu-close-btn">&times;</button>

                <h3 class="cpiu-modal-title"><?php esc_html_e('Upload Your files', 'custom-product-image-upload'); ?></h3>

                <div class="custom-image-upload cpiu-container">
                    <label for="cpiu_custom_images"><?php esc_html_e('Choose files', 'custom-product-image-upload'); ?></label>
                    <input type="file" id="cpiu_custom_images" name="cpiu_custom_images[]" multiple
                        accept="<?php echo esc_attr($accept_attr); ?>" data-product-id="<?php echo esc_attr($product_id); ?>"
                        data-max-files="<?php echo esc_attr($image_count); ?>"
                        data-max-size="<?php echo esc_attr($max_file_size); ?>"
                        data-allowed-types="<?php echo esc_attr(wp_json_encode($allowed_types)); ?>" />

                    <p class="cpiu-hint">
                        <?php
                        printf(
                            /* translators: %1$d: Number of images, %2$s: Max file size, %3$s: Allowed formats */
                            esc_html__('Please upload exactly %1$d files. Maximum file size: %2$s. Allowed formats: %3$s.', 'custom-product-image-upload'),
                            esc_html($image_count),
                            esc_html(size_format($max_file_size)),
                            esc_html(implode(', ', array_map('strtoupper', $allowed_types)))
                        );

                        if (!empty($config['resolution_validation'])) {
                            $res_text = array();
                            if (!empty($config['min_width']) || !empty($config['min_height'])) {
                                $min_w = !empty($config['min_width']) ? $config['min_width'] : 0;
                                $min_h = !empty($config['min_height']) ? $config['min_height'] : 0;
                                /* translators: %1$d: Min width, %2$d: Min height */
                                $res_text[] = sprintf(esc_html__('Min: %1$d×%2$d px', 'custom-product-image-upload'), $min_w, $min_h);
                            }
                            if (!empty($config['max_width']) || !empty($config['max_height'])) {
                                $max_w = !empty($config['max_width']) ? $config['max_width'] : 0;
                                $max_h = !empty($config['max_height']) ? $config['max_height'] : 0;
                                /* translators: %1$d: Max width, %2$d: Max height */
                                $res_text[] = sprintf(esc_html__('Max: %1$d×%2$d px', 'custom-product-image-upload'), $max_w, $max_h);
                            }
                            if (!empty($res_text)) {
                                echo '<br>';
                                printf(
                                    /* translators: %s: Resolution requirements string */
                                    esc_html__('Required resolution: %s', 'custom-product-image-upload'),
                                    esc_html(implode(' | ', $res_text))
                                );
                            }
                        }
                        ?>
                    </p>

                    <div id="cpiu-image-preview" class="cpiu-image-preview">
                    </div>

                    <p class="cpiu-crop-hint">
                        <strong><?php esc_html_e('Tip:', 'custom-product-image-upload'); ?></strong>
                        <?php esc_html_e('You can crop images after selecting them.', 'custom-product-image-upload'); ?>
                    </p>

                    <div id="cpiu-error-container" class="cpiu-error-container"></div>

                    <div id="cpiu-upload-progress" class="cpiu-upload-progress">
                        <div class="cpiu-progress-bar-container">
                            <div id="cpiu-progress-bar" class="cpiu-progress-bar cpiu-dynamic-bg"
                                data-bg-color="<?php echo esc_attr($button_color); ?>"></div>
                        </div>
                        <p id="cpiu-progress-text" class="cpiu-progress-text"></p>
                    </div>

                    <div class="cpiu-modal-actions">
                        <button type="button" id="cpiu-done-button" class="cpiu-done-btn cpiu-dynamic-bg"
                            data-bg-color="<?php echo esc_attr($button_color); ?>">
                            <?php esc_html_e('Done', 'custom-product-image-upload'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cropper Modal -->
        <div id="cpiu-cropper-modal" class="cpiu-cropper-modal">
            <div class="cpiu-cropper-modal-content">
                <h3 class="cpiu-cropper-title"><?php esc_html_e('Crop Image', 'custom-product-image-upload'); ?></h3>
                <div id="cpiu-cropper-container" class="cpiu-cropper-container">
                    <?php // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- JS crop target with empty src; not a media-library attachment. ?>
                    <img id="cpiu-image-to-crop" src=""
                        alt="<?php esc_attr_e('Image to crop', 'custom-product-image-upload'); ?>" class="cpiu-image-to-crop" />
                </div>
                <div class="cpiu-cropper-actions">
                    <button type="button" id="cpiu-save-cropped-image"
                        class="button alt cpiu-save-crop-btn"><?php esc_html_e('Save Crop', 'custom-product-image-upload'); ?></button>
                    <button type="button" id="cpiu-close-cropper-modal"
                        class="button"><?php esc_html_e('Cancel', 'custom-product-image-upload'); ?></button>
                </div>
            </div>
        </div>

        <!-- Upload Loading Modal -->
        <div id="cpiu-upload-loading" class="cpiu-upload-loading-modal">
            <div class="cpiu-upload-loading-content">
                <h3 class="cpiu-upload-loading-title">
                    <?php esc_html_e('Uploading Your files', 'custom-product-image-upload'); ?>
                </h3>

                <!-- Progress Bar -->
                <div class="cpiu-progress-bar-container"
                    style="margin: 20px 0; width: 100%; background-color: #f3f3f3; border-radius: 5px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);">
                    <div class="cpiu-progress-bar"
                        style="width: 0%; height: 20px; background-color: <?php echo esc_attr($button_color); ?>; border-radius: 5px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); text-align: center; line-height: 20px; color: white; font-size: 12px; font-weight: bold; position: relative; overflow: hidden;">
                    </div>
                </div>

                <div class="cpiu-spinner cpiu-dynamic-border" data-border-color="<?php echo esc_attr($button_color); ?>"></div>
                <div id="cpiu-upload-status" class="cpiu-upload-status">
                    <p><?php esc_html_e('Please wait...', 'custom-product-image-upload'); ?></p>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Display image preview under add to cart button
     */
    public function display_image_preview()
    {
        global $post;

        if (!is_product() || !isset($post)) {
            return;
        }

        $product_id = $post->ID;
        $product = wc_get_product($product_id);

        // Check if product type is supported (exclude external/affiliate products)
        if (!$product || in_array($product->get_type(), array('external', 'affiliate'))) {
            return;
        }

        $all_configs = $this->get_all_product_configurations($product_id, $product);

        if (!$all_configs) {
            return;
        }

        $config = $all_configs['parent'] ? $all_configs['parent'] : current($all_configs['variations']);

        // Allow filtering of configuration before display
        $config = apply_filters('cpiu_product_config', $config, $product_id);

        // Allow filtering of whether to show upload interface specifically the preview box
        if (!apply_filters('cpiu_show_image_preview', true, $product_id, $config)) {
            return;
        }

        // Get default settings for fallback
        $default_settings = $this->data_manager->get_default_settings();
        $button_text = !empty($config['button_text']) ? $config['button_text'] : $default_settings['button_text'];
        $button_color = !empty($config['button_color']) ? $config['button_color'] : $default_settings['button_color'];

        ?>
        <div class="cpiu-image-preview-section">
            <h4 class="cpiu-preview-title">
                <?php esc_html_e('Your Uploaded files', 'custom-product-image-upload'); ?>
            </h4>

            <div id="cpiu-image-preview-external" class="cpiu-image-preview-external">
                <div class="cpiu-no-images-message">
                    <?php esc_html_e('No files uploaded yet.', 'custom-product-image-upload'); ?>
                </div>
            </div>

            <!-- Upload Button -->
            <div class="cpiu-upload-button-container cpiu-upload-button-inside">
                <button type="button" id="cpiu-open-upload-modal" class="cpiu-upload-btn cpiu-dynamic-bg"
                    data-bg-color="<?php echo esc_attr($button_color); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"
                        style="margin-right: 6px; vertical-align: -2px;">
                        <path fill-rule="evenodd"
                            d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z" />
                        <path fill-rule="evenodd"
                            d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z" />
                    </svg>
                    <?php echo esc_html($button_text); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts()
    {
        global $post;

        // Load on product pages, cart, and checkout
        if (!is_product() && !is_cart() && !is_checkout() && !isset($post)) {
            return;
        }

        // For cart and checkout pages, only enqueue styles
        if (is_cart() || is_checkout()) {
            wp_enqueue_style(
                'cpiu-frontend-styles',
                CPIU_PLUGIN_URL . 'assets/css/cpiu-frontend-styles.css',
                array(),
                CPIU_VERSION
            );

            // Enqueue blocks integration script for cart and checkout pages
            if (has_block('woocommerce/cart') || has_block('woocommerce/checkout')) {
                wp_enqueue_script(
                    'cpiu-blocks-integration',
                    CPIU_PLUGIN_URL . 'assets/js/cpiu-blocks-integration.js',
                    array('wp-blocks', 'wp-element', 'wp-components', 'wc-settings', 'wc-blocks-data-store'),
                    CPIU_VERSION,
                    true
                );
            }
            return;
        }

        $product_id = $post->ID;
        $product = wc_get_product($product_id);

        // Check if product type is supported (exclude external/affiliate products)
        if (!$product || in_array($product->get_type(), array('external', 'affiliate'))) {
            return;
        }

        $all_configs = $this->get_all_product_configurations($product_id, $product);

        if (!$all_configs) {
            return;
        }

        // Enqueue enhanced frontend script
        wp_enqueue_script(
            'cpiu-frontend-multi-product',
            CPIU_PLUGIN_URL . 'assets/js/cpiu-frontend-multi-product.js',
            array('jquery'),
            CPIU_VERSION,
            true
        );

        // Enqueue frontend styles
        wp_enqueue_style(
            'cpiu-frontend-styles',
            CPIU_PLUGIN_URL . 'assets/css/cpiu-frontend-styles.css',
            array(),
            CPIU_VERSION
        );

        // Localize script with product-specific data
        $ajax_nonce = wp_create_nonce("cpiu_image_upload_nonce");
        $config = $all_configs['parent'] ? $all_configs['parent'] : current($all_configs['variations']);

        $script_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $ajax_nonce,
            'product_id' => $product_id,
            'is_variable' => $product->is_type('variable'),
            'parent_config' => $all_configs['parent'],
            'variation_configs' => $all_configs['variations'],
            'required_image_count' => $config['image_count'],
            'max_file_size' => $config['max_file_size'],
            'allowed_types' => $config['allowed_types'],
            'progress_bar_color' => $config['button_color'],
            'resolution_validation' => $config['resolution_validation'],
            'min_width' => $config['min_width'],
            'min_height' => $config['min_height'],
            'max_width' => $config['max_width'],
            'max_height' => $config['max_height'],
            'error_less_images' => sprintf(
                /* translators: %1$d: Number of images */
                esc_html__('Please upload exactly %1$d files to proceed.', 'custom-product-image-upload'),
                $config['image_count']
            ),
            'error_more_images' => sprintf(
                /* translators: %1$d: Number of images */
                esc_html__('You can only upload %1$d files. Please remove the extra files.', 'custom-product-image-upload'),
                $config['image_count']
            ),
            'error_file_size' => sprintf(
                /* translators: %1$s: File size */
                esc_html__('File "{filename}" exceeds the maximum allowed size of %1$s.', 'custom-product-image-upload'),
                size_format($config['max_file_size'])
            ),
            'error_file_type' => sprintf(
                /* translators: %1$s: Allowed file types */
                esc_html__('File "{filename}" type not allowed. Allowed types: %1$s.', 'custom-product-image-upload'),
                implode(', ', array_map('strtoupper', $config['allowed_types']))
            ),
            'error_resolution_min_width' => esc_html__('Image "{filename}" width ({width}px) is below minimum required width ({min_width}px).', 'custom-product-image-upload'),
            'error_resolution_min_height' => esc_html__('Image "{filename}" height ({height}px) is below minimum required height ({min_height}px).', 'custom-product-image-upload'),
            'error_resolution_max_width' => esc_html__('Image "{filename}" width ({width}px) exceeds maximum allowed width ({max_width}px).', 'custom-product-image-upload'),
            'error_resolution_max_height' => esc_html__('Image "{filename}" height ({height}px) exceeds maximum allowed height ({max_height}px).', 'custom-product-image-upload'),
            'text_loading' => esc_html__('Reading files...', 'custom-product-image-upload'),
            'text_loading_file' => esc_html__('Reading {filename}...', 'custom-product-image-upload'),
            'text_loaded_progress' => esc_html__('Read {loaded} of {total} files ({percent}%)', 'custom-product-image-upload'),
            'text_error_loading' => esc_html__('Error reading file: {filename}', 'custom-product-image-upload'),
            'text_skipped_file' => esc_html__('Skipped non-image file: {filename}', 'custom-product-image-upload'),
            'text_ajax_starting' => esc_html__('Uploading files...', 'custom-product-image-upload'),
            'text_ajax_preparing' => esc_html__('Preparing files...', 'custom-product-image-upload'),
            'text_ajax_uploading' => esc_html__('Processing files... {percent}%', 'custom-product-image-upload'),
            'text_ajax_complete' => esc_html__('files Uploaded! Adding to cart...', 'custom-product-image-upload'),
            'text_ajax_error' => esc_html__('Error Uploading files: {message}', 'custom-product-image-upload'),
            'text_ajax_status_error' => esc_html__('Server error during Uploading. Status: {status}', 'custom-product-image-upload'),
            'text_ajax_network_error' => esc_html__('Network error during Uploading.', 'custom-product-image-upload'),
            // Enhanced progress messages
            'text_progress_preparing' => esc_html__('Preparing files for upload...', 'custom-product-image-upload'),
            'text_progress_processing' => esc_html__('Processing {count} file{plural}...', 'custom-product-image-upload'),
            'text_progress_uploading' => esc_html__('Uploading files to server...', 'custom-product-image-upload'),
            'text_progress_server_processing' => esc_html__('Processing server response...', 'custom-product-image-upload'),
            'text_progress_finalizing' => esc_html__('Finalizing upload...', 'custom-product-image-upload'),
            'text_progress_complete' => esc_html__('Upload complete! Redirecting to cart...', 'custom-product-image-upload'),
            'text_progress_error' => esc_html__('Error: {message}', 'custom-product-image-upload'),
            'text_progress_network_error' => esc_html__('Network error: {message}', 'custom-product-image-upload'),
            'text_no_images' => esc_html__('No Files uploaded yet. Click "button" above to add your files.', 'custom-product-image-upload'),
            'text_click_to_crop' => esc_html__('Click to crop', 'custom-product-image-upload'),
            'text_click_to_crop_hint' => esc_html__('Click on an image to crop it if needed.', 'custom-product-image-upload'),
            'text_delete_image' => esc_html__('Delete image', 'custom-product-image-upload'),
            'text_crop_error' => esc_html__('Could not crop image.', 'custom-product-image-upload'),
            'text_unknown_error' => esc_html__('Unknown server error.', 'custom-product-image-upload'),
            'text_resolution_error' => esc_html__('Could not load image for resolution validation.', 'custom-product-image-upload'),
            'cart_url' => wc_get_cart_url(),
            // Shape validation settings
            'shape_validation_enabled' => !empty($config['shape_validation_enabled']),
            'shape_requirements' => isset($config['shape_requirements']) ? $config['shape_requirements'] : array(),
            'enable_shape_cropping' => $config['enable_shape_cropping'],
            'cropping_ratio' => $config['cropping_ratio'],
            'text_shape_required' => esc_html__('Please upload {count} image(s) for {shape}.', 'custom-product-image-upload'),
            'text_shape_missing' => esc_html__('Missing images for shape: {shape}', 'custom-product-image-upload'),
            // PDF support
            'has_pdf' => in_array('pdf', $config['allowed_types']),
            'pdf_upload_action' => 'cpiu_upload_pdf_files',
            'error_pdf_type' => esc_html__('File "{filename}" is not allowed. Please upload only the accepted file types.', 'custom-product-image-upload'),
            'text_pdf_no_crop' => esc_html__('PDF files cannot be cropped.', 'custom-product-image-upload'),
        );

        wp_localize_script('cpiu-frontend-multi-product', 'cpiu_params', $script_data);
    }

    /**
     * Enqueue Cropper.js library
     */
    public function enqueue_cropper_js()
    {
        global $post;

        if (!is_product() || !isset($post)) {
            return;
        }

        $product_id = $post->ID;
        $product = wc_get_product($product_id);

        // Check if product type is supported (exclude external/affiliate products)
        if (!$product || in_array($product->get_type(), array('external', 'affiliate'))) {
            return;
        }

        $all_configs = $this->get_all_product_configurations($product_id, $product);

        if (!$all_configs) {
            return;
        }

        wp_enqueue_script('cropper-js', CPIU_PLUGIN_URL . 'assets/js/cropper.min.js', array(), '1.6.1', true);
        wp_enqueue_style('cropper-css', CPIU_PLUGIN_URL . 'assets/css/cropper.min.css', array(), '1.6.1');
    }

    /**
     * Handle WooCommerce Blocks cart item data
     */
    public function blocks_cart_item_data($item_data, $cart_item, $cart_item_key)
    {
        if (isset($cart_item['cpiu_uploaded_images']) && is_array($cart_item['cpiu_uploaded_images'])) {


            $image_html = '<div class="cpiu-cart-thumbnails">';
            foreach ($cart_item['cpiu_uploaded_images'] as $image_url) {
                if (filter_var(trim($image_url), FILTER_VALIDATE_URL)) {
                    $filename = basename(wp_parse_url($image_url, PHP_URL_PATH));
                    $token = cpiu_generate_public_token($filename);
                    $secured_url = home_url('?cpiu_file=' . urlencode($filename) . '&cpiu_nonce=' . wp_create_nonce('cpiu_serve_file') . '&cpiu_token=' . $token);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    if ($ext === 'pdf') {
                        $image_html .= '<div class="cpiu-order-image-container cpiu-pdf-icon-container" style="display:flex; align-items:center; justify-content:center; background:#f0f0f1; border-radius:4px; width:60px; height:60px;"><span style="font-size:32px;">📄</span></div>';
                    } else {
                        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Customer-uploaded file served via HMAC token URL, not a media-library attachment.
                        $image_html .= '<div class="cpiu-order-image-container"><img src="' . esc_url($secured_url) . '" alt="' . esc_attr__('Uploaded image', 'custom-product-image-upload') . '" class="cpiu-order-image" style="width:60px; height:60px; object-fit:cover; border-radius:4px;" /></div>';
                    }
                }
            }
            $image_html .= '</div>';

            $item_data['uploaded_images'] = array(
                'key' => esc_html__('Uploaded files', 'custom-product-image-upload'),
                'value' => $image_html,
                'display' => $image_html
            );
        }

        return $item_data;
    }

    /**
     * Display uploaded images in standard WooCommerce cart
     */
    public function display_uploaded_images_in_cart($item_data, $cart_item)
    {
        if (isset($cart_item['cpiu_uploaded_images']) && is_array($cart_item['cpiu_uploaded_images'])) {
            $image_html = '<div class="cpiu-cart-images-container" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 5px;">';
            foreach ($cart_item['cpiu_uploaded_images'] as $image_url) {
                if (filter_var(trim($image_url), FILTER_VALIDATE_URL)) {
                    $filename = basename(wp_parse_url($image_url, PHP_URL_PATH));
                    $token = cpiu_generate_public_token($filename);
                    $secured_url = home_url('?cpiu_file=' . urlencode($filename) . '&cpiu_nonce=' . wp_create_nonce('cpiu_serve_file') . '&cpiu_token=' . $token);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    if ($ext === 'pdf') {
                        $image_html .= '<div class="cpiu-cart-pdf-icon" style="background: #f0f0f1; border: 1px solid #ddd; border-radius: 4px; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 24px;" title="' . esc_attr($filename) . '">📄</div>';
                    } else {
                        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Customer-uploaded file served via HMAC token URL, not a media-library attachment.
                        $image_html .= '<img src="' . esc_url($secured_url) . '" alt="' . esc_attr__('Uploaded image', 'custom-product-image-upload') . '" style="width: 60px; height: 60px; object-fit: cover; border: 1px solid #ddd; border-radius: 4px; display: block;" />';
                    }
                }
            }
            $image_html .= '</div>';

            $item_data[] = array(
                'key' => esc_html__('Uploaded files', 'custom-product-image-upload'),
                'value' => $image_html,
                'display' => $image_html
            );
        }

        return $item_data;
    }

    /**
     * Add custom data to cart item.
     * Captures uploaded image data from the request.
     *
     * @param array $cart_item_data Custom cart item data.
     * @param int   $product_id     Product ID.
     * @param int   $variation_id   Variation ID.
     * @return array Modified cart item data.
     */
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        // Check for image data in the POST request.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Runs inside WooCommerce's add-to-cart request (woocommerce_add_cart_item_data), which WooCommerce nonce-protects; values are sanitized below with esc_url_raw()/absint().
        if (isset($_POST['cpiu_uploaded_images']) && is_array($_POST['cpiu_uploaded_images'])) {
            $cart_item_data['cpiu_uploaded_images'] = array_map('esc_url_raw', wp_unslash($_POST['cpiu_uploaded_images']));

            // Store variation_id alongside the image data
            if ($variation_id > 0) {
                $cart_item_data['cpiu_variation_id'] = $variation_id;
            } elseif (isset($_POST['variation_id'])) {
                $cart_item_data['cpiu_variation_id'] = absint($_POST['variation_id']);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $cart_item_data;
    }

    /**
     * Add custom data to cart item during addition.
     *
     * @param array $cart_item Cart item data.
     * @return array Modified cart item data.
     */
    public function add_cart_item($cart_item)
    {
        // This handles cases where data might need normalization upon addition
        return $cart_item;
    }

    /**
     * Get cart item from session.
     * Ensures custom data persists when cart is loaded from session.
     *
     * @param array $cart_item Cart item data.
     * @param array $values Session values.
     * @return array Modified cart item data.
     */
    public function get_cart_item_from_session($cart_item, $values)
    {
        if (isset($values['cpiu_uploaded_images'])) {
            $cart_item['cpiu_uploaded_images'] = $values['cpiu_uploaded_images'];
        }

        if (isset($values['cpiu_variation_id'])) {
            $cart_item['cpiu_variation_id'] = $values['cpiu_variation_id'];
        }

        if (isset($values['cpiu_guest_id'])) {
            $cart_item['cpiu_guest_id'] = $values['cpiu_guest_id'];
        }

        return $cart_item;
    }

    /**
     * Add upload confirmation to cart item name
     */
    public function add_upload_confirmation_to_cart_item_name($product_name, $cart_item, $cart_item_key)
    {
        if (isset($cart_item['cpiu_uploaded_images']) && is_array($cart_item['cpiu_uploaded_images']) && !empty($cart_item['cpiu_uploaded_images'])) {
            // Add checkmark and text after the product name
            $checkmark_html = '<div class="cpiu-cart-confirmation" style="margin-top: 5px; color: #28a745; font-weight: 500;">
                <span style="margin-right: 6px; font-size: 16px; vertical-align: middle;">✓</span><span style="vertical-align: middle;">' . esc_html__('Your files are uploaded', 'custom-product-image-upload') . '</span>
            </div>';

            $product_name .= $checkmark_html;
        }
        return $product_name;
    }

    /**
     * Save uploaded image URLs to order item meta
     */
    public function save_uploaded_images_to_order_meta($item, $cart_item_key, $values, $order)
    {
        if (isset($values['cpiu_uploaded_images']) && is_array($values['cpiu_uploaded_images'])) {
            // Remove the visible 'cpiu_uploaded_images' meta added auto-magically by WooCommerce 
            $item->delete_meta_data('cpiu_uploaded_images');
            $item->delete_meta_data(esc_html__('Uploaded files', 'custom-product-image-upload'));

            $uploaded_images_str = implode(', ', $values['cpiu_uploaded_images']);
            $item->add_meta_data('_cpiu_uploaded_images', $uploaded_images_str, true);

            // Save original filenames if available
            if (isset($values['cpiu_original_filenames']) && is_array($values['cpiu_original_filenames'])) {
                $item->add_meta_data('_cpiu_original_filenames', implode('|', $values['cpiu_original_filenames']), true);
            }
        }
    }

    /**
     * Display uploaded file thumbnails on customer-facing order details pages.
     * Renders images as thumbnails and PDFs as a document icon with a download link.
     */
    public function display_uploaded_images_on_order_pages($item_id, $item, $order)
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $uploaded_images_str = $item->get_meta('_cpiu_uploaded_images');

        if ($uploaded_images_str) {
            echo '<div class="cpiu-order-item-images-wrapper" style="margin-top: 15px; clear: both;">';
            echo '<strong class="cpiu-order-images-title" style="display: block; margin-bottom: 10px; font-size: 14px; color: #333;">' . esc_html__('Uploaded Files:', 'custom-product-image-upload') . '</strong>';
            echo '<div class="cpiu-order-item-images" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-start;">';

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
                        'cpiu_nonce' => wp_create_nonce('cpiu_serve_file'),
                        'cpiu_token' => $token
                    ), home_url('/'));

                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $display_name = isset($original_names[$index]) ? $original_names[$index] : $filename;

                    if ($ext === 'pdf') {
                        echo '<div class="cpiu-order-pdf-container" style="width: 100px; text-align: center; display: flex; flex-direction: column; align-items: center;">';
                        echo '<a href="' . esc_url($secured_url) . '" target="_blank" rel="noopener noreferrer" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; gap: 6px;">';
                        echo '<div style="background: #fdfdfd; border: 1px solid #e5e5e5; border-radius: 6px; width: 100px; height: 100px; display: flex; align-items: center; justify-content: center; font-size: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">📄</div>';
                        echo '<span style="font-size: 11px; width: 100%; word-break: break-all; color: #444; line-height: 1.3; font-weight: 500;">' . esc_html($display_name) . '</span>';
                        echo '</a>';
                        echo '<a href="' . esc_url(add_query_arg('download', '1', $secured_url)) . '" class="cpiu-download-link" style="font-size: 11px; color: #007cba; text-decoration: underline; margin-top: 4px;">' . esc_html__('Download', 'custom-product-image-upload') . '</a>';
                        echo '</div>';
                    } else {
                        echo '<div class="cpiu-order-image-container" style="width: 100px; text-align: center; display: flex; flex-direction: column; align-items: center;">';
                        echo '<a href="' . esc_url($secured_url) . '" target="_blank" rel="noopener noreferrer" style="display: block; width: 100px; height: 100px; margin-bottom: 6px;">';
                        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Customer-uploaded file served via HMAC token URL, not a media-library attachment.
                        echo '<img src="' . esc_url($secured_url) . '" alt="' . esc_attr__('Uploaded Image', 'custom-product-image-upload') . '" style="width: 100px; height: 100px; object-fit: cover; border: 1px solid #e5e5e5; border-radius: 6px; display: block; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">';
                        echo '</a>';
                        echo '<span style="font-size: 11px; width: 100%; word-break: break-all; color: #444; line-height: 1.3; font-weight: 500;">' . esc_html($display_name) . '</span>';
                        echo '<a href="' . esc_url(add_query_arg('download', '1', $secured_url)) . '" class="cpiu-download-link" style="font-size: 11px; color: #007cba; text-decoration: underline; margin-top: 4px;">' . esc_html__('Download', 'custom-product-image-upload') . '</a>';
                        echo '</div>';
                    }
                }
            }
            echo '</div>'; // .cpiu-order-item-images
            echo '</div>'; // .cpiu-order-item-images-wrapper
            echo '</div>'; // .cpiu-order-item-images
            echo '</div>'; // .cpiu-order-item-images-wrapper
            echo '</div>';
        }
    }

    /**
     * Check if current product has upload configuration
     * 
     * @return bool True if current product has configuration
     */
    public function has_current_product_configuration()
    {
        global $post;

        if (!is_product() || !isset($post)) {
            return false;
        }

        return $this->data_manager->has_product_configuration($post->ID);
    }

    /**
     * Get current product configuration
     * 
     * @return array|false Current product configuration or false
     */
    public function get_current_product_configuration()
    {
        global $post;

        if (!is_product() || !isset($post)) {
            return false;
        }

        return $this->data_manager->get_frontend_configuration($post->ID);
    }

    /**
     * Modify add to cart button on archive pages for products with configuration requirements
     * 
     * @param string $add_to_cart_html The add to cart button HTML
     * @param WC_Product $product The product object
     * @return string Modified HTML or empty string to disable button
     */
    public function modify_archive_add_to_cart_button($add_to_cart_html, $product)
    {
        // Check if this product has configuration requirements
        $all_configs = $this->get_all_product_configurations($product->get_id(), $product);

        if (!$all_configs) {
            return $add_to_cart_html; // No configuration, return original button
        }

        // Product has configuration requirements, disable add to cart on archive pages <mcreference link="https://rudrastyh.com/woocommerce/remove-add-to-cart-button.html" index="2">2</mcreference>
        // Replace with a "View Product" button that links to the product page <mcreference link="https://www.businessbloomer.com/woocommerce-remove-add-cart-add-view-product-loop/" index="3">3</mcreference>
        $product_link = $product->get_permalink();
        $view_product_text = esc_html__('Add to Cart', 'custom-product-image-upload');

        // Get the original button classes for styling consistency
        $button_classes = 'button product_type_simple add_to_cart_button ajax_add_to_cart cpiu-configure-button';

        return sprintf(
            '<a href="%s" class="%s" rel="nofollow">%s</a>',
            esc_url($product_link),
            esc_attr($button_classes),
            esc_html($view_product_text)
        );
    }

    /**
     * Get all product configurations including variations
     */
    private function get_all_product_configurations($product_id, $product)
    {
        $has_config = false;
        $parent_config = $this->data_manager->get_frontend_configuration($product_id, 0);
        $variation_configs = array();

        if ($parent_config) {
            $has_config = true;
        }

        if ($product->is_type('variable')) {
            $children = $product->get_children();
            foreach ($children as $var_id) {
                // Pass variation_id to retrieve specific config, which falls back to parent
                $var_config = $this->data_manager->get_frontend_configuration($product_id, $var_id);
                if ($var_config) {
                    $variation_configs[$var_id] = $var_config;
                    $has_config = true;
                }
            }
        }

        if (!$has_config) {
            return false;
        }

        return array('parent' => $parent_config, 'variations' => $variation_configs);
    }

    /**
     * Disable express checkout buttons if configured
     */
    public function maybe_remove_express_checkout()
    {
        if (!is_product()) {
            return;
        }

        // Check if global setting is enabled
        $global_settings = $this->data_manager->get_global_settings();
        if (empty($global_settings['disable_express_checkout'])) {
            return;
        }

        // Check if current product has upload configuration
        if (!$this->has_current_product_configuration()) {
            return;
        }

        // Remove Stripe (Apple Pay / Google Pay) actions
        if (class_exists('WC_Stripe_Payment_Request')) {
            remove_action('woocommerce_after_add_to_cart_quantity', array(\WC_Stripe_Payment_Request::instance(), 'display_payment_request_button'), 1);
            remove_action('woocommerce_after_add_to_cart_quantity', array(\WC_Stripe_Payment_Request::instance(), 'display_payment_request_button'), 10);
            remove_action('woocommerce_after_add_to_cart_quantity', array(\WC_Stripe_Payment_Request::instance(), 'display_payment_request_button'), 20);
            remove_action('woocommerce_before_add_to_cart_button', array(\WC_Stripe_Payment_Request::instance(), 'display_payment_request_button'));
            remove_action('woocommerce_after_add_to_cart_button', array(\WC_Stripe_Payment_Request::instance(), 'display_payment_request_button'));
        }

        // Common filters to disable payment request buttons
        add_filter('wc_stripe_hide_payment_request_on_product_page', '__return_true');
        add_filter('wcpay_payment_request_is_product_supported', '__return_false');
        add_filter('woocommerce_paypal_payments_product_page_buttons_visible', '__return_false');
        add_filter('woocommerce_paypal_express_checkout_disable_on_product', '__return_true');

        // Disable generic express checkout buttons that hook into 'woocommerce_after_add_to_cart_quantity'
        // Strategy: Iterate through callbacks? No, hard to know which is which.
        // Trusted specific filters are the best way.
    }
}