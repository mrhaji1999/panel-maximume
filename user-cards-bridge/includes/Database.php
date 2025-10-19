<?php

namespace UCB;

use WP_Error;
use wpdb;

/**
 * Data access layer for User Cards Bridge.
 */
class Database {
    /**
     * @var wpdb
     */
    protected $db;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Returns the capacity table name.
     */
    protected function capacity_table(): string {
        return $this->db->prefix . 'ucb_capacity_slots';
    }

    /**
     * Returns the reservations table name.
     */
    protected function reservations_table(): string {
        return $this->db->prefix . 'ucb_reservations';
    }

    /**
     * Returns the status logs table name.
     */
    protected function status_logs_table(): string {
        return $this->db->prefix . 'ucb_status_logs';
    }

    /**
     * Returns the sms logs table name.
     */
    protected function sms_logs_table(): string {
        return $this->db->prefix . 'ucb_sms_logs';
    }

    /**
     * Returns the general logs table name.
     */
    protected function logs_table(): string {
        return $this->db->prefix . 'ucb_logs';
    }

    /**
     * Returns the payment tokens table name.
     */
    protected function payment_tokens_table(): string {
        return $this->db->prefix . 'ucb_payment_tokens';
    }

    /**
     * Check if table exists in the database.
     */
    protected function table_exists(string $table): bool {
        $result = $this->db->get_var(
            $this->db->prepare('SHOW TABLES LIKE %s', $table)
        );

        return $result === $table;
    }

    /**
     * Ensure the general logs table exists.
     */
    public function ensure_logs_table(): void {
        if ($this->table_exists($this->logs_table())) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $this->db->get_charset_collate();
        $table = $this->logs_table();

        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext,
            user_id bigint(20) DEFAULT NULL,
            ip varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Fetch capacity matrix for the given supervisor/card pair.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_capacity_matrix(int $supervisor_id, int $card_id): array {
        $key = sprintf('ucb_capacity_%d_%d', $supervisor_id, $card_id);
        $cached = wp_cache_get($key, 'ucb');

        if (false !== $cached) {
            return $cached;
        }

        $query = $this->db->prepare(
            "SELECT weekday, hour, capacity FROM {$this->capacity_table()} WHERE supervisor_id = %d AND card_id = %d ORDER BY weekday, hour",
            $supervisor_id,
            $card_id
        );

        $rows = $this->db->get_results($query, ARRAY_A) ?: [];
        wp_cache_set($key, $rows, 'ucb', 30);

        return $rows;
    }

    /**
     * Upsert matrix rows.
     */
    public function upsert_capacity_matrix(int $supervisor_id, int $card_id, array $matrix): void {
        $table = $this->capacity_table();

        foreach ($matrix as $row) {
            $weekday = isset($row['weekday']) ? (int) $row['weekday'] : 0;
            $hour = isset($row['hour']) ? (int) $row['hour'] : 0;
            $capacity = isset($row['capacity']) ? (int) $row['capacity'] : 0;

            $this->db->replace(
                $table,
                [
                    'card_id'       => $card_id,
                    'supervisor_id' => $supervisor_id,
                    'weekday'       => $weekday,
                    'hour'          => $hour,
                    'capacity'      => $capacity,
                ],
                [
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                ]
            );
        }

        wp_cache_delete(sprintf('ucb_capacity_%d_%d', $supervisor_id, $card_id), 'ucb');
    }

    /**
     * Delete capacity rows for supervisor/card not in provided matrix.
     */
    public function prune_capacity_matrix(int $supervisor_id, int $card_id, array $matrix): void {
        $table = $this->capacity_table();
        $hash = [];

        foreach ($matrix as $row) {
            $weekday = isset($row['weekday']) ? (int) $row['weekday'] : 0;
            $hour = isset($row['hour']) ? (int) $row['hour'] : 0;
            $hash[] = sprintf('%d:%d', $weekday, $hour);
        }

        $placeholders = implode(',', array_fill(0, count($hash), '%s'));

        if (empty($hash)) {
            $this->db->delete(
                $table,
                [
                    'card_id'       => $card_id,
                    'supervisor_id' => $supervisor_id,
                ],
                [
                    '%d',
                    '%d',
                ]
            );
            return;
        }

        $sql = $this->db->prepare(
            "DELETE FROM {$table} WHERE supervisor_id = %d AND card_id = %d AND CONCAT(weekday, ':', hour) NOT IN ($placeholders)",
            array_merge([$supervisor_id, $card_id], $hash)
        );

        $this->db->query($sql);
        wp_cache_delete(sprintf('ucb_capacity_%d_%d', $supervisor_id, $card_id), 'ucb');
    }

    /**
     * Fetch reservations for a slot.
     */
    public function count_reservations_for_slot(int $card_id, int $weekday, int $hour): int {
        $query = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->reservations_table()} WHERE card_id = %d AND slot_weekday = %d AND slot_hour = %d",
            $card_id,
            $weekday,
            $hour
        );

