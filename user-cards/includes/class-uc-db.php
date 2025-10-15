<?php
if (!defined('ABSPATH')) { exit; }

class UC_DB {
    public static function table_name(){
        global $wpdb; return $wpdb->prefix . 'uc_codes';
    }

    public static function table_exists(){
        global $wpdb; $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function activate() {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            card_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY card_code (card_id, code),
            KEY code_idx (code)
        ) $charset_collate;";
        dbDelta($sql);
    }

    public static function maybe_install(){
        if (!self::table_exists()) self::activate();
    }

    public static function insert_codes($card_id, $codes) {
        global $wpdb; $table = self::table_name();
        $card_id = (int) $card_id;
        if ($card_id <= 0 || empty($codes)) return 0;
        $values = [];
        $placeholders = [];
        foreach ($codes as $c) {
            $c = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$c);
            if ($c === '') continue;
            $values[] = $card_id;
            $values[] = $c;
            $placeholders[] = '(%d,%s)';
        }
        if (empty($placeholders)) return 0;
        $sql = "INSERT IGNORE INTO $table (card_id, code) VALUES " . implode(',', $placeholders);
        $prepared = $wpdb->prepare($sql, $values);
        $res = $wpdb->query($prepared);
        return is_wp_error($res) ? 0 : (int) $res;
    }

    public static function code_exists($card_id, $code) {
        global $wpdb; $table = self::table_name();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE card_id=%d AND code=%s LIMIT 1", (int)$card_id, (string)$code));
    }

    public static function count_codes($card_id) {
        global $wpdb; $table = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE card_id=%d", (int)$card_id));
    }
}
