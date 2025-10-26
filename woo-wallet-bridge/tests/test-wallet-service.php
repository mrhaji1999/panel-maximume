<?php
require_once __DIR__ . '/../includes/class-wallet-service.php';

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $message;
        public function __construct($code = '', $message = '') {
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

if (!function_exists('current_time')) {
    function current_time($type) {
        return '2024-01-01 00:00:00';
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = '') {
        global $wwb_options;
        return $wwb_options[$name] ?? $default;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return trim(strip_tags($text));
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration) {
        global $wwb_transients;
        $wwb_transients[$key] = ['value' => $value, 'expires' => time() + $expiration];
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        global $wwb_transients;
        if (!isset($wwb_transients[$key])) {
            return false;
        }
        if ($wwb_transients[$key]['expires'] < time()) {
            unset($wwb_transients[$key]);
            return false;
        }
        return $wwb_transients[$key]['value'];
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value) {
        global $wwb_user_meta;
        $wwb_user_meta[$user_id][$key] = $value;
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = true) {
        global $wwb_user_meta;
        return $wwb_user_meta[$user_id][$key] ?? 0;
    }
}

class FakeDB {
    public $prefix = 'wp_';
    public $codes = [];
    public $transactions = [];

    public function replace($table, $data, $format) {
        $data['id'] = count($this->codes) + 1;
        $this->codes[$data['code']] = (object) $data;
        return 1;
    }

    public function get_row($query) {
        if (preg_match('/WHERE code = \'([^\']+)\'/', $query, $m)) {
            $code = $m[1];
            return $this->codes[$code] ?? null;
        }
        return null;
    }

    public function update($table, $data, $where) {
        $id = $where['id'] ?? null;
        if (!$id) {
            return false;
        }
        foreach ($this->codes as $code => $row) {
            if ($row->id == $id) {
                foreach ($data as $key => $value) {
                    $row->$key = $value;
                }
                $this->codes[$code] = $row;
            }
        }
        return true;
    }

    public function insert($table, $data, $format) {
        $data['id'] = count($this->transactions) + 1;
        $this->transactions[] = (object) $data;
        return true;
    }

    public function get_results($query) {
        return array_values($this->codes);
    }

    public function get_var($query) {
        return count($this->codes);
    }

    public function prepare($query, $args) {
        foreach ((array) $args as $arg) {
            if (is_int($arg)) {
                $query = preg_replace('/%d/', (string) $arg, $query, 1);
            } else {
                $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
            }
        }
        return $query;
    }
}

global $wpdb, $wwb_user_meta, $wwb_transients, $wwb_options;
$wpdb = new FakeDB();
$wwb_user_meta = [];
$wwb_transients = [];
$wwb_options = [
    'wwb_default_code_expiry_days' => 0,
    'wwb_rate_limit_window' => 60,
    'wwb_rate_limit_max' => 2,
];

$service = new WWB\WalletService();

$result = $service->upsert_code([
    'code' => 'TEST1',
    'amount' => 100,
    'wallet_amount' => 100,
    'type' => 'wallet',
    'status' => 'unused',
    'user_id' => 1,
]);
assert(!is_wp_error($result), 'Code should be inserted');

$redeem = $service->redeem_code('TEST1', 5);
assert(!is_wp_error($redeem), 'Redeem should succeed');
assert($service->get_balance(5) === 100.0, 'Balance should be credited');

// Rate limit test (max 2 attempts per window)
$service->redeem_code('MISSING', 5);
$service->redeem_code('MISSING', 5);
$rateLimited = $service->redeem_code('MISSING', 5);
assert(is_wp_error($rateLimited), 'Third redemption attempt should be rate limited');

echo "Wallet service tests passed" . PHP_EOL;
