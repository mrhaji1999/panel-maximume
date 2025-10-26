<?php

namespace WWB\Frontend;

use WWB\WalletService;

class Checkout {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_action('woocommerce_review_order_before_payment', [$this, 'render_checkout_wallet']);
        add_action('woocommerce_checkout_process', [$this, 'validate_wallet_usage']);
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_wallet_fee'], 20);
        add_action('woocommerce_checkout_create_order', [$this, 'attach_order_meta'], 20, 2);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_debit_wallet']);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_debit_wallet']);
    }

    protected function get_session_amount(): float {
        if (!function_exists('WC')) {
            return 0.0;
        }

        return (float) WC()->session->get('wwb_wallet_apply_amount', 0);
    }

    protected function set_session_amount(float $amount): void {
        if (!function_exists('WC')) {
            return;
        }

        if ($amount <= 0) {
            WC()->session->set('wwb_wallet_apply_amount', null);
        } else {
            WC()->session->set('wwb_wallet_apply_amount', $amount);
        }
    }

    public function render_checkout_wallet(): void {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $balance = $this->service->get_balance($user_id);
        $applied = $this->get_session_amount();

        echo '<div class="wwb-checkout-wallet">';
        echo '<h3>' . esc_html__('Wallet', 'woo-wallet-bridge') . '</h3>';
        echo '<p>' . sprintf(esc_html__('Available balance: %s', 'woo-wallet-bridge'), wp_kses_post(wc_price($balance))) . '</p>';
        echo '<form method="post" class="wwb-wallet-use">';
        wp_nonce_field('wwb_wallet_use', 'wwb_wallet_use_nonce');
        echo '<p><label for="wwb_wallet_use_amount">' . esc_html__('Amount to use', 'woo-wallet-bridge') . '</label> ';
        printf('<input type="number" step="0.01" min="0" id="wwb_wallet_use_amount" name="wwb_wallet_use_amount" value="%s" />', esc_attr($applied));
        echo '</p>';
        echo '<p><button type="submit" class="button">' . esc_html__('Apply Wallet', 'woo-wallet-bridge') . '</button></p>';
        echo '</form>';

        echo '<form method="post" class="wwb-wallet-redeem">';
        wp_nonce_field('wwb_wallet_redeem', 'wwb_wallet_redeem_nonce');
        echo '<input type="hidden" name="wwb_wallet_context" value="checkout" />';
        echo '<p><label for="wwb_wallet_code_checkout">' . esc_html__('Have a wallet code?', 'woo-wallet-bridge') . '</label> ';
        echo '<input type="text" id="wwb_wallet_code_checkout" name="wwb_wallet_code" placeholder="' . esc_attr__('Enter code', 'woo-wallet-bridge') . '" /> ';
        echo '<button type="submit" class="button button-secondary">' . esc_html__('Redeem', 'woo-wallet-bridge') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public function validate_wallet_usage(): void {
        if (!is_user_logged_in() || !isset($_POST['wwb_wallet_use_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['wwb_wallet_use_nonce'], 'wwb_wallet_use')) {
            wc_add_notice(__('Unable to verify wallet usage request.', 'woo-wallet-bridge'), 'error');
            return;
        }

        $amount = isset($_POST['wwb_wallet_use_amount']) ? floatval($_POST['wwb_wallet_use_amount']) : 0;
        if ($amount <= 0) {
            $this->set_session_amount(0);
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        $balance = $this->service->get_balance(get_current_user_id());
        if ($amount > $balance) {
            $amount = $balance;
        }

        $cart_total = (float) WC()->cart->get_total('edit');
        if ($amount > $cart_total) {
            $amount = $cart_total;
        }

        $this->set_session_amount($amount);
        wc_add_notice(sprintf(__('Wallet amount %s will be applied.', 'woo-wallet-bridge'), wc_price($amount)), 'success');
    }

    public function apply_wallet_fee(): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        $amount = $this->get_session_amount();
        if ($amount <= 0) {
            return;
        }

        $balance = $this->service->get_balance(get_current_user_id());
        $amount = min($amount, $balance);

        $cart_total = (float) WC()->cart->get_total('edit');
        if ($cart_total <= 0) {
            $amount = 0;
        } else {
            $amount = min($amount, $cart_total);
        }

        if ($amount <= 0) {
            $this->set_session_amount(0);
            return;
        }

        $this->set_session_amount($amount);
        WC()->cart->add_fee(__('Wallet Credit', 'woo-wallet-bridge'), -1 * $amount, false);
    }

    public function attach_order_meta($order, $data): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        $amount = $this->get_session_amount();
        if ($amount <= 0) {
            return;
        }

        $order->update_meta_data('_wwb_wallet_amount', $amount);
        $order->update_meta_data('_wwb_wallet_user', get_current_user_id());
    }

    public function maybe_debit_wallet($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ('yes' === $order->get_meta('_wwb_wallet_debited')) {
            return;
        }

        $amount = (float) $order->get_meta('_wwb_wallet_amount');
        $user_id = (int) $order->get_meta('_wwb_wallet_user');

        if ($amount <= 0 || $user_id <= 0) {
            return;
        }

        $result = $this->service->debit_wallet($user_id, $amount, __('Wallet payment applied to order', 'woo-wallet-bridge'), ['order_id' => $order_id], $order_id);
        if (!is_wp_error($result)) {
            $order->update_meta_data('_wwb_wallet_debited', 'yes');
            $order->save_meta_data();
        }
    }
}
