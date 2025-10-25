<?php

namespace WCW\REST;

use WP_Error;
use WP_REST_Request;
use WCW\Security\HMAC;
use WCW\Services\WalletService;

class CodesController {
    protected string $namespace = 'wc-smart-wallet/v1';
    protected WalletService $wallet;

    public function __construct() {
        $this->wallet = new WalletService();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/codes/upsert', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_upsert'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_upsert(WP_REST_Request $request) {
        $auth = HMAC::verify_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $code = sanitize_text_field($request->get_param('code'));
        $amount = (float) $request->get_param('amount');
        $currency = sanitize_text_field($request->get_param('currency') ?? get_option('woocommerce_currency', 'IRR'));
        $expires_at = $request->get_param('expires_at');
        $user_email = $request->get_param('user_email');
        $status = sanitize_text_field($request->get_param('status') ?? 'new');
        $meta = $request->get_param('meta');

        if ($code === '' || $amount <= 0) {
            return new WP_Error('wcw_invalid_payload', __('Code and amount are required.', 'wc-smart-wallet'), ['status' => 400]);
        }

        $data = [
            'code' => $code,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'expires_at' => $expires_at ? sanitize_text_field($expires_at) : null,
            'user_email' => $user_email ? sanitize_email($user_email) : null,
            'meta' => is_array($meta) ? $meta : null,
        ];

        $result = $this->wallet->upsert_code($data);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'status' => 'ok',
        ]);
    }
}
