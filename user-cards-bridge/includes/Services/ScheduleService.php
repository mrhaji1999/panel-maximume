<?php

namespace UCB\Services;

use UCB\Database;
use WP_Error;

/**
 * Manages schedule capacity matrices.
 */
class ScheduleService {
    /**
     * @var Database
     */
    protected $database;

    public function __construct() {
        $this->database = new Database();
    }

    /**
     * Get schedule matrix for supervisor/card.
     *
     * @return array<int, array<string, int>>
     */
    public function get_matrix(int $supervisor_id, int $card_id): array {
        return $this->database->get_capacity_matrix($supervisor_id, $card_id);
    }

    /**
     * Persist schedule matrix.
     */
    public function save_matrix(int $supervisor_id, int $card_id, array $matrix): void {
        $normalized = [];

        foreach ($matrix as $row) {
            $weekday = isset($row['weekday']) ? (int) $row['weekday'] : null;
            $hour = isset($row['hour']) ? (int) $row['hour'] : null;
            $capacity = isset($row['capacity']) ? max(0, (int) $row['capacity']) : 0;

            if ($weekday === null || $hour === null) {
                continue;
            }

            $normalized[] = [
                'weekday'  => $weekday,
                'hour'     => $hour,
                'capacity' => $capacity,
            ];
        }

        $this->database->upsert_capacity_matrix($supervisor_id, $card_id, $normalized);
        $this->database->prune_capacity_matrix($supervisor_id, $card_id, $normalized);
    }

    /**
     * Compute availability for card.
     *
     * @return array<int, array<string, int>>
     */
    public function get_availability(int $card_id, int $supervisor_id, ?string $date = null): array {
        $matrix = $this->get_matrix($supervisor_id, $card_id);
        $availability = [];
        $target_weekday = null;

        if ($date) {
            $timestamp = strtotime($date . ' 00:00:00');
            if (false !== $timestamp) {
                // date('N') returns 1 (Mon) through 7 (Sun)
                $target_weekday = (int) (function_exists('wp_date') ? \wp_date('N', $timestamp) : date('N', $timestamp));
            }
        }

        foreach ($matrix as $slot) {
            $weekday = (int) $slot['weekday'];
            $hour = (int) $slot['hour'];
            $capacity = max(0, (int) $slot['capacity']);
            $used = 0;

            if (null !== $target_weekday && $weekday !== $target_weekday) {
                continue;
            }

            if ($date) {
                $used = $this->database->count_reservations_for_slot_on_date($card_id, $date, $weekday, $hour);
            }

            $remaining = max(0, $capacity - $used);

            $availability[] = [
                'weekday'   => $weekday,
                'hour'      => $hour,
                'capacity'  => $capacity,
                'used'      => $used,
                'remaining' => $remaining,
                'is_full'   => $capacity > 0 ? $used >= $capacity : true,
            ];
        }

        return $availability;
    }
}
