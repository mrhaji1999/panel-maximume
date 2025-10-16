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
    public function create(int $customer_id, int $card_id, int $supervisor_id, string $reservation_date, int $hour): array|WP_Error {
        $reservation_date = sanitize_text_field($reservation_date);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservation_date)) {
            return new WP_Error('ucb_invalid_date_format', __('Invalid reservation date format. Expected YYYY-MM-DD.', 'user-cards-bridge'), ['status' => 400]);
        }

        try {
            $date_object = new \DateTimeImmutable($reservation_date, wp_timezone());
        } catch (\Exception $exception) {
            return new WP_Error('ucb_invalid_date', __('The provided reservation date is not valid.', 'user-cards-bridge'), ['status' => 400]);
        }

        $normalized_date = $date_object->format('Y-m-d');
        $weekday = (int) $date_object->format('w');

        $schedule = new ScheduleService();
        $availability = $schedule->get_availability($card_id, $supervisor_id, $normalized_date);

        $matching = array_filter($availability, static function ($slot) use ($weekday, $hour) {
            return (int) $slot['weekday'] === $weekday && (int) $slot['hour'] === $hour;
        });

        if (empty($matching)) {
            return new WP_Error('ucb_slot_not_found', __('Slot not available for the selected date.', 'user-cards-bridge'), ['status' => 404]);
        }

        $slot = current($matching);
        $capacity = (int) ($slot['capacity'] ?? 0);

        if ($capacity <= 0) {
            return new WP_Error('ucb_slot_full', __('Slot capacity reached for the selected date.', 'user-cards-bridge'), ['status' => 409]);
        }

        $used = $this->database->count_reservations_for_slot_on_date($card_id, $normalized_date, $weekday, $hour);

        if ($used >= $capacity) {
            return new WP_Error('ucb_slot_full', __('Slot capacity reached for the selected date.', 'user-cards-bridge'), ['status' => 409]);
        }

        $reservation_id = $this->database->create_reservation([
            'customer_id'     => $customer_id,
            'card_id'         => $card_id,
            'supervisor_id'   => $supervisor_id,
            'slot_weekday'    => $weekday,
            'slot_hour'       => $hour,
            'reservation_date'=> $normalized_date,
        ]);

        $customer_service = new CustomerService();
        $customer_service->assign_supervisor($customer_id, $supervisor_id);
        $customer_service->set_card($customer_id, $card_id);

        $final_used = $used + 1;
        $remaining = max(0, $capacity - $final_used);

        return [
            'reservation_id' => $reservation_id,
            'slot'           => [
                'weekday'   => $weekday,
                'hour'      => $hour,
                'capacity'  => $capacity,
                'used'      => $final_used,
                'remaining' => $remaining,
                'is_full'   => $remaining <= 0,
                'date'      => $normalized_date,
            ],
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
        $reservation_date = isset($row['reservation_date']) ? sanitize_text_field($row['reservation_date']) : null;

        $reservation_date_display = null;
        $reservation_date_rfc3339 = null;

        if ($reservation_date) {
            try {
                $date_object = new \DateTimeImmutable($reservation_date, wp_timezone());
                $reservation_date_display = $date_object->format(get_option('date_format', 'Y-m-d'));
                $reservation_date_rfc3339 = $date_object->format(DATE_RFC3339);
            } catch (\Exception $exception) {
                $reservation_date_display = $reservation_date;
                $reservation_date_rfc3339 = $reservation_date . 'T00:00:00';
            }
        }

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
            'reservation_date' => $reservation_date,
            'reservation_date_display' => $reservation_date_display,
            'reservation_date_rfc3339' => $reservation_date_rfc3339,
            'created_at' => mysql_to_rfc3339($created_at),
            'time_display' => trim(sprintf('%s %s %02d:00', $reservation_date_display ?: $reservation_date, $this->get_weekday_label($weekday), $hour)),
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
