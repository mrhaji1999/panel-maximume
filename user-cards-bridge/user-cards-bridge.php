<?php
/**
 * Plugin Name: User Cards Bridge
 * Plugin URI: https://example.com/user-cards-bridge
 * Description: A comprehensive web service bridge plugin for managing user cards, roles, scheduling, and WooCommerce integration with SMS notifications.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: user-cards-bridge
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.1
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('UCB_PLUGIN_FILE', __FILE__);
define('UCB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UCB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UCB_VERSION', '1.0.0');
define('UCB_TEXT_DOMAIN', 'user-cards-bridge');

// Prevent WordPress from attempting to read translation directories as files.
add_filter('load_textdomain_mofile', static function ($mofile, $domain) {
    if (is_string($mofile) && is_dir($mofile)) {
        return false;
    }

    return $mofile;
}, 10, 2);

// Check PHP version
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>' . 
             sprintf(__('User Cards Bridge requires PHP 8.1 or higher. You are running PHP %s.', UCB_TEXT_DOMAIN), PHP_VERSION) . 
             '</p></div>';
    });
    return;
}

// Check for required plugins
add_action('admin_init', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . 
                 __('User Cards Bridge requires WooCommerce to be installed and activated.', UCB_TEXT_DOMAIN) . 
                 '</p></div>';
        });
    }
});

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'UCB\\';
    $base_dir = UCB_PLUGIN_DIR . 'includes/';
    
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

// Load translations after WordPress core is ready.
add_action('init', static function () {
    load_plugin_textdomain(UCB_TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Initialize the plugin once all other plugins are loaded.
add_action('plugins_loaded', static function () {
    UCB\Plugin::get_instance();
}, 20);

// Activation hook
register_activation_hook(__FILE__, ['UCB\Activator', 'activate']);

// Deactivation hook
register_deactivation_hook(__FILE__, ['UCB\Deactivator', 'deactivate']);

// Uninstall hook
register_uninstall_hook(__FILE__, ['UCB\Uninstaller', 'uninstall']);
