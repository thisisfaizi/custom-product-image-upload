/**
 * CPIU Admin Multi-Product JavaScript
 * 
 * Handles admin interface interactions for multi-product configuration
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

/* global jQuery, ajaxurl, cpiu_admin */
'use strict';

jQuery(document).ready(function ($) {

    // Initialize color pickers
    try {
        if ($.fn.wpColorPicker) {
            $('.cpiu-color-field').wpColorPicker();
        }
    } catch (e) {
        console.error('CPIU Admin - Error initializing color picker:', e);
    }

    // Initialize product search dropdowns
    try {
        initializeProductSearch();
    } catch (e) {
        console.error('CPIU Admin - Error initializing product search:', e);
    }

    // Initialize form handlers
    try {
        initializeFormHandlers();
    } catch (e) {
        console.error('CPIU Admin - Error initializing form handlers:', e);
    }

    // Initialize tab functionality
    try {
        initializeTabs();
    } catch (e) {
        console.error('CPIU Admin - Error initializing tabs:', e);
    }

    // Initialize edit modal
    try {
        initializeEditModal();
    } catch (e) {
        console.error('CPIU Admin - Error initializing edit modal:', e);
    }

    // Initialize resolution validation toggles
    try {
        initializeResolutionToggles();
    } catch (e) {
        console.error('CPIU Admin - Error initializing resolution toggles:', e);
    }

    // Initialize cleanup toggle visibility
    try {
        initializeCleanupToggle();
    } catch (e) {
        console.error('CPIU Admin - Error initializing cleanup toggle:', e);
    }

    // Initialize PDF-only cropping toggle
    try {
        initializePdfCroppingToggle();
    } catch (e) {
        console.error('CPIU Admin - Error initializing PDF cropping toggle:', e);
    }

    /**
     * Initialize product search dropdowns
     */
    function initializeProductSearch() {
        // Check if Select2 is available
        if (typeof $.fn.select2 === 'undefined') {
            console.error('CPIU: Select2 library is not loaded. Product search functionality will be disabled.');
            return;
        }

        $('.cpiu-product-select').select2({
            placeholder: cpiu_admin.strings.search_placeholder,
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: cpiu_admin.ajax_url,
                type: 'POST',
                dataType: 'json',
                delay: 250,
                data: function (params) {

                    // Ensure search_term is not undefined or null
                    var searchTerm = params.term || '';

                    var data = {
                        action: 'cpiu_search_products',
                        nonce: cpiu_admin.nonce,
                        search_term: searchTerm,
                        page: params.page || 1
                    };

                    return data;
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;


                    // Check if the response is successful and has the expected structure
                    if (!data.success || !data.data || !data.data.products) {
                        console.error('CPIU - Invalid response structure:', data);
                        return {
                            results: [],
                            pagination: {
                                more: false
                            }
                        };
                    }

                    return {
                        results: data.data.products.map(function (product) {
                            var displayText = product.title + ' (ID: ' + product.id + ')';
                            if (product.sku) {
                                displayText += ' - SKU: ' + product.sku;
                            }
                            if (product.type) {
                                displayText += ' [' + product.type.toUpperCase() + ']';
                            }

                            // Add fuzzy score indicator if available
                            if (product.fuzzy_score && product.fuzzy_score < 100) {
                                displayText += ' ~' + Math.round(product.fuzzy_score) + '%';
                            }

                            return {
                                id: product.id,
                                text: displayText,
                                sku: product.sku,
                                type: product.type,
                                title: product.title,
                                fuzzy_score: product.fuzzy_score || 100
                            };
                        }),
                        pagination: {
                            more: data.data.current < data.data.pages
                        }
                    };
                },
                cache: true
            },
            templateResult: formatProductOption,
            templateSelection: formatProductSelection
        });
    }

    /**
     * Format product option in dropdown
     */
    function formatProductOption(product) {
        if (product.loading) {
            return product.text;
        }

        if (!product.id) {
            return product.text;
        }

        // Create a more detailed product display
        var displayText = product.title || product.text;
        var additionalInfo = [];

        if (product.sku) {
            additionalInfo.push('SKU: ' + product.sku);
        }
        if (product.type) {
            additionalInfo.push('Type: ' + product.type.toUpperCase());
        }

        var infoText = additionalInfo.length > 0 ? ' (' + additionalInfo.join(', ') + ')' : '';

        return $('<div class="cpiu-product-option">' +
            '<strong>' + displayText + '</strong>' +
            '<br><small style="color: #666;">ID: ' + product.id + infoText + '</small>' +
            '</div>');
    }

    /**
     * Format selected product
     */
    function formatProductSelection(product) {
        if (product.title) {
            return product.title + ' (ID: ' + product.id + ')';
        }
        return product.text || product.id;
    }

    /**
     * Initialize form handlers
     */
    function initializeFormHandlers() {

        // Global settings form - Use delegated event to ensure it's caught
        $(document).on('submit', '#cpiu-global-settings-form', function (e) {
            e.preventDefault();
            saveGlobalSettings();
            return false;
        });

        // Default settings form
        $(document).on('submit', '#cpiu-default-settings-form', function (e) {
            e.preventDefault();
            saveDefaultSettings();
            return false;
        });

        // Add configuration form
        $(document).on('submit', '#cpiu-add-config-form', function (e) {
            e.preventDefault();
            addConfiguration();
            return false;
        });

        // Edit configuration buttons
        $(document).on('click', '.cpiu-edit-config', function (e) {
            e.preventDefault();
            var productId = $(this).data('product-id');
            editConfiguration(productId);
        });

        // Delete configuration buttons
        $(document).on('click', '.cpiu-delete-config', function (e) {
            e.preventDefault();
            var productId = $(this).data('product-id');
            deleteConfiguration(productId);
        });
    }

    /**
     * Save global settings
     */
    function saveGlobalSettings() {
        var form = $('#cpiu-global-settings-form');
        var formData = new FormData(form[0]);
        formData.append('action', 'cpiu_save_global_settings');
        formData.append('nonce', cpiu_admin.nonce);

        // Explicitly set checkbox values
        formData.set('settings[disable_express_checkout]', form.find('input[name="disable_express_checkout"]').is(':checked') ? 1 : 0);
        formData.set('settings[enable_order_image_cleanup]', form.find('input[name="enable_order_image_cleanup"]').is(':checked') ? 1 : 0);
        formData.set('settings[order_image_cleanup_days]', form.find('input[name="order_image_cleanup_days"]').val() || 30);
        formData.set('settings[enable_elementor_support]', form.find('input[name="enable_elementor_support"]').is(':checked') ? 1 : 0);

        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function () {
                form.find('button[type="submit"]').prop('disabled', true).text('Saving...');
            },
            success: function (response) {
                if (response.success) {
                    showNotice(cpiu_admin.strings.save_success, 'success');
                } else {
                    showNotice(response.data.message || cpiu_admin.strings.error, 'error');
                }
            },
            error: function () {
                showNotice(cpiu_admin.strings.error, 'error');
            },
            complete: function () {
                form.find('button[type="submit"]').prop('disabled', false).text('Save Global Settings');
            }
        });
    }

    /**
     * Save default settings
     */
    function saveDefaultSettings() {
        var form = $('#cpiu-default-settings-form');
        var formData = new FormData(form[0]);
        formData.append('action', 'cpiu_save_default_settings');
        formData.append('nonce', cpiu_admin.nonce);

        // Get color picker value
        var colorField = $('#default_button_color');
        if (colorField.wpColorPicker) {
            formData.set('settings[button_color]', colorField.wpColorPicker('color'));
        }

        // Explicitly set nested settings fields and convert units
        formData.set('settings[image_count]', $('#default_image_count').val());
        var maxMb = parseFloat($('#default_max_file_size').val() || '1');
        var maxBytes = Math.round(maxMb * 1024 * 1024);
        formData.set('settings[max_file_size]', maxBytes);
        var buttonText = $('#default_button_text').val();
        if (!buttonText || !buttonText.trim()) {
            showNotice(cpiu_admin.strings.button_text_required || 'Button text is required', 'error');
            return;
        }
        formData.set('settings[button_text]', buttonText);

        // Allowed types as array fields
        // Clear any previous nested keys
        formData.delete('settings[allowed_types]');
        form.find('input[name="allowed_types[]"]:checked').each(function () {
            formData.append('settings[allowed_types][]', $(this).val());
        });

        // Resolution validation settings
        formData.set('settings[resolution_validation]', form.find('input[name="resolution_validation"]').is(':checked') ? 1 : 0);
        formData.set('settings[min_width]', form.find('input[name="min_width"]').val() || 0);
        formData.set('settings[min_height]', form.find('input[name="min_height"]').val() || 0);
        formData.set('settings[max_width]', form.find('input[name="max_width"]').val() || 0);
        formData.set('settings[max_height]', form.find('input[name="max_height"]').val() || 0);
        formData.set('settings[enable_shape_cropping]', form.find('input[name="enable_shape_cropping"]').is(':checked') ? 1 : 0);
        formData.set('settings[enable_shape_cropping]', form.find('input[name="enable_shape_cropping"]').is(':checked') ? 1 : 0);
        formData.set('settings[cropping_ratio]', form.find('select[name="cropping_ratio"]').val());
        formData.set('settings[cropping_ratio]', form.find('select[name="cropping_ratio"]').val());
        formData.set('settings[disable_quantity]', form.find('input[name="disable_quantity"]').is(':checked') ? 1 : 0);

        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function () {
                form.find('button[type="submit"]').prop('disabled', true).text('Saving...');
            },
            success: function (response) {
                if (response.success) {
                    showNotice(cpiu_admin.strings.save_success, 'success');
                    // Update other forms with new default settings
                    updateOtherFormsWithDefaults(response.data.settings);
                } else {
                    showNotice(response.data.message || cpiu_admin.strings.error, 'error');
                }
            },
            error: function () {
                showNotice(cpiu_admin.strings.error, 'error');
            },
            complete: function () {
                form.find('button[type="submit"]').prop('disabled', false).text('Save Default Settings');
            }
        });
    }

    /**
     * Add new configuration
     */
    function addConfiguration() {
        var form = $('#cpiu-add-config-form');
        var formData = new FormData(form[0]);
        formData.append('action', 'cpiu_save_configuration');
        formData.append('nonce', cpiu_admin.nonce);

        // Get color picker value
        var colorField = $('#button_color');
        if (colorField.wpColorPicker) {
            formData.set('config[button_color]', colorField.wpColorPicker('color'));
        }

        // Explicitly set nested config fields and convert units
        formData.set('config[image_count]', $('#image_count').val());
        var cMaxMb = parseFloat($('#max_file_size').val() || '1');
        var cMaxBytes = Math.round(cMaxMb * 1024 * 1024);
        formData.set('config[max_file_size]', cMaxBytes);
        var buttonText = $('#button_text').val();
        if (!buttonText || !buttonText.trim()) {
            showNotice(cpiu_admin.strings.button_text_required || 'Button text is required', 'error');
            return;
        }
        formData.set('config[button_text]', buttonText);
        formData.set('config[enabled]', 1);

        // Allowed types as array fields
        formData.delete('config[allowed_types]');
        form.find('input[name="allowed_types[]"]:checked').each(function () {
            formData.append('config[allowed_types][]', $(this).val());
        });

        // Resolution validation settings
        formData.set('config[resolution_validation]', form.find('input[name="resolution_validation"]').is(':checked') ? 1 : 0);
        formData.set('config[min_width]', form.find('input[name="min_width"]').val() || 0);
        formData.set('config[min_height]', form.find('input[name="min_height"]').val() || 0);
        formData.set('config[max_width]', form.find('input[name="max_width"]').val() || 0);
        formData.set('config[max_height]', form.find('input[name="max_height"]').val() || 0);
        formData.set('config[enable_shape_cropping]', form.find('input[name="enable_shape_cropping"]').is(':checked') ? 1 : 0);
        formData.set('config[cropping_ratio]', form.find('select[name="cropping_ratio"]').val());
        formData.set('config[disable_quantity]', form.find('input[name="disable_quantity"]').is(':checked') ? 1 : 0);

        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function () {
                form.find('button[type="submit"]').prop('disabled', true).text('Adding...');
            },
            success: function (response) {
                if (response.success) {
                    showNotice(cpiu_admin.strings.save_success, 'success');
                    form[0].reset();
                    $('#product_search').val(null).trigger('change');
                    refreshConfigurationsTable();
                } else {
                    showNotice(response.data.message || cpiu_admin.strings.error, 'error');
                }
            },
            error: function () {
                showNotice(cpiu_admin.strings.error, 'error');
            },
            complete: function () {
                form.find('button[type="submit"]').prop('disabled', false).text('Add Configuration');
            }
        });
    }

    /**
     * Edit configuration
     */
    function editConfiguration(productId) {
        // Get configuration data
        var formData = new FormData();
        formData.append('action', 'cpiu_get_configuration');
        formData.append('nonce', cpiu_admin.nonce);
        formData.append('product_id', productId);

        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    populateEditModal(response.data);
                    $('#cpiu-edit-modal').removeClass('cpiu-modal-hidden').show();
                    $(document).trigger('cpiu:editModalOpened');
                } else {
                    showNotice(response.data.message || 'Failed to load configuration.', 'error');
                }
            },
            error: function () {
                showNotice('Network error while loading configuration.', 'error');
            }
        });
    }

    /**
     * Populate edit modal with configuration data
     */
    function populateEditModal(data) {
        var config = data.configuration;

        // Set product information
        $('#edit_product_id').val(data.product_id);
        $('#edit_product_id_display').text(data.product_id);
        $('#edit_product_name').text(data.product_name);

        // Set form values
        $('#edit_image_count').val(config.image_count);
        $('#edit_max_file_size').val((config.max_file_size / 1024 / 1024).toFixed(1)); // Convert to MB with 1 decimal place
        $('#edit_button_text').val(config.button_text || '');
        $('#edit_button_color').val(config.button_color || '');
        $('#edit_enabled').prop('checked', config.enabled === true || config.enabled === 1);

        // Set allowed types checkboxes
        $('#edit_allowed_types input[type="checkbox"]').prop('checked', false);
        if (config.allowed_types && Array.isArray(config.allowed_types)) {
            config.allowed_types.forEach(function (type) {
                $('#edit_allowed_types input[value="' + type + '"]').prop('checked', true);
            });
        }

        // Set resolution validation settings
        $('#edit_resolution_validation').prop('checked', config.resolution_validation === true || config.resolution_validation === 1);
        $('#edit_min_width').val(config.min_width || 0);
        $('#edit_min_height').val(config.min_height || 0);
        $('#edit_max_width').val(config.max_width || 0);
        $('#edit_max_height').val(config.max_height || 0);

        // Set shape cropping settings
        $('#edit_enable_shape_cropping').prop('checked', config.enable_shape_cropping === true || config.enable_shape_cropping === 1 || config.enable_shape_cropping === '1');
        $('#edit_cropping_ratio').val(config.cropping_ratio || 'free');

        // Set quantity lock setting
        $('#edit_disable_quantity').prop('checked', config.disable_quantity === true || config.disable_quantity === 1 || config.disable_quantity === '1');

        // Show/hide resolution settings based on checkbox
        $('.edit-resolution-settings').toggle(config.resolution_validation === true || config.resolution_validation === 1);

        // Initialize color picker for edit modal
        if ($('#edit_button_color').hasClass('wp-color-picker')) {
            $('#edit_button_color').wpColorPicker('color', config.button_color || '#4CAF50');
        } else {
            $('#edit_button_color').wpColorPicker({
                defaultColor: config.button_color || '#4CAF50'
            });
        }
    }

    /**
     * Save edit configuration
     */
    function saveEditConfiguration() {
        var productId = $('#edit_product_id').val();
        var formData = new FormData();

        // Collect form data in the format expected by the AJAX handler
        formData.append('action', 'cpiu_save_configuration');
        formData.append('nonce', cpiu_admin.nonce);
        formData.append('product_id', productId);

        // Create config object with proper structure
        var config = {
            image_count: parseInt($('#edit_image_count').val(), 10),
            max_file_size: Math.round(parseFloat($('#edit_max_file_size').val()) * 1024 * 1024), // Convert MB to bytes
            button_text: $('#edit_button_text').val(),
            button_color: $('#edit_button_color').val(),
            enabled: $('#edit_enabled').is(':checked') ? 1 : 0,
            resolution_validation: $('#edit_resolution_validation').is(':checked') ? 1 : 0,
            min_width: parseInt($('#edit_min_width').val(), 10) || 0,
            min_height: parseInt($('#edit_min_height').val(), 10) || 0,
            max_width: parseInt($('#edit_max_width').val(), 10) || 0,
            max_height: parseInt($('#edit_max_height').val(), 10) || 0,
            enable_shape_cropping: $('#edit_enable_shape_cropping').is(':checked') ? 1 : 0,
            cropping_ratio: $('#edit_cropping_ratio').val(),
            disable_quantity: $('#edit_disable_quantity').is(':checked') ? 1 : 0
        };

        if (!config.button_text || !config.button_text.trim()) {
            showNotice(cpiu_admin.strings.button_text_required || 'Button text is required', 'error');
            return;
        }

        // Collect allowed types
        var allowedTypes = [];
        $('#edit_allowed_types input[type="checkbox"]:checked').each(function () {
            allowedTypes.push($(this).val());
        });
        config.allowed_types = allowedTypes;

        // Append config data
        formData.append('config[image_count]', config.image_count);
        formData.append('config[max_file_size]', config.max_file_size);
        formData.append('config[button_text]', config.button_text);
        formData.append('config[button_color]', config.button_color);
        formData.append('config[enabled]', config.enabled);
        formData.append('config[resolution_validation]', config.resolution_validation);
        formData.append('config[min_width]', config.min_width);
        formData.append('config[min_height]', config.min_height);
        formData.append('config[max_width]', config.max_width);
        formData.append('config[max_height]', config.max_height);
        formData.append('config[enable_shape_cropping]', config.enable_shape_cropping);
        formData.append('config[cropping_ratio]', config.cropping_ratio);
        formData.append('config[disable_quantity]', config.disable_quantity);

        // Append allowed types as array
        formData.delete('config[allowed_types]');
        allowedTypes.forEach(function (type) {
            formData.append('config[allowed_types][]', type);
        });






        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    showNotice('Configuration updated successfully!', 'success');
                    $('#cpiu-edit-modal').hide().addClass('cpiu-modal-hidden');
                    // Refresh the configurations table
                    refreshConfigurationsTable();
                } else {
                    showNotice(response.data.message || 'Failed to update configuration.', 'error');
                }
            },
            error: function () {
                showNotice('Network error while updating configuration.', 'error');
            }
        });
    }

    /**
     * Delete configuration
     */
    function deleteConfiguration(productId) {
        if (!confirm(cpiu_admin.strings.confirm_delete)) {
            return;
        }

        var formData = new FormData();
        formData.append('action', 'cpiu_delete_configuration');
        formData.append('nonce', cpiu_admin.nonce);
        formData.append('product_id', productId);

        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    showNotice(cpiu_admin.strings.delete_success, 'success');
                    refreshConfigurationsTable();
                } else {
                    showNotice(response.data.message || cpiu_admin.strings.error, 'error');
                }
            },
            error: function () {
                showNotice(cpiu_admin.strings.error, 'error');
            }
        });
    }

    /**
     * Refresh configurations table
     */
    function refreshConfigurationsTable() {
        // Reload the current page with the same tab parameter to maintain the current view
        var currentUrl = window.location.href;
        window.location.href = currentUrl;
    }

    /**
     * Show notice message
     */
    function showNotice(message, type) {
        var noticeClass = 'notice notice-' + type + ' is-dismissible';
        var notice = $('<div class="' + noticeClass + '"><p>' + message + '</p></div>');

        // Remove existing notices
        $('.notice').remove();

        // Add new notice below tabs
        var nav = $('.cpiu-tab-nav');
        if (nav.length) {
            nav.after(notice);
        } else {
            $('.wrap').prepend(notice);
        }

        // Auto-dismiss after 5 seconds
        setTimeout(function () {
            notice.fadeOut();
        }, 5000);
    }

    /**
     * Validate form data
     */
    function validateForm(form) {
        var isValid = true;
        var errors = [];

        // Check required fields
        form.find('[required]').each(function () {
            if (!$(this).val()) {
                isValid = false;
                errors.push($(this).attr('name') + ' is required');
            }
        });

        // Check image count
        var imageCount = form.find('input[name*="image_count"]').val();
        if (imageCount && (imageCount < 1 || imageCount > 50)) {
            isValid = false;
            errors.push('Image count must be between 1 and 50');
        }

        // Check file size (minimum 1MB, no maximum limit)
        var fileSize = form.find('input[name*="max_file_size"]').val();
        if (fileSize && fileSize < 1) {
            isValid = false;
            errors.push('File size must be at least 1 MB');
        }

        // Check allowed types
        var allowedTypes = form.find('input[name*="allowed_types[]"]:checked');
        if (allowedTypes.length === 0) {
            isValid = false;
            errors.push('At least one file type must be selected');
        }

        if (!isValid) {
            showNotice(errors.join(', '), 'error');
        }

        return isValid;
    }

    /**
     * Initialize edit modal functionality
     */
    function initializeEditModal() {
        // Close modal when clicking close button or outside modal
        $(document).on('click', '.cpiu-modal-close, #cpiu-cancel-edit', function () {
            $('#cpiu-edit-modal').hide().addClass('cpiu-modal-hidden');
        });

        // Close modal when clicking outside
        $(document).on('click', '#cpiu-edit-modal', function (e) {
            if (e.target === this) {
                $(this).hide().addClass('cpiu-modal-hidden');
            }
        });

        // Save edit changes
        $(document).on('click', '#cpiu-save-edit', function () {
            saveEditConfiguration();
        });
    }

    /**
     * Initialize tab functionality
     */
    function initializeTabs() {
        // Handle tab clicks
        $('.cpiu-tab-nav .nav-tab').on('click', function (e) {
            e.preventDefault();

            var targetTab = $(this).data('tab');
            var targetUrl = $(this).attr('href');

            // Navigate to the new URL instead of just switching tabs
            window.location.href = targetUrl;
        });

        // No need for hash-based tab switching since we're using proper URLs
        // The server-side PHP will handle showing the correct tab based on the URL parameter

        // Refresh forms with current default settings when on Add Configuration or Bulk Operations tabs
        var currentTab = cpiu_admin.current_tab;
        if (currentTab === 'add-configuration' || currentTab === 'bulk-operations') {
            refreshFormsWithCurrentDefaults();
        }
    }

    /**
     * Update other forms with new default settings
     */
    function updateOtherFormsWithDefaults(settings) {
        if (!settings) {
            return;
        }

        // Update Add Configuration form
        $('#image_count').val(settings.image_count);
        $('#max_file_size').val((settings.max_file_size / 1024 / 1024).toFixed(1));
        $('#button_text').val(settings.button_text);

        // Update color picker
        var addColorField = $('#button_color');
        if (addColorField.wpColorPicker) {
            addColorField.wpColorPicker('color', settings.button_color);
        } else {
            addColorField.val(settings.button_color);
        }

        // Update allowed types checkboxes
        $('#cpiu-add-config-form input[name="allowed_types[]"]').prop('checked', false);
        if (settings.allowed_types && Array.isArray(settings.allowed_types)) {
            settings.allowed_types.forEach(function (type) {
                $('#cpiu-add-config-form input[name="allowed_types[]"][value="' + type + '"]').prop('checked', true);
            });
        }

        // Update resolution validation settings
        $('#cpiu-add-config-form input[name="resolution_validation"]').prop('checked', settings.resolution_validation === 1);
        $('#cpiu-add-config-form input[name="min_width"]').val(settings.min_width || 0);
        $('#cpiu-add-config-form input[name="min_height"]').val(settings.min_height || 0);
        $('#cpiu-add-config-form input[name="max_width"]').val(settings.max_width || 0);
        $('#cpiu-add-config-form input[name="max_height"]').val(settings.max_height || 0);
        $('#cpiu-add-config-form .resolution-settings').toggle(settings.resolution_validation === 1);

        // The Bulk Operations form is provided by the Pro add-on; nothing to sync in the free build.
    }

    /**
     * Refresh forms with current default settings from server
     */
    function refreshFormsWithCurrentDefaults() {
        // Get current default settings from the default settings form
        var currentDefaults = {
            image_count: $('#default_image_count').val(),
            max_file_size: parseFloat($('#default_max_file_size').val() || '1') * 1024 * 1024,
            button_text: $('#default_button_text').val(),
            button_color: $('#default_button_color').val(),
            allowed_types: [],
            resolution_validation: $('#cpiu-default-settings-form input[name="resolution_validation"]').is(':checked') ? 1 : 0,
            min_width: parseInt($('#cpiu-default-settings-form input[name="min_width"]').val(), 10) || 0,
            min_height: parseInt($('#cpiu-default-settings-form input[name="min_height"]').val(), 10) || 0,
            max_width: parseInt($('#cpiu-default-settings-form input[name="max_width"]').val(), 10) || 0,
            max_height: parseInt($('#cpiu-default-settings-form input[name="max_height"]').val(), 10) || 0
        };

        // Get checked allowed types from default form
        $('#cpiu-default-settings-form input[name="allowed_types[]"]:checked').each(function () {
            currentDefaults.allowed_types.push($(this).val());
        });

        // Update other forms with current defaults
        updateOtherFormsWithDefaults(currentDefaults);
    }

    /**
     * Initialize resolution validation toggles
     */
    function initializeResolutionToggles() {
        // Toggle resolution settings visibility
        $(document).on('change', 'input[name="resolution_validation"]', function () {
            var isChecked = $(this).is(':checked');
            var settingsRows = $(this).closest('form').find('.resolution-settings');
            settingsRows.toggle(isChecked);
        });

        // Toggle edit modal resolution settings
        $(document).on('change', '#edit_resolution_validation', function () {
            var isChecked = $(this).is(':checked');
            $('.edit-resolution-settings').toggle(isChecked);
        });


        // Initialize state on page load
        $('input[name="resolution_validation"]').trigger('change');
        $('#edit_resolution_validation').trigger('change');

    }




    // Save uninstall preference
    $('#cpiu-save-uninstall-preference').on('click', function () {
        var preference = $('input[name="cpiu_uninstall_preference"]:checked').val();

        if (!preference) {
            showNotice(cpiu_admin.strings.select_preference, 'error');
            return;
        }

        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'cpiu_set_uninstall_preference',
                nonce: cpiu_admin.nonce,
                preference: preference
            },
            success: function (response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message || 'Failed to save preference.', 'error');
                }
            },
            error: function () {
                showNotice('Network error while saving preference.', 'error');
            }
        });
    });

    // Export settings for uninstall
    $('#cpiu-export-for-uninstall').on('click', function () {
        $.ajax({
            url: cpiu_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'cpiu_export_for_uninstall',
                nonce: cpiu_admin.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Create and trigger download
                    var blob = new Blob([response.data.data], { type: 'application/json' });
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);

                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message || 'Failed to export settings.', 'error');
                }
            },
            error: function () {
                showNotice('Network error while exporting settings.', 'error');
            }
        });
    });

    /**
     * Initialize PDF-only cropping toggle
     *
     * When PDF is the only selected file type, disable the Shape Cropping
     * checkbox and Cropping Ratio select (since PDFs cannot be cropped).
     * This runs for all forms: Default Settings, Add Config, Bulk, and Edit Modal.
     */
    function initializePdfCroppingToggle() {

        /**
         * Evaluate the state of a set of allowed-type checkboxes and
         * update the cropping controls accordingly.
         *
         * @param {jQuery} $typeCheckboxes - all allowed_types checkboxes in the form
         * @param {jQuery} $shapeRow       - the <tr> wrapping the shape-cropping checkbox
         * @param {jQuery} $ratioRow       - the <tr> wrapping the cropping-ratio select
         */
        function updateCroppingControlState($typeCheckboxes, $shapeRow, $ratioRow) {
            var checkedValues = $typeCheckboxes.filter(':checked').map(function () {
                return $(this).val();
            }).get();

            var pdfOnly = checkedValues.length === 1 && checkedValues[0] === 'pdf';

            if (pdfOnly) {
                $shapeRow.css('opacity', '0.4').find('input, select').prop('disabled', true);
                $ratioRow.css('opacity', '0.4').find('input, select').prop('disabled', true);
            } else {
                $shapeRow.css('opacity', '').find('input, select').prop('disabled', false);
                $ratioRow.css('opacity', '').find('input, select').prop('disabled', false);
            }
        }

        // ── Main forms (Default Settings / Add Config / Bulk) ──────────────────
        // These forms share the same name attribute for allowed_types checkboxes.
        // We scope to each .cpiu-form to handle multiple forms on the same page.
        $('.cpiu-form').each(function () {
            var $form = $(this);
            var $typeCheckboxes = $form.find('input[name="allowed_types[]"]');

            if ($typeCheckboxes.length === 0) {
                return; // skip forms that don't have allowed_types (e.g. global settings)
            }

            // Find cropping rows by looking for the matching labels / inputs
            var $shapeRow = $form.find('input[name="enable_shape_cropping"]').closest('tr');
            var $ratioRow = $form.find('select[name="cropping_ratio"]').closest('tr');

            if ($shapeRow.length === 0 || $ratioRow.length === 0) {
                return;
            }

            // Set initial state
            updateCroppingControlState($typeCheckboxes, $shapeRow, $ratioRow);

            // React to changes
            $typeCheckboxes.on('change', function () {
                updateCroppingControlState($typeCheckboxes, $shapeRow, $ratioRow);
            });
        });

        // ── Edit Modal ─────────────────────────────────────────────────────────
        var $editTypeCheckboxes = $('#edit_allowed_types').find('input[name="allowed_types[]"]');
        var $editShapeRow = $('#edit_enable_shape_cropping').closest('tr');
        var $editRatioRow = $('#edit_cropping_ratio').closest('tr');

        if ($editTypeCheckboxes.length > 0 && $editShapeRow.length > 0 && $editRatioRow.length > 0) {
            // React to changes
            $editTypeCheckboxes.on('change', function () {
                updateCroppingControlState($editTypeCheckboxes, $editShapeRow, $editRatioRow);
            });

            // Also re-evaluate each time the edit modal is opened
            // (the modal is populated by JS, so we hook into the modal-open event)
            $(document).on('cpiu:editModalOpened', function () {
                // Re-query because modal contents may have been re-rendered
                var $freshTypeCheckboxes = $('#edit_allowed_types').find('input[name="allowed_types[]"]');
                var $freshShapeRow = $('#edit_enable_shape_cropping').closest('tr');
                var $freshRatioRow = $('#edit_cropping_ratio').closest('tr');
                updateCroppingControlState($freshTypeCheckboxes, $freshShapeRow, $freshRatioRow);

                // Re-bind change listener (unbind first to avoid duplicates)
                $freshTypeCheckboxes.off('change.pdfToggle').on('change.pdfToggle', function () {
                    updateCroppingControlState($freshTypeCheckboxes, $freshShapeRow, $freshRatioRow);
                });
            });
        }
    }

    /**
     * Initialize cleanup toggle visibility
     * Shows/hides the cleanup days row based on the enable checkbox
     */
    function initializeCleanupToggle() {
        var $checkbox = $('input[name="enable_order_image_cleanup"]');
        var $daysRow = $('input[name="order_image_cleanup_days"]').closest('tr');

        if ($checkbox.length === 0 || $daysRow.length === 0) {
            return;
        }

        // Set initial state
        if ($checkbox.is(':checked')) {
            $daysRow.show();
        } else {
            $daysRow.hide();
        }

        // Toggle on change
        $checkbox.on('change', function () {
            if ($(this).is(':checked')) {
                $daysRow.slideDown(200);
            } else {
                $daysRow.slideUp(200);
            }
        });
    }
});
