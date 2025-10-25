<?php
/**
 * Plugin Name: WC Smart Wallet
 * Description: Wallet management for WooCommerce with code redemption and centralized bridge integration.
 * Version: 1.0.0
 * Author: Your Team
 * Text Domain: wc-smart-wallet
 * Requires PHP: 8.0
 * Requires at least: 5.9
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCW_VERSION', '1.0.0');
define('WCW_PLUGIN_FILE', __FILE__);
define('WCW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCW_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'WCW\\';
    $base_dir = WCW_PLUGIN_DIR . 'includes/';

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

register_activation_hook(__FILE__, ['WCW\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WCW\\Activator', 'deactivate']);

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('WC Smart Wallet requires WooCommerce to be active.', 'wc-smart-wallet') . '</p></div>';
        });
        return;
    }

    WCW\Plugin::instance();
});
