<?php
/**
 * Plugin Name: IUR - Image URL Replacement
 * Description: جایگزینی خودکار آدرس تصاویر محصولات و پست‌ها با لینک‌های میزبانی شده در Freeimage.host یا سایر سرویس‌ها
 * Version: 2.0.1
 * Author: Baloch Mark
 * License: GPLv2
 */

defined('ABSPATH') || exit;

define('IUR_PLUGIN_FILE', __FILE__);
define('IUR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IUR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IUR_VERSION', '2.0.1');
define('IUR_LOG_FILE', WP_CONTENT_DIR . '/iur-debug.log');

// Check log file permissions and create if needed
if (!file_exists(IUR_LOG_FILE)) {
    @touch(IUR_LOG_FILE);
    @chmod(IUR_LOG_FILE, 0644);
}

if (file_exists(IUR_LOG_FILE) && !is_writable(IUR_LOG_FILE)) {
    // We cannot add admin notice here because it's too early, so we'll handle it later
}

// Enable error reporting in development
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Load dependencies
require_once IUR_PLUGIN_DIR . 'includes/class-iur-autoloader.php';

require_once IUR_PLUGIN_DIR . 'includes/vendor/autoload.php';

// تست وجود کلاس Cloudinary
if (class_exists('Cloudinary\Cloudinary')) {
    error_log('Cloudinary SDK به درستی بارگذاری شد!');
} else {
    error_log('خطا: Cloudinary SDK بارگذاری نشد!');
}

// Activation hook
register_activation_hook(__FILE__, 'iur_activate_plugin');
function iur_activate_plugin() {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $default_settings = [
        'upload_method' => 'freeimage',
        'freeimage' => [
            'api_key' => '',
        ],
        'imgbb' => [
            'api_key' => '',
        ],
        'cloudinary' => [
            'api_key' => '',
            'api_secret' => '',
            'cloud_name' => '',
            'folder' => 'iur_uploads',
            'secure' => true
        ],
        'quality' => 'high',
        'target_content' => ['post', 'product'],
        'delete_after_replace' => 0,
        'auto_replace' => 'no',
        'process_featured_image' => 1,
        'process_content_images' => 1,
        'process_galleries' => 1,
        'process_custom_fields' => 0,
        'group_limit' => 10,
        'group_timeout' => 5
    ];

    add_option('iur_settings', $default_settings);
}

// Initialize plugin
add_action('plugins_loaded', 'iur_init_plugin');
/**
 * Initialize the plugin with proper dependency loading and error handling
 */
function iur_init_plugin() {
    // Check and handle log file permissions first
    iur_check_log_file_permissions();
    
    // Initialize core components
    iur_initialize_core_components();
    
    // Initialize admin-related components
    if (is_admin()) {
        iur_initialize_admin_components();
    }
    
    // Register meta fields
    add_action('init', 'iur_register_meta_fields');
}

/**
 * Check and handle log file permissions
 */
function iur_check_log_file_permissions() {
    if (file_exists(IUR_LOG_FILE) && !is_writable(IUR_LOG_FILE)) {
        add_action('admin_notices', 'iur_admin_notice_log_permission');
        error_log('IUR Error: Log file is not writable at ' . IUR_LOG_FILE);
    }
}

/**
 * Initialize core plugin components
 */
function iur_initialize_core_components() {
    try {
        // Initialize autoloader and load dependencies
        $autoloader = new IUR_Autoloader();
        $autoloader->init();
        
        // Initialize error handler (should be initialized early)
        IUR_Error_Handler::init();
        
        // Initialize AJAX handlers
        IUR_Ajax_Handler::init();
        
        // Initialize settings (loads and validates settings)
        $settings = IUR_Settings::get_instance();
        $settings->init();
        
        // Initialize processor (main functionality)
        IUR_Processor::init();
        
    } catch (Exception $e) {
        error_log('IUR Core Initialization Error: ' . $e->getMessage());
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die('IUR Plugin Initialization Failed: ' . $e->getMessage());
        }
    }
}

/**
 * Initialize admin-specific components
 */
function iur_initialize_admin_components() {
    try {
        // Initialize admin interface
        IUR_Admin::init();
        
        // Initialize bulk processor
        require_once IUR_PLUGIN_DIR . 'includes/class-iur-bulk-processor.php';
        IUR_Bulk_Processor::init();
        
        // Add AJAX handlers
        add_action('wp_ajax_iur_process_single_post', 'iur_ajax_process_single_post');
        add_action('admin_post_iur_clear_errors', 'iur_clear_errors');
        
    } catch (Exception $e) {
        error_log('IUR Admin Initialization Error: ' . $e->getMessage());
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p>IUR Admin Initialization Error: ' . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

// Admin notice for log permission issue
function iur_admin_notice_log_permission() {
    ?>
    <div class="error">
        <p><?php _e('فایل iur-debug.log قابل نوشتن نیست. لطفاً دسترسی‌ها را بررسی کنید.', 'iur'); ?></p>
    </div>
    <?php
}

// Register custom meta fields
function iur_register_meta_fields() {
    register_meta('post', '_iur_upload_status', [
        'type' => 'object',
        'single' => true,
        'show_in_rest' => [
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'service' => ['type' => 'string'],
                    'images' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'original_url' => ['type' => 'string'],
                                'uploaded_url' => ['type' => 'string'],
                                'success' => ['type' => 'boolean'],
                                'reason' => ['type' => 'string'],
                                'error' => ['type' => 'string']
                            ]
                        ]
                    ]
                ]
            ]
        ],
        'auth_callback' => function() { 
            return current_user_can('edit_posts'); 
        }
    ]);
    
    register_meta('post', '_iur_last_processed', [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => false
    ]);
}

// AJAX handler for single post processing
add_action('wp_ajax_iur_process_single_post', 'iur_ajax_process_single_post');
function iur_ajax_process_single_post() {
    check_ajax_referer('iur_process_nonce', 'security');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access', 'iur')], 403);
    }

    $post_id = intval($_POST['post_id']);
    
    try {
        $processor = IUR_Processor::get_instance();
        $result = $processor->process_post($post_id);
        
        wp_send_json_success([
            'replaced' => $result['replaced'],
            'warnings' => $result['warnings'],
            'errors'  => $result['errors']
        ]);
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ], 500);
    }
}

// Clear errors handler
add_action('admin_post_iur_clear_errors', 'iur_clear_errors');
function iur_clear_errors() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized access', 'iur'), 403);
    }
    
    check_admin_referer('iur_clear_errors_nonce');
    
    $error_handler = IUR_Error_Handler::get_instance();
    $error_handler->clear_logs();
    
    wp_safe_redirect(admin_url('admin.php?page=iur-settings'));
    exit;
}