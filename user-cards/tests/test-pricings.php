<?php
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../includes/class-uc-post-types.php';

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) {
        return strip_tags($text);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

$input = [
    ['label' => '<b>Normal</b>', 'amount' => '100', 'wallet_amount' => '150', 'code_type' => 'wallet'],
    ['label' => 'Coupon', 'amount' => '200', 'wallet_amount' => '250', 'code_type' => 'coupon'],
];

$result = UC_Post_Types::sanitize_pricings($input, 0, '_uc_pricings');

assert(count($result) === 2, 'Two rows should be preserved');
assert($result[0]['label'] === 'Normal', 'Labels should be stripped of tags');
assert($result[0]['wallet_amount'] === 150.0, 'Wallet amount should be cast to float');
assert($result[1]['code_type'] === 'coupon', 'Code type coupon should persist');
assert($result[1]['wallet_amount'] === 250.0, 'Coupon wallet amount stored');

$empty = UC_Post_Types::sanitize_pricings([
    ['label' => '', 'amount' => 0, 'wallet_amount' => 0, 'code_type' => 'wallet'],
], 0, '_uc_pricings');
assert($empty === [], 'Empty rows should be removed');

echo "_uc_pricings sanitize tests passed" . PHP_EOL;
