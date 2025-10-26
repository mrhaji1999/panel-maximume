<?php

namespace WWB\Frontend;

use WWB\WalletService;

class Topup {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_action('woocommerce_account_wallet-topup_endpoint', [$this, 'render_topup_page']);
        add_action('template_redirect', [$this, 'handle_topup_submission']);
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_topup_fee'], 5);
        add_action('woocommerce_checkout_create_order', [$this, 'attach_order_meta'], 15, 2);
        add_action('woocommerce_before_checkout_form', [$this, 'maybe_show_notice']);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_credit_wallet']);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_credit_wallet']);
    }

    protected function get_topup_amount(): float {
        if (!function_exists('WC')) {
            return 0.0;
        }

        return (float) WC()->session->get('wwb_topup_amount', 0);
    }

    protected function set_topup_amount(float $amount): void {
        if (!function_exists('WC')) {
            return;
        }

        if ($amount <= 0) {
            WC()->session->set('wwb_topup_amount', null);
        } else {
            WC()->session->set('wwb_topup_amount', $amount);
        }
    }

    public function render_topup_page(): void {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Please log in to top up your wallet.', 'woo-wallet-bridge') . '</p>';
            return;
        }

        $balance = $this->service->get_balance(get_current_user_id());
        $multiplier_enabled = (int) get_option('wwb_multiplier_enabled', 1);
        $multiplier_value = (float) get_option('wwb_multiplier_value', 2.0);

        echo '<div class="wwb-topup">';
        echo '<h2>' . esc_html__('Wallet Top-up', 'woo-wallet-bridge') . '</h2>';
        echo '<p>' . sprintf(esc_html__('Current balance: %s', 'woo-wallet-bridge'), wp_kses_post(wc_price($balance))) . '</p>';

        if ($multiplier_enabled) {
            echo '<p class="wwb-topup-info">' . sprintf(esc_html__('Top-up payments are multiplied by %s after confirmation.', 'woo-wallet-bridge'), esc_html($multiplier_value)) . '</p>';
        }

        echo '<form method="post" class="wwb-topup-form">';
        wp_nonce_field('wwb_wallet_topup', 'wwb_wallet_topup_nonce');
        echo '<p><label for="wwb_topup_amount">' . esc_html__('Amount', 'woo-wallet-bridge') . '</label> ';
        echo '<input type="number" step="0.01" min="1" id="wwb_topup_amount" name="wwb_topup_amount" required /></p>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Proceed to Checkout', 'woo-wallet-bridge') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public function handle_topup_submission(): void {
        if (!is_user_logged_in() || !function_exists('WC')) {
            return;
        }

        if (isset($_POST['wwb_wallet_topup_nonce']) && wp_verify_nonce($_POST['wwb_wallet_topup_nonce'], 'wwb_wallet_topup')) {
            $amount = isset($_POST['wwb_topup_amount']) ? floatval($_POST['wwb_topup_amount']) : 0;
            if ($amount <= 0) {
                wc_add_notice(__('Please enter a valid top-up amount.', 'woo-wallet-bridge'), 'error');
                return;
            }

            WC()->cart->empty_cart();
            $this->set_topup_amount($amount);
            WC()->session->set('wwb_topup_active', 'yes');
            wc_add_notice(__('Top-up amount added to checkout.', 'woo-wallet-bridge'), 'success');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    public function apply_topup_fee(): void {
        if (!function_exists('WC')) {
            return;
        }

        $amount = $this->get_topup_amount();
        if ($amount <= 0) {
            return;
        }

        WC()->cart->add_fee(__('Wallet Top-up', 'woo-wallet-bridge'), $amount, false);
    }

    public function maybe_show_notice(): void {
        if (function_exists('WC') && 'yes' === WC()->session->get('wwb_topup_active') && ($amount = $this->get_topup_amount()) > 0) {
            wc_print_notice(sprintf(__('Wallet top-up of %s is pending payment.', 'woo-wallet-bridge'), wc_price($amount)), 'notice');
        }
    }

    public function attach_order_meta($order, $data): void {
        if (!function_exists('WC')) {
            return;
        }

        $amount = $this->get_topup_amount();
        if ($amount <= 0) {
            return;
        }

        $order->update_meta_data('_wwb_topup_amount', $amount);
        $order->update_meta_data('_wwb_topup_user', get_current_user_id());
        WC()->session->set('wwb_topup_active', null);
        $this->set_topup_amount(0);
    }

    public function maybe_credit_wallet($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ('yes' === $order->get_meta('_wwb_topup_credited')) {
            return;
        }

        $amount = (float) $order->get_meta('_wwb_topup_amount');
        $user_id = (int) $order->get_meta('_wwb_topup_user');

        if ($amount <= 0 || $user_id <= 0) {
            return;
        }

        $multiplier_enabled = (int) get_option('wwb_multiplier_enabled', 1);
        $multiplier_value = (float) get_option('wwb_multiplier_value', 2.0);
        $credit = $multiplier_enabled ? $amount * $multiplier_value : $amount;

        $result = $this->service->credit_wallet($user_id, $credit, __('Wallet top-up payment', 'woo-wallet-bridge'), ['order_id' => $order_id], $order_id);
        if (!is_wp_error($result)) {
            $order->update_meta_data('_wwb_topup_credited', 'yes');
            $order->save_meta_data();
        }
    }
}
