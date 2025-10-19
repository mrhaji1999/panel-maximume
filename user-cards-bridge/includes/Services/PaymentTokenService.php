<?php

namespace UCB\Services;

use WP_Error;

class PaymentTokenService {

    /**
     * Creates a new payment token for the provided order/customer pair.
     *
     * @param int                   $order_id      WooCommerce order identifier.
     * @param int                   $customer_id   Related WordPress user ID.
     * @param array<string, mixed>  $payload       Additional metadata to persist with the token.
     * @param int|null              $expiry_hours  Token lifetime in hours. Null falls back to option value.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function create_token(int $order_id, int $customer_id, array $payload = [], ?int $expiry_hours = null) {
        if ($order_id <= 0 || $customer_id <= 0) {
            return new WP_Error(
                'token_invalid_arguments',
                __('Unable to create payment token with missing order or customer.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }

        if (null === $expiry_hours) {
            $expiry_hours = (int) get_option('ucb_payment_token_expiry', 24);
        }

        if ($expiry_hours <= 0) {
            $expiry_hours = 24;
        }

        $token = $this->create_unique_token();
        $expires_at = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + ($expiry_hours * HOUR_IN_SECONDS));

        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';

        $payload_data = [
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'metadata' => $payload,
            'created_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert(
            $table,
            [
                'order_id' => $order_id,
                'customer_id' => $customer_id,
                'token' => $token,
                'expires_at' => $expires_at,
                'payload' => wp_json_encode($payload_data, JSON_UNESCAPED_UNICODE),
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );

        if (!$result) {
            return new WP_Error(
                'token_creation_failed',
                __('Failed to create payment token.', UCB_TEXT_DOMAIN),
                ['status' => 500]
            );
        }

        \UCB\Logger::log('info', 'Payment token generated', [
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'token_id' => $wpdb->insert_id,
            'expires_at' => $expires_at,
        ]);

        return [
            'token' => $token,
            'expires_at' => $expires_at,
            'token_id' => $wpdb->insert_id,
        ];
    }

    /**
     * Backwards compatible alias for create_token().
     *
     * @param int $order_id
     * @param int $customer_id
     * @param int $expiry_hours
     *
     * @return array<string, mixed>|WP_Error
     */
    public function generate_token($order_id, $customer_id, $expiry_hours = 24) {
        return $this->create_token((int) $order_id, (int) $customer_id, [], (int) $expiry_hours);
    }
    
