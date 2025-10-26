<?php
/**
 * Plugin Name: Woo Wallet Bridge
 * Description: Wallet bridge for forwarding wallet codes, redemptions and top-up flows between WooCommerce stores.
 * Version: 1.0.0
 * Author: Panel Maximum
 * Text Domain: woo-wallet-bridge
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WWB_PLUGIN_FILE', __FILE__);
define('WWB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WWB_PLUGIN_URL', plugin_dir_url(__FILE__));
spl_autoload_register(function ($class) {
    if (strpos($class, 'WWB\\') !== 0) {
        return;
    }

    $relative = substr($class, 4);
    $psr_path = WWB_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($psr_path)) {
        require_once $psr_path;
        return;
    }

    $fallback = 'class-' . strtolower(str_replace(['\\', '_'], ['-', '-'], $relative)) . '.php';
    $fallback_path = WWB_PLUGIN_DIR . 'includes/' . $fallback;
    if (file_exists($fallback_path)) {
        require_once $fallback_path;
    }
});

function wwb_activate() {
    require_once WWB_PLUGIN_DIR . 'includes/class-activator.php';
    \WWB\Activator::activate();
}

function wwb_deactivate() {
    require_once WWB_PLUGIN_DIR . 'includes/class-deactivator.php';
    \WWB\Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'wwb_activate');
register_deactivation_hook(__FILE__, 'wwb_deactivate');
register_uninstall_hook(__FILE__, ['\\WWB\\Uninstaller', 'uninstall']);

if (file_exists(WWB_PLUGIN_DIR . 'includes/class-plugin.php')) {
    require_once WWB_PLUGIN_DIR . 'includes/class-plugin.php';
    \WWB\Plugin::instance();
}
