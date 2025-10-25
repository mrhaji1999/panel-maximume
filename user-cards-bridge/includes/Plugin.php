<?php

namespace UCB;

use UCB\Migrations\ReservationDateMigration;

class Plugin {
    
    /**
     * Holds the bootstrapped instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Cached WooCommerce integration instance.
     *
     * @var WooCommerce\Integration|null
     */
    private $woocommerce_integration = null;
    
    /**
     * Retrieve the singleton instance.
     */
    public static function get_instance() {
        if (self::$instance instanceof self) {
            return self::$instance;
        }
        
        return new self();
    }
    
    /**
     * Public constructor so WordPress can instantiate the plugin without fatal errors.
     * Ensures boot logic only runs once even if constructed directly.
     */
    public function __construct() {
        if (self::$instance instanceof self) {
            return;
        }
        
        self::$instance = $this;
        
        $this->init_hooks();
        $this->init_components();
    }
    
    private function init_hooks() {
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'init_rest_api']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_init', [ReservationDateMigration::class, 'migrate']);
    }
    
    private function init_components() {
        // Initialize roles first
        $this->init_user_roles();
        
        new Database();
        new Security();
        new Logger();
        new AjaxHandlers();
        new SMS\PayamakPanel();
        new Services\FormSyncService();
        
        // Initialize JWT authentication
        \UCB\JWT\JWTAuth::init();

        // Delay WooCommerce hooks until core initialization completes.
        add_action('init', function () {
            $this->get_woocommerce_integration();
        }, 20);
    }
    
    public function init() {
        // Initialize custom post types if needed
        $this->init_custom_post_types();
    }
    
    public function init_rest_api() {
        new API\Authentication();
        new API\Health();
        new API\Users();
        new API\Customers();
        new API\Cards();
        new API\Forms();
        new API\Schedule();
        new API\Reservations();
        new API\Upsell();
        new API\SMS();
        new API\Stats();
        new API\Webhooks();
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('User Cards Bridge', UCB_TEXT_DOMAIN),
            __('User Cards Bridge', UCB_TEXT_DOMAIN),
            'manage_options',
            'user-cards-bridge',
            [$this, 'admin_page'],
            'dashicons-admin-site-alt3',
            30
        );
        
        add_submenu_page(
            'user-cards-bridge',
            __('Settings', UCB_TEXT_DOMAIN),
            __('Settings', UCB_TEXT_DOMAIN),
            'manage_options',
            'user-cards-bridge-settings',
            [$this, 'settings_page']
        );
        
        add_submenu_page(
            'user-cards-bridge',
            __('Logs', UCB_TEXT_DOMAIN),
            __('Logs', UCB_TEXT_DOMAIN),
            'manage_options',
            'user-cards-bridge-logs',
            [$this, 'logs_page']
        );
    }
    
    public function admin_page() {
        include UCB_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }
    
    public function settings_page() {
        include UCB_PLUGIN_DIR . 'templates/admin-settings.php';
    }
    
    public function logs_page() {
        include UCB_PLUGIN_DIR . 'templates/admin-logs.php';
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'user-cards-bridge') === false) {
            return;
        }
        
        wp_enqueue_script('ucb-admin', UCB_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], UCB_VERSION, true);
        wp_enqueue_style('ucb-admin', UCB_PLUGIN_URL . 'assets/css/admin.css', [], UCB_VERSION);
        
        wp_localize_script('ucb-admin', 'ucbAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ucb_admin_nonce'),
            'apiUrl' => rest_url('user-cards-bridge/v1/'),
            'strings' => [
                'remove' => __('Remove', UCB_TEXT_DOMAIN),
                'confirmDelete' => __('Are you sure you want to delete this item?', UCB_TEXT_DOMAIN),
                'deleteError' => __('Failed to delete item: ', UCB_TEXT_DOMAIN),
                'confirmTestSMS' => __('Do you want to send a test SMS?', UCB_TEXT_DOMAIN),
                'testSMSSuccess' => __('Test SMS sent successfully.', UCB_TEXT_DOMAIN),
                'testSMSError' => __('Failed to send test SMS: ', UCB_TEXT_DOMAIN),
                'testing' => __('Testing...', UCB_TEXT_DOMAIN),
                'testSMS' => __('Test SMS Configuration', UCB_TEXT_DOMAIN),
                'confirmCleanup' => __('This will delete old log entries. Continue?', UCB_TEXT_DOMAIN),
                'cleaning' => __('Cleaning up...', UCB_TEXT_DOMAIN),
                'cleanupSuccess' => __('Logs cleaned up successfully!', UCB_TEXT_DOMAIN),
                'cleanupError' => __('Log cleanup failed: ', UCB_TEXT_DOMAIN),
                'cleanupLogs' => __('Cleanup Old Logs', UCB_TEXT_DOMAIN),
                'settingsSaved' => __('Settings saved successfully.', UCB_TEXT_DOMAIN),
                'error' => __('An error occurred. Please try again.', UCB_TEXT_DOMAIN),
                'success' => __('Operation completed successfully.', UCB_TEXT_DOMAIN),
            ]
        ]);
    }
    
    public function enqueue_frontend_scripts() {
        // Only enqueue if needed for frontend functionality
    }
    
    private function init_user_roles() {
        $roles = new Roles();
        $roles->init();
    }
    
    private function init_custom_post_types() {
        // Initialize any custom post types if needed
    }

    /**
     * Retrieve the WooCommerce integration, bootstrapping it on demand.
     */
    public function get_woocommerce_integration(): WooCommerce\Integration {
        if (!$this->woocommerce_integration instanceof WooCommerce\Integration) {
            $this->woocommerce_integration = new WooCommerce\Integration();
        }

        return $this->woocommerce_integration;
    }
}
