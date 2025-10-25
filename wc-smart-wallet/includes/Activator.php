<?php

namespace WCW;

class Activator {
    public static function activate(): void {
        (new Database())->maybe_upgrade(true);
        if (!wp_next_scheduled('wcw_cleanup_expired_codes')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'twicedaily', 'wcw_cleanup_expired_codes');
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled('wcw_cleanup_expired_codes');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wcw_cleanup_expired_codes');
        }
        flush_rewrite_rules();
    }
}
