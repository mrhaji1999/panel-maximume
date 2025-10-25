<?php

namespace WCW;


class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_settings']);
    }

    public function register_menu(): void {
        add_menu_page(
            __('WC Smart Wallet', 'wc-smart-wallet'),
            __('WC Smart Wallet', 'wc-smart-wallet'),
            'manage_woocommerce',
            'wc-smart-wallet',
            [$this, 'render_accounts'],
            'dashicons-portfolio',
            58
        );

        add_submenu_page('wc-smart-wallet', __('Accounts', 'wc-smart-wallet'), __('Accounts', 'wc-smart-wallet'), 'manage_woocommerce', 'wc-smart-wallet', [$this, 'render_accounts']);
        add_submenu_page('wc-smart-wallet', __('Codes', 'wc-smart-wallet'), __('Codes', 'wc-smart-wallet'), 'manage_woocommerce', 'wc-smart-wallet-codes', [$this, 'render_codes']);
        add_submenu_page('wc-smart-wallet', __('Transactions', 'wc-smart-wallet'), __('Transactions', 'wc-smart-wallet'), 'manage_woocommerce', 'wc-smart-wallet-transactions', [$this, 'render_transactions']);
        add_submenu_page('wc-smart-wallet', __('Settings', 'wc-smart-wallet'), __('Settings', 'wc-smart-wallet'), 'manage_woocommerce', 'wc-smart-wallet-settings', [$this, 'render_settings']);
    }

    public function handle_settings(): void {
        if (empty($_POST['wcw_settings_nonce']) || !wp_verify_nonce($_POST['wcw_settings_nonce'], 'wcw_settings')) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = [
            'topup_product_id' => isset($_POST['wcw_topup_product_id']) ? (int) $_POST['wcw_topup_product_id'] : 0,
            'topup_min' => isset($_POST['wcw_topup_min']) ? (float) $_POST['wcw_topup_min'] : 0,
            'topup_max' => isset($_POST['wcw_topup_max']) ? (float) $_POST['wcw_topup_max'] : 0,
            'topup_multiplier' => isset($_POST['wcw_topup_multiplier']) ? (float) $_POST['wcw_topup_multiplier'] : 2.0,
        ];
        update_option('wcw_settings', $settings);

        $api_keys_input = isset($_POST['wcw_api_keys']) ? (array) $_POST['wcw_api_keys'] : [];
        $keys = [];
        foreach ($api_keys_input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = sanitize_text_field($row['key'] ?? '');
            $secret = sanitize_text_field($row['secret'] ?? '');
            if ($key && $secret) {
                $keys[] = ['key' => $key, 'secret' => $secret];
            }
        }
        update_option('wcw_api_keys', $keys);

        add_settings_error('wcw_messages', 'wcw_messages', __('Settings saved.', 'wc-smart-wallet'), 'updated');
    }

    public function render_accounts(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Access denied.', 'wc-smart-wallet'));
        }
        global $wpdb;
        $db = new Database();
        $table = $db->table_accounts();
        $accounts = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 50");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet Accounts', 'wc-smart-wallet'); ?></h1>
            <table class="widefat">
                <thead><tr><th><?php esc_html_e('User', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Balance', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Updated', 'wc-smart-wallet'); ?></th></tr></thead>
                <tbody>
                <?php if (empty($accounts)): ?>
                    <tr><td colspan="3"><?php esc_html_e('No accounts found.', 'wc-smart-wallet'); ?></td></tr>
                <?php else: foreach ($accounts as $account): $user = get_userdata($account->user_id); ?>
                    <tr>
                        <td><?php echo esc_html($user ? $user->display_name : sprintf(__('User #%d', 'wc-smart-wallet'), $account->user_id)); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $account->balance, 2)) . ' ' . esc_html($account->currency); ?></td>
                        <td><?php echo esc_html($account->updated_at); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_codes(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Access denied.', 'wc-smart-wallet'));
        }
        global $wpdb;
        $db = new Database();
        $table = $db->table_codes();
        $codes = $wpdb->get_results("SELECT * FROM {$table} ORDER BY issued_at DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet Codes', 'wc-smart-wallet'); ?></h1>
            <table class="widefat">
                <thead><tr><th><?php esc_html_e('Code', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Amount', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Status', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Issued', 'wc-smart-wallet'); ?></th></tr></thead>
                <tbody>
                <?php if (empty($codes)): ?>
                    <tr><td colspan="4"><?php esc_html_e('No codes found.', 'wc-smart-wallet'); ?></td></tr>
                <?php else: foreach ($codes as $code): ?>
                    <tr>
                        <td><?php echo esc_html($code->code); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $code->amount, 2)) . ' ' . esc_html($code->currency); ?></td>
                        <td><?php echo esc_html(ucfirst($code->status)); ?></td>
                        <td><?php echo esc_html($code->issued_at); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_transactions(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Access denied.', 'wc-smart-wallet'));
        }
        global $wpdb;
        $db = new Database();
        $table = $db->table_transactions();
        $transactions = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet Transactions', 'wc-smart-wallet'); ?></h1>
            <table class="widefat">
                <thead><tr><th><?php esc_html_e('User', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Type', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Amount', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Source', 'wc-smart-wallet'); ?></th><th><?php esc_html_e('Date', 'wc-smart-wallet'); ?></th></tr></thead>
                <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="5"><?php esc_html_e('No transactions found.', 'wc-smart-wallet'); ?></td></tr>
                <?php else: foreach ($transactions as $tx): $user = get_userdata($tx->user_id); ?>
                    <tr>
                        <td><?php echo esc_html($user ? $user->display_name : sprintf(__('User #%d', 'wc-smart-wallet'), $tx->user_id)); ?></td>
                        <td><?php echo esc_html(ucfirst($tx->type)); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $tx->amount, 2)) . ' ' . esc_html($tx->currency); ?></td>
                        <td><?php echo esc_html($tx->source); ?></td>
                        <td><?php echo esc_html($tx->created_at); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_settings(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Access denied.', 'wc-smart-wallet'));
        }
        $settings = get_option('wcw_settings', []);
        $keys = get_option('wcw_api_keys', []);
        settings_errors('wcw_messages');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet Settings', 'wc-smart-wallet'); ?></h1>
            <style>
                .wcw-api-row { display:flex; gap:10px; margin-bottom:10px; }
                .wcw-api-row input { flex:1; }
            </style>
            <form method="post">
                <?php wp_nonce_field('wcw_settings', 'wcw_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wcw_topup_product_id"><?php esc_html_e('Top-up Product ID', 'wc-smart-wallet'); ?></label></th>
                        <td><input type="number" name="wcw_topup_product_id" id="wcw_topup_product_id" value="<?php echo esc_attr($settings['topup_product_id'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcw_topup_min"><?php esc_html_e('Minimum amount', 'wc-smart-wallet'); ?></label></th>
                        <td><input type="number" name="wcw_topup_min" id="wcw_topup_min" value="<?php echo esc_attr($settings['topup_min'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcw_topup_max"><?php esc_html_e('Maximum amount', 'wc-smart-wallet'); ?></label></th>
                        <td><input type="number" name="wcw_topup_max" id="wcw_topup_max" value="<?php echo esc_attr($settings['topup_max'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcw_topup_multiplier"><?php esc_html_e('Top-up multiplier', 'wc-smart-wallet'); ?></label></th>
                        <td><input type="number" step="0.1" name="wcw_topup_multiplier" id="wcw_topup_multiplier" value="<?php echo esc_attr($settings['topup_multiplier'] ?? 2); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('API Keys', 'wc-smart-wallet'); ?></h2>
                <div id="wcw-api-keys">
                    <?php if (empty($keys)): ?>
                        <div class="wcw-api-row">
                            <input type="text" name="wcw_api_keys[0][key]" placeholder="<?php esc_attr_e('Public key', 'wc-smart-wallet'); ?>">
                            <input type="text" name="wcw_api_keys[0][secret]" placeholder="<?php esc_attr_e('Secret key', 'wc-smart-wallet'); ?>">
                        </div>
                    <?php else: foreach ($keys as $index => $row): ?>
                        <div class="wcw-api-row">
                            <input type="text" name="wcw_api_keys[<?php echo esc_attr($index); ?>][key]" value="<?php echo esc_attr($row['key']); ?>" placeholder="<?php esc_attr_e('Public key', 'wc-smart-wallet'); ?>">
                            <input type="text" name="wcw_api_keys[<?php echo esc_attr($index); ?>][secret]" value="<?php echo esc_attr($row['secret']); ?>" placeholder="<?php esc_attr_e('Secret key', 'wc-smart-wallet'); ?>">
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <p><button type="button" class="button" id="wcw-add-api-key"><?php esc_html_e('Add key', 'wc-smart-wallet'); ?></button></p>

                <p class="submit"><button type="submit" class="button-primary"><?php esc_html_e('Save settings', 'wc-smart-wallet'); ?></button></p>
            </form>
        </div>
        <script>
        document.getElementById('wcw-add-api-key').addEventListener('click', function() {
            var container = document.getElementById('wcw-api-keys');
            var index = container.querySelectorAll('.wcw-api-row').length;
            var div = document.createElement('div');
            div.className = 'wcw-api-row';
            div.innerHTML = '<input type="text" name="wcw_api_keys[' + index + '][key]" placeholder="<?php echo esc_js(__('Public key', 'wc-smart-wallet')); ?>">' +
                '<input type="text" name="wcw_api_keys[' + index + '][secret]" placeholder="<?php echo esc_js(__('Secret key', 'wc-smart-wallet')); ?>">';
            container.appendChild(div);
        });
        </script>
        <?php
    }
}
