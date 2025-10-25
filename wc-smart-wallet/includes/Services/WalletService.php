<?php

namespace WCW\Services;

use WCW\Database;
use WP_Error;

class WalletService {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function register_hooks(): void {
        add_action('wcw_cleanup_expired_codes', [$this, 'cleanup_expired_codes']);
        add_action('woocommerce_payment_complete', [$this, 'handle_wallet_order']);
        add_action('woocommerce_order_status_processing', [$this, 'handle_wallet_order']);
        add_action('woocommerce_order_status_completed', [$this, 'handle_wallet_order']);
    }

    public function get_account(int $user_id): ?object {
        global $wpdb;
        $table = $this->db->table_accounts();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", $user_id));
    }

    public function get_balance(int $user_id): float {
        $account = $this->get_account($user_id);
        return $account ? (float) $account->balance : 0.0;
    }

    public function credit(int $user_id, float $amount, string $currency, string $source, string $source_ref = '', string $note = '') {
        if ($amount <= 0) {
            return new WP_Error('wcw_invalid_amount', __('Amount must be positive.', 'wc-smart-wallet'));
        }

        global $wpdb;
        $accounts = $this->db->table_accounts();
        $transactions = $this->db->table_transactions();

        $wpdb->query('START TRANSACTION');

        $insert = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$accounts} (user_id, balance, currency, created_at, updated_at) VALUES (%d, %f, %s, NOW(), NOW())
            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), currency = VALUES(currency), updated_at = NOW()",
            $user_id,
            $amount,
            $currency
        ));

        if ($insert === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wcw_db_error', __('Failed to update wallet balance.', 'wc-smart-wallet'));
        }

        $balance = (float) $wpdb->get_var($wpdb->prepare("SELECT balance FROM {$accounts} WHERE user_id = %d", $user_id));

        $tx = $wpdb->insert(
            $transactions,
            [
                'user_id' => $user_id,
                'type' => 'credit',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $balance,
                'source' => $source,
                'source_ref' => $source_ref,
                'note' => $note,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s']
        );

        if ($tx === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wcw_tx_error', __('Failed to record transaction.', 'wc-smart-wallet'));
        }

        $wpdb->query('COMMIT');

        return [
            'balance' => $balance,
        ];
    }

    public function debit(int $user_id, float $amount, string $currency, string $source, string $source_ref = '', string $note = '') {
        if ($amount <= 0) {
            return new WP_Error('wcw_invalid_amount', __('Amount must be positive.', 'wc-smart-wallet'));
        }

        global $wpdb;
        $accounts = $this->db->table_accounts();
        $transactions = $this->db->table_transactions();

        $wpdb->query('START TRANSACTION');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$accounts} SET balance = balance - %f, updated_at = NOW() WHERE user_id = %d AND balance >= %f",
            $amount,
            $user_id,
            $amount
        ));

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wcw_db_error', __('Failed to update wallet balance.', 'wc-smart-wallet'));
        }

        if ($wpdb->rows_affected === 0) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wcw_insufficient_balance', __('Insufficient wallet balance.', 'wc-smart-wallet'));
        }

        $balance = (float) $wpdb->get_var($wpdb->prepare("SELECT balance FROM {$accounts} WHERE user_id = %d", $user_id));

        $tx = $wpdb->insert(
            $transactions,
            [
                'user_id' => $user_id,
                'type' => 'debit',
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $balance,
                'source' => $source,
                'source_ref' => $source_ref,
                'note' => $note,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%f', '%s', '%f', '%s', '%s', '%s', '%s']
        );

        if ($tx === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wcw_tx_error', __('Failed to record transaction.', 'wc-smart-wallet'));
        }

        $wpdb->query('COMMIT');

        return ['balance' => $balance];
    }

    public function redeem_code(int $user_id, string $code) {
        global $wpdb;
        $codes_table = $this->db->table_codes();
        $code = trim($code);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$codes_table} WHERE code = %s", $code));

        if (!$row) {
            return new WP_Error('wcw_code_not_found', __('Code not found.', 'wc-smart-wallet'));
        }

        if ($row->status !== 'new') {
            return new WP_Error('wcw_code_used', __('Code has already been used.', 'wc-smart-wallet'));
        }

        if (!empty($row->expires_at) && strtotime($row->expires_at) < current_time('timestamp', true)) {
            return new WP_Error('wcw_code_expired', __('Code has expired.', 'wc-smart-wallet'));
        }

        if (!empty($row->user_email)) {
            $user = get_userdata($user_id);
            if (!$user || strtolower($user->user_email) !== strtolower($row->user_email)) {
                return new WP_Error('wcw_code_email_mismatch', __('This code is restricted to another email.', 'wc-smart-wallet'));
            }
        }

        $credit = $this->credit($user_id, (float) $row->amount, $row->currency, 'code', $row->code, __('Wallet code redemption', 'wc-smart-wallet'));
        if (is_wp_error($credit)) {
            return $credit;
        }

        $wpdb->update(
            $codes_table,
            [
                'status' => 'redeemed',
                'redeemed_at' => current_time('mysql', true),
                'user_id' => $user_id,
            ],
            ['id' => $row->id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        return $credit;
    }

    public function upsert_code(array $data) {
        global $wpdb;
        $codes_table = $this->db->table_codes();

        $defaults = [
            'code' => '',
            'amount' => 0.0,
            'currency' => 'IRR',
            'status' => 'new',
            'issued_at' => current_time('mysql', true),
            'expires_at' => null,
            'user_email' => null,
            'meta' => null,
        ];
        $payload = wp_parse_args($data, $defaults);
        $status = strtolower((string) $payload['status']);
        if (!in_array($status, ['new', 'redeemed', 'expired', 'blocked'], true)) {
            $status = 'new';
        }
        $payload['status'] = $status;

        $meta = isset($payload['meta']) && is_array($payload['meta']) ? wp_json_encode($payload['meta']) : null;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$codes_table} (code, amount, currency, status, issued_at, expires_at, user_email, meta)
            VALUES (%s, %f, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency), status = VALUES(status), expires_at = VALUES(expires_at), user_email = VALUES(user_email), meta = VALUES(meta)",
            $payload['code'],
            $payload['amount'],
            $payload['currency'],
            $payload['status'],
            $payload['issued_at'],
            $payload['expires_at'],
            $payload['user_email'],
            $meta
        ));

        if ($result === false) {
            return new WP_Error('wcw_code_upsert_failed', __('Failed to save wallet code.', 'wc-smart-wallet'));
        }

        return true;
    }

    public function cleanup_expired_codes(): void {
        global $wpdb;
        $codes = $this->db->table_codes();
        $wpdb->query("UPDATE {$codes} SET status = 'expired' WHERE status = 'new' AND expires_at IS NOT NULL AND expires_at < NOW()");
    }

    public function handle_wallet_order($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }
        if (!$order->get_meta('_wcw_wallet_payment') || $order->get_meta('_wcw_wallet_debited')) {
            return;
        }

        $user_id = $order->get_user_id();
        if ($user_id <= 0) {
            return;
        }

        $amount = (float) $order->get_total();
        if ($amount <= 0) {
            return;
        }

        $currency = $order->get_currency();
        $result = $this->debit($user_id, $amount, $currency, 'order', (string) $order->get_id(), __('Wallet payment for order', 'wc-smart-wallet'));
        if (!is_wp_error($result)) {
            $order->add_order_note(__('Wallet balance deducted for this order.', 'wc-smart-wallet'));
            $order->update_meta_data('_wcw_wallet_debited', 1);
            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Wallet debit failed: %s', 'wc-smart-wallet'), $result->get_error_message()));
        }
    }
}
