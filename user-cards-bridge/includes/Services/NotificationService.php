<?php

namespace UCB\Services;

use UCB\SMS\PayamakPanel;
use WP_Error;

/**
 * Handles customer-facing notifications (SMS, etc.).
 */
class NotificationService {
    protected PayamakPanel $sms;

    public function __construct() {
        $this->sms = new PayamakPanel();
    }

    /**
     * Generates and sends normal status code.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function send_normal_code(int $customer_id, ?int $card_id = null) {
        $code = strtoupper(wp_generate_password(8, false, false));
        update_user_meta($customer_id, 'ucb_customer_random_code', $code);
        if ($card_id) {
            $repo = new CustomerCardRepository();
            $repo->update_random_code($customer_id, $card_id, $code);
        }

        $phone = get_user_meta($customer_id, 'phone', true);
        if (!$phone) {
            $phone = get_user_meta($customer_id, 'billing_phone', true);
        }

        if (!$phone) {
            return new WP_Error('ucb_phone_missing', __('Customer does not have a phone number.', 'user-cards-bridge'));
        }

        $result = $this->sms->send_normal_code($customer_id, $phone, $code);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'customer_id' => $customer_id,
            'code'        => $code,
            'phone'       => $phone,
            'sms_result'  => $result,
        ];
    }
}
