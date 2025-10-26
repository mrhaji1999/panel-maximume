<?php

namespace WWB;

class Uninstaller {
    public static function uninstall(): void {
        Database::drop_tables();
        delete_option('wwb_auth_type');
        delete_option('wwb_auth_token');
        delete_option('wwb_hmac_secret');
        delete_option('wwb_multiplier_enabled');
        delete_option('wwb_multiplier_value');
        delete_option('wwb_default_code_expiry_days');
        delete_option('wwb_rate_limit_window');
        delete_option('wwb_rate_limit_max');
    }
}
