<?php

namespace UCB;

class AjaxHandlers {
    
    public function __construct() {
        add_action('wp_ajax_ucb_test_sms', [$this, 'test_sms']);
        add_action('wp_ajax_ucb_cleanup_logs', [$this, 'cleanup_logs']);
        add_action('wp_ajax_ucb_export_logs', [$this, 'export_logs']);
        add_action('wp_ajax_ucb_delete_log', [$this, 'delete_log']);
        add_action('wp_ajax_ucb_save_settings', [$this, 'save_settings']);
        add_action('wp_ajax_ucb_get_statistics', [$this, 'get_statistics']);
        add_action('wp_ajax_ucb_get_recent_activity', [$this, 'get_recent_activity']);
    }
    
    /**
     * Test SMS configuration
     */
    public function test_sms() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $sms = new SMS\PayamakPanel();
        $result = $sms->test_configuration();
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => __('SMS configuration test successful.', UCB_TEXT_DOMAIN),
                'result' => $result,
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }
    
    /**
     * Cleanup old logs
     */
    public function cleanup_logs() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $logger = Logger::get_instance();
        $removed = $logger->cleanup_old_logs();
        
        wp_send_json_success([
            'message' => __('Logs cleaned up successfully.', UCB_TEXT_DOMAIN),
            'removed' => $removed,
        ]);
    }
    
    /**
     * Export logs
     */
    public function export_logs() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $level = sanitize_text_field($_GET['level'] ?? '');
        $user_id = (int) ($_GET['user_id'] ?? 0);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per_page = 1000; // Export more records
        
        $logs = Logger::get_logs($level, $user_id, $page, $per_page);
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ucb-logs-' . date('Y-m-d-H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Write CSV header
        fputcsv($output, [
            'ID',
            'Level',
            'Message',
            'Context',
            'User ID',
            'IP',
            'Created At',
        ]);
        
        // Write log data
        foreach ($logs['logs'] as $log) {
            fputcsv($output, [
                $log->id,
                $log->level,
                $log->message,
                $log->context,
                $log->user_id,
                $log->ip,
                $log->created_at,
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Delete log entry
     */
    public function delete_log() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $log_id = (int) $_POST['log_id'];
        
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_logs';
        
        $result = $wpdb->delete(
            $table,
            ['id' => $log_id],
            ['%d']
        );
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Log entry deleted successfully.', UCB_TEXT_DOMAIN),
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to delete log entry.', UCB_TEXT_DOMAIN),
            ]);
        }
    }
    
    /**
     * Save settings
     */
    public function save_settings() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $settings = [
            'ucb_sms_gateway' => sanitize_key($_POST['ucb_sms_gateway'] ?? 'payamak_panel'),
            'ucb_sms_username' => sanitize_text_field($_POST['ucb_sms_username'] ?? ''),
            'ucb_sms_password' => sanitize_text_field($_POST['ucb_sms_password'] ?? ''),
            'ucb_sms_sender_number' => sanitize_text_field($_POST['ucb_sms_sender_number'] ?? ''),
            'ucb_sms_normal_body_id' => sanitize_text_field($_POST['ucb_sms_normal_body_id'] ?? ''),
            'ucb_sms_upsell_body_id' => sanitize_text_field($_POST['ucb_sms_upsell_body_id'] ?? ''),
            'ucb_payment_token_expiry' => (int) ($_POST['ucb_payment_token_expiry'] ?? 24),
            'ucb_log_retention_days' => (int) ($_POST['ucb_log_retention_days'] ?? 30),
        ];
        
        foreach ($settings as $option => $value) {
            update_option($option, $value);
        }
        
        // Handle CORS origins
        $cors_origins_input = $_POST['ucb_cors_origins'] ?? [];
        $cors_origins = array_values(array_unique(array_filter(array_map(function($origin) {
            return Security::sanitize_origin(sanitize_text_field($origin));
        }, (array) $cors_origins_input))));
        update_option('ucb_cors_allowed_origins', $cors_origins);
        
        // Handle webhook secret
        if (!empty($_POST['ucb_webhook_secret'])) {
            update_option('ucb_webhook_secret', sanitize_text_field($_POST['ucb_webhook_secret']));
        }
        
        wp_send_json_success([
            'message' => __('Settings saved successfully.', UCB_TEXT_DOMAIN),
        ]);
    }
    
    /**
     * Get statistics
     */
    public function get_statistics() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $days = (int) ($_GET['days'] ?? 7);
        
        $sms_stats = SMS\PayamakPanel::get_statistics($days);
        $upsell_stats = WooCommerce\Integration::get_upsell_statistics($days);
        $log_stats = Logger::get_log_statistics($days);
        
        wp_send_json_success([
            'sms' => $sms_stats,
            'upsell' => $upsell_stats,
            'logs' => $log_stats,
        ]);
    }
    
    /**
     * Get recent activity
     */
    public function get_recent_activity() {
        check_ajax_referer('ucb_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', UCB_TEXT_DOMAIN));
        }
        
        $logs = Logger::get_logs('', '', 1, 10);
        
        wp_send_json_success([
            'logs' => $logs['logs'],
        ]);
    }
}
