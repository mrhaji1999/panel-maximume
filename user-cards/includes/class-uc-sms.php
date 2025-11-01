<?php
if (!defined('ABSPATH')) { exit; }

class UC_SMS {
    const GATEWAY_IPPANEL = 'ippanel';
    const IPPANEL_BASE = 'https://rest.ippanel.com/v1';
    const IPPANEL_PATTERN_ENDPOINT = self::IPPANEL_BASE . '/messages/patterns/send';
    const IPPANEL_BALANCE_ENDPOINT = self::IPPANEL_BASE . '/sms/accounting/balance';

    public static function sanitize_phone($value) {
        return self::sanitize_phone_value($value);
    }

    public static function test_connection($gateway, $username, $access_key, $sender_number = '') {
        $gateway = self::normalize_gateway($gateway);
        $access_key = trim((string) $access_key);

        if ($access_key === '') {
            throw new \InvalidArgumentException(__('توکن دسترسی وب‌سرویس وارد نشده است.', 'user-cards'));
        }

        if ($gateway !== self::GATEWAY_IPPANEL) {
            throw new \RuntimeException(__('درگاه انتخاب شده پشتیبانی نمی‌شود.', 'user-cards'));
        }

        $response = self::ippanel_request('GET', self::IPPANEL_BALANCE_ENDPOINT, null, $access_key);

        $credit = null;
        if (isset($response['data']['balance'])) {
            $credit = $response['data']['balance'];
        } elseif (isset($response['data']['Balance'])) {
            $credit = $response['data']['Balance'];
        } elseif (isset($response['data'])) {
            $credit = $response['data'];
        }

        $message = __('اتصال با موفقیت برقرار شد.', 'user-cards');
        if ($credit !== null && $credit !== '') {
            $message .= ' ' . sprintf(__('اعتبار فعلی: %s', 'user-cards'), is_scalar($credit) ? $credit : wp_json_encode($credit));
        }

        return [
            'status'  => 'success',
            'message' => $message,
            'data'    => $response,
        ];
    }

    public static function send_manual_test($gateway, $username, $access_key, $sender_number, $pattern_code, array $mappings, array $manual_values, $phone) {
        $gateway = self::normalize_gateway($gateway);
        $access_key = trim((string) $access_key);
        $pattern_code = trim((string) $pattern_code);
        $phone = self::sanitize_phone_value($phone);
        $sender_number = trim((string) $sender_number);

        if ($access_key === '' || $pattern_code === '' || $phone === '') {
            throw new \InvalidArgumentException(__('اطلاعات لازم برای ارسال پیامک تستی تکمیل نشده است.', 'user-cards'));
        }

        if ($gateway !== self::GATEWAY_IPPANEL) {
            throw new \RuntimeException(__('درگاه انتخاب شده پشتیبانی نمی‌شود.', 'user-cards'));
        }

        $values = [];
        foreach ($mappings as $mapping) {
            if (!isset($mapping['placeholder'])) {
                continue;
            }
            $placeholder = (string) $mapping['placeholder'];
            if ($placeholder === '') {
                continue;
            }
            if (array_key_exists($placeholder, $manual_values)) {
                $values[$placeholder] = (string) $manual_values[$placeholder];
            }
        }

        if (empty($values)) {
            throw new \InvalidArgumentException(__('هیچ مقدار معتبری برای متغیرها ارسال نشده است.', 'user-cards'));
        }

        $payload = [
            'pattern_code' => $pattern_code,
            'recipient'    => $phone,
            'values'       => $values,
        ];

        if ($sender_number !== '') {
            $payload['originator'] = $sender_number;
        }

        $context = [
            'username'      => (string) $username,
            'api_key'       => $access_key,
            'sender_number' => $sender_number,
            'phone'         => $phone,
            'pattern_code'  => $pattern_code,
            'values'        => $values,
            'manual'        => true,
        ];

        return self::dispatch($gateway, $payload, $context);
    }

    protected static function normalize_gateway($gateway) {
        $gateway = sanitize_key((string) $gateway);
        if ($gateway !== self::GATEWAY_IPPANEL) {
            $gateway = self::GATEWAY_IPPANEL;
        }
        return $gateway;
    }

    protected static function get_gateway_slug() {
        $gateway = get_option('uc_sms_gateway', self::GATEWAY_IPPANEL);
        return self::normalize_gateway($gateway);
    }

