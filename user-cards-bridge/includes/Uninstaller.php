<?php

namespace UCB;

class Uninstaller {
    
    public static function uninstall() {
        // Only run if user has proper permissions
        if (!current_user_can('delete_plugins')) {
            return;
        }
        
        // Remove database tables
        self::drop_tables();
        
        // Remove user roles
        self::remove_user_roles();
        
        // Remove options
        self::remove_options();
        
        // Remove user meta
        self::cleanup_user_meta();
    }
    
    private static function drop_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'ucb_capacity_slots',
            $wpdb->prefix . 'ucb_reservations',
            $wpdb->prefix . 'ucb_status_logs',
            $wpdb->prefix . 'ucb_sms_logs',
            $wpdb->prefix . 'ucb_payment_tokens',
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
    
    private static function remove_user_roles() {
        remove_role('company_manager');
        remove_role('supervisor');
        remove_role('agent');
    }
    
    private static function remove_options() {
        $options = [
            'ucb_sms_username',
            'ucb_sms_password',
            'ucb_sms_gateway',
            'ucb_sms_sender_number',
            'ucb_sms_normal_body_id',
            'ucb_sms_upsell_body_id',
            'ucb_jwt_secret_key',
            'ucb_cors_allowed_origins',
            'ucb_payment_token_expiry',
            'ucb_log_retention_days',
            'ucb_customer_statuses',
            'ucb_webhook_secret',
        ];
        
        foreach ($options as $option) {
            delete_option($option);
        }
    }
    
    private static function cleanup_user_meta() {
        global $wpdb;
        
        $meta_keys = [
            'ucb_supervisor_cards',
            'ucb_agent_supervisor_id',
            'ucb_customer_status',
            'ucb_customer_assigned_supervisor',
            'ucb_customer_assigned_agent',
            'ucb_customer_card_id',
            'ucb_customer_random_code',
        ];
        
        foreach ($meta_keys as $meta_key) {
            $wpdb->delete(
                $wpdb->usermeta,
                ['meta_key' => $meta_key]
            );
        }
    }
}
