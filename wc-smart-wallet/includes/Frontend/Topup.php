<?php

namespace WCW\Frontend;

use WC_Order;
use WCW\Services\WalletService;

class Topup {
    private WalletService $wallet;

    public function __construct(WalletService $wallet) {
        $this->wallet = $wallet;
        add_action('template_redirect', [$this, 'handle_form']);
        add_action('woocommerce_before_calculate_totals', [$this, 'adjust_cart_prices']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 5);
        add_filter('woocommerce_coupon_is_valid_for_product', [$this, 'block_coupon_on_topup'], 10, 4);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'store_order_item_meta'], 10, 4);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_credit_order']);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_credit_order']);
    }

    public static function render_shortcode(array $atts = []): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please login to top up your wallet.', 'wc-smart-wallet') . '</p>';
        }

        $settings = get_option('wcw_settings', []);
        $min = isset($settings['topup_min']) ? (float) $settings['topup_min'] : 10000;
        $max = isset($settings['topup_max']) ? (float) $settings['topup_max'] : 10000000;
        $currency = get_option('woocommerce_currency', 'IRR');

        ob_start();
        ?>
        <form method="post" class="wcw-topup-form">
            <?php wp_nonce_field('wcw_topup', 'wcw_topup_nonce'); ?>
            <p>
                <label for="wcw_topup_amount"><?php esc_html_e('Top-up amount', 'wc-smart-wallet'); ?></label>
                <input type="number" name="wcw_topup_amount" id="wcw_topup_amount" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="1000" required>
                <span><?php echo esc_html($currency); ?></span>
            </p>
            <p>
                <button type="submit" class="button"><?php esc_html_e('Add to cart', 'wc-smart-wallet'); ?></button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_form(): void {
        if (empty($_POST['wcw_topup_nonce']) || !wp_verify_nonce($_POST['wcw_topup_nonce'], 'wcw_topup')) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }

        $settings = get_option('wcw_settings', []);
        $product_id = isset($settings['topup_product_id']) ? (int) $settings['topup_product_id'] : 0;
        if ($product_id <= 0) {
            wc_add_notice(__('Top-up product is not configured.', 'wc-smart-wallet'), 'error');
            return;
        }

        if (!WC()->cart) {
            return;
        }

        $min = isset($settings['topup_min']) ? (float) $settings['topup_min'] : 10000;
        $max = isset($settings['topup_max']) ? (float) $settings['topup_max'] : 10000000;
        $amount = isset($_POST['wcw_topup_amount']) ? (float) $_POST['wcw_topup_amount'] : 0;

        if ($amount < $min || $amount > $max) {
            wc_add_notice(sprintf(__('Amount must be between %1$s and %2$s.', 'wc-smart-wallet'), number_format_i18n($min), number_format_i18n($max)), 'error');
            return;
        }

        $cart_item_data = [
            'wcw_topup_amount' => $amount,
        ];
        WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);
        wc_add_notice(__('Top-up added to cart.', 'wc-smart-wallet'));
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public function adjust_cart_prices($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!did_action('woocommerce_before_calculate_totals')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $item) {
            if (isset($item['wcw_topup_amount'])) {
                $item['data']->set_price((float) $item['wcw_topup_amount']);
            }
        }
    }

    public function validate_add_to_cart(bool $passed, $product_id, $quantity, $variation_id = 0, $variations = []): bool {
        $settings = get_option('wcw_settings', []);
        $topup_product = isset($settings['topup_product_id']) ? (int) $settings['topup_product_id'] : 0;
        if ($product_id === $topup_product && empty($_POST['wcw_topup_amount']) && !isset($_POST['wcw_topup_nonce'])) {
            wc_add_notice(__('Please use the top-up form to specify an amount.', 'wc-smart-wallet'), 'error');
            return false;
        }
        return $passed;
    }

    public function block_coupon_on_topup($valid, $coupon, $product, $values): bool {
        $settings = get_option('wcw_settings', []);
        $topup_product = isset($settings['topup_product_id']) ? (int) $settings['topup_product_id'] : 0;
        if ($product && $product->get_id() === $topup_product) {
            return false;
        }
        return $valid;
    }

    public function store_order_item_meta($item, $cart_item_key, $values, $order): void {
        if (isset($values['wcw_topup_amount'])) {
            $item->add_meta_data('_wcw_topup_amount', (float) $values['wcw_topup_amount'], true);
        }
    }

    public function maybe_credit_order($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        if ($order->get_meta('_wcw_topup_processed')) {
            return;
        }
        $user_id = $order->get_user_id();
        if ($user_id <= 0) {
            return;
        }

        $settings = get_option('wcw_settings', []);
        $topup_product = isset($settings['topup_product_id']) ? (int) $settings['topup_product_id'] : 0;
        $multiplier = isset($settings['topup_multiplier']) ? (float) $settings['topup_multiplier'] : 2.0;
        $total_credit = 0.0;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($product_id === $topup_product) {
                $amount = (float) $item->get_meta('_wcw_topup_amount', true);
                if ($amount > 0) {
                    $total_credit += $amount * $multiplier;
                }
            }
        }

        if ($total_credit > 0) {
            $currency = $order->get_currency();
            $result = $this->wallet->credit($user_id, $total_credit, $currency, 'topup', (string) $order->get_id(), __('Wallet top-up order', 'wc-smart-wallet'));
            if (!is_wp_error($result)) {
                $order->add_order_note(sprintf(__('Wallet credited by %s due to top-up.', 'wc-smart-wallet'), wc_price($total_credit, ['currency' => $currency])));
                $order->update_meta_data('_wcw_topup_processed', 1);
                $order->save();
            }
        }
    }
}
