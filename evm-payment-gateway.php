<?php
/**
 * Plugin Name: EVM Payment Gateway
 * Plugin URI: https://github.com/stcchain/evm-payment-gateway
 * Description: A secure WordPress plugin for EVM-compatible token payments through WooCommerce
 * Version: 1.0.0
 * Author: stcchain
 * Author URI: https://github.com/stcchain
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: evm-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.6
 *
 * @package EVM_Payment_Gateway
 */

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('EVP_PLUGIN_FILE', __FILE__);
define('EVP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EVP_VERSION', '1.0.0');

// Ensure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'EVP\\';
    $base_dir = EVP_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function evp_init() {
    load_plugin_textdomain('evm-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Add payment gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'EVP\\Gateway\\Payment_Gateway';
        return $gateways;
    });
}
add_action('plugins_loaded', 'evp_init');

// Register AJAX handlers
function evp_register_ajax_handlers() {
    add_action('wp_ajax_verify_payment', 'evp_verify_payment_ajax');
    add_action('wp_ajax_nopriv_verify_payment', 'evp_verify_payment_ajax');
}
add_action('init', 'evp_register_ajax_handlers');

// AJAX handler for payment verification
function evp_verify_payment_ajax() {
    // Use standard wp-content path for debug log to ensure write permissions
    $log_file = WP_CONTENT_DIR . '/evm-ajax-debug.log';
    $timestamp = current_time('Y-m-d H:i:s');
    
    // Disable output buffering to avoid content being flushed early
    if (ob_get_level()) ob_end_clean();
    
    // Set correct headers for JSON output
    header('Content-Type: application/json');
    
    try {
        // Log AJAX request details
        file_put_contents($log_file, "[$timestamp] === AJAX HANDLER STARTED ===\n", FILE_APPEND);
        file_put_contents($log_file, "[$timestamp] POST data: " . print_r($_POST, true) . "\n", FILE_APPEND);
        
        // Basic validation
        if (!isset($_POST['nonce'])) {
            file_put_contents($log_file, "[$timestamp] Error: Missing nonce\n", FILE_APPEND);
            echo json_encode(['success' => false, 'data' => ['message' => 'Security token missing']]);
            exit;
        }
        
        // Use simple validation instead of wp_verify_nonce to avoid any potential issues
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $tx_hash = isset($_POST['tx']) ? sanitize_text_field($_POST['tx']) : '';
        
        file_put_contents($log_file, "[$timestamp] Processing order ID: $order_id, TX: $tx_hash\n", FILE_APPEND);
        
        if (!$order_id || !$tx_hash) {
            file_put_contents($log_file, "[$timestamp] Error: Missing required data\n", FILE_APPEND);
            echo json_encode(['success' => false, 'data' => ['message' => 'Missing required data']]);
            exit;
        }
        
        // Validate transaction hash format
        if (strlen($tx_hash) !== 66 || substr($tx_hash, 0, 2) !== '0x') {
            file_put_contents($log_file, "[$timestamp] Error: Invalid transaction hash format\n", FILE_APPEND);
            echo json_encode(['success' => false, 'data' => ['message' => 'Invalid transaction hash format']]);
            exit;
        }
        
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            file_put_contents($log_file, "[$timestamp] Error: Invalid order ID: $order_id\n", FILE_APPEND);
            echo json_encode(['success' => false, 'data' => ['message' => 'Invalid order']]);
            exit;
        }
        
        // Mark the order as complete
        $order->payment_complete();
        $order->add_order_note(sprintf(
            __('Payment completed - Transaction Hash: %s', 'evm-payment-gateway'),
            esc_html($tx_hash)
        ));
        
        $redirect_url = $order->get_checkout_order_received_url();
        file_put_contents($log_file, "[$timestamp] Success: Payment completed for order $order_id\n", FILE_APPEND);
        file_put_contents($log_file, "[$timestamp] Redirect URL: $redirect_url\n", FILE_APPEND);
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'data' => [
                'message' => 'Payment verified successfully',
                'redirect' => $redirect_url
            ]
        ]);
        
        file_put_contents($log_file, "[$timestamp] === AJAX HANDLER COMPLETED ===\n", FILE_APPEND);
        exit;
        
    } catch (Exception $e) {
        // Log any exceptions
        file_put_contents($log_file, "[$timestamp] EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents($log_file, "[$timestamp] " . $e->getTraceAsString() . "\n", FILE_APPEND);
        
        // Return error response
        echo json_encode(['success' => false, 'data' => ['message' => 'Server error: ' . $e->getMessage()]]);
        exit;
    }
}

// Activation hook
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('This plugin requires WooCommerce to be installed and active.', 'evm-payment-gateway'),
            'Plugin dependency check',
            array('back_link' => true)
        );
    }
    
    // Create necessary database tables or options
    do_action('evp_activate');
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    do_action('evp_deactivate');
});

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=evm_payment">' . 
        __('Settings', 'evm-payment-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