    public static function send_submission_confirmation($submission_id, $card_id, $user_id, $surprise, $date, $time, $card_code = '', array $context = []) {
        $pattern_code = get_post_meta($card_id, '_uc_sms_normal_pattern_code', true);
        $pattern_vars_raw = get_post_meta($card_id, '_uc_sms_normal_pattern_vars', true);

        if (empty($pattern_code)) {
            $pattern_code = get_option('uc_sms_default_pattern_code', '');
        }

        if (empty($pattern_code)) {
            return;
        }

        $mappings = UC_Settings::decode_pattern_vars($pattern_vars_raw, false);
        if (empty($mappings)) {
            $default_vars = get_option('uc_sms_default_pattern_vars', '');
            $mappings = UC_Settings::decode_pattern_vars($default_vars, true);
        }

        if (empty($mappings)) {
            return;
        }

        $username = get_option('ucb_sms_username', '');
        $access_key = get_option('ucb_sms_password', '');

        if (empty($username)) {
            $username = get_option('uc_sms_username', '');
        }

        if (empty($access_key)) {
            $access_key = get_option('uc_sms_password', '');
        }

        $sender_number = trim((string) get_option('uc_sms_sender_number', ''));

        $username = apply_filters('uc_sms_username', $username, $submission_id, $card_id, $context);
        $access_key = apply_filters('uc_sms_password', $access_key, $submission_id, $card_id, $context);
        $sender_number = apply_filters('uc_sms_sender_number', $sender_number, $submission_id, $card_id, $context);

        if ($access_key === '') {
            return;
        }

        $phone = self::resolve_recipient_phone($user_id, $context);
        if (empty($phone)) {
            return;
        }

        $variables_map = self::build_variables($submission_id, $card_id, $user_id, $surprise, $date, $time, $card_code, $phone, $context);
        $values = self::build_values_from_mappings($variables_map, $mappings);

        if (empty($values)) {
            return;
        }

        $payload = [
            'pattern_code' => $pattern_code,
            'recipient'    => $phone,
            'values'       => $values,
        ];

        if ($sender_number !== '') {
            $payload['originator'] = $sender_number;
        }

        $payload = apply_filters('uc_sms_payload', $payload, $variables_map, $submission_id, $card_id, $context);

        try {
            $response = self::dispatch(self::get_gateway_slug(), $payload, [
                'username'      => $username,
                'api_key'       => $access_key,
                'sender_number' => $sender_number,
                'phone'         => $phone,
                'pattern_code'  => $pattern_code,
                'values'        => $values,
            ]);
            do_action('uc_sms_dispatched', $submission_id, $card_id, $response, $payload, $variables_map, $context);
        } catch (\Throwable $exception) {
            error_log('UC_SMS: Failed to send SMS - ' . $exception->getMessage());
            do_action('uc_sms_failed', $submission_id, $card_id, $exception, $payload, $variables_map, $context);
        }
    }

    protected static function build_values_from_mappings(array $variables_map, array $mappings) {
        $values = [];
        foreach ($mappings as $mapping) {
            if (!isset($mapping['placeholder'], $mapping['source'])) {
                continue;
            }
            $placeholder = (string) $mapping['placeholder'];
            $source = (string) $mapping['source'];
            if ($placeholder === '' || $source === '') {
                continue;
            }
            $values[$placeholder] = isset($variables_map[$source]) ? (string) $variables_map[$source] : '';
        }
        return array_filter($values, static function ($value) {
            return $value !== '';
        });
    }

    protected static function dispatch($gateway, array $payload, array $context) {
        if ($gateway !== self::GATEWAY_IPPANEL) {
            throw new \RuntimeException(__('درگاه پیامک پشتیبانی نمی‌شود.', 'user-cards'));
        }

        if (empty($context['api_key'])) {
            throw new \RuntimeException(__('توکن دسترسی وب‌سرویس تنظیم نشده است.', 'user-cards'));
        }

        return self::dispatch_with_ippanel($payload, $context);
    }

    protected static function dispatch_with_ippanel(array $payload, array $context) {
        $response = self::ippanel_request('POST', self::IPPANEL_PATTERN_ENDPOINT, $payload, $context['api_key']);

        $result_code = '';
        if (isset($response['data']['bulk_id'])) {
            $result_code = (string) $response['data']['bulk_id'];
        } elseif (isset($response['data']['BulkId'])) {
            $result_code = (string) $response['data']['BulkId'];
        }

        return [
            'transport' => 'ippanel_rest',
            'status'    => $response['status'] ?? 'success',
            'result'    => $result_code,
            'response'  => $response,
        ];
    }

