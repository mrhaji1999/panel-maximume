<?php

namespace WWB;

use wpdb;

class Database {
    public const VERSION = '1.0.0';

    public static function create_tables(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $codes_table = $wpdb->prefix . 'wwb_wallet_codes';
        $transactions_table = $wpdb->prefix . 'wwb_wallet_transactions';

        $sql = [];

        $sql[] = "CREATE TABLE {$codes_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            type VARCHAR(20) NOT NULL DEFAULT 'wallet',
            status VARCHAR(20) NOT NULL DEFAULT 'unused',
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            expires_at DATETIME NULL,
            meta LONGTEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$transactions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            note TEXT NULL,
            order_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            meta LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option('wwb_db_version', self::VERSION);
    }

    public static function drop_tables(): void {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wwb_wallet_codes');
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wwb_wallet_transactions');
        delete_option('wwb_db_version');
    }
}
