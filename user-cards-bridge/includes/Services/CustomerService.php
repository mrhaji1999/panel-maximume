<?php

namespace UCB\Services;

use WP_Error;
use WP_User;
use WP_Post;
use WP_User_Query;

/**
 * Customer domain helpers.
 */
class CustomerService {
    private CustomerCardRepository $card_repository;

    public function __construct() {
        $this->card_repository = new CustomerCardRepository();
    }

    /**
     * Fetch customer user.
     */
    public function get_customer(int $customer_id): ?WP_User {
        $user = get_user_by('id', $customer_id);

        if ($user instanceof WP_User) {
            $this->card_repository->ensure_legacy_migrated($customer_id);
        }

        return $user ?: null;
    }

    /**
     * Paginate customers based on submissions.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function list_customers(array $filters, int $page = 1, int $per_page = 20): array {
        $args = [
            'post_type'      => 'uc_submission',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => ['relation' => 'AND'],
        ];

        if (!empty($filters['order'])) {
            $order = strtoupper(sanitize_text_field((string) $filters['order']));
            if (in_array($order, ['ASC', 'DESC'], true)) {
                $args['order'] = $order;
            }
        }

        if (isset($filters['status']) && '' !== $filters['status']) {
            $args['meta_query'][] = [
                'key' => '_uc_status',
                'value' => sanitize_key($filters['status']),
                'compare' => '=',
            ];
        }

        if (!empty($filters['card_id'])) {
            $args['meta_query'][] = [
                'key' => '_uc_card_id',
                'value' => (int) $filters['card_id'],
                'compare' => '=',
            ];
        }

        if (!empty($filters['card_id_in'])) {
            $args['meta_query'][] = [
                'key' => '_uc_card_id',
                'value' => $filters['card_id_in'],
                'compare' => 'IN',
            ];
        }

        if (!empty($filters['supervisor_id'])) {
            $args['meta_query'][] = [
                'key' => '_uc_supervisor_id',
                'value' => (int) $filters['supervisor_id'],
                'compare' => '=',
            ];
        }

        if (array_key_exists('agent_id', $filters)) {
            $agent_id = (int) $filters['agent_id'];

            if ($agent_id > 0) {
                $args['meta_query'][] = [
                    'key' => '_uc_agent_id',
                    'value' => $agent_id,
                    'compare' => '=',
                ];
            } else {
                $args['meta_query'][] = [
                    'relation' => 'OR',
                    [
                        'key' => '_uc_agent_id',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => '_uc_agent_id',
                        'value' => '',
                        'compare' => '=',
                    ],
                    [
                        'key' => '_uc_agent_id',
                        'value' => 0,
                        'type' => 'NUMERIC',
                        'compare' => '=',
                    ],
                ];
            }
        }

        if (!empty($filters['search'])) {
            $search = sanitize_text_field($filters['search']);
            $user_ids = get_users([
                'search' => '*' . $search . '*',
                'search_columns' => ['user_login', 'user_email', 'display_name'],
                'fields' => 'ID',
            ]);

            if (!empty($user_ids)) {
                $args['meta_query'][] = [
                    'key' => '_uc_user_id',
                    'value' => $user_ids,
                    'compare' => 'IN',
                ];
            } else {
                 return ['items' => [], 'total' => 0];
            }
        }

        if (1 === count($args['meta_query'])) {
            unset($args['meta_query']);
        }

        $query = new \WP_Query($args);
        $items = [];
        foreach ($query->posts as $submission) {
            if ($submission instanceof WP_Post) {
                $items[] = $this->format_customer_from_submission($submission);
            }
        }

        return [
            'items' => $items,
            'total' => $query->found_posts,
        ];
    }

    /**
     * Format a customer entry from a submission post.
     *
     * @return array<string, mixed>
     */
    public function format_customer_from_submission(WP_Post $submission): array {
        $submission_id = $submission->ID;
        $user_id = (int) get_post_meta($submission_id, '_uc_user_id', true);
        $user = get_user_by('id', $user_id);
        $card_id = (int) get_post_meta($submission_id, '_uc_card_id', true);

        if (!$user instanceof WP_User) {
            return [];
        }

        $status = get_post_meta($submission_id, '_uc_status', true);
        if (!is_string($status) || '' === $status) {
            $status = 'unassigned';
        }

        $supervisor_id = (int) get_post_meta($submission_id, '_uc_supervisor_id', true);
        $agent_id = (int) get_post_meta($submission_id, '_uc_agent_id', true);

        $supervisor_name = $supervisor_id ? $this->get_user_display_name($supervisor_id) : null;
        $agent_name = $agent_id ? $this->get_user_display_name($agent_id) : null;
        $card_title = $card_id ? get_the_title($card_id) : null;

        $date_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_date', true));
        $time_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_time', true));
        $random_code = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_surprise', true));

        $form_data = $this->get_latest_submission_data($user_id, $card_id, $submission_id);

        $upsell = $this->card_repository->get_card_upsell($user_id, $card_id);
        $submission_upsell = array_filter([
            'field_key'   => get_post_meta($submission_id, '_uc_upsell_field_key', true),
            'field_label' => get_post_meta($submission_id, '_uc_upsell_field_label', true),
            'amount'      => get_post_meta($submission_id, '_uc_upsell_amount', true),
            'order_id'    => get_post_meta($submission_id, '_uc_upsell_order_id', true) ?: get_post_meta($submission_id, '_uc_upsell_last_order_id', true),
            'pay_link'    => get_post_meta($submission_id, '_uc_upsell_pay_link', true),
        ], static function ($value) {
            return null !== $value && $value !== '';
        });

        if (!empty($submission_upsell)) {
            if (isset($submission_upsell['amount'])) {
                $submission_upsell['amount'] = (float) $submission_upsell['amount'];
            }
            if (isset($submission_upsell['order_id'])) {
                $submission_upsell['order_id'] = (int) $submission_upsell['order_id'];
            }
            $upsell = array_merge($upsell, $submission_upsell);
        }

        $phone_meta = get_user_meta($user_id, 'ucb_customer_phone', true);
        if ('' === $phone_meta) {
            $phone_meta = get_user_meta($user_id, 'phone', true);
        }
        $phone_value = is_scalar($phone_meta) ? (string) $phone_meta : '';

        return [
            'id'                        => $user_id,
            'customer_id'               => $user_id,
            'entry_id'                  => $submission_id,
            'username'                  => $user->user_login,
            'email'                     => $user->user_email,
            'first_name'                => $user->first_name,
            'last_name'                 => $user->last_name,
            'display_name'              => $user->display_name,
            'phone'                     => $phone_value !== '' ? $phone_value : null,
            'status'                    => $status,
            'assigned_supervisor'       => $supervisor_id,
            'assigned_supervisor_name'  => $supervisor_name,
            'assigned_agent'            => $agent_id,
            'assigned_agent_name'       => $agent_name,
            'card_id'                   => $card_id,
            'card_title'                => $card_title,
            'random_code'               => $random_code,
            'upsell_field_key'          => $upsell['field_key'] ?? null,
            'upsell_field_label'        => $upsell['field_label'] ?? null,
            'upsell_amount'             => isset($upsell['amount']) ? (float) $upsell['amount'] : 0.0,
            'upsell_order_id'           => isset($upsell['order_id']) ? (int) $upsell['order_id'] : 0,
            'upsell_pay_link'           => $upsell['pay_link'] ?? null,
            'registered_at'             => mysql_to_rfc3339($user->user_registered),
            'form_data'                 => $form_data['fields'],
            'form_schedule'             => $form_data['schedule'],
        ];
    }

