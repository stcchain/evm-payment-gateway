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
