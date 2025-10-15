<?php

namespace UCB\Migrations;

use wpdb;

/**
 * Adds the reservation_date column and related indexes to the reservations table.
 */
class ReservationDateMigration {

    /**
     * Ensure the reservations table has the reservation_date column and indexes.
     */
    public static function migrate(): void {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            return;
        }

        $table = $wpdb->prefix . 'ucb_reservations';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        self::ensure_reservation_date_column($wpdb, $table);
        self::ensure_indexes($wpdb, $table);
    }

    /**
     * Add reservation_date column and backfill values if required.
     */
    protected static function ensure_reservation_date_column(wpdb $wpdb, string $table): void {
        $column_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'reservation_date')
        );

        if (!$column_exists) {
            $alter_sql = sprintf('ALTER TABLE %s ADD COLUMN reservation_date DATE NULL AFTER slot_hour', esc_sql($table));
            $wpdb->query($alter_sql);

            $created_at_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'created_at')
            );

            if ($created_at_exists) {
                $wpdb->query("UPDATE {$table} SET reservation_date = DATE(created_at) WHERE reservation_date IS NULL OR reservation_date = ''");
            } else {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table} SET reservation_date = %s WHERE reservation_date IS NULL OR reservation_date = ''",
                        wp_date('Y-m-d')
                    )
                );
            }

            $wpdb->query(sprintf('ALTER TABLE %s MODIFY reservation_date DATE NOT NULL', esc_sql($table)));
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET reservation_date = %s WHERE reservation_date IS NULL OR reservation_date = ''",
                    wp_date('Y-m-d')
                )
            );
        }
    }

    /**
     * Ensure reservation_date and date_slot indexes exist.
     */
    protected static function ensure_indexes(wpdb $wpdb, string $table): void {
        $reservation_date_index = $wpdb->get_var(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'reservation_date'"
        );

        if (!$reservation_date_index) {
            $wpdb->query(sprintf('ALTER TABLE %s ADD KEY reservation_date (reservation_date)', esc_sql($table)));
        }

        $date_slot_index = $wpdb->get_var(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'date_slot'"
        );

        if (!$date_slot_index) {
            $wpdb->query(
                sprintf(
                    'ALTER TABLE %s ADD KEY date_slot (reservation_date, slot_weekday, slot_hour)',
                    esc_sql($table)
                )
            );
        }
    }
}