    /**
     * Validate payment token
     */
    public function validate_token($token, $expected_order_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';

        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE token = %s AND consumed_at IS NULL",
            $token
        ));

        if (!$token_data) {
            return new WP_Error(
                'invalid_token',
                __('Invalid or expired payment token.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }

        if (null !== $expected_order_id && (int) $token_data->order_id !== (int) $expected_order_id) {
            return new WP_Error(
                'token_order_mismatch',
                __('Token does not match the requested order.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }

        // Check if token has expired
        if (strtotime($token_data->expires_at) < current_time('timestamp', true)) {
            return new WP_Error(
                'token_expired',
                __('Payment token has expired.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }

        if (!empty($token_data->payload)) {
            $payload = json_decode($token_data->payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $token_data->payload = $payload;
            }
        }

        return $token_data;
    }

    /**
     * Consume payment token
     */
    public function consume_token($token) {
        $token_data = $this->validate_token($token);

        if (is_wp_error($token_data)) {
            return $token_data;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';
        
        $result = $wpdb->update(
            $table,
            ['consumed_at' => current_time('mysql')],
            ['id' => $token_data->id],
            ['%s'],
            ['%d']
        );
        
        if (!$result) {
            return new WP_Error(
                'token_consumption_failed',
                __('Failed to consume payment token.', UCB_TEXT_DOMAIN),
                ['status' => 500]
            );
        }
        
        \UCB\Logger::log('info', 'Payment token consumed', [
            'token_id' => $token_data->id,
            'order_id' => $token_data->order_id,
            'customer_id' => $token_data->customer_id,
        ]);
        
        return $token_data;
    }

    /**
     * Wrapper to keep backwards compatibility with legacy callers.
     *
     * @param string $token
     * @return mixed
     */
    public function consume($token) {
        return $this->consume_token($token);
    }
    
    /**
     * Get token by order ID
     */
    public function get_token_by_order($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND consumed_at IS NULL ORDER BY created_at DESC",
            $order_id
        ));
    }
    
    /**
     * Get active tokens for customer
     */
    public function get_customer_tokens($customer_id, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE customer_id = %d 
             AND consumed_at IS NULL 
             AND expires_at > NOW()
             ORDER BY created_at DESC 
             LIMIT %d",
            $customer_id, $limit
        ));
    }
    
    /**
     * Clean up expired tokens
     */
    public function cleanup_expired_tokens() {
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';
        
        $expired_tokens = $wpdb->get_results(
            "SELECT id FROM $table WHERE expires_at < NOW() AND consumed_at IS NULL"
        );
        
        $deleted_count = $wpdb->query(
            "DELETE FROM $table WHERE expires_at < NOW() AND consumed_at IS NULL"
        );
        
        if ($deleted_count > 0) {
            \UCB\Logger::log('info', 'Expired payment tokens cleaned up', [
                'deleted_count' => $deleted_count,
            ]);
        }
        
        return $deleted_count;
    }
    
    /**
     * Get token statistics
     */
    public function get_token_statistics($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_tokens,
                SUM(CASE WHEN consumed_at IS NOT NULL THEN 1 ELSE 0 END) as consumed_tokens,
                SUM(CASE WHEN consumed_at IS NULL AND expires_at > NOW() THEN 1 ELSE 0 END) as active_tokens,
                SUM(CASE WHEN consumed_at IS NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired_tokens
             FROM $table 
             WHERE created_at >= %s",
            $cutoff_date
        ));
        
        return [
            'total_tokens' => (int) $stats->total_tokens,
            'consumed_tokens' => (int) $stats->consumed_tokens,
            'active_tokens' => (int) $stats->active_tokens,
            'expired_tokens' => (int) $stats->expired_tokens,
            'consumption_rate' => $stats->total_tokens > 0 ? 
                round(($stats->consumed_tokens / $stats->total_tokens) * 100, 2) : 0,
        ];
    }
    
    /**
     * Create unique token
     */
    private function create_unique_token() {
        $max_attempts = 10;
        $attempts = 0;
        
        do {
            $token = bin2hex(random_bytes(32));
            $attempts++;
            
            // Check if token already exists
            global $wpdb;
            $table = $wpdb->prefix . 'ucb_payment_tokens';
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE token = %s",
                $token
            ));
            
            if (!$exists) {
                return $token;
            }
            
        } while ($attempts < $max_attempts);
        
        // Fallback to timestamp-based token if unique generation fails
        return 'ucb_' . time() . '_' . wp_generate_password(16, false);
    }
    
    /**
     * Generate payment URL with token
     */
    public function generate_payment_url($order_id, $customer_id, $expiry_hours = 24) {
        $token_result = $this->generate_token($order_id, $customer_id, $expiry_hours);
        
        if (is_wp_error($token_result)) {
            return $token_result;
        }
        
        $payment_url = add_query_arg([
            'ucb_payment_token' => $token_result['token'],
            'order_id' => $order_id,
        ], wc_get_checkout_url());
        
        return [
            'token' => $token_result['token'],
            'url' => $payment_url,
            'expires_at' => $token_result['expires_at'],
        ];
    }
    
    /**
     * Revoke token
     */
    public function revoke_token($token) {
        global $wpdb;
        $table = $wpdb->prefix . 'ucb_payment_tokens';
        
        $result = $wpdb->update(
            $table,
            ['consumed_at' => current_time('mysql')],
            ['token' => $token],
            ['%s'],
            ['%s']
        );
        
        if ($result) {
            \UCB\Logger::log('info', 'Payment token revoked', [
                'token' => $token,
                'revoked_by' => get_current_user_id(),
            ]);
        }
        
        return $result;
    }
}