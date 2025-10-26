<?php

namespace UCB\Services;

use WP_Error;

class CouponService {
    public function forward_coupon(array $payload) {
        $mode = get_option('ucb_coupon_api_mode', 'woo_rest');

        if ('generic' === $mode) {
            return $this->forward_generic($payload);
        }

        return $this->forward_woo_rest($payload);
    }

    protected function forward_woo_rest(array $payload) {
        $consumer_key = trim((string) get_option('ucb_coupon_wc_consumer_key', ''));
        $consumer_secret = trim((string) get_option('ucb_coupon_wc_consumer_secret', ''));

        if ($consumer_key === '' || $consumer_secret === '') {
            return new WP_Error('ucb_coupon_credentials_missing', __('WooCommerce REST credentials are not configured.', UCB_TEXT_DOMAIN));
        }

        $endpoint = trailingslashit($payload['store_link']) . 'wp-json/wc/v3/coupons';
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
        ];

        $body = $this->build_coupon_payload($payload);

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $message = isset($body['message']) ? $body['message'] : __('Failed to create coupon on destination store.', UCB_TEXT_DOMAIN);
            return new WP_Error('ucb_coupon_http_error', $message, ['status' => $code, 'response' => $body]);
        }

        return [
            'endpoint' => $endpoint,
            'response_code' => $code,
            'body' => $body,
        ];
    }

    protected function forward_generic(array $payload) {
        $api_path = trim((string) get_option('ucb_coupon_generic_endpoint', 'api/coupons'));
        $endpoint = trailingslashit($payload['store_link']) . ltrim($api_path, '/');
        $auth_type = get_option('ucb_destination_auth_type', 'api_key');
        $auth_token = trim((string) get_option('ucb_destination_auth_token', ''));
        $hmac_secret = trim((string) get_option('ucb_destination_hmac_secret', ''));

        $body = $this->build_coupon_payload($payload);
        $body_json = wp_json_encode($body);

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($auth_type === 'jwt' && $auth_token !== '') {
            $headers['Authorization'] = 'Bearer ' . $auth_token;
        } elseif ($auth_type === 'api_key' && $auth_token !== '') {
            $headers['X-API-Key'] = $auth_token;
        }

        if ($hmac_secret !== '') {
            $headers['X-WWB-Signature'] = hash_hmac('sha256', $body_json, $hmac_secret);
        }

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => $body_json,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $message = isset($body['message']) ? $body['message'] : __('Destination coupon API rejected the request.', UCB_TEXT_DOMAIN);
            return new WP_Error('ucb_coupon_generic_error', $message, ['status' => $code, 'response' => $body]);
        }

        return [
            'endpoint' => $endpoint,
            'response_code' => $code,
            'body' => $body,
        ];
    }

    protected function build_coupon_payload(array $payload): array {
        $usage_limit = max(0, (int) get_option('ucb_coupon_usage_limit', 1));
        $usage_limit_per_user = max(0, (int) get_option('ucb_coupon_usage_limit_per_user', 1));
        $expiry_days = (int) get_option('ucb_coupon_expiry_days', 0);

        $body = [
            'code' => $payload['unique_code'],
            'discount_type' => 'fixed_cart',
            'amount' => number_format((float) $payload['wallet_amount'], 2, '.', ''),
            'description' => sprintf(__('Forwarded coupon for card #%1$d user #%2$d', UCB_TEXT_DOMAIN), $payload['product_id'], $payload['user_id']),
        ];

        if ($usage_limit > 0) {
            $body['usage_limit'] = $usage_limit;
        }

        if ($usage_limit_per_user > 0) {
            $body['usage_limit_per_user'] = $usage_limit_per_user;
        }

        if ($expiry_days > 0) {
            $body['date_expires'] = gmdate('Y-m-d\TH:i:s', time() + ($expiry_days * DAY_IN_SECONDS));
        }

        if (!empty($payload['meta']) && is_array($payload['meta'])) {
            $body['meta_data'] = [];
            foreach ($payload['meta'] as $meta_key => $meta_value) {
                $body['meta_data'][] = [
                    'key' => sanitize_key($meta_key),
                    'value' => maybe_serialize($meta_value),
                ];
            }
        }

        return $body;
    }
}
