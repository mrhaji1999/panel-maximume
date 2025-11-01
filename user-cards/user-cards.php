<?php
/*
Plugin Name: User Cards (Multi-step Verification)
Description: Custom registration/login + user dashboard with dynamic card grid, code verification, Jalali date/time booking, and submissions tracking.
Version: 0.1.0
Author: Your Team
*/

if (!defined('ABSPATH')) { exit; }

define('UC_VERSION', '0.1.0');
define('UC_PLUGIN_FILE', __FILE__);
define('UC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Includes
require_once UC_PLUGIN_DIR . 'includes/class-uc-post-types.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-db.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-metaboxes.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-assets.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-shortcodes.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-ajax.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-admin.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-settings.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-sms.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-redirects.php';

class UC_Bootstrap {
    public function __construct() {
        register_activation_hook(UC_PLUGIN_FILE, ['UC_DB', 'activate']);
        add_action('plugins_loaded', ['UC_DB', 'maybe_install']);
        add_action('init', ['UC_Post_Types', 'register']);
        add_action('add_meta_boxes', ['UC_Metaboxes', 'register']);
        add_action('save_post', ['UC_Metaboxes', 'save'], 10, 2);
        add_action('admin_enqueue_scripts', ['UC_Assets', 'admin']);
        add_action('wp_enqueue_scripts', ['UC_Assets', 'frontend']);
        add_action('admin_init', ['UC_Admin', 'init']);
        UC_Settings::init();
        UC_Redirects::init();

        // Shortcodes
        add_action('init', function(){
            add_shortcode('uc_auth', ['UC_Shortcodes', 'auth']);
            add_shortcode('uc_dashboard', ['UC_Shortcodes', 'dashboard']);
        });

        // AJAX
        add_action('wp_ajax_uc_login', ['UC_Ajax', 'login']);
        add_action('wp_ajax_nopriv_uc_login', ['UC_Ajax', 'login']);
        add_action('wp_ajax_uc_register', ['UC_Ajax', 'register']);
        add_action('wp_ajax_nopriv_uc_register', ['UC_Ajax', 'register']);
        add_action('wp_ajax_uc_validate_code', ['UC_Ajax', 'validate_code']);
        add_action('wp_ajax_nopriv_uc_validate_code', ['UC_Ajax', 'validate_code']);
        add_action('wp_ajax_uc_submit_form', ['UC_Ajax', 'submit_form']);
        add_action('wp_ajax_nopriv_uc_submit_form', ['UC_Ajax', 'submit_form']);

        // Admin AJAX (codes import, post lists)
        add_action('wp_ajax_uc_admin_fetch_posts', ['UC_Metaboxes', 'ajax_fetch_posts']);
        add_action('wp_ajax_uc_import_codes_init', ['UC_Metaboxes', 'ajax_import_init']);
        add_action('wp_ajax_uc_import_codes_batch', ['UC_Metaboxes', 'ajax_import_batch']);
    }
}

new UC_Bootstrap();
