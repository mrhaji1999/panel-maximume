<?php

namespace UCB\SMS;

use SoapClient;
use SoapFault;
use UCB\Logger;
use WP_Error;

/**
 * Payamak Panel SOAP integration.
 */
class PayamakPanel {
    public const GATEWAY_PAYAMAK_PANEL = 'payamak_panel';
    public const GATEWAY_IRAN_PAYAMAK = 'iran_payamak';
    public const WSDL = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';
    private const IRAN_PAYAMAK_PATTERN_ENDPOINT = 'https://rest.iranpayamak.com/api/Pattern/Send';

    /**
     * @var Logger
     */
    protected $logger;

    public function __construct() {
        $this->logger = Logger::get_instance();
    }

    /**
     * Sends templated SMS message.
     *
     * @param int|null $customer_id
     * @param string   $phone
     * @param string   $body_id
     * @param array    $variables
     * @param int|null $sent_by
     * @return array<string, mixed>|WP_Error
     */
    public function send(?int $customer_id, string $phone, string $body_id, array $variables, ?int $sent_by = null) {
        $username = get_option('ucb_sms_username', '');
        $password = get_option('ucb_sms_password', '');

        if (empty($username) || empty($password)) {
            return new WP_Error('ucb_sms_credentials_missing', __('SMS credentials are not configured.', 'user-cards-bridge'));
        }

        $gateway = $this->get_gateway();

        if (self::GATEWAY_IRAN_PAYAMAK === $gateway) {
            $result = $this->send_via_iran_payamak($customer_id, $phone, $body_id, $variables, $sent_by, $username, $password);
        } else {
            $result = $this->send_via_payamak_panel($customer_id, $phone, $body_id, $variables, $sent_by, $username, $password);
        }

        $log_payload = [
            'customer_id'   => $customer_id,
            'phone'         => $phone,
            'message'       => wp_json_encode($variables),
            'body_id'       => $body_id,
            'result_code'   => is_wp_error($result) ? 'error' : ($result['result'] ?? 'success'),
            'result_message'=> is_wp_error($result) ? $result->get_error_message() : ($result['message'] ?? __('Success', 'user-cards-bridge')),
            'rec_id'        => !is_wp_error($result) ? ($result['result'] ?? null) : null,
            'sent_by'       => $sent_by ?: get_current_user_id(),
        ];

        $this->logger->sms($log_payload);

        return $result;
    }

    protected function get_gateway(): string {
        $gateway = get_option('ucb_sms_gateway', self::GATEWAY_PAYAMAK_PANEL);
        $gateway = is_string($gateway) ? sanitize_key($gateway) : self::GATEWAY_PAYAMAK_PANEL;

        if (!in_array($gateway, [self::GATEWAY_PAYAMAK_PANEL, self::GATEWAY_IRAN_PAYAMAK], true)) {
            $gateway = self::GATEWAY_PAYAMAK_PANEL;
        }

        return (string) apply_filters('ucb_sms_active_gateway', $gateway);
    }

    protected function get_sender_number(?string $body_id = null): string {
        $sender = trim((string) get_option('ucb_sms_sender_number', ''));

        return (string) apply_filters('ucb_sms_sender_number', $sender, $body_id, $this->get_gateway());
    }

    protected function send_via_payamak_panel(?int $customer_id, string $phone, string $body_id, array $variables, ?int $sent_by, string $username, string $password) {
        if ($phone === '') {
            return new WP_Error('ucb_sms_phone_missing', __('Destination phone number is required for SMS delivery.', 'user-cards-bridge'));
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'text'     => array_map('strval', $variables),
            'to'       => $phone,
            'bodyId'   => $body_id,
        ];

        $payload = apply_filters('ucb_sms_payamak_payload', $payload, $customer_id, $phone, $body_id, $variables, $sent_by);

        try {
            $client = new SoapClient(self::WSDL, [
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'connection_timeout' => 10,
            ]);

            $response = $client->SendByBaseNumber($payload);
            return [
                'result'  => isset($response->SendByBaseNumberResult) ? (string) $response->SendByBaseNumberResult : '',
                'payload' => $payload,
                'message' => __('Success', 'user-cards-bridge'),
            ];
        } catch (SoapFault $fault) {
            return new WP_Error('ucb_sms_fault', $fault->getMessage(), ['code' => $fault->faultcode]);
        } catch (\Exception $exception) {
            return new WP_Error('ucb_sms_error', $exception->getMessage());
        }
    }

