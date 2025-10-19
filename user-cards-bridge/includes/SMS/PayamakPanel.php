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
            $result = [
                'result' => isset($response->SendByBaseNumberResult) ? (string) $response->SendByBaseNumberResult : '',
                'payload' => $payload,
            ];
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
            'result_code'   => is_wp_error($result) ? 'error' : $result['result'],
            'result_message'=> is_wp_error($result) ? $result->get_error_message() : __('Success', 'user-cards-bridge'),
            'rec_id'        => !is_wp_error($result) ? $result['result'] : null,
            'sent_by'       => $sent_by ?: get_current_user_id(),
        ];

        $this->logger->sms($log_payload);

        return $result;
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
