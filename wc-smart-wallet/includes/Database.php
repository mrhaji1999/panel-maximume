<?php

namespace WCW;

class Database {
    public function maybe_upgrade(bool $force = false): void {
        $installed = get_option('wcw_db_version');
        if ($force || version_compare((string) $installed, WCW_VERSION, '<')) {
            $this->create_tables();
            update_option('wcw_db_version', WCW_VERSION);
        }
    }

    public function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $accounts = $wpdb->prefix . 'wc_wallet_accounts';
        $codes = $wpdb->prefix . 'wc_wallet_codes';
        $transactions = $wpdb->prefix . 'wc_wallet_transactions';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_accounts = "CREATE TABLE {$accounts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL UNIQUE,
            balance decimal(20,6) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'IRR',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) {$charset};";

        $sql_codes = "CREATE TABLE {$codes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(191) NOT NULL,
            amount decimal(20,6) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            redeemed_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            user_email varchar(191) DEFAULT NULL,
            user_id bigint(20) DEFAULT NULL,
            meta longtext NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY user_email (user_email)
        ) {$charset};";

        $sql_transactions = "CREATE TABLE {$transactions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            amount decimal(20,6) NOT NULL,
            currency varchar(10) NOT NULL,
            balance_after decimal(20,6) NOT NULL,
            source varchar(20) NOT NULL,
            source_ref varchar(191) DEFAULT NULL,
            note text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY source (source)
        ) {$charset};";

        dbDelta($sql_accounts);
        dbDelta($sql_codes);
        dbDelta($sql_transactions);
    }

    public function table_accounts(): string {
        global $wpdb;
        return $wpdb->prefix . 'wc_wallet_accounts';
    }

    public function table_codes(): string {
        global $wpdb;
        return $wpdb->prefix . 'wc_wallet_codes';
    }

    public function table_transactions(): string {
        global $wpdb;
        return $wpdb->prefix . 'wc_wallet_transactions';
    }
}
