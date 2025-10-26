<?php

namespace WWB;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Security {
    public function __construct() {
        add_filter('rest_pre_dispatch', [$this, 'verify_rest_request'], 10, 3);
        add_action('rest_api_init', [$this, 'register_cors_headers'], 5);
    }

    public function register_cors_headers(): void {
        add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
            if (strpos($request->get_route(), '/wwb/v1/') !== 0) {
                return $served;
            }

            $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
            if ($origin) {
                $server->send_header('Access-Control-Allow-Origin', $origin);
                $server->send_header('Vary', 'Origin');
                $server->send_header('Access-Control-Allow-Credentials', 'true');
            }

            if ($request->get_method() === WP_REST_Server::EDITABLE || $request->get_method() === 'OPTIONS') {
                $server->send_header('Access-Control-Allow-Methods', 'POST, OPTIONS');
                $server->send_header('Access-Control-Allow-Headers', 'Authorization, X-API-Key, X-WWB-Signature, Content-Type');
            }

            if ('OPTIONS' === $request->get_method()) {
                return true;
            }

            return $served;
        }, 10, 4);
    }

    public function verify_rest_request($response, WP_REST_Server $server, WP_REST_Request $request) {
        if (strpos($request->get_route(), '/wwb/v1/') !== 0) {
            return $response;
        }

        $auth = self::verify_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        return $response;
    }

    public static function verify_request(WP_REST_Request $request) {
        $auth_type = get_option('wwb_auth_type', 'api_key');
        $expected_token = (string) get_option('wwb_auth_token', '');
        $provided_token = '';

        if ('jwt' === $auth_type) {
            $header = $request->get_header('authorization');
            if ($header && stripos($header, 'bearer ') === 0) {
                $provided_token = trim(substr($header, 7));
            }
        } else {
            $provided_token = $request->get_header('x-api-key');
        }

        if (!empty($expected_token) && $provided_token !== $expected_token) {
            return new WP_Error('wwb_auth_failed', __('Authentication failed for wallet bridge endpoint.', 'woo-wallet-bridge'), ['status' => 401]);
        }

        $secret = (string) get_option('wwb_hmac_secret', '');
        if (!empty($secret)) {
            $signature = $request->get_header('x-wwb-signature');
            $payload = $request->get_body();
            $expected = hash_hmac('sha256', $payload, $secret);
            if (empty($signature) || !hash_equals($expected, $signature)) {
                return new WP_Error('wwb_signature_mismatch', __('Invalid request signature.', 'woo-wallet-bridge'), ['status' => 401]);
            }
        }

        return true;
    }
}
