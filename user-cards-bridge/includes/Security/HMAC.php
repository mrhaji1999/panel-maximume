<?php

namespace UCB\Security;

use WP_Error;
use WP_REST_Request;

/**
 * Handles HMAC based authentication for bridge requests.
 */
class HMAC {
    /**
     * Verify a REST request using HMAC headers.
     *
     * @param WP_REST_Request $request
     * @return array{key:string,secret:string}|WP_Error
     */
    public static function verify_request(WP_REST_Request $request) {
        $key = trim((string) $request->get_header('x-cb-key'));
        $signature = trim((string) $request->get_header('x-cb-signature'));
        $timestamp = trim((string) $request->get_header('x-cb-ts'));

        if ($key === '' || $signature === '' || $timestamp === '') {
            return new WP_Error(
                'ucb_hmac_missing_headers',
                __('Missing HMAC authentication headers.', UCB_TEXT_DOMAIN),
                ['status' => 401]
            );
        }

        if (!ctype_digit($timestamp)) {
            return new WP_Error(
                'ucb_hmac_invalid_timestamp',
                __('Invalid timestamp header.', UCB_TEXT_DOMAIN),
                ['status' => 401]
            );
        }

        $ts = (int) $timestamp;
        $now = current_time('timestamp', true);
        if (abs($now - $ts) > 300) {
            return new WP_Error(
                'ucb_hmac_timestamp_skew',
                __('Timestamp is outside of the accepted window.', UCB_TEXT_DOMAIN),
                ['status' => 401]
            );
        }

        $keys = self::get_keys();
        if (!isset($keys[$key])) {
            return new WP_Error(
                'ucb_hmac_unknown_key',
                __('Unknown API key.', UCB_TEXT_DOMAIN),
                ['status' => 401]
            );
        }

        $secret = (string) $keys[$key]['secret'];

        $path = '/' . ltrim($request->get_route(), '/');
        $raw_body = $request->get_body();

        $payload = implode("\n", [$key, $timestamp, $path, $raw_body]);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error(
                'ucb_hmac_signature_mismatch',
                __('Invalid HMAC signature.', UCB_TEXT_DOMAIN),
                ['status' => 401]
            );
        }

        $rate_limited = self::check_rate_limit($key);
        if (is_wp_error($rate_limited)) {
            return $rate_limited;
        }

        return [
            'key' => $key,
            'secret' => $secret,
        ];
    }

    /**
     * Generate signature for outgoing requests.
     */
    public static function sign(string $key, string $secret, string $path, string $body, int $timestamp): string {
        $payload = implode("\n", [$key, (string) $timestamp, $path, $body]);
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Retrieve configured API keys.
     *
     * @return array<string,array{secret:string,label?:string}>
     */
    public static function get_keys(): array {
        $option = get_option('ucb_bridge_api_keys', []);

        if (!is_array($option)) {
            return [];
        }

        $normalized = [];
        foreach ($option as $entry) {
            if (is_array($entry)) {
                $entry_key = isset($entry['key']) ? (string) $entry['key'] : '';
                $entry_secret = isset($entry['secret']) ? (string) $entry['secret'] : '';
                $label = isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '';
            } elseif (is_object($entry)) {
                $entry_key = isset($entry->key) ? (string) $entry->key : '';
                $entry_secret = isset($entry->secret) ? (string) $entry->secret : '';
                $label = isset($entry->label) ? sanitize_text_field((string) $entry->label) : '';
            } else {
                continue;
            }

            $entry_key = trim($entry_key);
            if ($entry_key === '' || $entry_secret === '') {
                continue;
            }

            $normalized[$entry_key] = [
                'secret' => (string) $entry_secret,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * Simple rate limiter per key.
     */
    protected static function check_rate_limit(string $key) {
        $limits = get_option('ucb_bridge_rate_limit', [
            'requests' => 120,
            'interval' => 300,
        ]);

        $max_requests = isset($limits['requests']) ? (int) $limits['requests'] : 120;
        $interval = isset($limits['interval']) ? (int) $limits['interval'] : 300;

        if ($max_requests <= 0 || $interval <= 0) {
            return true;
        }

        $transient_key = sprintf('ucb_hmac_rl_%s', md5($key));
        $bucket = get_transient($transient_key);

        if (!is_array($bucket)) {
            $bucket = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $now = time();
        if (($now - (int) $bucket['start']) > $interval) {
            $bucket = [
                'count' => 0,
                'start' => $now,
            ];
        }

        $bucket['count']++;

        if ($bucket['count'] > $max_requests) {
            set_transient($transient_key, $bucket, $interval);
            return new WP_Error(
                'ucb_hmac_rate_limited',
                __('Too many requests. Please slow down.', UCB_TEXT_DOMAIN),
                ['status' => 429]
            );
        }

        set_transient($transient_key, $bucket, $interval);
        return true;
    }
}

