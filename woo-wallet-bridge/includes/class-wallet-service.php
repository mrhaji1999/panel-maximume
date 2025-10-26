<?php

namespace WWB;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use WP_Error;

class WalletService {
    protected $wpdb;
    protected $codes_table;
    protected $transactions_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->codes_table = $this->wpdb->prefix . 'wwb_wallet_codes';
        $this->transactions_table = $this->wpdb->prefix . 'wwb_wallet_transactions';
    }

    public function upsert_code(array $data) {
        $code = sanitize_text_field($data['code'] ?? '');
        if ($code === '') {
            return new WP_Error('wwb_invalid_code', __('Wallet code is required.', 'woo-wallet-bridge'));
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        if ($amount <= 0) {
            return new WP_Error('wwb_invalid_amount', __('Wallet amount must be greater than zero.', 'woo-wallet-bridge'));
        }

        $type = in_array($data['type'] ?? 'wallet', ['wallet', 'coupon'], true) ? $data['type'] : 'wallet';
        $status = in_array($data['status'] ?? 'unused', ['unused', 'used', 'expired'], true) ? $data['status'] : 'unused';
        $user_id = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $expires_at = $this->normalize_datetime($data['expires_at'] ?? null);

        if (!$expires_at) {
            $default_expiry_days = (int) get_option('wwb_default_code_expiry_days', 0);
            if ($default_expiry_days > 0) {
                $expires_at = gmdate('Y-m-d H:i:s', time() + ($default_expiry_days * DAY_IN_SECONDS));
            }
        }

        $meta = isset($data['meta']) ? wp_json_encode($data['meta']) : null;

        $result = $this->wpdb->replace(
            $this->codes_table,
            [
                'code' => $code,
                'amount' => $amount,
                'type' => $type,
                'status' => $status,
                'user_id' => $user_id ?: null,
                'created_at' => current_time('mysql'),
                'expires_at' => $expires_at,
                'meta' => $meta,
            ],
            [
                '%s', '%f', '%s', '%s', '%d', '%s', '%s', '%s'
            ]
        );

        if ($result === false) {
            return new WP_Error('wwb_db_error', __('Failed to store wallet code.', 'woo-wallet-bridge'));
        }

        return [
            'code' => $code,
            'amount' => $amount,
            'expires_at' => $expires_at,
            'status' => $status,
        ];
    }

    public function redeem_code(string $code, int $user_id) {
        $code = sanitize_text_field($code);
        if ($code === '') {
            return new WP_Error('wwb_invalid_code', __('Wallet code is required.', 'woo-wallet-bridge'));
        }

        $rate_limit = $this->check_redeem_rate_limit($user_id);
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->codes_table} WHERE code = %s", $code));
        if (!$row) {
            return new WP_Error('wwb_code_not_found', __('Wallet code not found.', 'woo-wallet-bridge'));
        }

        if ($row->status !== 'unused') {
            return new WP_Error('wwb_code_used', __('This wallet code has already been used.', 'woo-wallet-bridge'));
        }

        if (!empty($row->expires_at)) {
            $expires = strtotime($row->expires_at . ' UTC');
            if ($expires !== false && $expires < time()) {
                $this->wpdb->update($this->codes_table, ['status' => 'expired'], ['id' => $row->id]);
                return new WP_Error('wwb_code_expired', __('This wallet code has expired.', 'woo-wallet-bridge'));
            }
        }

        $amount = (float) $row->amount;
        $this->wpdb->update(
            $this->codes_table,
            [
                'status' => 'used',
                'used_at' => current_time('mysql'),
                'user_id' => $user_id,
            ],
            ['id' => $row->id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        $balance = $this->credit_wallet($user_id, $amount, __('Wallet code redemption', 'woo-wallet-bridge'), ['code' => $code]);

        if (is_wp_error($balance)) {
            return $balance;
        }

        return [
            'code' => $code,
            'amount' => $amount,
            'balance' => $balance,
        ];
    }

    public function credit_wallet(int $user_id, float $amount, string $note = '', array $meta = [], int $order_id = 0) {
        if ($amount <= 0) {
            return new WP_Error('wwb_invalid_credit', __('Credit amount must be positive.', 'woo-wallet-bridge'));
        }

        $current = (float) get_user_meta($user_id, '_wwb_wallet_balance', true);
        $new_balance = round($current + $amount, 2);
        update_user_meta($user_id, '_wwb_wallet_balance', $new_balance);

        $this->log_transaction($user_id, $amount, 'credit', $note, $order_id, $meta);

        return $new_balance;
    }

    public function debit_wallet(int $user_id, float $amount, string $note = '', array $meta = [], int $order_id = 0) {
        $amount = abs($amount);
        if ($amount <= 0) {
            return new WP_Error('wwb_invalid_debit', __('Debit amount must be positive.', 'woo-wallet-bridge'));
        }

        $current = (float) get_user_meta($user_id, '_wwb_wallet_balance', true);
        if ($current < $amount) {
            return new WP_Error('wwb_insufficient_funds', __('Insufficient wallet balance.', 'woo-wallet-bridge'));
        }

        $new_balance = round($current - $amount, 2);
        update_user_meta($user_id, '_wwb_wallet_balance', $new_balance);

        $this->log_transaction($user_id, -$amount, 'debit', $note, $order_id, $meta);

        return $new_balance;
    }

    public function get_balance(int $user_id): float {
        return (float) get_user_meta($user_id, '_wwb_wallet_balance', true);
    }

    public function get_codes(array $args = []): array {
        $defaults = [
            'status' => '',
            'search' => '',
            'user_id' => 0,
            'paged' => 1,
            'per_page' => 20,
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = max(0, ($args['paged'] - 1) * $args['per_page']);

        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['search'])) {
            $where .= ' AND code LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($args['search']) . '%';
        }

        if (!empty($args['user_id'])) {
            $where .= ' AND user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->codes_table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = (int) $args['per_page'];
        $params[] = (int) $offset;

        $prepared = $this->wpdb->prepare($sql, $params);
        $items = $this->wpdb->get_results($prepared);
        $total = (int) $this->wpdb->get_var('SELECT FOUND_ROWS()');

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $args['per_page'] > 0 ? ceil($total / $args['per_page']) : 1,
        ];
    }

    public function get_transactions(array $args = []): array {
        $defaults = [
            'user_id' => 0,
            'paged' => 1,
            'per_page' => 20,
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = max(0, ($args['paged'] - 1) * $args['per_page']);

        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($args['user_id'])) {
            $where .= ' AND user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->transactions_table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = (int) $args['per_page'];
        $params[] = (int) $offset;
        $prepared = $this->wpdb->prepare($sql, $params);
        $items = $this->wpdb->get_results($prepared);
        $total = (int) $this->wpdb->get_var('SELECT FOUND_ROWS()');

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $args['per_page'] > 0 ? ceil($total / $args['per_page']) : 1,
        ];
    }

    protected function log_transaction(int $user_id, float $amount, string $type, string $note, int $order_id, array $meta = []): void {
        $this->wpdb->insert(
            $this->transactions_table,
            [
                'user_id' => $user_id,
                'type' => $type,
                'amount' => $amount,
                'note' => $note,
                'order_id' => $order_id ?: null,
                'created_at' => current_time('mysql'),
                'meta' => !empty($meta) ? wp_json_encode($meta) : null,
            ],
            ['%d', '%s', '%f', '%s', '%d', '%s', '%s']
        );
    }

    protected function normalize_datetime($value): ?string {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            $dt = new DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $exception) {
            return null;
        }
    }

    protected function check_redeem_rate_limit(int $user_id) {
        $window = max(30, (int) get_option('wwb_rate_limit_window', 300));
        $max = max(1, (int) get_option('wwb_rate_limit_max', 5));

        $key = 'wwb_rl_' . $user_id;
        $record = get_transient($key);
        $now = time();

        if (!is_array($record) || ($now - ($record['start'] ?? 0)) > $window) {
            $record = [
                'start' => $now,
                'count' => 0,
            ];
        }

        if ($record['count'] >= $max) {
            return new WP_Error('wwb_rate_limited', __('Too many redemption attempts. Please try again later.', 'woo-wallet-bridge'), ['status' => 429]);
        }

        $record['count']++;
        set_transient($key, $record, $window);

        return true;
    }
}