    /**
     * Fetch customers without an explicit status assignment.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    /**
     * Count customers matching filters.
     */
    public function count_customers(array $filters = []): int {
        $args = [
            'post_type'      => 'uc_submission',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [],
        ];

        if (!empty($filters['status'])) {
            $args['meta_query'][] = [
                'key' => '_uc_status',
                'value' => sanitize_key($filters['status']),
                'compare' => '=',
            ];
        }

        if (!empty($filters['card_id'])) {
            $args['meta_query'][] = [
                'key' => '_uc_card_id',
                'value' => (int) $filters['card_id'],
                'compare' => '=',
            ];
        }

        if (!empty($filters['card_id_in'])) {
            $args['meta_query'][] = [
                'key' => '_uc_card_id',
                'value' => $filters['card_id_in'],
                'compare' => 'IN',
            ];
        }

        if (!empty($filters['supervisor_id'])) {
            $args['meta_query'][] = [
                'key' => '_uc_supervisor_id',
                'value' => (int) $filters['supervisor_id'],
                'compare' => '=',
            ];
        }

        if (!empty($filters['agent_id'])) {
            $args['meta_query'][] = [
                'key' => '_uc_agent_id',
                'value' => (int) $filters['agent_id'],
                'compare' => '=',
            ];
        }

        $query = new \WP_Query($args);
        return $query->found_posts;
    }

