<?php

namespace WWB\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsPage {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu(): void {
        add_menu_page(
            __('Woo Wallet Bridge', 'woo-wallet-bridge'),
            __('Wallet Bridge', 'woo-wallet-bridge'),
            'manage_wallet',
            'woo-wallet-bridge',
            [$this, 'render'],
            'dashicons-vault'
        );

        add_submenu_page(
            'woo-wallet-bridge',
            __('Settings', 'woo-wallet-bridge'),
            __('Settings', 'woo-wallet-bridge'),
            'manage_wallet',
            'woo-wallet-bridge',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_wallet')) {
            wp_die(__('You do not have permission to manage wallet settings.', 'woo-wallet-bridge'));
        }

        if (isset($_POST['wwb_settings_nonce']) && wp_verify_nonce($_POST['wwb_settings_nonce'], 'wwb_save_settings')) {
            $auth_type = isset($_POST['wwb_auth_type']) && in_array($_POST['wwb_auth_type'], ['api_key', 'jwt'], true)
                ? $_POST['wwb_auth_type']
                : 'api_key';
            update_option('wwb_auth_type', $auth_type);
            update_option('wwb_auth_token', sanitize_text_field($_POST['wwb_auth_token'] ?? ''));
            update_option('wwb_hmac_secret', sanitize_text_field($_POST['wwb_hmac_secret'] ?? ''));

            update_option('wwb_multiplier_enabled', isset($_POST['wwb_multiplier_enabled']) ? 1 : 0);
            update_option('wwb_multiplier_value', max(1, (float) ($_POST['wwb_multiplier_value'] ?? 1)));
            update_option('wwb_default_code_expiry_days', max(0, (int) ($_POST['wwb_default_code_expiry_days'] ?? 0)));
            update_option('wwb_rate_limit_window', max(30, (int) ($_POST['wwb_rate_limit_window'] ?? 300)));
            update_option('wwb_rate_limit_max', max(1, (int) ($_POST['wwb_rate_limit_max'] ?? 5)));

            add_settings_error('wwb_messages', 'wwb_saved', __('Settings saved.', 'woo-wallet-bridge'), 'updated');
        }

        $auth_type = get_option('wwb_auth_type', 'api_key');
        $auth_token = get_option('wwb_auth_token', '');
        $hmac_secret = get_option('wwb_hmac_secret', '');
        $multiplier_enabled = (int) get_option('wwb_multiplier_enabled', 1);
        $multiplier_value = (float) get_option('wwb_multiplier_value', 2.0);
        $default_code_expiry = (int) get_option('wwb_default_code_expiry_days', 0);
        $rate_limit_window = (int) get_option('wwb_rate_limit_window', 300);
        $rate_limit_max = (int) get_option('wwb_rate_limit_max', 5);

        settings_errors('wwb_messages');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Woo Wallet Bridge Settings', 'woo-wallet-bridge') . '</h1>';
        echo '<form method="post">';
        wp_nonce_field('wwb_save_settings', 'wwb_settings_nonce');

        echo '<h2>' . esc_html__('API Authentication', 'woo-wallet-bridge') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="wwb_auth_type">' . esc_html__('Auth Type', 'woo-wallet-bridge') . '</label></th><td>';
        echo '<select id="wwb_auth_type" name="wwb_auth_type">';
        printf('<option value="api_key" %s>%s</option>', selected($auth_type, 'api_key', false), esc_html__('API Key', 'woo-wallet-bridge'));
        printf('<option value="jwt" %s>%s</option>', selected($auth_type, 'jwt', false), esc_html__('JWT Bearer', 'woo-wallet-bridge'));
        echo '</select>';
        echo '<p class="description">' . esc_html__('Select how incoming requests are authenticated.', 'woo-wallet-bridge') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wwb_auth_token">' . esc_html__('Auth Token', 'woo-wallet-bridge') . '</label></th><td>';
        printf('<input type="text" id="wwb_auth_token" name="wwb_auth_token" value="%s" class="regular-text" />', esc_attr($auth_token));
        echo '<p class="description">' . esc_html__('Bearer token or API key expected from bridge requests.', 'woo-wallet-bridge') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wwb_hmac_secret">' . esc_html__('HMAC Secret', 'woo-wallet-bridge') . '</label></th><td>';
        printf('<input type="text" id="wwb_hmac_secret" name="wwb_hmac_secret" value="%s" class="regular-text" />', esc_attr($hmac_secret));
        echo '<p class="description">' . esc_html__('Optional shared secret for verifying X-WWB-Signature headers.', 'woo-wallet-bridge') . '</p>';
        echo '</td></tr>';
        echo '</table>';

        echo '<h2>' . esc_html__('Wallet Behaviour', 'woo-wallet-bridge') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th scope="row">' . esc_html__('Enable Double Credit', 'woo-wallet-bridge') . '</th><td>';
        printf('<label><input type="checkbox" name="wwb_multiplier_enabled" value="1" %s /> %s</label>', checked($multiplier_enabled, 1, false), esc_html__('Apply multiplier to top-up orders.', 'woo-wallet-bridge'));
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wwb_multiplier_value">' . esc_html__('Multiplier Value', 'woo-wallet-bridge') . '</label></th><td>';
        printf('<input type="number" step="0.1" min="1" id="wwb_multiplier_value" name="wwb_multiplier_value" value="%s" class="small-text" />', esc_attr($multiplier_value));
        echo '<p class="description">' . esc_html__('Wallet credits from paid orders will be multiplied by this factor.', 'woo-wallet-bridge') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wwb_default_code_expiry_days">' . esc_html__('Default Code Expiry (days)', 'woo-wallet-bridge') . '</label></th><td>';
        printf('<input type="number" min="0" id="wwb_default_code_expiry_days" name="wwb_default_code_expiry_days" value="%s" class="small-text" />', esc_attr($default_code_expiry));
        echo '<p class="description">' . esc_html__('Applies when the bridge does not supply an explicit expiry.', 'woo-wallet-bridge') . '</p>';
        echo '</td></tr>';
        echo '</table>';

        echo '<h2>' . esc_html__('Security & Rate Limiting', 'woo-wallet-bridge') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="wwb_rate_limit_window">' . esc_html__('Rate Limit Window (seconds)', 'woo-wallet-bridge') . '</label></th><td>';
        printf('<input type="number" min="30" id="wwb_rate_limit_window" name="wwb_rate_limit_window" value="%s" class="small-text" />', esc_attr($rate_limit_window));
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wwb_rate_limit_max">' . esc_html__('Maximum Redemptions per Window', 'woo-wallet-bridge') . '</label></th><td>';
        printf('<input type="number" min="1" id="wwb_rate_limit_max" name="wwb_rate_limit_max" value="%s" class="small-text" />', esc_attr($rate_limit_max));
        echo '</td></tr>';
        echo '</table>';

        submit_button(__('Save Changes', 'woo-wallet-bridge'));
        echo '</form>';
        echo '</div>';
    }
}
