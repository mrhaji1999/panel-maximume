<?php

namespace WWB;

class Activator {
    public static function activate(): void {
        Database::create_tables();

        add_option('wwb_auth_type', 'api_key');
        add_option('wwb_auth_token', '');
        add_option('wwb_hmac_secret', '');
        add_option('wwb_multiplier_enabled', 1);
        add_option('wwb_multiplier_value', 2.0);
        add_option('wwb_default_code_expiry_days', 0);
        add_option('wwb_rate_limit_window', 300);
        add_option('wwb_rate_limit_max', 5);

        self::add_capabilities();
        self::register_endpoints();
        flush_rewrite_rules();
    }

    protected static function add_capabilities(): void {
        $roles = ['administrator', 'shop_manager'];
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }

            $role->add_cap('manage_wallet');
            $role->add_cap('view_wallet_reports');
        }
    }

    protected static function register_endpoints(): void {
        add_rewrite_endpoint('my-wallet', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('wallet-topup', EP_ROOT | EP_PAGES);
    }
}
