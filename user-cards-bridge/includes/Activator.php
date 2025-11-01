<?php

namespace UCB;

use UCB\Migrations\ReservationDateMigration;

class Activator {
    
    public static function activate() {
        // Create database tables
        self::create_tables();
        
        // Create user roles
        self::create_user_roles();
        
        // Set default options
        self::set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Capacity slots table
        $table_capacity = $wpdb->prefix . 'ucb_capacity_slots';
        $sql_capacity = "CREATE TABLE $table_capacity (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            card_id bigint(20) NOT NULL,
            supervisor_id bigint(20) NOT NULL,
            weekday tinyint(1) NOT NULL DEFAULT 0,
            hour tinyint(2) NOT NULL DEFAULT 8,
            capacity int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slot (card_id, supervisor_id, weekday, hour),
            KEY card_id (card_id),
            KEY supervisor_id (supervisor_id)
        ) $charset_collate;";
        
        // Reservations table
        $table_reservations = $wpdb->prefix . 'ucb_reservations';
        $sql_reservations = "CREATE TABLE $table_reservations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) NOT NULL,
            card_id bigint(20) NOT NULL,
            supervisor_id bigint(20) NOT NULL,
            slot_weekday tinyint(1) NOT NULL,
            slot_hour tinyint(2) NOT NULL,
            reservation_date date NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY card_id (card_id),
            KEY supervisor_id (supervisor_id),
            KEY slot (slot_weekday, slot_hour),
            KEY reservation_date (reservation_date),
            KEY date_slot (reservation_date, slot_weekday, slot_hour)
        ) $charset_collate;";
        
        // Status logs table
        $table_status_logs = $wpdb->prefix . 'ucb_status_logs';
        $sql_status_logs = "CREATE TABLE $table_status_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) NOT NULL,
            old_status varchar(50) DEFAULT NULL,
            new_status varchar(50) NOT NULL,
            changed_by bigint(20) NOT NULL,
            reason text,
            meta longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY changed_by (changed_by),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // SMS logs table
        $table_sms_logs = $wpdb->prefix . 'ucb_sms_logs';
        $sql_sms_logs = "CREATE TABLE $table_sms_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) DEFAULT NULL,
            phone varchar(20) NOT NULL,
            message text NOT NULL,
            body_id varchar(50) DEFAULT NULL,
            result_code varchar(20) DEFAULT NULL,
            result_message text,
            rec_id varchar(100) DEFAULT NULL,
            sent_by bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY phone (phone),
            KEY sent_by (sent_by),
            KEY created_at (created_at)
        ) $charset_collate;";

        // General logs table
        $table_logs = $wpdb->prefix . 'ucb_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext,
            user_id bigint(20) DEFAULT NULL,
            ip varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Payment tokens table
        $table_payment_tokens = $wpdb->prefix . 'ucb_payment_tokens';
        $sql_payment_tokens = "CREATE TABLE $table_payment_tokens (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            token varchar(64) NOT NULL,
            expires_at datetime NOT NULL,
            payload longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            consumed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_capacity);
        dbDelta($sql_reservations);
        dbDelta($sql_status_logs);
        dbDelta($sql_sms_logs);
        dbDelta($sql_logs);
        dbDelta($sql_payment_tokens);

        // Ensure schema upgrades also run during activation.
        ReservationDateMigration::migrate();
    }
    
    private static function create_user_roles() {
        $roles = new Roles();
        $roles->init();
    }
    
    private static function set_default_options() {
        $default_options = [
            'ucb_sms_gateway' => 'payamak_panel',
            'ucb_sms_username' => '',
            'ucb_sms_password' => '',
            'ucb_sms_sender_number' => '',
            'ucb_sms_normal_body_id' => '',
            'ucb_sms_upsell_body_id' => '',
            'ucb_jwt_secret_key' => wp_generate_password(64, true, true),
            'ucb_cors_allowed_origins' => [],
            'ucb_payment_token_expiry' => 24, // hours
            'ucb_log_retention_days' => 30,
            'ucb_customer_statuses' => (new Services\StatusManager())->get_default_statuses(),
            'ucb_webhook_secret' => '',
        ];
        
        foreach ($default_options as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }
}
