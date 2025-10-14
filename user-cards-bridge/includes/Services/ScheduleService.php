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
    public function get_availability(int $card_id, int $supervisor_id): array {
        $matrix = $this->get_matrix($supervisor_id, $card_id);
        $availability = [];

        foreach ($matrix as $slot) {
            $weekday = (int) $slot['weekday'];
            $hour = (int) $slot['hour'];
            $capacity = (int) $slot['capacity'];

            $reservations = $this->database->count_reservations_for_slot($card_id, $weekday, $hour);

            $availability[] = [
                'weekday'    => $weekday,
                'hour'       => $hour,
                'capacity'   => $capacity,
                'reserved'   => $reservations,
                'available'  => max(0, $capacity - $reservations),
                'is_open'    => $reservations < $capacity,
            ];
        }

        return $availability;
    }
}
