<?php

namespace UCB\API;

use UCB\Security\HMAC;
use UCB\Services\DispatchService;
use WP_Error;
use WP_REST_Request;

class Codes extends BaseController {
    protected DispatchService $dispatch;

    public function __construct() {
        $this->dispatch = new DispatchService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/codes/dispatch', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_dispatch'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/coupons/upsert', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_coupon_upsert'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_dispatch(WP_REST_Request $request) {
        $auth = HMAC::verify_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $param_rules = [
            'code' => ['required' => true],
            'type' => ['required' => true, 'enum' => ['wallet', 'coupon']],
            'amount' => ['required' => true, 'type' => 'float'],
            'currency' => ['required' => true],
            'user_email' => ['required' => true, 'type' => 'email'],
            'user_id' => ['required' => true, 'type' => 'int'],
            'card_post_id' => ['required' => true, 'type' => 'int'],
            'store_url' => ['required' => true, 'type' => 'url'],
            'expires_at' => ['type' => 'text'],
            'meta' => ['type' => 'array'],
        ];

        $sanitized = $this->sanitize_params($request, $param_rules);
        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        $card_id = (int) $sanitized['card_post_id'];
        $card = $card_id > 0 ? get_post($card_id) : null;
        if (!$card || 'uc_card' !== $card->post_type) {
            return $this->error('ucb_card_not_found', __('Card not found.', UCB_TEXT_DOMAIN), 404);
        }

        $store_url_meta = get_post_meta($card_id, 'store_url', true);
        $type_meta = get_post_meta($card_id, 'code_type', true);
        $wallet_amount_meta = get_post_meta($card_id, 'wallet_amount', true);

        if (empty($sanitized['store_url']) && !empty($store_url_meta)) {
            $sanitized['store_url'] = $store_url_meta;
        }

        if (empty($sanitized['type']) && !empty($type_meta)) {
            $sanitized['type'] = $type_meta;
        }

        if ((float) $sanitized['amount'] <= 0 && $wallet_amount_meta !== '') {
            $sanitized['amount'] = (float) $wallet_amount_meta;
        }

        $sanitized['store_url'] = esc_url_raw($sanitized['store_url']);
        if ($sanitized['store_url'] === '' || strpos($sanitized['store_url'], 'https://') !== 0) {
            return $this->error('ucb_invalid_store_url', __('Destination store must be HTTPS.', UCB_TEXT_DOMAIN), 422);
        }

        $currency = get_option('ucb_bridge_currency');
        if (!$currency) {
            $currency = get_option('woocommerce_currency', 'IRR');
        }
        if (empty($sanitized['currency'])) {
            $sanitized['currency'] = $currency;
        }

        $idempotency_key = (string) $request->get_header('Idempotency-Key');
        $payload = [
            'code'         => $sanitized['code'],
            'type'         => $sanitized['type'],
            'amount'       => (float) $sanitized['amount'],
            'currency'     => $sanitized['currency'],
            'user_email'   => $sanitized['user_email'],
            'user_id'      => (int) $sanitized['user_id'],
            'card_post_id' => $card_id,
            'store_url'    => $sanitized['store_url'],
            'expires_at'   => $sanitized['expires_at'] ?? null,
            'meta'         => $sanitized['meta'] ?? [],
        ];

        if (!isset($payload['meta']['card_title'])) {
            $payload['meta']['card_title'] = get_the_title($card_id);
        }

        if (!isset($payload['meta']['source'])) {
            $payload['meta']['source'] = 'bridge';
        }

        $result = $this->dispatch->dispatch($payload, [
            'idempotency_key' => $idempotency_key,
            'api_key' => $auth['key'],
        ]);

        if (is_wp_error($result)) {
            return $this->error(
                $result->get_error_code(),
                $result->get_error_message(),
                $result->get_error_data()['status'] ?? 500,
                $result->get_error_data()
            );
        }

        return $this->success($result);
    }

    public function handle_coupon_upsert(WP_REST_Request $request) {
        $auth = HMAC::verify_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        if (!class_exists('WC_Coupon')) {
            return $this->error('ucb_wc_missing', __('WooCommerce is required to manage coupons.', UCB_TEXT_DOMAIN), 500);
        }

        $rules = [
            'code' => ['required' => true],
            'amount' => ['required' => true, 'type' => 'float'],
            'discount_type' => ['type' => 'string', 'enum' => ['fixed_cart', 'percent', 'fixed_product']],
            'currency' => ['type' => 'string'],
            'description' => ['type' => 'text'],
            'expires_at' => ['type' => 'text'],
            'user_email' => ['type' => 'email'],
        ];

        $data = $this->sanitize_params($request, $rules);
        if (is_wp_error($data)) {
            return $data;
        }

        $coupon_code = sanitize_text_field($data['code']);
        $coupon_id = function_exists('wc_get_coupon_id_by_code') ? wc_get_coupon_id_by_code($coupon_code) : 0;
        $is_new = $coupon_id ? false : true;

        if ($is_new) {
            $coupon = new \WC_Coupon();
            $coupon->set_code($coupon_code);
        } else {
            $coupon = new \WC_Coupon($coupon_id);
        }

        $coupon->set_discount_type($data['discount_type'] ?? 'fixed_cart');
        $coupon->set_amount((float) $data['amount']);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_individual_use(true);
        $coupon->set_description($data['description'] ?? __('Issued via bridge', UCB_TEXT_DOMAIN));

        if (!empty($data['expires_at'])) {
            try {
                $date = new \WC_DateTime($data['expires_at']);
                $coupon->set_date_expires($date);
            } catch (\Exception $e) {
                // ignore invalid dates but log
                \UCB\Logger::log('warning', 'Invalid coupon expiry provided', [
                    'code' => $coupon_code,
                    'expires_at' => $data['expires_at'],
                ]);
            }
        }

        if (!empty($data['user_email']) && method_exists($coupon, 'set_email_restrictions')) {
            $coupon->set_email_restrictions([$data['user_email']]);
        }

        $coupon->save();

        return $this->success([
            'status' => 'ok',
            'coupon_id' => $coupon->get_id(),
            'is_new' => $is_new,
        ]);
    }
}

