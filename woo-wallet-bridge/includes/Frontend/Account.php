<?php

namespace WWB\Frontend;

use WWB\WalletService;

class Account {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_my-wallet_endpoint', [$this, 'render_wallet_page']);
        add_action('template_redirect', [$this, 'handle_wallet_actions']);
    }

    public function add_menu_item(array $items): array {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ('customer-logout' === $key) {
                $new['my-wallet'] = __('کیف پول من', 'woo-wallet-bridge');
                $new['wallet-topup'] = __('افزایش اعتبار', 'woo-wallet-bridge');
            }
        }

        if (!isset($new['my-wallet'])) {
            $new['my-wallet'] = __('کیف پول من', 'woo-wallet-bridge');
            $new['wallet-topup'] = __('افزایش اعتبار', 'woo-wallet-bridge');
        }

        return $new;
    }

    public function handle_wallet_actions(): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (isset($_POST['wwb_wallet_redeem_nonce']) && wp_verify_nonce($_POST['wwb_wallet_redeem_nonce'], 'wwb_wallet_redeem')) {
            $code = isset($_POST['wwb_wallet_code']) ? sanitize_text_field(wp_unslash($_POST['wwb_wallet_code'])) : '';
            if ($code === '') {
                wc_add_notice(__('Please enter a wallet code.', 'woo-wallet-bridge'), 'error');
                return;
            }

            $result = $this->service->redeem_code($code, get_current_user_id());
            if (is_wp_error($result)) {
                wc_add_notice($result->get_error_message(), 'error');
            } else {
                wc_add_notice(sprintf(__('Wallet code applied. New balance: %s', 'woo-wallet-bridge'), wc_price($result['balance'])), 'success');
            }

            $context = isset($_POST['wwb_wallet_context']) ? sanitize_text_field(wp_unslash($_POST['wwb_wallet_context'])) : '';
            $redirect = 'checkout' === $context ? wc_get_checkout_url() : wc_get_account_endpoint_url('my-wallet');
            wp_safe_redirect($redirect);
            exit;
        }
    }

    public function render_wallet_page(): void {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('You need to be logged in to view your wallet.', 'woo-wallet-bridge') . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $balance = $this->service->get_balance($user_id);
        $transactions = $this->service->get_transactions([
            'user_id' => $user_id,
            'paged' => 1,
            'per_page' => 10,
        ]);

        echo '<div class="wwb-wallet">';
        echo '<h2>' . esc_html__('Wallet Balance', 'woo-wallet-bridge') . '</h2>';
        echo '<p class="wwb-wallet-balance"><strong>' . wp_kses_post(wc_price($balance)) . '</strong></p>';

        echo '<h3>' . esc_html__('Redeem a wallet code', 'woo-wallet-bridge') . '</h3>';
        echo '<form method="post" class="wwb-wallet-redeem">';
        wp_nonce_field('wwb_wallet_redeem', 'wwb_wallet_redeem_nonce');
        echo '<p><input type="text" name="wwb_wallet_code" placeholder="' . esc_attr__('Enter your code', 'woo-wallet-bridge') . '" required /></p>';
        echo '<p><button type="submit" class="button">' . esc_html__('Redeem Code', 'woo-wallet-bridge') . '</button></p>';
        echo '</form>';

        echo '<h3>' . esc_html__('Recent transactions', 'woo-wallet-bridge') . '</h3>';
        if (empty($transactions['items'])) {
            echo '<p>' . esc_html__('No wallet activity yet.', 'woo-wallet-bridge') . '</p>';
        } else {
            echo '<table class="shop_table shop_table_responsive">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'woo-wallet-bridge') . '</th>';
            echo '<th>' . esc_html__('Type', 'woo-wallet-bridge') . '</th>';
            echo '<th>' . esc_html__('Amount', 'woo-wallet-bridge') . '</th>';
            echo '<th>' . esc_html__('Note', 'woo-wallet-bridge') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($transactions['items'] as $item) {
                echo '<tr>';
                echo '<td>' . esc_html(wc_format_datetime(wc_string_to_datetime($item->created_at))) . '</td>';
                echo '<td>' . esc_html(ucfirst($item->type)) . '</td>';
                echo '<td>' . wp_kses_post(wc_price((float) $item->amount)) . '</td>';
                echo '<td>' . esc_html($item->note) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
