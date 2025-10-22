<?php

namespace UCB\Services;

use WP_Error;
use WP_User;
use WP_User_Query;

/**
 * Customer domain helpers.
 */
class CustomerService {
    /**
     * Fetch customer user.
     */
    public function get_customer(int $customer_id): ?WP_User {
        $user = get_user_by('id', $customer_id);

        return $user ?: null;
    }

    /**
     * Paginate customers.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function list_customers(array $filters, int $page = 1, int $per_page = 20): array {
        $meta_query_clauses = [];

        if (!empty($filters['status'])) {
            $status = sanitize_key($filters['status']);

            if ('unassigned' === $status) {
                $meta_query_clauses[] = [
                    'relation' => 'OR',
                    [
                        'key'   => 'ucb_customer_status',
                        'value' => 'unassigned',
                    ],
                    [
                        'key'     => 'ucb_customer_status',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => 'ucb_customer_status',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ];
            } else {
                $meta_query_clauses[] = [
                    'key'   => 'ucb_customer_status',
                    'value' => $status,
                ];
            }
        }

        if (!empty($filters['card_id'])) {
            $meta_query_clauses[] = [
                'key'   => 'ucb_customer_card_id',
                'value' => (int) $filters['card_id'],
            ];
        }

        if (!empty($filters['supervisor_id'])) {
            $meta_query_clauses[] = [
                'key'   => 'ucb_customer_assigned_supervisor',
                'value' => (int) $filters['supervisor_id'],
            ];
        }

        if (!empty($filters['agent_id'])) {
            $meta_query_clauses[] = [
                'key'   => 'ucb_customer_assigned_agent',
                'value' => (int) $filters['agent_id'],
            ];
        }

        $meta_query = empty($meta_query_clauses)
            ? []
            : array_merge(['relation' => 'AND'], $meta_query_clauses);

        $args = [
            'number'     => $per_page,
            'paged'      => $page,
            'orderby'    => 'registered',
            'order'      => 'DESC',
            'meta_query' => $meta_query,
        ];

        if (!empty($filters['order'])) {
            $order = strtoupper(sanitize_text_field((string) $filters['order']));
            if (in_array($order, ['ASC', 'DESC'], true)) {
                $args['order'] = $order;
            }
        }

        if (!empty($filters['registered_date'])) {
            $date = sanitize_text_field((string) $filters['registered_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $args['date_query'] = [
                    [
                        'after'     => $date . ' 00:00:00',
                        'before'    => $date . ' 23:59:59',
                        'inclusive' => true,
                    ],
                ];
            }
        }

        if (!empty($filters['search'])) {
            $search = sanitize_text_field($filters['search']);
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query = new WP_User_Query($args);

        $items = array_map(function (WP_User $user) {
            return $this->format_customer($user);
        }, $query->get_results());

        return [
            'items' => $items,
            'total' => (int) $query->get_total(),
        ];
    }

    /**
     * Count customers matching filters.
     */
    public function count_customers(array $filters = []): int {
        $result = $this->list_customers($filters, 1, 1);

        return (int) $result['total'];
    }

