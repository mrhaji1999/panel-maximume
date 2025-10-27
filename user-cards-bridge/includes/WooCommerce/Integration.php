<?php

namespace UCB\WooCommerce;

use UCB\Services\PaymentTokenService;
use UCB\Services\StatusManager;
use WP_Error;

/**
 * Handles WooCommerce integration for upsell orders.
 */
class Integration {
    /**
     * @var PaymentTokenService
     */
    protected $tokens;

    /**
     * @var StatusManager
     */
    protected $status_manager;

    public function __construct() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $this->tokens = new PaymentTokenService();
        $this->status_manager = new StatusManager();

        add_action('woocommerce_payment_complete', [$this, 'handle_payment_complete']);
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_status_change'], 10, 2);
        add_action('woocommerce_order_status_processing', [$this, 'handle_order_status_change'], 10, 2);
        add_action('template_redirect', [$this, 'validate_payment_token']);
    }

    /**
     * Creates order for upsell.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function create_upsell_order(array $args) {
        if (!class_exists('\WC_Order')) {
            return new WP_Error('ucb_wc_missing', __('WooCommerce is not installed.', 'user-cards-bridge'));
        }

        $customer_id = (int) ($args['customer_id'] ?? 0);
        $card_id = (int) ($args['card_id'] ?? 0);
        $field_key = sanitize_text_field($args['field_key'] ?? '');
        $label = sanitize_text_field($args['label'] ?? __('Upsell Item', 'user-cards-bridge'));
        $amount = floatval($args['amount'] ?? 0);

        if ($amount <= 0) {
            return new WP_Error('ucb_invalid_amount', __('Invalid amount.', 'user-cards-bridge'));
        }

        $order = wc_create_order([
            'status'      => 'pending',
            'customer_id' => $customer_id,
        ]);

        if (is_wp_error($order)) {
            return $order;
        }

        $fee = new \WC_Order_Item_Fee();
        $fee->set_name($label);
        $fee->set_amount($amount);
        $fee->set_total($amount);
        $order->add_item($fee);

        $order->set_total($amount);
        $order->update_meta_data('_ucb_customer_id', $customer_id);
        $order->update_meta_data('_ucb_card_id', $card_id);
        $order->update_meta_data('_ucb_field_key', $field_key);
        $order->save();

        $token_data = $this->tokens->create_token($order->get_id(), $customer_id, [
            'card_id'   => $card_id,
            'field_key' => $field_key,
            'amount'    => $amount,
        ]);

        $order->update_meta_data('_ucb_payment_token', $token_data['token']);
        $order->save_meta_data();

        $payment_url = $order->get_checkout_payment_url(true);
        $payment_url = add_query_arg('ucb_token', $token_data['token'], $payment_url);

        return [
            'order_id'   => $order->get_id(),
            'order_key'  => $order->get_order_key(),
            'pay_link'   => $payment_url,
            'token'      => $token_data['token'],
            'expires_at' => $token_data['expires_at'],
        ];
    }

    /**
     * Validates token to protect direct payment link.
     */
    public function validate_payment_token(): void {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }

        $token = isset($_GET['ucb_token']) ? sanitize_text_field(wp_unslash($_GET['ucb_token'])) : '';
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (empty($token) || empty($order_key)) {
            return;
        }

        $order_id = wc_get_order_id_by_order_key($order_key);

        if (!$order_id) {
            wp_die(__('Invalid order.', 'user-cards-bridge'), 403);
        }

        $validation = $this->tokens->validate_token($token, (int) $order_id);

        if (is_wp_error($validation)) {
            wp_die($validation->get_error_message(), 403);
        }
    }

    /**
     * WooCommerce hook when payment complete fired.
     */
    public function handle_payment_complete(int $order_id): void {
        $this->mark_order_paid($order_id);
    }

    /**
     * Sync status on manual status change.
     */
    public function handle_order_status_change(int $order_id, $order): void {
        $this->mark_order_paid($order_id);
    }

    /**
     * Updates status once payment succeeds.
     */
    protected function mark_order_paid(int $order_id): void {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $customer_id = (int) $order->get_meta('_ucb_customer_id');
        $token = (string) $order->get_meta('_ucb_payment_token');

        if ($token) {
            $this->tokens->consume($token);
        }

        if ($customer_id > 0) {
            $card_id = (int) $order->get_meta('_ucb_card_id');
            $current_status = get_user_meta($customer_id, 'ucb_customer_status', true);
            if ('upsell_paid' !== $current_status) {
                $this->status_manager->change_status($customer_id, 'upsell_paid', get_current_user_id() ?: 0, [], $card_id ?: null);
            }
        }
    }

    /**
     * Summarise upsell orders within the provided window.
     *
     * @return array<string, float|int>
     */
    public static function get_upsell_statistics(int $days = 7): array {
        $defaults = [
            'total_orders' => 0,
            'completed_orders' => 0,
            'failed_orders' => 0,
            'total_revenue' => 0.0,
        ];

        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders') || !function_exists('wc_get_order_statuses')) {
            return $defaults;
        }

        $timestamp = current_time('timestamp', true) - ($days * DAY_IN_SECONDS);
        $after = gmdate('Y-m-d H:i:s', $timestamp);

        $args = [
            'limit' => -1,
            'status' => array_keys(wc_get_order_statuses()),
            'meta_key' => '_ucb_payment_token',
            'meta_compare' => 'EXISTS',
            'date_created' => '>=' . $after,
            'return' => 'ids',
        ];

        $order_ids = wc_get_orders($args);
        if (is_wp_error($order_ids) || empty($order_ids)) {
            return $defaults;
        }

        $stats = $defaults;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            $stats['total_orders']++;

            if ($order->has_status(['completed', 'processing'])) {
                $stats['completed_orders']++;
            } elseif ($order->has_status(['failed', 'cancelled', 'refunded'])) {
                $stats['failed_orders']++;
            }

            $stats['total_revenue'] += (float) $order->get_total();
        }

        $stats['total_revenue'] = round($stats['total_revenue'], 2);

        return $stats;
    }
}
