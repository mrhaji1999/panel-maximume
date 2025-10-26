<?php

namespace UCB\Services;

use WP_Error;

class WalletBridgeService {
    public function push_wallet_code(array $payload) {
        $endpoint_path = trim((string) get_option('ucb_wallet_endpoint_path', 'wp-json/wwb/v1/wallet-codes'));
        $endpoint = trailingslashit($payload['store_link']) . ltrim($endpoint_path, '/');

        $auth_type = get_option('ucb_destination_auth_type', 'api_key');
        $auth_token = trim((string) get_option('ucb_destination_auth_token', ''));
        $hmac_secret = trim((string) get_option('ucb_destination_hmac_secret', ''));
        $default_expiry_days = (int) get_option('ucb_wallet_code_expiry_days', 0);

        $body = [
            'code' => $payload['unique_code'],
            'amount' => number_format((float) $payload['wallet_amount'], 2, '.', ''),
            'user_id' => (int) $payload['user_id'],
        ];

        if ($default_expiry_days > 0) {
            $body['expires_at'] = gmdate('Y-m-d\TH:i:s', time() + ($default_expiry_days * DAY_IN_SECONDS));
        }

        if (!empty($payload['meta']) && is_array($payload['meta'])) {
            $body['meta'] = $payload['meta'];
        }

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
            $message = isset($body['message']) ? $body['message'] : __('Destination wallet API rejected the request.', UCB_TEXT_DOMAIN);
            return new WP_Error('ucb_wallet_forward_error', $message, ['status' => $code, 'response' => $body]);
        }

        return [
            'endpoint' => $endpoint,
            'response_code' => $code,
            'body' => $body,
        ];
    }
}
