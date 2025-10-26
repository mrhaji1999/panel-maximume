<?php
require_once __DIR__ . '/../includes/Services/CouponService.php';
require_once __DIR__ . '/../includes/Services/WalletBridgeService.php';

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $code;
        protected $message;

        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

global $test_options, $requests;
$test_options = [];
$requests = [];

define('UCB_TEXT_DOMAIN', 'user-cards-bridge');

if (!function_exists('get_option')) {
    function get_option($name, $default = '') {
        global $test_options;
        return $test_options[$name] ?? $default;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/') . '/';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data) {
        return serialize($data);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return $url;
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        global $requests;
        $requests[] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 201], 'body' => json_encode(['ok' => true])];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return $response['response']['code'];
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return $response['body'];
    }
}

$test_options = [
    'ucb_coupon_api_mode' => 'generic',
    'ucb_destination_auth_type' => 'api_key',
    'ucb_destination_auth_token' => 'APIKEY',
    'ucb_destination_hmac_secret' => 'secret',
    'ucb_coupon_generic_endpoint' => 'api/coupons',
    'ucb_coupon_usage_limit' => 1,
    'ucb_coupon_usage_limit_per_user' => 1,
    'ucb_coupon_expiry_days' => 3,
    'ucb_wallet_endpoint_path' => 'wp-json/wwb/v1/wallet-codes',
    'ucb_wallet_code_expiry_days' => 7,
];

$coupon_service = new UCB\Services\CouponService();
$result = $coupon_service->forward_coupon([
    'store_link' => 'https://shop.test',
    'unique_code' => 'COUPON1',
    'wallet_amount' => 150,
    'user_id' => 10,
    'product_id' => 55,
]);

assert(!is_wp_error($result), 'Coupon forwarding should succeed');
assert($requests[0]['url'] === 'https://shop.test/api/coupons', 'Generic endpoint should append correctly');
assert($requests[0]['args']['headers']['X-API-Key'] === 'APIKEY', 'API key header present');
assert(isset($requests[0]['args']['headers']['X-WWB-Signature']), 'HMAC signature should be present');

$wallet_service = new UCB\Services\WalletBridgeService();
$result2 = $wallet_service->push_wallet_code([
    'store_link' => 'https://wallet.test',
    'unique_code' => 'WALLET1',
    'wallet_amount' => 200,
    'user_id' => 22,
]);

assert(!is_wp_error($result2), 'Wallet forwarding should succeed');
assert($requests[1]['url'] === 'https://wallet.test/wp-json/wwb/v1/wallet-codes', 'Wallet endpoint should be default path');
assert($requests[1]['args']['headers']['X-API-Key'] === 'APIKEY', 'Wallet request should include API key');

// Switch to Woo REST mode.
$requests = [];
$test_options['ucb_coupon_api_mode'] = 'woo_rest';
$test_options['ucb_coupon_wc_consumer_key'] = 'ck_test';
$test_options['ucb_coupon_wc_consumer_secret'] = 'cs_test';

$result3 = $coupon_service->forward_coupon([
    'store_link' => 'https://woo.test',
    'unique_code' => 'COUPON2',
    'wallet_amount' => 99,
    'user_id' => 5,
    'product_id' => 11,
]);

assert(strpos($requests[0]['args']['headers']['Authorization'], 'Basic') === 0, 'Woo REST should use basic auth header');
assert($requests[0]['url'] === 'https://woo.test/wp-json/wc/v3/coupons', 'Woo REST endpoint should be correct');

echo "Bridge service tests passed" . PHP_EOL;