    /**
     * Get customer status counts.
     *
     * @return array<string, int>
     */
    public function get_status_counts(array $filters = [], ?int $user_id = null): array {
        global $wpdb;

        if (null === $user_id) {
            $user_id = get_current_user_id();
        }

        $role = \UCB\Roles::get_user_role($user_id);

        if ('supervisor' === $role) {
            $filters['supervisor_id'] = $user_id;
        } elseif ('agent' === $role) {
            $filters['agent_id'] = $user_id;
        }

        $joins = ["INNER JOIN {$wpdb->users} users ON users.ID = status_meta.user_id"];
        $where = ["status_meta.meta_key = 'ucb_customer_status'"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status_meta.meta_value = %s';
            $params[] = sanitize_key($filters['status']);
        }

        if (!empty($filters['card_id'])) {
            $joins[] = "INNER JOIN {$wpdb->usermeta} card_meta ON card_meta.user_id = status_meta.user_id AND card_meta.meta_key = 'ucb_customer_card_id'";
            $where[] = 'card_meta.meta_value = %d';
            $params[] = (int) $filters['card_id'];
        }

        if (!empty($filters['supervisor_id'])) {
            $joins[] = "INNER JOIN {$wpdb->usermeta} sup_meta ON sup_meta.user_id = status_meta.user_id AND sup_meta.meta_key = 'ucb_customer_assigned_supervisor'";
            $where[] = 'sup_meta.meta_value = %d';
            $params[] = (int) $filters['supervisor_id'];
        }

        if (!empty($filters['agent_id'])) {
            $joins[] = "INNER JOIN {$wpdb->usermeta} agent_meta ON agent_meta.user_id = status_meta.user_id AND agent_meta.meta_key = 'ucb_customer_assigned_agent'";
            $where[] = 'agent_meta.meta_value = %d';
            $params[] = (int) $filters['agent_id'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
            $where[] = '(users.user_login LIKE %s OR users.user_email LIKE %s OR users.display_name LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['registered_date'])) {
            $date = sanitize_text_field((string) $filters['registered_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = 'DATE(users.user_registered) = %s';
                $params[] = $date;
            }
        }

        $sql = "SELECT status_meta.meta_value AS status, COUNT(*) AS total
                FROM {$wpdb->usermeta} status_meta
                " . implode(' ', $joins) . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY status_meta.meta_value";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $results = $wpdb->get_results($sql);
        $counts = [];

        foreach ((array) $results as $row) {
            $status_key = is_object($row) ? (string) $row->status : '';
            if ($status_key === '') {
                continue;
            }
            $counts[$status_key] = (int) $row->total;
        }

        $status_manager = new StatusManager();
        foreach (array_keys($status_manager->get_statuses()) as $status) {
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Formats a customer for API responses.
     *
     * @return array<string, mixed>
     */
    public function format_customer(WP_User $user): array {
        $user_id = $user->ID;

        $supervisor_id = (int) get_user_meta($user_id, 'ucb_customer_assigned_supervisor', true);
        $agent_id = (int) get_user_meta($user_id, 'ucb_customer_assigned_agent', true);
        $card_id = (int) get_user_meta($user_id, 'ucb_customer_card_id', true);

        $supervisor_name = $supervisor_id ? $this->get_user_display_name($supervisor_id) : null;
        $agent_name = $agent_id ? $this->get_user_display_name($agent_id) : null;
        $card_title = $card_id ? get_the_title($card_id) : null;

        return [
            'id'                        => $user_id,
            'username'                 => $user->user_login,
            'email'                    => $user->user_email,
            'first_name'               => $user->first_name,
            'last_name'                => $user->last_name,
            'display_name'             => $user->display_name,
            'phone'                    => get_user_meta($user_id, 'phone', true),
            'status'                   => get_user_meta($user_id, 'ucb_customer_status', true) ?: 'unassigned',
            'assigned_supervisor'      => $supervisor_id,
            'assigned_supervisor_name' => $supervisor_name,
            'assigned_agent'           => $agent_id,
            'assigned_agent_name'      => $agent_name,
            'card_id'                  => $card_id,
            'card_title'               => $card_title,
            'random_code'              => get_user_meta($user_id, 'ucb_customer_random_code', true),
            'upsell_field_key'         => get_user_meta($user_id, 'ucb_upsell_field_key', true) ?: null,
            'upsell_field_label'       => get_user_meta($user_id, 'ucb_upsell_field_label', true) ?: null,
            'upsell_amount'            => (float) get_user_meta($user_id, 'ucb_upsell_amount', true),
            'upsell_order_id'          => (int) get_user_meta($user_id, 'ucb_upsell_order_id', true),
            'upsell_pay_link'          => get_user_meta($user_id, 'ucb_upsell_pay_link', true) ?: null,
            'registered_at'            => mysql_to_rfc3339($user->user_registered),
            'form_data'                => $this->get_sanitized_form_data($user_id),
        ];
    }

    /**
     * Retrieve sanitized customer form data.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function get_sanitized_form_data(int $user_id): array {
        $raw_data = get_user_meta($user_id, 'ucb_customer_form_data', true);

        $entries = $this->normalize_form_entries($raw_data);

        if (empty($entries)) {
            return [];
        }

        $sanitized = [];

        foreach ($entries as $key => $value) {
            $label = $this->resolve_form_label($key, $value);
            $sanitized[] = [
                'label' => $label,
                'value' => $this->sanitize_form_value($value),
            ];
        }

        return $sanitized;
    }

    /**
     * Normalize stored form entries into an array structure.
     *
     * @param mixed $raw_data
     * @return array<int|string, mixed>
     */
    private function normalize_form_entries($raw_data): array {
        if (is_array($raw_data)) {
            return $raw_data;
        }

        if (is_string($raw_data) && $raw_data !== '') {
            $decoded = json_decode($raw_data, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [
                [
                    'label' => __('Form data', UCB_TEXT_DOMAIN),
                    'value' => $raw_data,
                ],
            ];
        }

        return [];
    }

    private function resolve_form_label($key, $value): string {
        if (is_array($value) && isset($value['label'])) {
            return sanitize_text_field((string) $value['label']);
        }

        if (is_string($key) && $key !== '') {
            return sanitize_text_field($key);
        }

        $index = is_numeric($key) ? ((int) $key + 1) : 1;

        return sprintf(__('Field %d', UCB_TEXT_DOMAIN), max(1, $index));
    }

    private function sanitize_form_value($value): string {
        if (is_array($value) && isset($value['value'])) {
            return $this->sanitize_form_value($value['value']);
        }

        if (is_array($value)) {
            $flattened = array_map(function ($item) {
                return $this->sanitize_form_value($item);
            }, $value);

            $flattened = array_filter($flattened, function ($item) {
                return $item !== '';
            });

            return implode(', ', $flattened);
        }

        if (is_scalar($value)) {
            return sanitize_text_field((string) $value);
        }

        return '';
    }

    /**
     * Assign supervisor to customer.
     */
    public function assign_supervisor(int $customer_id, int $supervisor_id): void {
        update_user_meta($customer_id, 'ucb_customer_assigned_supervisor', $supervisor_id);
        $this->update_forms_meta($customer_id, '_uc_supervisor_id', $supervisor_id);
        $this->update_reservations_supervisor($customer_id, $supervisor_id);
    }

    /**
     * Assign agent to customer.
     */
    public function assign_agent(int $customer_id, int $agent_id): void {
        update_user_meta($customer_id, 'ucb_customer_assigned_agent', $agent_id);
        $this->update_forms_meta($customer_id, '_uc_agent_id', $agent_id);
    }

    /**
     * Update customer's card reference.
     */
    public function set_card(int $customer_id, int $card_id): void {
        update_user_meta($customer_id, 'ucb_customer_card_id', $card_id);
    }

    /**
     * Append a note to customer notes meta.
     */
    public function add_note(int $customer_id, int $user_id, string $note): void {
        $notes = get_user_meta($customer_id, 'ucb_customer_notes', true);
        if (!is_array($notes)) {
            $notes = [];
        }

        $notes[] = [
            'author'    => $user_id,
            'note'      => wp_kses_post($note),
            'created_at'=> current_time('mysql'),
        ];

        update_user_meta($customer_id, 'ucb_customer_notes', $notes);
    }

    /**
     * Retrieve notes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_notes(int $customer_id): array {
        $notes = get_user_meta($customer_id, 'ucb_customer_notes', true);

        return is_array($notes) ? $notes : [];
    }

    /**
     * Validate that given user exists and is in allowed role.
     */
    public function ensure_role(int $user_id, array $roles): WP_User|WP_Error {
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('ucb_user_not_found', __('User not found.', 'user-cards-bridge'));
        }

        $allowed = array_intersect($user->roles, $roles);

        if (empty($allowed)) {
            return new WP_Error('ucb_invalid_role', __('User does not have the required role.', 'user-cards-bridge'));
        }

        return $user;
    }

    /**
     * Update uc_submission meta for customer.
     */
    protected function update_forms_meta(int $customer_id, string $meta_key, int $value): void {
        $forms = get_posts([
            'post_type'      => 'uc_submission',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_uc_user_id',
                    'value' => $customer_id,
                ],
            ],
        ]);

        foreach ($forms as $form_id) {
            update_post_meta($form_id, $meta_key, $value);
        }
    }

    /**
     * Update supervisor in reservations table.
     */
    protected function update_reservations_supervisor(int $customer_id, int $supervisor_id): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ucb_reservations';
        $wpdb->update(
            $table,
            ['supervisor_id' => $supervisor_id],
            ['customer_id' => $customer_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Get display name of a user by ID.
     */
    protected function get_user_display_name(int $user_id): ?string {
        $user = get_user_by('id', $user_id);

        return $user ? $user->display_name : null;
    }
}
