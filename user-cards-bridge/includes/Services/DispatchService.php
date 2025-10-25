<?php

namespace UCB\Services;

use UCB\Logger;
use UCB\Security\HMAC;
use WP_Error;

/**
 * Handles dispatching wallet/coupon codes to remote stores.
 */
class DispatchService {
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const CRON_HOOK = 'ucb_bridge_dispatch_retry';

    protected string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cb_dispatch_log';

        if (!has_action(self::CRON_HOOK, [$this, 'process_retry'])) {
            add_action(self::CRON_HOOK, [$this, 'process_retry']);
        }
    }

    /**
     * Attempt to dispatch a payload, creating the log entry if needed.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>|WP_Error
     */
    public function dispatch(array $payload, array $meta = []) {
        global $wpdb;

        $idempotency_key = isset($meta['idempotency_key']) ? sanitize_text_field((string) $meta['idempotency_key']) : '';
        if ($idempotency_key !== '') {
            $existing = $this->get_by_idempotency($idempotency_key);
            if ($existing) {
                return $this->build_existing_response($existing);
            }
        }

        $data = $this->normalise_payload($payload);
        if (is_wp_error($data)) {
            return $data;
        }

        $wpdb->insert(
            $this->table,
            [
                'dispatch_uuid'   => wp_generate_uuid4(),
                'code'            => $data['code'],
                'card_id'         => $data['card_id'],
                'user_id'         => $data['user_id'],
                'user_email'      => $data['user_email'],
                'store_url'       => $data['store_url'],
                'type'            => $data['type'],
                'amount'          => $data['amount'],
                'currency'        => $data['currency'],
                'payload'         => wp_json_encode($data),
                'status'          => self::STATUS_PENDING,
                'attempts'        => 0,
                'idempotency_key' => $idempotency_key,
                'expires_at'      => $data['expires_at'],
                'created_at'      => current_time('mysql', true),
                'updated_at'      => current_time('mysql', true),
            ],
            [
                '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s', '%s',
            ]
        );

        $log_id = (int) $wpdb->insert_id;
        if ($log_id <= 0) {
            return new WP_Error('ucb_dispatch_insert_failed', __('Failed to create dispatch log.', UCB_TEXT_DOMAIN), ['status' => 500]);
        }

        $result = $this->attempt_dispatch($log_id, $data, $idempotency_key);
        if (is_wp_error($result)) {
            return $result;
        }

        return $result;
    }

    /**
     * Process a retry via cron.
     */
    public function process_retry(int $log_id) {
        $record = $this->get_record($log_id);
        if (!$record) {
            return null;
        }

        if ($record->status === self::STATUS_SUCCESS) {
            return [
                'status'      => 'ok',
                'dispatch_id' => $record->dispatch_uuid,
                'mode'        => $record->type,
            ];
        }

        $payload = json_decode($record->payload, true);
        if (!is_array($payload)) {
            return new WP_Error('ucb_dispatch_invalid_payload', __('Stored payload is invalid.', UCB_TEXT_DOMAIN));
        }

        return $this->attempt_dispatch($log_id, $payload, $record->idempotency_key ?: '');
    }

    /**
     * Attempt to dispatch a record.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    protected function attempt_dispatch(int $log_id, array $payload, string $idempotency_key) {
        global $wpdb;

        $destination = $this->get_destination_config($payload['store_url']);
        if (is_wp_error($destination)) {
            $this->mark_failure($log_id, 0, $destination->get_error_message(), $destination->get_error_code());
            return $destination;
        }

        $wpdb->update(
            $this->table,
            [
                'status' => self::STATUS_PENDING,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $log_id],
            ['%s', '%s'],
            ['%d']
        );

        $endpoint = $this->get_endpoint_for_type($payload['store_url'], $payload['type']);
        $body = wp_json_encode($this->build_destination_payload($payload));
        if (false === $body) {
            $error = new WP_Error('ucb_dispatch_json_error', __('Failed to encode payload.', UCB_TEXT_DOMAIN));
            $this->mark_failure($log_id, 0, $error->get_error_message(), $error->get_error_code());
            return $error;
        }

        $timestamp = time();
        $path = wp_parse_url($endpoint, PHP_URL_PATH) ?: '/wp-json';
        $path .= wp_parse_url($endpoint, PHP_URL_QUERY) ? '?' . wp_parse_url($endpoint, PHP_URL_QUERY) : '';
        $signature = HMAC::sign($destination['key'], $destination['secret'], $path, $body, $timestamp);

        $args = [
            'timeout' => (float) $destination['timeout'],
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-CB-Key'        => $destination['key'],
                'X-CB-TS'         => (string) $timestamp,
                'X-CB-Signature'  => $signature,
                'Idempotency-Key' => $idempotency_key !== '' ? $idempotency_key : $payload['code'],
            ],
            'body'    => $body,
        ];

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            $this->mark_failure($log_id, 0, $response->get_error_message(), $response->get_error_code());
            $this->schedule_retry($log_id);
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code >= 200 && $status_code < 300) {
            $decoded = json_decode($response_body, true);
            $dispatch_id = is_array($decoded) && isset($decoded['dispatch_id'])
                ? sanitize_text_field((string) $decoded['dispatch_id'])
                : wp_generate_uuid4();

            $wpdb->update(
                $this->table,
                [
                    'status'            => self::STATUS_SUCCESS,
                    'response_body'     => $response_body,
                    'last_response_code'=> $status_code,
                    'attempts'          => $this->get_attempts($log_id) + 1,
                    'dispatch_uuid'     => $dispatch_id,
                    'updated_at'        => current_time('mysql', true),
                ],
                ['id' => $log_id],
                ['%s', '%s', '%d', '%d', '%s', '%s'],
                ['%d']
            );

            Logger::log('info', 'Dispatch succeeded', [
                'dispatch_id' => $dispatch_id,
                'log_id'      => $log_id,
                'store_url'   => $payload['store_url'],
                'code'        => $payload['code'],
            ]);

            return [
                'status'      => 'ok',
                'dispatch_id' => $dispatch_id,
                'mode'        => $payload['type'],
            ];
        }

        $this->mark_failure($log_id, $status_code, $response_body, 'http_' . $status_code);
        $this->schedule_retry($log_id);

        return new WP_Error(
            'ucb_dispatch_http_error',
            __('Remote store returned an error.', UCB_TEXT_DOMAIN),
            [
                'status' => $status_code,
                'body'   => $response_body,
            ]
        );
    }

    /**
     * Mark a failure in the log.
     */
    protected function mark_failure(int $log_id, int $status_code, string $message, string $code): void {
        global $wpdb;
        $attempts = $this->get_attempts($log_id) + 1;

        $wpdb->update(
            $this->table,
            [
                'status'            => self::STATUS_FAILED,
                'last_response_code'=> $status_code,
                'last_error'        => $message,
                'attempts'          => $attempts,
                'updated_at'        => current_time('mysql', true),
            ],
            ['id' => $log_id],
            ['%s', '%d', '%s', '%d', '%s'],
            ['%d']
        );

        Logger::log('error', 'Dispatch failed', [
            'log_id' => $log_id,
            'status_code' => $status_code,
            'message' => $message,
            'code' => $code,
        ]);
    }

    /**
     * Schedule retry respecting backoff.
     */
    protected function schedule_retry(int $log_id): void {
        $max_attempts = (int) get_option('ucb_bridge_retry_limit', 10);
        $attempts = $this->get_attempts($log_id);

        if ($attempts >= $max_attempts) {
            Logger::log('warning', 'Dispatch retries exceeded', [
                'log_id' => $log_id,
                'attempts' => $attempts,
            ]);
            return;
        }

        $delay = $this->calculate_backoff_delay($attempts);
        wp_schedule_single_event(time() + $delay, self::CRON_HOOK, [$log_id]);
    }

    /**
     * Calculate exponential backoff delay.
     */
    protected function calculate_backoff_delay(int $attempts): int {
        $base = 30; // seconds
        $delay = $base * (2 ** max(0, $attempts - 1));
        return min($delay, DAY_IN_SECONDS);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    protected function normalise_payload(array $payload) {
        $required = ['code', 'type', 'amount', 'currency', 'user_email', 'user_id', 'card_post_id', 'store_url'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                return new WP_Error('ucb_dispatch_missing_field', sprintf(__('Missing field: %s', UCB_TEXT_DOMAIN), $field));
            }
        }

        $store_url = esc_url_raw((string) $payload['store_url']);
        if ($store_url === '' || strpos($store_url, 'https://') !== 0) {
            return new WP_Error('ucb_dispatch_invalid_store', __('Store URL must be HTTPS.', UCB_TEXT_DOMAIN));
        }

        if ($payload['amount'] <= 0) {
            return new WP_Error('ucb_dispatch_amount', __('Dispatch amount must be greater than zero.', UCB_TEXT_DOMAIN));
        }

        return [
            'code'       => sanitize_text_field((string) $payload['code']),
            'type'       => in_array($payload['type'], ['wallet', 'coupon'], true) ? $payload['type'] : 'coupon',
            'amount'     => (float) $payload['amount'],
            'currency'   => sanitize_text_field((string) $payload['currency']),
            'user_email' => sanitize_email((string) $payload['user_email']),
            'user_id'    => (int) $payload['user_id'],
            'card_id'    => (int) $payload['card_post_id'],
            'store_url'  => untrailingslashit($store_url),
            'expires_at' => isset($payload['expires_at']) ? sanitize_text_field((string) $payload['expires_at']) : null,
            'meta'       => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [],
        ];
    }

    /**
     * Retrieve record by idempotency key.
     *
     * @return object|null
     */
    public function get_by_idempotency(string $key) {
        global $wpdb;
        if ($key === '') {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE idempotency_key = %s LIMIT 1", $key));
    }

    /**
     * @return object|null
     */
    public function get_record(int $log_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $log_id));
    }

    /**
     * Build response for existing dispatch.
     */
    protected function build_existing_response(object $record): array {
        if ($record->status === self::STATUS_SUCCESS) {
            $dispatch_id = $record->dispatch_uuid ?: wp_generate_uuid4();
            return [
                'status'      => 'ok',
                'dispatch_id' => $dispatch_id,
                'mode'        => $record->type,
            ];
        }

        return [
            'status'      => $record->status,
            'dispatch_id' => $record->dispatch_uuid,
            'mode'        => $record->type,
        ];
    }

    /**
     * Retrieve destination configuration.
     *
     * @return array<string,mixed>|WP_Error
     */
    protected function get_destination_config(string $store_url) {
        $destinations = get_option('ucb_bridge_destinations', []);
        if (!is_array($destinations)) {
            $destinations = [];
        }

        foreach ($destinations as $destination) {
            if (!is_array($destination)) {
                continue;
            }

            $url = isset($destination['store_url']) ? untrailingslashit((string) $destination['store_url']) : '';
            if ($url === '') {
                continue;
            }

            if (untrailingslashit($store_url) === $url) {
                $key = isset($destination['key']) ? (string) $destination['key'] : '';
                $secret = isset($destination['secret']) ? (string) $destination['secret'] : '';
                $timeout = isset($destination['timeout']) ? (float) $destination['timeout'] : 20.0;

                if ($key === '' || $secret === '') {
                    return new WP_Error('ucb_dispatch_missing_destination_key', __('Destination key/secret missing.', UCB_TEXT_DOMAIN));
                }

                return [
                    'store_url' => $url,
                    'key'       => $key,
                    'secret'    => $secret,
                    'timeout'   => max(5.0, $timeout),
                ];
            }
        }

        return new WP_Error('ucb_dispatch_destination_not_found', __('Destination store configuration not found.', UCB_TEXT_DOMAIN));
    }

    /**
     * Build endpoint URL based on type.
     */
    protected function get_endpoint_for_type(string $store_url, string $type): string {
        $base = untrailingslashit($store_url);
        if ($type === 'wallet') {
            return $base . '/wp-json/wc-smart-wallet/v1/codes/upsert';
        }

        return $base . '/wp-json/cards-bridge/v1/coupons/upsert';
    }

    /**
     * Format payload for destination store.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function build_destination_payload(array $payload): array {
        $base = [
            'code'      => $payload['code'],
            'amount'    => $payload['amount'],
            'currency'  => $payload['currency'],
            'user_email'=> $payload['user_email'],
            'meta'      => $payload['meta'],
        ];

        if (!empty($payload['expires_at'])) {
            $base['expires_at'] = $payload['expires_at'];
        }

        if ($payload['type'] === 'coupon') {
            $base['discount_type'] = 'fixed_cart';
            $base['description'] = sprintf(
                __('Issued by Central Bridge for %s', UCB_TEXT_DOMAIN),
                $payload['user_email']
            );
        }

        return $base;
    }

    /**
     * Retrieve attempt count.
     */
    protected function get_attempts(int $log_id): int {
        global $wpdb;
        $attempts = (int) $wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$this->table} WHERE id = %d", $log_id));
        return $attempts;
    }
}

