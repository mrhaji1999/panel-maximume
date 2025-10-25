<?php

namespace WCW\Security;

use WP_Error;
use WP_REST_Request;

class HMAC {
    public static function verify_request(WP_REST_Request $request) {
        $key = trim((string) $request->get_header('x-cb-key'));
        $signature = trim((string) $request->get_header('x-cb-signature'));
        $timestamp = trim((string) $request->get_header('x-cb-ts'));

        if ($key === '' || $signature === '' || $timestamp === '') {
            return new WP_Error('wcw_hmac_headers', __('Missing authentication headers.', 'wc-smart-wallet'), ['status' => 401]);
        }

        if (!ctype_digit($timestamp)) {
            return new WP_Error('wcw_hmac_timestamp', __('Invalid timestamp header.', 'wc-smart-wallet'), ['status' => 401]);
        }

        $ts = (int) $timestamp;
        $now = current_time('timestamp', true);
        if (abs($now - $ts) > 300) {
            return new WP_Error('wcw_hmac_skew', __('Timestamp out of range.', 'wc-smart-wallet'), ['status' => 401]);
        }

        $keys = self::get_keys();
        if (!isset($keys[$key])) {
            return new WP_Error('wcw_hmac_key', __('Unknown API key.', 'wc-smart-wallet'), ['status' => 401]);
        }

        $secret = $keys[$key]['secret'];
        $path = '/' . ltrim($request->get_route(), '/');
        $body = $request->get_body();
        $payload = implode("\n", [$key, $timestamp, $path, $body]);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error('wcw_hmac_signature', __('Invalid signature.', 'wc-smart-wallet'), ['status' => 401]);
        }

        return ['key' => $key, 'secret' => $secret];
    }

    public static function sign(string $key, string $secret, string $path, string $body, int $timestamp): string {
        $payload = implode("\n", [$key, (string) $timestamp, $path, $body]);
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function get_keys(): array {
        $raw = get_option('wcw_api_keys', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $key = trim((string) ($entry['key'] ?? ''));
                $secret = trim((string) ($entry['secret'] ?? ''));
                if ($key !== '' && $secret !== '') {
                    $out[$key] = ['secret' => $secret];
                }
            }
        }
        return $out;
    }
}
