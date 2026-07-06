<?php
/**
 * CPIU Secure Upload Class
 * 
 * Handles secure file uploads with comprehensive security measures
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class CPIU_Secure_Upload
{

    /**
     * Allowed MIME types for images
     */
    private $allowed_mime_types;

    /**
     * Allowed file extensions
     */
    private $allowed_extensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'pdf'
    );

    /**
     * Maximum file size (will be set dynamically)
     */
    private $max_file_size = 10485760; // 10MB default

    /**
     * Resolution validation settings
     */
    private $resolution_validation = false;
    private $min_width = 0;
    private $min_height = 0;
    private $max_width = 0;
    private $max_height = 0;

    /**
     * Upload directory path
     */
    private $upload_dir;

    /**
     * Upload directory URL
     */
    private $upload_url;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->init_allowed_types();
        $this->init_upload_directory();
    }

    /**
     * Initialize allowed file types with filters
     */
    private function init_allowed_types()
    {
        $default_mime_types = array(
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf'
        );

        $default_extensions = array(
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'pdf'
        );

        // Allow filtering of allowed file types
        $this->allowed_mime_types = apply_filters('cpiu_allowed_mime_types', $default_mime_types);
        $this->allowed_extensions = apply_filters('cpiu_allowed_extensions', $default_extensions);
    }

    /**
     * Initialize upload directory
     */
    private function init_upload_directory()
    {
        $upload_dir_info = wp_upload_dir();
        $this->upload_dir = $upload_dir_info['basedir'] . '/' . CPIU_UPLOAD_DIR_NAME;
        $this->upload_url = $upload_dir_info['baseurl'] . '/' . CPIU_UPLOAD_DIR_NAME;

        // Ensure directory exists and is secure
        $this->ensure_secure_directory();
    }

    /**
     * Ensure upload directory exists and is secure
     */
    private function ensure_secure_directory()
    {
        if (!file_exists($this->upload_dir)) {
            if (!wp_mkdir_p($this->upload_dir)) {
                throw new Exception(esc_html__('Could not create secure upload directory.', 'nowdigiverse-product-image-upload'));
            }
        }

        // Create security files
        $this->create_security_files();

        // Set directory permissions (non-executable)
        if (is_dir($this->upload_dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            chmod($this->upload_dir, 0755); // Read/write for owner, read for others
        }
    }

    /**
     * Create security files to prevent execution
     */
    private function create_security_files()
    {
        $index_file = $this->upload_dir . '/index.php';
        $htaccess_file = $this->upload_dir . '/.htaccess';

        // Create index.php to prevent directory listing
        if (!file_exists($index_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a static protection file into the plugin's own uploads subdirectory.
            file_put_contents($index_file, '<?php // Silence is golden.');
        }

        // Deny ALL direct HTTP access. Files must be served through the
        // secure PHP endpoint (?cpiu_file=...&cpiu_token=...) only.
        // Always write (overwrite) to correct any legacy permissive .htaccess.
        $htaccess_content = "# Block ALL direct access to uploaded files.\n";
        $htaccess_content .= "# Access is only permitted via the secure plugin endpoint.\n";
        $htaccess_content .= "\n";
        $htaccess_content .= "<IfModule mod_authz_core.c>\n";
        $htaccess_content .= "    Require all denied\n";
        $htaccess_content .= "</IfModule>\n";
        $htaccess_content .= "\n";
        $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "    Order deny,allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</IfModule>\n";

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($htaccess_file, $htaccess_content);
    }

    /**
     * Set maximum file size
     */
    public function set_max_file_size($size_in_bytes)
    {
        $this->max_file_size = absint($size_in_bytes);
        if ($this->max_file_size < 1024) {
            $this->max_file_size = 1024; // Minimum 1KB
        }
    }

    /**
     * Set resolution validation settings
     */
    public function set_resolution_validation($enabled, $min_width = 0, $min_height = 0, $max_width = 0, $max_height = 0)
    {
        $this->resolution_validation = (bool) $enabled;
        $this->min_width = max(0, min(10000, absint($min_width)));
        $this->min_height = max(0, min(10000, absint($min_height)));
        $this->max_width = max(0, min(10000, absint($max_width)));
        $this->max_height = max(0, min(10000, absint($max_height)));
    }

    /**
     * Process base64 image data securely
     */
    public function process_base64_image($base64_data, $index, $product_id, $user_id, $ip_address, $original_name = '')
    {
        // Log upload attempt
        $this->log_upload_attempt($user_id, $ip_address, $product_id, 'base64_upload', true);

        try {
            // Validate base64 format
            if (empty($base64_data) || strpos($base64_data, 'data:image/') !== 0) {
                /* translators: %1$d: Image index */
                throw new Exception(sprintf(esc_html__('Invalid image format for image %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Extract image data
            if (!preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,(.*)$/i', $base64_data, $matches) || count($matches) !== 3) {
                /* translators: %1$d: Image index */
                throw new Exception(sprintf(esc_html__('Unsupported image type for image %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            $image_type = strtolower($matches[1]);
            // Decode the client-side cropped image sent as a data:image/...;base64 URI.
            // Not obfuscation: the decoded bytes are fully re-validated below
            // (finfo MIME, exif_imagetype, executable-signature scan) before saving.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            $image_data = base64_decode($matches[2]);

            if ($image_data === false) {
                /* translators: %1$d: Image index */
                throw new Exception(sprintf(esc_html__('Failed to decode image %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Create temporary file for validation
            $temp_file = $this->create_temp_file($image_data, $image_type);

            try {
                // Validate file security
                $this->validate_file_security($temp_file, $image_type, $original_name);

                // Generate secure filename
                $secure_filename = $this->generate_secure_filename($product_id, $index, $image_type);
                $file_path = $this->upload_dir . '/' . $secure_filename;

                // Move file to final location
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing validated image bytes to the plugin's protected uploads subdirectory.
                if (!file_put_contents($file_path, $image_data)) {
                    /* translators: %1$d: Image index */
                    throw new Exception(sprintf(esc_html__('Failed to save image %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
                }

                // Set file permissions (non-executable)
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                chmod($file_path, 0644);

                // Log successful upload
                $this->log_upload_attempt($user_id, $ip_address, $product_id, 'success', true, $secure_filename);

                $upload_result = array(
                    'path' => $file_path,
                    'url' => $this->upload_url . '/' . $secure_filename,
                    'filename' => $secure_filename,
                    'original_name' => $original_name
                );

                // Allow other plugins to hook into successful uploads
                do_action('cpiu_file_uploaded', $upload_result, $product_id, $user_id);

                return $upload_result;

            } finally {
                // Clean up temp file
                if (file_exists($temp_file)) {
                    wp_delete_file($temp_file);
                }
            }

        } catch (Exception $e) {
            $this->log_upload_attempt($user_id, $ip_address, $product_id, 'failed', false, '', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process a raw file upload from $_FILES (Image or PDF)
     *
     * @param array  $file_entry  A single entry from $_FILES (tmp_name, name, size, error).
     * @param int    $index       File index (used in filename).
     * @param int    $product_id  WooCommerce product ID.
     * @param int    $user_id     WordPress user ID (0 for guests).
     * @param string $ip_address  Client IP address for logging.
     * @return array             Array with 'path', 'url', 'filename' keys.
     * @throws Exception         On validation or upload failure.
     */
    public function process_raw_file($file_entry, $index, $product_id, $user_id, $ip_address)
    {
        $this->log_upload_attempt($user_id, $ip_address, $product_id, 'file_upload', true);

        try {
            if (!isset($file_entry['error']) || $file_entry['error'] !== UPLOAD_ERR_OK) {
                /* translators: %1$d: file index number */
                throw new Exception(sprintf(esc_html__('Upload error for file %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            if (!is_uploaded_file($file_entry['tmp_name'])) {
                /* translators: %1$d: file index number */
                throw new Exception(sprintf(esc_html__('Invalid file for file %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            $original_name = sanitize_file_name($file_entry['name']);
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            if (!in_array($ext, $this->allowed_extensions)) {
                /* translators: %1$d: file index number, %2$s: file extension */
                throw new Exception(sprintf(esc_html__('File %1$d has an invalid extension: %2$s.', 'nowdigiverse-product-image-upload'), $index + 1, esc_html($ext)));
            }

            // Route to security validation
            $this->validate_file_security($file_entry['tmp_name'], $ext, $original_name);

            $secure_filename = $this->generate_secure_filename($product_id, $index, $ext);
            $file_path = $this->upload_dir . '/' . $secure_filename;

            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
                WP_Filesystem();
            }
            if (!$wp_filesystem->move($file_entry['tmp_name'], $file_path, true)) {
                /* translators: %1$d: file index number */
                throw new Exception(sprintf(esc_html__('Failed to save file %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            chmod($file_path, 0644);

            $this->log_upload_attempt($user_id, $ip_address, $product_id, 'success', true, $secure_filename);

            $upload_result = array(
                'path' => $file_path,
                'url' => $this->upload_url . '/' . $secure_filename,
                'filename' => $secure_filename,
                'original_name' => $original_name
            );

            do_action('cpiu_file_uploaded', $upload_result, $product_id, $user_id);

            return $upload_result;

        } catch (Exception $e) {
            // Log failed upload
            $this->log_upload_attempt($user_id, $ip_address, $product_id, 'failed', false, '', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create temporary file for validation
     */
    private function create_temp_file($data, $extension)
    {
        // Keep validation scratch files inside the plugin's own uploads
        // subfolder (already created and .htaccess-protected by
        // ensure_secure_directory()). Never write outside wp-content/uploads.
        $temp_dir = $this->upload_dir . '/tmp';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $temp_filename = 'cpiu_temp_' . wp_generate_password(12, false) . '.' . $extension;
        $temp_path = $temp_dir . '/' . $temp_filename;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (file_put_contents($temp_path, $data) === false) {
            throw new Exception(esc_html__('Could not create temporary file for validation.', 'nowdigiverse-product-image-upload'));
        }

        return $temp_path;
    }

    /**
     * Validate file security comprehensively
     */
    private function validate_file_security($file_path, $expected_extension, $original_filename = '')
    {
        $filename_to_check = !empty($original_filename) ? $original_filename : basename($file_path);

        // Check file exists
        if (!file_exists($file_path)) {
            throw new Exception(esc_html__('File does not exist.', 'nowdigiverse-product-image-upload'));
        }

        // Check file size
        $file_size = filesize($file_path);
        if ($file_size > $this->max_file_size) {
            throw new Exception(sprintf(
                /* translators: %1$s: Formatted file size, %2$s: Formatted maximum allowed size */
                esc_html__('File size (%1$s) exceeds maximum allowed size (%2$s).', 'nowdigiverse-product-image-upload'),
                esc_html(size_format($file_size)),
                esc_html(size_format($this->max_file_size))
            ));
        }

        if ($file_size < 100) { // Minimum 100 bytes
            throw new Exception(esc_html__('File is too small to be a valid image.', 'nowdigiverse-product-image-upload'));
        }

        // Determine if this is a PDF (skip image-specific checks)
        $file_extension_check = strtolower(pathinfo($filename_to_check, PATHINFO_EXTENSION));
        $is_pdf = ($file_extension_check === 'pdf');

        // Use WordPress file type validation
        $allowed_wp_types = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        );
        $wp_filetype = wp_check_filetype_and_ext($file_path, $filename_to_check, $allowed_wp_types);

        if (!$wp_filetype['type'] || !$wp_filetype['ext']) {
            throw new Exception(esc_html__('Invalid file type. Please upload a valid image or PDF file.', 'nowdigiverse-product-image-upload'));
        }

        // Validate MIME type using finfo as additional security check
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_path);
            finfo_close($finfo);

            if (!in_array($mime_type, $this->allowed_mime_types)) {
                /* translators: %1$s: MIME type */
                throw new Exception(sprintf(esc_html__('Invalid MIME type: %1$s. Only image or PDF files are allowed.', 'nowdigiverse-product-image-upload'), esc_html($mime_type)));
            }
        }

        // Validate using exif_imagetype (more reliable for images — skip for PDFs)
        if (!$is_pdf && function_exists('exif_imagetype')) {
            $image_type = exif_imagetype($file_path);
            if ($image_type === false) {
                throw new Exception(esc_html__('File is not a valid image.', 'nowdigiverse-product-image-upload'));
            }

            // Map exif_imagetype constants to our allowed types
            $valid_exif_types = array(
                IMAGETYPE_JPEG,
                IMAGETYPE_PNG,
                IMAGETYPE_GIF,
                IMAGETYPE_WEBP
            );

            if (!in_array($image_type, $valid_exif_types)) {
                throw new Exception(esc_html__('Unsupported image type.', 'nowdigiverse-product-image-upload'));
            }
        }

        // Check for double extensions
        $path_info = pathinfo($filename_to_check);
        $extension = isset($path_info['extension']) ? strtolower($path_info['extension']) : '';

        // Check for dangerous double extensions (e.g. photo.php.jpg). Legitimate
        // filenames may contain multiple dots (e.g. "my.photo.jpg" or names where
        // sanitize_file_name() converted spaces to dashes), so only reject when an
        // intermediate segment is a known executable/script extension.
        $dangerous_extensions = array(
            'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht',
            'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl',
            'asp', 'aspx', 'jsp', 'js', 'htaccess', 'html', 'htm', 'svg',
        );
        $name_segments = explode('.', $filename_to_check);
        // Drop the actual (last) extension; inspect everything before it.
        array_pop($name_segments);
        array_shift($name_segments); // Drop the base name portion.
        foreach ($name_segments as $segment) {
            if (in_array(strtolower($segment), $dangerous_extensions, true)) {
                throw new Exception(esc_html__('Files with multiple extensions are not allowed.', 'nowdigiverse-product-image-upload'));
            }
        }

        // Validate extension matches expected
        if ($extension !== $expected_extension) {
            /* translators: %1$s: Uploaded file extension, %2$s: Expected file extension */
            throw new Exception(sprintf(esc_html__('File extension (%1$s) does not match expected type (%2$s).', 'nowdigiverse-product-image-upload'), esc_html($extension), esc_html($expected_extension)));
        }

        // Check for path traversal attempts
        if (strpos($filename_to_check, '..') !== false || strpos($filename_to_check, '/') !== false || strpos($filename_to_check, '\\') !== false) {
            throw new Exception(esc_html__('Invalid characters in filename detected.', 'nowdigiverse-product-image-upload'));
        }

        // Additional security: Check file content for executable signatures
        $this->check_for_executable_content($file_path);

        // Validate image resolution if enabled (skip for PDFs — they have no pixel dimensions)
        if ($this->resolution_validation && !$is_pdf) {
            $this->validate_image_resolution($file_path);
        }
    }

    /**
     * Validate image resolution
     */
    private function validate_image_resolution($file_path)
    {
        // Get image dimensions
        $image_info = getimagesize($file_path);

        if ($image_info === false) {
            throw new Exception(esc_html__('Could not determine image dimensions.', 'nowdigiverse-product-image-upload'));
        }

        $width = $image_info[0];
        $height = $image_info[1];

        // Validate minimum dimensions
        if ($this->min_width > 0 && $width < $this->min_width) {
            throw new Exception(sprintf(
                /* translators: %1$d: Uploaded image width, %2$d: Minimum required width */
                esc_html__('Image width (%1$dpx) is below minimum required width (%2$dpx).', 'nowdigiverse-product-image-upload'),
                esc_html($width),
                esc_html($this->min_width)
            ));
        }

        if ($this->min_height > 0 && $height < $this->min_height) {
            throw new Exception(sprintf(
                /* translators: %1$d: Uploaded image height, %2$d: Minimum required height */
                esc_html__('Image height (%1$dpx) is below minimum required height (%2$dpx).', 'nowdigiverse-product-image-upload'),
                esc_html($height),
                esc_html($this->min_height)
            ));
        }

        // Validate maximum dimensions
        if ($this->max_width > 0 && $width > $this->max_width) {
            throw new Exception(sprintf(
                /* translators: %1$d: Uploaded image width, %2$d: Maximum allowed width */
                esc_html__('Image width (%1$dpx) exceeds maximum allowed width (%2$dpx).', 'nowdigiverse-product-image-upload'),
                esc_html($width),
                esc_html($this->max_width)
            ));
        }

        if ($this->max_height > 0 && $height > $this->max_height) {
            throw new Exception(sprintf(
                /* translators: %1$d: Uploaded image height, %2$d: Maximum allowed height */
                esc_html__('Image height (%1$dpx) exceeds maximum allowed height (%2$dpx).', 'nowdigiverse-product-image-upload'),
                esc_html($height),
                esc_html($this->max_height)
            ));
        }
    }

    /**
     * Check for executable content in file
     */
    private function check_for_executable_content($file_path)
    {
        // Initialize WordPress File System API
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        // Read first 1KB to check for executable signatures
        $header = $wp_filesystem->get_contents($file_path);
        if ($header === false) {
            throw new Exception(esc_html__('Could not read file for security check.', 'nowdigiverse-product-image-upload'));
        }

        // Limit to first 1KB for performance
        $header = substr($header, 0, 1024);

        // Check for PHP tags
        if (strpos($header, '<?php') !== false || strpos($header, '<?=') !== false) {
            throw new Exception(esc_html__('File contains executable code and is not allowed.', 'nowdigiverse-product-image-upload'));
        }

        // Check for other executable signatures
        $executable_signatures = array(
            '<script',
            'javascript:',
            'vbscript:',
            'onload=',
            'onerror=',
            'eval(',
            'exec(',
            'system(',
            'shell_exec(',
            'passthru('
        );

        foreach ($executable_signatures as $signature) {
            if (stripos($header, $signature) !== false) {
                throw new Exception(esc_html__('File contains potentially malicious content.', 'nowdigiverse-product-image-upload'));
            }
        }
    }

    /**
     * Generate secure filename
     */
    private function generate_secure_filename($product_id, $index, $extension)
    {
        // Generate random hash
        $random_hash = wp_generate_password(16, false, false);

        // Sanitize extension properly
        $clean_extension = strtolower(trim($extension));

        // Validate extension is in allowed list
        if (!in_array($clean_extension, $this->allowed_extensions)) {
            $clean_extension = 'jpg'; // Default fallback
        }

        // Create secure filename: prod-{product_id}-{timestamp}-{random_hash}-{index}.{extension}
        $filename = sprintf(
            'prod-%d-%d-%s-%d.%s',
            absint($product_id),
            time(),
            $random_hash,
            absint($index),
            $clean_extension
        );

        return $filename;
    }

    /**
     * Process a raw PDF upload from $_FILES
     *
     * @param array  $file_entry  A single entry from $_FILES (tmp_name, name, size, error).
     * @param int    $index       File index (used in filename).
     * @param int    $product_id  WooCommerce product ID.
     * @param int    $user_id     WordPress user ID (0 for guests).
     * @param string $ip_address  Client IP address for logging.
     * @return array             Array with 'path', 'url', 'filename' keys.
     * @throws Exception         On validation or upload failure.
     */
    public function process_raw_pdf($file_entry, $index, $product_id, $user_id, $ip_address)
    {
        $this->log_upload_attempt($user_id, $ip_address, $product_id, 'pdf_upload', true);

        try {
            // Basic $_FILES error check
            if (!isset($file_entry['error']) || $file_entry['error'] !== UPLOAD_ERR_OK) {
                /* translators: %1$d: file index number */
                throw new Exception(sprintf(esc_html__('Upload error for file %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Validate that the file was actually uploaded via HTTP POST
            if (!is_uploaded_file($file_entry['tmp_name'])) {
                /* translators: %1$d: File index */
                throw new Exception(sprintf(esc_html__('Invalid upload for file %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Validate extension
            $original_name = sanitize_file_name($file_entry['name']);
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                /* translators: %1$d: File index */
                throw new Exception(sprintf(esc_html__('File %1$d is not a PDF.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Validate file size
            if ($file_entry['size'] > $this->max_file_size) {
                throw new Exception(sprintf(
                    /* translators: %1$s: Formatted file size, %2$s: Formatted maximum allowed size */
                    esc_html__('PDF size (%1$s) exceeds maximum allowed size (%2$s).', 'nowdigiverse-product-image-upload'),
                    esc_html(size_format($file_entry['size'])),
                    esc_html(size_format($this->max_file_size))
                ));
            }

            if ($file_entry['size'] < 100) {
                /* translators: %1$d: File index */
                throw new Exception(sprintf(esc_html__('File %1$d is too small to be a valid PDF.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Validate MIME type using finfo
            if (function_exists('finfo_file')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file_entry['tmp_name']);
                finfo_close($finfo);

                if ($mime_type !== 'application/pdf') {
                    /* translators: %1$s: MIME type */
                    throw new Exception(sprintf(esc_html__('Invalid MIME type for PDF: %1$s.', 'nowdigiverse-product-image-upload'), esc_html($mime_type)));
                }
            }

            // Check for executable content in first 1KB
            $this->check_for_executable_content($file_entry['tmp_name']);

            $secure_filename = $this->generate_secure_filename($product_id, $index, 'pdf');
            $file_path = $this->upload_dir . '/' . $secure_filename;

            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
                WP_Filesystem();
            }
            if (!$wp_filesystem->move($file_entry['tmp_name'], $file_path, true)) {
                /* translators: %1$d: File index */
                throw new Exception(sprintf(esc_html__('Failed to save PDF %1$d.', 'nowdigiverse-product-image-upload'), $index + 1));
            }

            // Set file permissions (non-executable)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            chmod($file_path, 0644);

            // Log successful upload
            $this->log_upload_attempt($user_id, $ip_address, $product_id, 'pdf_success', true, $secure_filename);

            $upload_result = array(
                'path' => $file_path,
                'url' => $this->upload_url . '/' . $secure_filename,
                'filename' => $secure_filename,
                'original_name' => $original_name,
            );

            do_action('cpiu_file_uploaded', $upload_result, $product_id, $user_id);

            return $upload_result;

        } catch (Exception $e) {
            $this->log_upload_attempt($user_id, $ip_address, $product_id, 'pdf_failed', false, '', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Log upload attempt
     */
    private function log_upload_attempt($user_id, $ip_address, $product_id, $status, $success, $filename = '', $error_message = '')
    {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'user_id' => absint($user_id),
            'ip_address' => sanitize_text_field($ip_address),
            'product_id' => absint($product_id),
            'status' => sanitize_text_field($status),
            'success' => (bool) $success,
            'filename' => sanitize_file_name($filename),
            'error_message' => sanitize_text_field($error_message)
        );

        /**
         * Fires after every upload attempt (success or failure).
         *
         * The free edition does not persist an upload/security log. The Pro
         * add-on hooks this action to store entries and render the Security
         * Logs dashboard.
         *
         * @param array $log_entry Sanitized upload-attempt data.
         */
        do_action('cpiu_upload_attempt', $log_entry);
    }

    /**
     * Clean up files
     */
    public function cleanup_files($file_paths)
    {
        foreach ($file_paths as $file_path) {
            if (file_exists($file_path)) {
                wp_delete_file($file_path);
            }
        }
    }

    /**
     * Get upload directory info
     */
    public function get_upload_info()
    {
        return array(
            'dir' => $this->upload_dir,
            'url' => $this->upload_url,
            'max_size' => $this->max_file_size
        );
    }
}