    protected function send_via_iran_payamak(?int $customer_id, string $phone, string $body_id, array $variables, ?int $sent_by, string $username, string $password) {
        if ($phone === '') {
            return new WP_Error('ucb_sms_phone_missing', __('Destination phone number is required for SMS delivery.', 'user-cards-bridge'));
        }

        if (!function_exists('wp_remote_post')) {
            return new WP_Error('ucb_sms_http_missing', __('WordPress HTTP API is required for IranPayamak integration.', 'user-cards-bridge'));
        }

        if ($body_id === '') {
            return new WP_Error('ucb_sms_body_missing', __('Pattern code is required for IranPayamak.', 'user-cards-bridge'));
        }

        $sender = $this->get_sender_number($body_id);
        $values = array_map('strval', $variables);
        $input_data = [];

        foreach ($values as $index => $value) {
            $key = 'value' . ($index + 1);
            $input_data[] = [
                'Parameter' => $key,
                'Name'      => $key,
                'Value'     => $value,
            ];
        }

        $payload = [
            'username'     => $username,
            'password'     => $password,
            'pattern_code' => $body_id,
            'patternCode'  => $body_id,
            'to'           => [$phone],
            'mobile'       => [$phone],
            'recipient'    => $phone,
            'values'       => $values,
            'input_data'   => $input_data,
            'inputData'    => $input_data,
        ];

        if ($sender !== '') {
            $payload['from'] = $sender;
            $payload['originator'] = $sender;
        }

        $payload = apply_filters('ucb_sms_iranpayamak_payload', $payload, $customer_id, $phone, $body_id, $variables, $sent_by);

        $response = wp_remote_post(self::IRAN_PAYAMAK_PATTERN_ENDPOINT, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('ucb_sms_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            return new WP_Error('ucb_sms_http_error', sprintf(__('Gateway HTTP status %d', 'user-cards-bridge'), $status), ['response' => $raw_body]);
        }

        $data = null;
        if ($raw_body !== '') {
            $decoded = json_decode($raw_body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        $result_code = '';
        $message = '';
        $success = true;

        if (is_array($data)) {
            $status_values = [];
            if (array_key_exists('status', $data)) {
                $status_values[] = $data['status'];
            }
            if (array_key_exists('Status', $data)) {
                $status_values[] = $data['Status'];
            }

            foreach ($status_values as $status_value) {
                if (is_bool($status_value)) {
                    $success = $success && $status_value;
                } elseif (is_numeric($status_value)) {
                    $success = $success && ((int) $status_value >= 0);
                } else {
                    $normalized = strtolower((string) $status_value);
                    if (in_array($normalized, ['false', 'failed', 'error'], true)) {
                        $success = false;
                    }
                }
            }

            if (isset($data['code']) || isset($data['Code'])) {
                $code_value = isset($data['code']) ? $data['code'] : $data['Code'];
                if (is_numeric($code_value) && (int) $code_value < 0) {
                    $success = false;
                }
                $result_code = (string) $code_value;
            }

            if (isset($data['recId']) || isset($data['RecId'])) {
                $result_code = (string) ($data['recId'] ?? $data['RecId']);
            }

            if (isset($data['message']) || isset($data['Message'])) {
                $message = (string) ($data['message'] ?? $data['Message']);
            }
        } elseif ($raw_body !== '') {
            $result_code = $raw_body;
        }

        if (!$success) {
            if ($message === '') {
                $message = $result_code !== '' ? $result_code : __('SMS gateway returned an error.', 'user-cards-bridge');
            }

            return new WP_Error('ucb_sms_error', $message, ['response' => $data ?? $raw_body]);
        }

        if ($result_code === '') {
            $result_code = 'success';
        }

        return [
            'result'   => $result_code,
            'payload'  => $payload,
            'response' => $data !== null ? $data : $raw_body,
            'message'  => $message !== '' ? $message : __('Success', 'user-cards-bridge'),
        ];
    }

    /**
     * Send upsell payment link SMS.
     */
    public function send_upsell(int $customer_id, string $phone, string $link, string $label, string $amount): array|WP_Error {
        $body_id = get_option('ucb_sms_upsell_body_id', '');

        if (empty($body_id)) {
            return new WP_Error('ucb_sms_body_missing', __('Upsell bodyId is not configured.', 'user-cards-bridge'));
        }

        $variables = [
            $label,
            $amount,
            $link,
        ];

        return $this->send($customer_id, $phone, $body_id, $variables);
    }

    /**
     * Send normal status code.
     */
    public function send_normal_code(int $customer_id, string $phone, string $code): array|WP_Error {
        $body_id = get_option('ucb_sms_normal_body_id', '');

        if (empty($body_id)) {
            return new WP_Error('ucb_sms_body_missing', __('Normal status bodyId is not configured.', 'user-cards-bridge'));
        }

        return $this->send($customer_id, $phone, $body_id, [$code]);
    }

    /**
     * Provide SMS delivery statistics.
     */
    public static function get_statistics(int $days = 7): array {
        $database = new \UCB\Database();
        return $database->get_sms_statistics($days);
    }

    /**
     * Test SMS configuration without sending a message.
     *
     * @return array<string, mixed>
     */
    public function test_configuration(): array {
        $gateway = $this->get_gateway();
        $username = get_option('ucb_sms_username', '');
        $password = get_option('ucb_sms_password', '');

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => __('Please configure SMS username and password first.', 'user-cards-bridge'),
            ];
        }

        if (self::GATEWAY_IRAN_PAYAMAK === $gateway) {
            if (!function_exists('wp_remote_post')) {
                return [
                    'success' => false,
                    'message' => __('WordPress HTTP API is required for IranPayamak integration.', 'user-cards-bridge'),
                ];
            }

            return [
                'success' => true,
                'message' => __('Credentials saved. Send a test SMS to verify IranPayamak connectivity.', 'user-cards-bridge'),
            ];
        }

        if (!class_exists(SoapClient::class)) {
            return [
                'success' => false,
                'message' => __('SOAP extension is required for Payamak Panel integration.', 'user-cards-bridge'),
            ];
        }

        try {
            $client = new SoapClient(self::WSDL, [
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'connection_timeout' => 5,
            ]);

            // Trigger a lightweight request to ensure connectivity.
            $client->__getFunctions();
        } catch (SoapFault $fault) {
            return [
                'success' => false,
                'message' => sprintf(__('Connection failed: %s', 'user-cards-bridge'), $fault->getMessage()),
            ];
        } catch (\Exception $exception) {
            return [
                'success' => false,
                'message' => sprintf(__('Unexpected error: %s', 'user-cards-bridge'), $exception->getMessage()),
            ];
        }

        return [
            'success' => true,
            'message' => __('SMS credentials look good and the service is reachable.', 'user-cards-bridge'),
        ];
    }
}