    protected static function ippanel_request($method, $endpoint, $payload, $access_key) {
        if (!function_exists('wp_remote_request')) {
            throw new \RuntimeException('WordPress HTTP API is not available.');
        }

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'AccessKey ' . $access_key,
            ],
        ];

        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $response = wp_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('UC_SMS: HTTP transport error - ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf(__('خطا در ارتباط با وب‌سرویس (کد %d): %s', 'user-cards'), $code, $raw_body));
        }

        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : [];
        if ($raw_body !== '' && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(__('پاسخ نامعتبر از وب‌سرویس دریافت شد.', 'user-cards'));
        }

        if (isset($decoded['status']) && in_array(strtolower((string) $decoded['status']), ['failed', 'error'], true)) {
            $message = isset($decoded['message']) ? $decoded['message'] : __('درگاه پیامک خطا بازگرداند.', 'user-cards');
            throw new \RuntimeException('UC_SMS: ' . $message);
        }

        return $decoded;
    }

    protected static function sanitize_phone_value($value) {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);
        if ($digits === '') {
            return '';
        }

        if (strpos($digits, '0098') === 0) {
            $digits = substr($digits, 2);
        }

        if (strpos($digits, '98') === 0) {
            return $digits;
        }

        if ($digits[0] === '0' && strlen($digits) >= 10) {
            return '98' . substr($digits, 1);
        }

        if ($digits[0] === '9' && strlen($digits) === 10) {
            return '98' . $digits;
        }

        return '98' . ltrim($digits, '0');
    }

    protected static function build_variables($submission_id, $card_id, $user_id, $surprise, $date, $time, $card_code, $phone, array $context) {
        $user = get_userdata($user_id);
        $first_name = trim((string) get_user_meta($user_id, 'first_name', true));
        $last_name  = trim((string) get_user_meta($user_id, 'last_name', true));

        $submitted_name = isset($context['customer_name']) ? trim((string) $context['customer_name']) : '';
        if ($submitted_name !== '') {
            $full_name = $submitted_name;
            if ($last_name === '' && strpos($submitted_name, ' ') !== false) {
                $parts = preg_split('/\s+/', $submitted_name);
                $last_name = trim((string) array_pop($parts));
            }
        } else {
            $full_name  = trim($first_name . ' ' . $last_name);
        }

        if ($full_name === '' && $user) {
            $full_name = $user->display_name;
        }

        if ($last_name === '' && $user) {
            $last_name = trim((string) get_user_meta($user_id, 'last_name', true));
            if ($last_name === '' && strpos($full_name, ' ') !== false) {
                $pieces = preg_split('/\s+/', $full_name);
                $last_name = trim((string) array_pop($pieces));
            }
        }

        $card_title = get_the_title($card_id);
        $jalali_date = isset($context['date']) && $context['date'] !== '' ? (string) $context['date'] : (string) $date;
        $gregorian_date = isset($context['reservation_date']) ? (string) $context['reservation_date'] : '';

        $display_phone = $phone;
        if (strpos($display_phone, '98') === 0 && strlen($display_phone) >= 12) {
            $display_phone = '0' . substr($display_phone, 2);
        }

        $variables = [
            'user_name'      => $full_name,
            'user_family'    => $last_name,
            'user_mobile'    => $display_phone,
            'card_title'     => $card_title,
            'card_code'      => $card_code,
            'submission_id'  => (string) $submission_id,
            'jalali_date'    => $jalali_date,
            'selected_time'  => $time,
            'surprise_code'  => $surprise,
            'upsell_items'   => '',
            'gregorian_date' => $gregorian_date,
        ];

        if (isset($context['form']) && is_array($context['form'])) {
            foreach ($context['form'] as $key => $value) {
                if (!isset($variables[$key])) {
                    $variables[$key] = $value;
                }
            }
        }

        return apply_filters('uc_sms_variables', $variables, $submission_id, $card_id, $user_id, $context);
    }

    protected static function get_user_phone($user_id) {
        $meta_keys = ['billing_phone', 'phone', 'mobile', 'user_phone', 'user_mobile', 'cellphone', 'user_cellphone'];

        foreach ($meta_keys as $key) {
            $phone = get_user_meta($user_id, $key, true);
            if (empty($phone)) {
                continue;
            }

            $clean = self::sanitize_phone_value($phone);
            if (!empty($clean)) {
                return (string) apply_filters('uc_sms_user_meta_phone', $clean, $key, $user_id, $phone);
            }
        }

        return '';
    }

    protected static function resolve_recipient_phone($user_id, array $context) {
        $candidates = [];

        if (!empty($context['phone'])) {
            $candidates[] = $context['phone'];
        }

        if (!empty($context['mobile'])) {
            $candidates[] = $context['mobile'];
        }

        if (isset($context['form']) && is_array($context['form'])) {
            $form_keys = ['phone', 'mobile', 'user_phone', 'user_mobile', 'cellphone', 'user_cellphone'];
            foreach ($form_keys as $key) {
                if (!empty($context['form'][$key])) {
                    $candidates[] = $context['form'][$key];
                }
            }
        }

        foreach ($candidates as $candidate) {
            $clean = self::sanitize_phone_value($candidate);
            if (!empty($clean)) {
                $clean = apply_filters('uc_sms_recipient_phone_candidate', $clean, $candidate, $context, $user_id);
                if (!empty($clean)) {
                    return (string) apply_filters('uc_sms_recipient_phone', $clean, $context, $user_id);
                }
            }
        }

        $phone = self::get_user_phone($user_id);
        return (string) apply_filters('uc_sms_recipient_phone', $phone, $context, $user_id);
    }
}
