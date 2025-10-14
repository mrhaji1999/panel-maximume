<?php

namespace UCB\Services;

use UCB\Database;
use WP_Error;

/**
 * Reservation handling.
 */
class ReservationService {
    /**
     * @var Database
     */
    protected $database;

    public function __construct() {
        $this->database = new Database();
    }

    /**
     * Creates a reservation if slot is available.
     */
    public function create(int $customer_id, int $card_id, int $supervisor_id, int $weekday, int $hour): array|WP_Error {
        $schedule = new ScheduleService();
        $availability = $schedule->get_availability($card_id, $supervisor_id);

        $matching = array_filter($availability, function ($slot) use ($weekday, $hour) {
            return (int) $slot['weekday'] === $weekday && (int) $slot['hour'] === $hour;
        });

        if (empty($matching)) {
            return new WP_Error('ucb_slot_not_found', __('Slot not available.', 'user-cards-bridge'));
        }

        $slot = current($matching);

        if (!$slot['is_open']) {
            return new WP_Error('ucb_slot_full', __('Slot capacity reached.', 'user-cards-bridge'));
        }

        $reservation_id = $this->database->create_reservation([
            'customer_id'   => $customer_id,
            'card_id'       => $card_id,
            'supervisor_id' => $supervisor_id,
            'slot_weekday'  => $weekday,
            'slot_hour'     => $hour,
        ]);

        return [
            'reservation_id' => $reservation_id,
            'slot'           => $slot,
        ];
    }

    /**
     * List reservations.
     */
    public function list(array $filters = [], int $page = 1, int $per_page = 20): array {
        $rows = $this->database->get_reservations($filters, $page, $per_page);

        $items = array_map(function (array $row) {
            return $this->format_reservation($row);
        }, $rows);

        return [
            'items' => $items,
            'total' => $this->database->count_reservations($filters),
        ];
    }

    /**
     * Format reservation row for API.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function format_reservation(array $row): array {
        $reservation_id = (int) ($row['id'] ?? 0);
        $customer_id = (int) ($row['customer_id'] ?? 0);
        $card_id = (int) ($row['card_id'] ?? 0);
        $supervisor_id = (int) ($row['supervisor_id'] ?? 0);
        $weekday = (int) ($row['slot_weekday'] ?? 0);
        $hour = (int) ($row['slot_hour'] ?? 0);
        $created_at = $row['created_at'] ?? current_time('mysql');

        $customer = $customer_id ? get_user_by('id', $customer_id) : null;
        $supervisor = $supervisor_id ? get_user_by('id', $supervisor_id) : null;
        $card = $card_id ? get_post($card_id) : null;

        return [
            'id' => $reservation_id,
            'customer_id' => $customer_id,
            'customer_name' => $customer ? $customer->display_name : null,
            'customer_email' => $customer ? $customer->user_email : null,
            'card_id' => $card_id,
            'card_title' => $card ? get_the_title($card) : null,
            'supervisor_id' => $supervisor_id,
            'supervisor_name' => $supervisor ? $supervisor->display_name : null,
            'weekday' => $weekday,
            'hour' => $hour,
            'created_at' => mysql_to_rfc3339($created_at),
            'time_display' => sprintf('%s %02d:00', $this->get_weekday_label($weekday), $hour),
        ];
    }

    /**
     * Get localized weekday label.
     */
    protected function get_weekday_label(int $weekday): string {
        $weekdays = [
            __('Sunday', 'user-cards-bridge'),
            __('Monday', 'user-cards-bridge'),
            __('Tuesday', 'user-cards-bridge'),
            __('Wednesday', 'user-cards-bridge'),
            __('Thursday', 'user-cards-bridge'),
            __('Friday', 'user-cards-bridge'),
            __('Saturday', 'user-cards-bridge'),
        ];

        return $weekdays[$weekday] ?? (string) $weekday;
    }
}
