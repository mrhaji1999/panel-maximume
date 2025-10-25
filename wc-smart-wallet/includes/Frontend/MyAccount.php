<?php

namespace WCW\Frontend;

use WCW\Services\WalletService;

class MyAccount {
    private WalletService $wallet;

    public function __construct(WalletService $wallet) {
        $this->wallet = $wallet;
        add_action('init', [$this, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_wallet_endpoint', [$this, 'render_endpoint']);
        add_action('template_redirect', [$this, 'handle_submission']);
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);
    }

    public function add_menu_item(array $items): array {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ('dashboard' === $key) {
                $new['wallet'] = __('Wallet', 'wc-smart-wallet');
            }
        }
        if (!isset($new['wallet'])) {
            $new['wallet'] = __('Wallet', 'wc-smart-wallet');
        }
        return $new;
    }

    public function render_endpoint(): void {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('You need to be logged in to view this page.', 'wc-smart-wallet') . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $balance = $this->wallet->get_balance($user_id);
        $currency = get_option('woocommerce_currency', 'IRR');
        ?>
        <div class="wcw-wallet-balance">
            <h3><?php esc_html_e('Wallet Balance', 'wc-smart-wallet'); ?></h3>
            <p><?php echo esc_html(number_format_i18n($balance, 2)) . ' ' . esc_html($currency); ?></p>
        </div>
        <form method="post" class="wcw-wallet-form">
            <?php wp_nonce_field('wcw_wallet_redeem', 'wcw_wallet_nonce'); ?>
            <p>
                <label for="wcw_wallet_code"><?php esc_html_e('Wallet code', 'wc-smart-wallet'); ?></label>
                <input type="text" name="wcw_wallet_code" id="wcw_wallet_code" required>
            </p>
            <p>
                <button type="submit" class="button"><?php esc_html_e('Redeem code', 'wc-smart-wallet'); ?></button>
            </p>
        </form>
        <?php
        $transactions = $this->get_transactions($user_id, 10);
        if (!empty($transactions)) {
            echo '<h3>' . esc_html__('Recent transactions', 'wc-smart-wallet') . '</h3>';
            echo '<table class="shop_table wcw-transactions"><thead><tr>';
            echo '<th>' . esc_html__('Date', 'wc-smart-wallet') . '</th>';
            echo '<th>' . esc_html__('Type', 'wc-smart-wallet') . '</th>';
            echo '<th>' . esc_html__('Amount', 'wc-smart-wallet') . '</th>';
            echo '<th>' . esc_html__('Source', 'wc-smart-wallet') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($transactions as $tx) {
                $amount = ('credit' === $tx->type ? '+' : '-') . number_format_i18n((float) $tx->amount, 2) . ' ' . esc_html($tx->currency);
                echo '<tr>';
                echo '<td>' . esc_html(get_date_from_gmt($tx->created_at, get_option('date_format') . ' ' . get_option('time_format'))) . '</td>';
                echo '<td>' . esc_html(ucfirst($tx->type)) . '</td>';
                echo '<td>' . esc_html($amount) . '</td>';
                echo '<td>' . esc_html($tx->source);
                if (!empty($tx->source_ref)) {
                    echo ' #' . esc_html($tx->source_ref);
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function get_transactions(int $user_id, int $limit = 10): array {
        global $wpdb;
        $table = (new \WCW\Database())->table_transactions();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", $user_id, $limit));
    }

    public function handle_submission(): void {
        if (!is_user_logged_in()) {
            return;
        }
        if (empty($_POST['wcw_wallet_nonce']) || !wp_verify_nonce($_POST['wcw_wallet_nonce'], 'wcw_wallet_redeem')) {
            return;
        }
        $code = isset($_POST['wcw_wallet_code']) ? sanitize_text_field(wp_unslash($_POST['wcw_wallet_code'])) : '';
        if ($code === '') {
            wc_add_notice(__('Please enter a wallet code.', 'wc-smart-wallet'), 'error');
            return;
        }

        $result = $this->wallet->redeem_code(get_current_user_id(), $code);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        } else {
            wc_add_notice(__('Wallet code redeemed successfully.', 'wc-smart-wallet'));
        }

        wp_safe_redirect(wc_get_account_endpoint_url('wallet'));
        exit;
    }
}
