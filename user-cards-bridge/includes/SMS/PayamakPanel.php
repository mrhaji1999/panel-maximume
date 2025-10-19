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
    public const WSDL = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';

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

        $payload = [
            'username' => $username,
            'password' => $password,
            'text'     => array_map('strval', $variables),
            'to'       => $phone,
            'bodyId'   => $body_id,
        ];

        try {
            $client = new SoapClient(self::WSDL, [
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'connection_timeout' => 10,
            ]);

            $response = $client->SendByBaseNumber($payload);
            $raw_result = isset($response->SendByBaseNumberResult) ? (string) $response->SendByBaseNumberResult : '';
            $result = $this->interpret_gateway_result($raw_result, $payload);
        } catch (SoapFault $fault) {
            $result = new WP_Error('ucb_sms_fault', $fault->getMessage(), ['code' => $fault->faultcode]);
        } catch (\Exception $exception) {
            $result = new WP_Error('ucb_sms_error', $exception->getMessage());
        }

        $log_payload = [
            'customer_id'   => $customer_id,
            'phone'         => $phone,
            'message'       => wp_json_encode($variables),
            'body_id'       => $body_id,
            'result_code'   => null,
            'result_message'=> null,
            'rec_id'        => null,
            'sent_by'       => $sent_by ?: get_current_user_id(),
        ];

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $log_payload['result_code'] = isset($data['result']) ? (string) $data['result'] : 'error';
            $log_payload['result_message'] = $result->get_error_message();
        } else {
            $log_payload['result_code'] = $result['result'];
            $log_payload['result_message'] = __('Success', 'user-cards-bridge');
            $log_payload['rec_id'] = $result['rec_id'];
        }

        $this->logger->sms($log_payload);

        return $result;
    }

    /**
     * Interpret the raw gateway response and normalise it.
     *
     * @param string               $raw_result
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|WP_Error
     */
    protected function interpret_gateway_result(string $raw_result, array $payload) {
        $trimmed = trim($raw_result);

        if ($trimmed === '') {
            return new WP_Error('ucb_sms_empty_response', __('Empty response from SMS gateway.', 'user-cards-bridge'));
        }

        $success_threshold = 10;
        if (ctype_digit($trimmed) && strlen($trimmed) >= $success_threshold) {
            return [
                'result'  => $trimmed,
                'rec_id'  => $trimmed,
                'payload' => $payload,
            ];
        }

        $messages = [
            '-10' => __('Message variables contain a URL.', 'user-cards-bridge'),
            '-7'  => __('Sender number is invalid. Please contact support.', 'user-cards-bridge'),
            '-6'  => __('Internal gateway error. Please contact support.', 'user-cards-bridge'),
            '-5'  => __('Message text does not match the approved template.', 'user-cards-bridge'),
            '-4'  => __('Provided bodyId is invalid or not approved.', 'user-cards-bridge'),
            '-3'  => __('Sender line is not defined. Please contact support.', 'user-cards-bridge'),
            '-2'  => __('Only a single recipient is allowed for this message.', 'user-cards-bridge'),
            '-1'  => __('Access to this web service is disabled.', 'user-cards-bridge'),
            '0'   => __('Invalid username or password.', 'user-cards-bridge'),
            '2'   => __('Insufficient SMS credit.', 'user-cards-bridge'),
            '6'   => __('SMS gateway is currently updating. Please try again later.', 'user-cards-bridge'),
            '7'   => __('Message contains a filtered keyword. Contact support.', 'user-cards-bridge'),
            '10'  => __('The requested user account is inactive.', 'user-cards-bridge'),
            '11'  => __('Message was not sent.', 'user-cards-bridge'),
            '12'  => __('User documentation is incomplete.', 'user-cards-bridge'),
            '16'  => __('Recipient number not found.', 'user-cards-bridge'),
            '17'  => __('Message text cannot be empty.', 'user-cards-bridge'),
            '18'  => __('Recipient number is invalid.', 'user-cards-bridge'),
        ];

        if (isset($messages[$trimmed])) {
            return new WP_Error('ucb_sms_gateway_error', $messages[$trimmed], ['result' => $trimmed]);
        }

        return new WP_Error(
            'ucb_sms_gateway_error',
            sprintf(__('Unexpected gateway response: %s', 'user-cards-bridge'), $trimmed),
            ['result' => $trimmed]
        );
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
        if (!class_exists(SoapClient::class)) {
            return [
                'success' => false,
                'message' => __('SOAP extension is required for SMS integration.', 'user-cards-bridge'),
            ];
        }

        $username = get_option('ucb_sms_username', '');
        $password = get_option('ucb_sms_password', '');

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => __('Please configure SMS username and password first.', 'user-cards-bridge'),
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