    /**
     * Get customer status counts.
     *
     * @return array<string, int>
     */
    public function get_status_counts(array $filters = [], ?int $user_id = null): array {
        if (null === $user_id) {
            $user_id = get_current_user_id();
        }

        $role = \UCB\Roles::get_user_role($user_id);

        if ('supervisor' === $role) {
            $filters['supervisor_id'] = $user_id;
        } elseif ('agent' === $role) {
            $filters['agent_id'] = $user_id;
        }

        $status_manager = new StatusManager();
        $counts = [];
        $all_statuses = array_keys($status_manager->get_statuses());

        foreach ($all_statuses as $status) {
            $status_filters = array_merge($filters, ['status' => $status]);
            $counts[$status] = $this->count_customers($status_filters);
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Formats a customer for API responses.
     *
     * @return array<string, mixed>
     */
    public function format_customer(WP_User $user, ?int $card_id = null): array {
        $cards = $this->get_customer_cards_map((int) $user->ID);

        if (empty($cards)) {
            return $this->format_customer_for_card($user, $card_id ?? 0, []);
        }

        if (null !== $card_id && isset($cards[$card_id])) {
            return $this->format_customer_for_card($user, $card_id, $cards[$card_id]);
        }

        $first_card_id = (int) array_key_first($cards);

        return $this->format_customer_for_card($user, $first_card_id, $cards[$first_card_id]);
    }

    /**
     * Retrieve sanitized customer form data.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function get_sanitized_form_data(int $user_id, $raw_data = null): array {
        if (null === $raw_data) {
            $raw_data = get_user_meta($user_id, 'ucb_customer_form_data', true);
        }

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
     * Retrieve latest submission data to hydrate form fields.
     *
     * @return array{fields: array<int, array{label: string, value: string}>, schedule: array{date: ?string, time: ?string}}
     */
    private function get_latest_submission_data(int $user_id, ?int $card_id = null, ?int $preferred_submission_id = null): array {
        $submission_id = 0;

        if ($preferred_submission_id) {
            $owner_id = (int) get_post_meta($preferred_submission_id, '_uc_user_id', true);
            $submission_card = (int) get_post_meta($preferred_submission_id, '_uc_card_id', true);
            if ($owner_id === $user_id && (null === $card_id || $submission_card === $card_id)) {
                $submission_id = $preferred_submission_id;
            }
        }

        if ($submission_id <= 0) {
            $meta_query = [
                [
                    'key'   => '_uc_user_id',
                    'value' => $user_id,
                    'compare' => '=',
                ],
            ];

            if (null !== $card_id) {
                $meta_query[] = [
                    'key'   => '_uc_card_id',
                    'value' => $card_id,
                    'compare' => '=',
                ];
            }

            $submissions = get_posts([
                'post_type'      => 'uc_submission',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'meta_query'     => $meta_query,
            ]);

            if (empty($submissions)) {
                return [
                    'fields' => [],
                    'schedule' => [
                        'date' => null,
                        'time' => null,
                    ],
                    'random_code' => null,
                ];
            }

            $submission_id = (int) $submissions[0];
        }

        $field_map = [
            '_uc_code'     => __('کد رزرو', UCB_TEXT_DOMAIN),
            '_uc_date'     => __('تاریخ رزرو', UCB_TEXT_DOMAIN),
            '_uc_time'     => __('ساعت رزرو', UCB_TEXT_DOMAIN),
            '_uc_surprise' => __('کد شگفتانه', UCB_TEXT_DOMAIN),
        ];

        $fields = [];

        foreach ($field_map as $meta_key => $label) {
            $value = $this->sanitize_form_value(get_post_meta($submission_id, $meta_key, true));
            if ('' !== $value) {
                $fields[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        $custom_fields = get_post_meta($submission_id, '_uc_meta_fields', true);
        if (is_array($custom_fields)) {
            foreach ($custom_fields as $key => $value) {
                $fields[] = [
                    'label' => $this->resolve_form_label($key, $value),
                    'value' => $this->sanitize_form_value($value),
                ];
            }
        }

        $date_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_date', true));
        $time_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_time', true));
        $random_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_surprise', true));

        return [
            'fields' => $fields,
            'schedule' => [
                'date' => '' !== $date_value ? $date_value : null,
                'time' => '' !== $time_value ? $time_value : null,
            ],
            'random_code' => '' !== $random_value ? $random_value : null,
        ];
    }

    /**
     * Merge primary form entries with fallback submission data.
     *
     * @param array<int, array{label: string, value: string}> $primary
     * @param array<int, array{label: string, value: string}> $secondary
     * @return array<int, array{label: string, value: string}>
     */
    private function merge_form_fields(array $primary, array $secondary): array {
        if (empty($secondary)) {
            return $primary;
        }

        if (empty($primary)) {
            return $secondary;
        }

        $seen = [];
        foreach ($primary as $field) {
            if (!isset($field['label'])) {
                continue;
            }

            $normalized = $this->normalize_label_key((string) $field['label']);
            if ('' !== $normalized) {
                $seen[] = $normalized;
            }
        }

        foreach ($secondary as $field) {
            if (!isset($field['label'], $field['value'])) {
                continue;
            }

            $normalized = $this->normalize_label_key((string) $field['label']);

            if ('' === $normalized || in_array($normalized, $seen, true)) {
                continue;
            }

            $primary[] = [
                'label' => (string) $field['label'],
                'value' => (string) $field['value'],
            ];
            $seen[] = $normalized;
        }

        return $primary;
    }

    private function normalize_label_key(string $label): string {
        $label = trim($label);

        if ('' === $label) {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $label = mb_strtolower($label, 'UTF-8');
        } else {
            $label = strtolower($label);
        }

        return $label;
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

    /**
     * Fetch customer user IDs that have at least one form submission matching filters.
     *
     * @param array<string, mixed> $filters
     * @return array<int>
     */
    private function get_customer_ids_from_forms(array $filters): array {
        global $wpdb;

        $posts_table = $wpdb->posts;
        $meta_table = $wpdb->postmeta;

        $joins = [
            "INNER JOIN {$meta_table} form_user ON form_user.post_id = posts.ID AND form_user.meta_key = '_uc_user_id'",
        ];

        $where = [
            "posts.post_type = 'uc_submission'",
            "posts.post_status NOT IN ('trash', 'auto-draft')",
        ];

        $params = [];

        if (!empty($filters['card_id'])) {
            $joins[] = "INNER JOIN {$meta_table} form_card ON form_card.post_id = posts.ID AND form_card.meta_key = '_uc_card_id'";
            $where[] = 'CAST(form_card.meta_value AS UNSIGNED) = %d';
            $params[] = (int) $filters['card_id'];
        }

        if (!empty($filters['supervisor_id'])) {
            $joins[] = "INNER JOIN {$meta_table} form_supervisor ON form_supervisor.post_id = posts.ID AND form_supervisor.meta_key = '_uc_supervisor_id'";
            $where[] = 'CAST(form_supervisor.meta_value AS UNSIGNED) = %d';
            $params[] = (int) $filters['supervisor_id'];
        }

        if (!empty($filters['agent_id'])) {
            $joins[] = "INNER JOIN {$meta_table} form_agent ON form_agent.post_id = posts.ID AND form_agent.meta_key = '_uc_agent_id'";
            $where[] = 'CAST(form_agent.meta_value AS UNSIGNED) = %d';
            $params[] = (int) $filters['agent_id'];
        }

        if (!empty($filters['registered_date'])) {
            $date = sanitize_text_field((string) $filters['registered_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = 'DATE(posts.post_date) = %s';
                $params[] = $date;
            }
        }

        $sql = "SELECT DISTINCT CAST(form_user.meta_value AS UNSIGNED) AS user_id
                FROM {$posts_table} posts " . implode(' ', array_unique($joins)) . "
                WHERE " . implode(' AND ', $where);

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $ids = array_map('intval', (array) $wpdb->get_col($sql));

        return array_values(array_filter($ids, function ($id) {
            return $id > 0;
        }));
    }

    /**
     * Retrieve per-card metadata for the given customer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_customer_cards_map(int $customer_id): array {
        $cards = $this->card_repository->get_cards($customer_id);

        if (!empty($cards)) {
            return $cards;
        }

        $derived = $this->derive_cards_from_submissions($customer_id);
        if (!empty($derived)) {
            foreach ($derived as $card_id => $data) {
                $this->card_repository->set_card($customer_id, (int) $card_id, $data);
            }

            return $this->card_repository->get_cards($customer_id);
        }

        return [];
    }

    /**
     * Populate card map from stored submissions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function derive_cards_from_submissions(int $customer_id): array {
        $submissions = get_posts([
            'post_type'      => 'uc_submission',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_uc_user_id',
                    'value' => $customer_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if (empty($submissions)) {
            return [];
        }

        $cards = [];

        foreach ($submissions as $submission) {
            $submission_id = (int) $submission;
            $card_id = (int) get_post_meta($submission_id, '_uc_card_id', true);

            if ($card_id <= 0) {
                continue;
            }

            if (isset($cards[$card_id])) {
                continue; // Already captured the most recent entry.
            }

            $status = get_post_meta($submission_id, '_uc_status', true);
            if (!is_string($status) || '' === $status) {
                $status = get_user_meta($customer_id, 'ucb_customer_status', true) ?: 'unassigned';
            }

            $date_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_date', true));
            $time_value = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_time', true));
            $random_code = $this->sanitize_form_value(get_post_meta($submission_id, '_uc_surprise', true));
            $meta_fields = get_post_meta($submission_id, '_uc_meta_fields', true);

            $cards[$card_id] = array_filter([
                'status'         => sanitize_key($status),
                'supervisor_id'  => (int) get_post_meta($submission_id, '_uc_supervisor_id', true),
                'agent_id'       => (int) get_post_meta($submission_id, '_uc_agent_id', true),
                'submission_id'  => $submission_id,
                'schedule'       => [
                    'date' => '' !== $date_value ? $date_value : null,
                    'time' => '' !== $time_value ? $time_value : null,
                ],
                'random_code'    => '' !== $random_code ? $random_code : null,
                'form_data'      => is_array($meta_fields) ? $meta_fields : null,
            ], static function ($value) {
                return null !== $value;
            });
        }

        return $cards;
    }

    /**
     * Format a customer entry for a specific card.
     *
     * @return array<string, mixed>
     */
    private function format_customer_for_card(WP_User $user, int $card_id, array $card_meta): array {
        $user_id = (int) $user->ID;

        $status = isset($card_meta['status']) && $card_meta['status'] !== ''
            ? sanitize_key($card_meta['status'])
            : 'unassigned';

        $supervisor_id = (int) ($card_meta['supervisor_id'] ?? 0);
        $agent_id = (int) ($card_meta['agent_id'] ?? 0);
        $submission_id = (int) ($card_meta['submission_id'] ?? 0);

        $supervisor_name = $supervisor_id ? $this->get_user_display_name($supervisor_id) : null;
        $agent_name = $agent_id ? $this->get_user_display_name($agent_id) : null;
        $card_title = $card_id ? get_the_title($card_id) : null;

        $form_data = [];
        if (isset($card_meta['form_data']) && !empty($card_meta['form_data'])) {
            $form_data = $this->get_sanitized_form_data($user_id, $card_meta['form_data']);
        } else {
            $form_data = $this->get_sanitized_form_data($user_id);
        }

        $submission_data = $this->get_latest_submission_data($user_id, $card_id, $submission_id);
        if (!empty($submission_data['fields'])) {
            $form_data = $this->merge_form_fields($form_data, $submission_data['fields']);
        }

        $schedule = ['date' => null, 'time' => null];
        if (isset($card_meta['schedule']) && is_array($card_meta['schedule'])) {
            $schedule = array_merge($schedule, $card_meta['schedule']);
        }
        if (!empty($submission_data['schedule'])) {
            $schedule = array_merge($schedule, $submission_data['schedule']);
        }

        $random_code = $card_meta['random_code'] ?? null;
        if (null === $random_code) {
            $random_code = $submission_data['random_code'] ?? null;
        }
        if (null === $random_code) {
            $random_code = get_user_meta($user_id, 'ucb_customer_random_code', true) ?: null;
        }

        $upsell = $this->card_repository->get_card_upsell($user_id, $card_id);

        $phone_meta = get_user_meta($user_id, 'ucb_customer_phone', true);
        if ('' === $phone_meta) {
            $phone_meta = get_user_meta($user_id, 'phone', true);
        }
        $phone_value = is_scalar($phone_meta) ? (string) $phone_meta : '';

        return [
            'id'                        => $user_id,
            'customer_id'               => $user_id,
            'entry_id'                  => $submission_id ?: null,
            'username'                  => $user->user_login,
            'email'                     => $user->user_email,
            'first_name'                => $user->first_name,
            'last_name'                 => $user->last_name,
            'display_name'              => $user->display_name,
            'phone'                     => $phone_value !== '' ? $phone_value : null,
            'status'                    => $status,
            'assigned_supervisor'       => $supervisor_id,
            'assigned_supervisor_name'  => $supervisor_name,
            'assigned_agent'            => $agent_id,
            'assigned_agent_name'       => $agent_name,
            'card_id'                   => $card_id,
            'card_title'                => $card_title,
            'random_code'               => $random_code,
            'upsell_field_key'          => $upsell['field_key'] ?? null,
            'upsell_field_label'        => $upsell['field_label'] ?? null,
            'upsell_amount'             => isset($upsell['amount']) ? (float) $upsell['amount'] : 0.0,
            'upsell_order_id'           => isset($upsell['order_id']) ? (int) $upsell['order_id'] : 0,
            'upsell_pay_link'           => $upsell['pay_link'] ?? null,
            'registered_at'             => mysql_to_rfc3339($user->user_registered),
            'form_data'                 => $form_data,
            'form_schedule'             => $schedule,
        ];
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
    public function assign_supervisor(int $customer_id, int $supervisor_id, ?int $card_id = null, ?int $submission_id = null): void {
        update_user_meta($customer_id, 'ucb_customer_assigned_supervisor', $supervisor_id);

        if (null !== $submission_id && $submission_id > 0) {
            update_post_meta($submission_id, '_uc_supervisor_id', $supervisor_id);

            if (null !== $card_id && $card_id > 0) {
                $this->card_repository->update_supervisor($customer_id, $card_id, $supervisor_id);
                $this->card_repository->update_submission($customer_id, $card_id, $submission_id);
            }

            $this->update_reservations_supervisor($customer_id, $supervisor_id, $card_id, $submission_id);
            return;
        }

        if (null !== $card_id && $card_id > 0) {
            $this->card_repository->update_supervisor($customer_id, $card_id, $supervisor_id);
            $this->update_forms_meta($customer_id, '_uc_supervisor_id', $supervisor_id, $card_id);
            $this->update_reservations_supervisor($customer_id, $supervisor_id, $card_id, null);
        } else {
            $cards = $this->get_customer_cards_map($customer_id);
            foreach (array_keys($cards) as $existing_card_id) {
                $this->card_repository->update_supervisor($customer_id, (int) $existing_card_id, $supervisor_id);
            }
            $this->update_forms_meta($customer_id, '_uc_supervisor_id', $supervisor_id, null);
            $this->update_reservations_supervisor($customer_id, $supervisor_id, null, null);
        }
    }

    /**
     * Assign agent to customer.
     */
    public function assign_agent(int $customer_id, int $agent_id, ?int $card_id = null, ?int $submission_id = null): void {
        update_user_meta($customer_id, 'ucb_customer_assigned_agent', $agent_id);

        if (null !== $submission_id && $submission_id > 0) {
             update_post_meta($submission_id, '_uc_agent_id', $agent_id);
            if (null !== $card_id && $card_id > 0) {
                $this->card_repository->update_agent($customer_id, $card_id, $agent_id);
                $this->card_repository->update_submission($customer_id, $card_id, $submission_id);
            }
        } elseif (null !== $card_id && $card_id > 0) {
            $this->card_repository->update_agent($customer_id, $card_id, $agent_id);
            $this->update_forms_meta($customer_id, '_uc_agent_id', $agent_id, $card_id);
        } else {
            $cards = $this->get_customer_cards_map($customer_id);
            foreach (array_keys($cards) as $existing_card_id) {
                $this->card_repository->update_agent($customer_id, (int) $existing_card_id, $agent_id);
            }
            $this->update_forms_meta($customer_id, '_uc_agent_id', $agent_id, null);
        }
    }

    /**
     * Update customer's card reference.
     */
    public function set_card(int $customer_id, int $card_id): void {
        update_user_meta($customer_id, 'ucb_customer_card_id', $card_id);
        if ($card_id > 0) {
            $this->card_repository->set_card($customer_id, $card_id, ['status' => 'unassigned']);
        }
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
    protected function update_forms_meta(int $customer_id, string $meta_key, int $value, ?int $card_id = null): void {
        $meta_query = [
            [
                'key'   => '_uc_user_id',
                'value' => $customer_id,
            ],
        ];

        if (null !== $card_id && $card_id > 0) {
            $meta_query[] = [
                'key'   => '_uc_card_id',
                'value' => $card_id,
            ];
        }

        $forms = get_posts([
            'post_type'      => 'uc_submission',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ]);

        foreach ($forms as $form_id) {
            update_post_meta($form_id, $meta_key, $value);
        }
    }

    /**
     * Update supervisor in reservations table.
     */
    protected function update_reservations_supervisor(int $customer_id, int $supervisor_id, ?int $card_id = null, ?int $submission_id = null): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ucb_reservations';
        $where = ['customer_id' => $customer_id];
        $where_format = ['%d'];

        if (null !== $card_id && $card_id > 0) {
            $where['card_id'] = $card_id;
            $where_format[] = '%d';
        }

        $wpdb->update(
            $table,
            ['supervisor_id' => $supervisor_id],
            $where,
            ['%d'],
            $where_format
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
