<?php

namespace WCW\Frontend;

use WCW\Services\WalletService;
use WP_Error;

class Checkout {
    private WalletService $wallet;

    public function __construct(WalletService $wallet) {
        $this->wallet = $wallet;
        add_action('woocommerce_review_order_before_payment', [$this, 'render_field']);
        add_action('woocommerce_checkout_process', [$this, 'process']);
    }

    public function render_field(): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (!WC()->session) {
            return;
        }

        $value = WC()->session->get('wcw_wallet_checkout_code', '');
        echo '<div class="wcw-checkout-code">';
        echo '<h3>' . esc_html__('Wallet code', 'wc-smart-wallet') . '</h3>';
        echo '<p><input type="text" name="wcw_wallet_code_checkout" value="' . esc_attr($value) . '" placeholder="' . esc_attr__('Enter wallet code', 'wc-smart-wallet') . '"></p>';
        echo '</div>';
    }

    public function process(): void {
        if (!is_user_logged_in()) {
            return;
        }
        $code = isset($_POST['wcw_wallet_code_checkout']) ? sanitize_text_field(wp_unslash($_POST['wcw_wallet_code_checkout'])) : '';
        if ($code === '') {
            return;
        }

        if (!WC()->session) {
            return;
        }

        $redeemed = (array) WC()->session->get('wcw_wallet_redeemed', []);
        if (in_array($code, $redeemed, true)) {
            return;
        }

        $result = $this->wallet->redeem_code(get_current_user_id(), $code);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        } else {
            $redeemed[] = $code;
            WC()->session->set('wcw_wallet_redeemed', $redeemed);
            WC()->session->set('wcw_wallet_checkout_code', $code);
            wc_add_notice(__('Wallet code redeemed and balance updated.', 'wc-smart-wallet'));
        }
    }
}
