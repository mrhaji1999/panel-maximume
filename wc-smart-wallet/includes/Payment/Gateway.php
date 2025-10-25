<?php

namespace WCW\Payment;

use WC_Order;
use WC_Payment_Gateway;
use WCW\Services\WalletService;

class Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'wcw_wallet';
        $this->icon = '';
        $this->method_title = __('Wallet Payment', 'wc-smart-wallet');
        $this->method_description = __('Allow customers to pay using their wallet balance.', 'wc-smart-wallet');
        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'wc-smart-wallet'),
                'type' => 'checkbox',
                'label' => __('Enable wallet payment', 'wc-smart-wallet'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'wc-smart-wallet'),
                'type' => 'text',
                'default' => __('Wallet', 'wc-smart-wallet'),
            ],
            'description' => [
                'title' => __('Description', 'wc-smart-wallet'),
                'type' => 'textarea',
                'default' => __('Pay using your wallet balance.', 'wc-smart-wallet'),
            ],
        ];
    }

    public function is_available(): bool {
        if ('yes' !== $this->get_option('enabled')) {
            return false;
        }

        if (!is_user_logged_in()) {
            return false;
        }

        if (!WC()->cart) {
            return false;
        }

        $total = (float) WC()->cart->total;
        $wallet = new WalletService();
        $balance = $wallet->get_balance(get_current_user_id());

        return $balance >= $total && $total > 0;
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Unable to process wallet payment.', 'wc-smart-wallet'), 'error');
            return [];
        }

        $order->update_status(apply_filters('wcw_wallet_order_status', 'processing', $order), __('Paid via wallet balance.', 'wc-smart-wallet'));
        $order->update_meta_data('_wcw_wallet_payment', 1);
        $order->save();

        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }
}