        return (int) $this->db->get_var($query);
    }

    /**
     * Count reservations for a specific card, date, weekday and hour.
     */
    public function count_reservations_for_slot_on_date(int $card_id, string $date, int $weekday, int $hour): int {
        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->reservations_table()} WHERE card_id = %d AND reservation_date = %s AND slot_weekday = %d AND slot_hour = %d",
            $card_id,
            $date,
            $weekday,
            $hour
        );

        return (int) $this->db->get_var($sql);
    }

    /**
     * Create reservation entry.
     *
     * @param array<string, mixed> $data
     */
    public function create_reservation(array $data): int {
        $defaults = [
            'customer_id'     => 0,
            'card_id'         => 0,
            'supervisor_id'   => 0,
            'slot_weekday'    => 0,
            'slot_hour'       => 0,
            'reservation_date'=> null,
            'created_at'      => current_time('mysql'),
        ];

        $payload = wp_parse_args($data, $defaults);

        $payload['reservation_date'] = $payload['reservation_date'] ? sanitize_text_field($payload['reservation_date']) : null;

        if (empty($payload['reservation_date'])) {
            throw new \InvalidArgumentException('Reservation date is required.');
        }

        $this->db->insert(
            $this->reservations_table(),
            $payload,
            [
                '%d',
                '%d',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Get reservations with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function get_reservations(array $filters = [], int $page = 1, int $per_page = 20): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['card_id'])) {
            $where[] = 'card_id = %d';
            $params[] = (int) $filters['card_id'];
        }

        if (!empty($filters['supervisor_id'])) {
            $where[] = 'supervisor_id = %d';
            $params[] = (int) $filters['supervisor_id'];
        }

        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = %d';
            $params[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['reservation_date'])) {
            $where[] = 'reservation_date = %s';
            $params[] = sanitize_text_field($filters['reservation_date']);
        }

        $offset = max(0, ($page - 1) * $per_page);
        $limit_clause = $this->db->prepare('LIMIT %d OFFSET %d', $per_page, $offset);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY created_at DESC %s',
            $this->reservations_table(),
            implode(' AND ', $where),
            $limit_clause
        );

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, ...$params);
        }

        return $this->db->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Count reservations with filters for pagination.
     *
     * @param array<string, mixed> $filters
     */
    public function count_reservations(array $filters = []): int {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['card_id'])) {
            $where[] = 'card_id = %d';
            $params[] = (int) $filters['card_id'];
        }

        if (!empty($filters['supervisor_id'])) {
            $where[] = 'supervisor_id = %d';
            $params[] = (int) $filters['supervisor_id'];
        }

        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = %d';
            $params[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['reservation_date'])) {
            $where[] = 'reservation_date = %s';
            $params[] = sanitize_text_field($filters['reservation_date']);
        }

        if (!empty($filters['date'])) {
            $where[] = 'reservation_date = %s';
            $params[] = sanitize_text_field($filters['date']);
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = sanitize_text_field($filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = sanitize_text_field($filters['date_to']);
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->reservations_table(),
            implode(' AND ', $where)
        );

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, ...$params);
        }

        return (int) $this->db->get_var($sql);
    }

    /**
     * Insert status log.
     *
     * @param array<string, mixed> $data
     * @return int|WP_Error Insert ID on success or WP_Error on failure.
     */
    public function log_status_change(array $data) {
        $defaults = [
            'customer_id' => 0,
            'old_status'  => null,
            'new_status'  => '',
            'changed_by'  => 0,
            'reason'      => null,
            'created_at'  => current_time('mysql'),
            'meta'        => null,
        ];

        $parsed = wp_parse_args($data, $defaults);

        $columns = [
            'customer_id' => '%d',
            'old_status'  => '%s',
            'new_status'  => '%s',
            'changed_by'  => '%d',
            'reason'      => '%s',
            'created_at'  => '%s',
            'meta'        => '%s',
        ];

        $payload = [];
        $formats = [];

        foreach ($columns as $column => $format) {
            if (array_key_exists($column, $parsed)) {
                $value = $parsed[$column];

                if ('reason' === $column && ('' === $value || null === $value)) {
                    $value = null;
                }

                if ('meta' === $column) {
                    if (is_array($value) || is_object($value)) {
                        $value = wp_json_encode($value);
                    }

                    if ('' === $value) {
                        $value = null;
                    }
                }

                $payload[$column] = $value;
                $formats[] = $format;
            }
        }

        $result = $this->db->insert(
            $this->status_logs_table(),
            $payload,
            $formats
        );

        if (false === $result) {
            return new WP_Error(
                'ucb_status_log_failed',
                __('Failed to log status change.', 'user-cards-bridge'),
                [
                    'db_error' => $this->db->last_error,
                    'payload'  => $payload,
                ]
            );
        }

        return (int) $this->db->insert_id;
    }

    /**
     * Insert SMS log entry.
     *
     * @param array<string, mixed> $data
     */
    public function log_sms(array $data): int {
        $defaults = [
            'customer_id'   => null,
            'phone'         => '',
            'message'       => '',
            'body_id'       => null,
            'result_code'   => null,
            'result_message'=> null,
            'rec_id'        => null,
            'sent_by'       => 0,
            'created_at'    => current_time('mysql'),
        ];

        $payload = wp_parse_args($data, $defaults);

        $this->db->insert(
            $this->sms_logs_table(),
            $payload,
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
            ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Insert generic log entry.
     *
     * @param array<string, mixed> $data
     */
    public function insert_log(array $data): int {
        if (!$this->table_exists($this->logs_table())) {
            $this->ensure_logs_table();
        }

        $defaults = [
            'level'      => 'info',
            'message'    => '',
            'context'    => null,
            'user_id'    => null,
            'ip'         => '',
            'created_at' => current_time('mysql'),
        ];

        $payload = wp_parse_args($data, $defaults);
        $payload['level'] = strtolower((string) $payload['level']);
        $payload['context'] = empty($payload['context'])
            ? null
            : (is_string($payload['context']) ? $payload['context'] : wp_json_encode($payload['context']));

        $this->db->insert(
            $this->logs_table(),
            $payload,
            [
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
            ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Paginate general logs.
     *
     * @return array{logs: array<int, object>, total: int, total_pages: int}
     */
    public function get_logs(array $filters = [], int $page = 1, int $per_page = 20): array {
        if (!$this->table_exists($this->logs_table())) {
            return [
                'logs' => [],
                'total' => 0,
                'total_pages' => 0,
            ];
        }

        $page = max(1, $page);
        $per_page = max(1, $per_page);

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['level'])) {
            $where[] = 'level = %s';
            $params[] = sanitize_key($filters['level']);
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        $where_sql = implode(' AND ', $where);
        $total_sql = "SELECT COUNT(*) FROM {$this->logs_table()} WHERE {$where_sql}";

        $total = !empty($params)
            ? (int) $this->db->get_var($this->db->prepare($total_sql, ...$params))
            : (int) $this->db->get_var($total_sql);

        $offset = max(0, ($page - 1) * $per_page);
        $select_sql = "SELECT * FROM {$this->logs_table()} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $select_params = array_merge($params, [$per_page, $offset]);
        $query = $this->db->prepare($select_sql, ...$select_params);

        $logs = $this->db->get_results($query) ?: [];
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 0;

        return [
            'logs' => $logs,
            'total' => $total,
            'total_pages' => $total_pages,
        ];
    }

    /**
     * Cleanup logs and sms logs older than the retention period.
     *
     * @return array<string, int>
     */
    public function cleanup_logs(int $retention_days): array {
        $cutoff_timestamp = current_time('timestamp') - ($retention_days * DAY_IN_SECONDS);
        $cutoff = wp_date('Y-m-d H:i:s', $cutoff_timestamp);
        $deleted = [
            'logs' => 0,
            'sms_logs' => 0,
        ];

        if ($this->table_exists($this->logs_table())) {
            $deleted['logs'] = (int) $this->db->query(
                $this->db->prepare(
                    "DELETE FROM {$this->logs_table()} WHERE created_at < %s",
                    $cutoff
                )
            );
        }

        if ($this->table_exists($this->sms_logs_table())) {
            $deleted['sms_logs'] = (int) $this->db->query(
                $this->db->prepare(
                    "DELETE FROM {$this->sms_logs_table()} WHERE created_at < %s",
                    $cutoff
                )
            );
        }

        return $deleted;
    }

    /**
     * Aggregate SMS statistics.
     *
     * @return array<string, float|int>
     */
    public function get_sms_statistics(int $days = 7): array {
        $defaults = [
            'total_sent' => 0,
            'successful' => 0,
            'failed' => 0,
            'success_rate' => 0.0,
        ];

        if (!$this->table_exists($this->sms_logs_table())) {
            return $defaults;
        }

        $cutoff_timestamp = current_time('timestamp') - ($days * DAY_IN_SECONDS);
        $cutoff = wp_date('Y-m-d H:i:s', $cutoff_timestamp);

        $sql = $this->db->prepare(
            "SELECT result_code, COUNT(*) as total FROM {$this->sms_logs_table()} WHERE created_at >= %s GROUP BY result_code",
            $cutoff
        );

        $rows = $this->db->get_results($sql);
        if (empty($rows)) {
            return $defaults;
        }

        $stats = $defaults;
        foreach ($rows as $row) {
            $count = (int) $row->total;
            $stats['total_sent'] += $count;

            $result_code = strtolower((string) $row->result_code);
            if ('error' === $result_code) {
                $stats['failed'] += $count;
            } else {
                $stats['successful'] += $count;
            }
        }

        if ($stats['total_sent'] > 0) {
            $stats['success_rate'] = round(
                ($stats['successful'] / $stats['total_sent']) * 100,
                2
            );
        }

        return $stats;
    }

    /**
     * Aggregate log statistics.
     *
     * @return array{total_logs:int,status_changes:array<string,int>}
     */
    public function get_log_statistics(int $days = 7): array {
        $stats = [
            'total_logs' => 0,
            'status_changes' => [],
        ];

        $cutoff_timestamp = current_time('timestamp') - ($days * DAY_IN_SECONDS);
        $cutoff = wp_date('Y-m-d H:i:s', $cutoff_timestamp);

        if ($this->table_exists($this->logs_table())) {
            $sql = $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->logs_table()} WHERE created_at >= %s",
                $cutoff
            );
            $stats['total_logs'] = (int) $this->db->get_var($sql);
        }

        if ($this->table_exists($this->status_logs_table())) {
            $sql = $this->db->prepare(
                "SELECT new_status, COUNT(*) as total 
                 FROM {$this->status_logs_table()}
                 WHERE created_at >= %s
                 GROUP BY new_status",
                $cutoff
            );

            $rows = $this->db->get_results($sql);
            foreach ((array) $rows as $row) {
                $status = (string) $row->new_status;
                $stats['status_changes'][$status] = (int) $row->total;
            }
        }

        return $stats;
    }

    /**
     * Paginate logs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_status_logs(array $filters = [], int $page = 1, int $per_page = 20): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = %d';
            $params[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['changed_by'])) {
            $where[] = 'changed_by = %d';
            $params[] = (int) $filters['changed_by'];
        }

        $offset = max(0, ($page - 1) * $per_page);
        $limit_clause = $this->db->prepare('LIMIT %d OFFSET %d', $per_page, $offset);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY created_at DESC %s',
            $this->status_logs_table(),
            implode(' AND ', $where),
            $limit_clause
        );

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, ...$params);
        }

        return $this->db->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Paginate SMS logs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_sms_logs(array $filters = [], int $page = 1, int $per_page = 20): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = %d';
            $params[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['phone'])) {
            $where[] = 'phone = %s';
            $params[] = sanitize_text_field($filters['phone']);
        }

        if (!empty($filters['status'])) {
            $status = sanitize_key($filters['status']);
            if ('sent' === $status) {
                $where[] = "(result_code IS NULL OR result_code <> 'error')";
            } elseif ('failed' === $status) {
                $where[] = "(result_code = 'error')";
            }
        }

        if (!empty($filters['search'])) {
            $search = '%' . $this->db->esc_like(sanitize_text_field($filters['search'])) . '%';
            $where[] = '(phone LIKE %s OR message LIKE %s OR result_message LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $offset = max(0, ($page - 1) * $per_page);
        $limit_clause = $this->db->prepare('LIMIT %d OFFSET %d', $per_page, $offset);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY created_at DESC %s',
            $this->sms_logs_table(),
            implode(' AND ', $where),
            $limit_clause
        );

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, ...$params);
        }

        return $this->db->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Count SMS logs with filters for pagination.
     */
    public function count_sms_logs(array $filters = []): int {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = %d';
            $params[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['phone'])) {
            $where[] = 'phone = %s';
            $params[] = sanitize_text_field($filters['phone']);
        }

        if (!empty($filters['status'])) {
            $status = sanitize_key($filters['status']);
            if ('sent' === $status) {
                $where[] = "(result_code IS NULL OR result_code <> 'error')";
            } elseif ('failed' === $status) {
                $where[] = "(result_code = 'error')";
            }
        }

        if (!empty($filters['search'])) {
            $search = '%' . $this->db->esc_like(sanitize_text_field($filters['search'])) . '%';
            $where[] = '(phone LIKE %s OR message LIKE %s OR result_message LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->sms_logs_table(),
            implode(' AND ', $where)
        );

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, ...$params);
        }

        return (int) $this->db->get_var($sql);
    }

    /**
     * Persist payment token.
     *
     * @param array<string, mixed> $data
     */
    public function store_payment_token(array $data): int {
        $defaults = [
            'order_id'     => 0,
            'customer_id'  => 0,
            'token'        => '',
            'expires_at'   => current_time('mysql'),
            'payload'      => null,
            'created_at'   => current_time('mysql'),
            'consumed_at'  => null,
        ];

        $payload = wp_parse_args($data, $defaults);

        $this->db->insert(
            $this->payment_tokens_table(),
            $payload,
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Retrieve payment token row.
     */
    public function get_payment_token(string $token): ?array {
        $query = $this->db->prepare(
            "SELECT * FROM {$this->payment_tokens_table()} WHERE token = %s",
            $token
        );

        $row = $this->db->get_row($query, ARRAY_A);

        return $row ?: null;
    }

    /**
     * Mark payment token consumed.
     */
    public function consume_payment_token(string $token): void {
        $this->db->update(
            $this->payment_tokens_table(),
            [
                'consumed_at' => current_time('mysql'),
            ],
            [
                'token' => $token,
            ],
            [
                '%s',
            ],
            [
                '%s',
            ]
        );
    }

    /**
     * Cleanup expired tokens.
     */
    public function delete_expired_tokens(): void {
        $now = current_time('mysql');
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->payment_tokens_table()} WHERE expires_at < %s",
                $now
            )
        );
    }
}
