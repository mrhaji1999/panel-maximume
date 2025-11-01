<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Simple SMS helper for Payamak Panel SOAP API.
 */
class UC_SMS {
    /** @var string */
    const GATEWAY_PAYAMAK_PANEL = 'payamak_panel';

    /** @var string */
    const GATEWAY_IRAN_PAYAMAK = 'iran_payamak';

    /** @var string */
    const WSDL = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';

    /** @var string */
    const SERVICE_ENDPOINT = 'http://api.payamak-panel.com/post/Send.asmx';

    /** @var string */
    const SOAP_ACTION = 'http://tempuri.org/SendByBaseNumber';

    /** @var string */
    const SOAP_ACTION_GET_CREDIT = 'http://tempuri.org/GetCredit';

    /** @var string */
    const IRAN_PAYAMAK_PATTERN_ENDPOINT = 'https://rest.iranpayamak.com/api/Pattern/Send';

    /** @var string */
    const DEFAULT_VARIABLE_ORDER = 'user_name,card_title,jalali_date,selected_time,surprise_code';

    public static function sanitize_phone($value) {
        return self::sanitize_phone_value($value);
    }

    public static function normalize_pattern_variables($pattern_vars) {
        return self::normalize_pattern_vars($pattern_vars);
    }

    public static function parse_manual_variables_input($input, array $keys = []) {
        $input = trim((string) $input);
        if ($input === '') {
            return [
                'map'     => [],
                'ordered' => [],
            ];
        }

        $map = [];
        $ordered = [];
        $lines = preg_split('/\r\n|\n|\r/', $input);
        $has_pairs = false;

        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }

                if (strpos($line, '=') !== false || strpos($line, ':') !== false) {
                    $delimiter = strpos($line, '=') !== false ? '=' : ':';
                    [$raw_key, $raw_value] = array_map('trim', explode($delimiter, $line, 2));
                    $key = sanitize_key($raw_key);
                    if ($key !== '') {
                        $map[$key] = $raw_value;
                        $has_pairs = true;
                        continue;
                    }
                }

                $ordered[] = $line;
            }
        }

        if (!$has_pairs && empty($ordered)) {
            $ordered = array_values(array_filter(array_map('trim', explode(',', $input)), static function ($value) {
                return $value !== '';
            }));
        }

        if (!empty($ordered) && !empty($keys)) {
            foreach ($keys as $index => $key) {
                if (isset($map[$key])) {
                    continue;
                }
                if (isset($ordered[$index])) {
                    $map[$key] = $ordered[$index];
                }
            }
        }

        return [
            'map'     => $map,
            'ordered' => array_values($ordered),
        ];
    }

    public static function test_connection($gateway, $username, $password, $sender_number = '') {
        $gateway = sanitize_key((string) $gateway);
        if (!in_array($gateway, [self::GATEWAY_PAYAMAK_PANEL, self::GATEWAY_IRAN_PAYAMAK], true)) {
            $gateway = self::GATEWAY_PAYAMAK_PANEL;
        }

        $username = trim((string) $username);
        $password = trim((string) $password);

        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException(__('نام کاربری یا کلمه عبور معتبر نیست.', 'user-cards'));
        }

        if ($gateway === self::GATEWAY_IRAN_PAYAMAK) {
            return self::test_with_iran_payamak($username, $password);
        }

        return self::test_with_payamak_panel($username, $password);
    }

    public static function send_manual_test($gateway, $username, $password, $sender_number, $pattern_code, array $keys, array $variables_map, array $text_variables, $phone) {
        $gateway = sanitize_key((string) $gateway);
        if (!in_array($gateway, [self::GATEWAY_PAYAMAK_PANEL, self::GATEWAY_IRAN_PAYAMAK], true)) {
            $gateway = self::GATEWAY_PAYAMAK_PANEL;
        }

        $username = trim((string) $username);
        $password = trim((string) $password);
        $pattern_code = trim((string) $pattern_code);
        $phone = self::sanitize_phone_value($phone);
        $sender_number = trim((string) $sender_number);

        if ($username === '' || $password === '' || $pattern_code === '' || $phone === '') {
            throw new \InvalidArgumentException(__('اطلاعات لازم برای ارسال پیامک تستی تکمیل نشده است.', 'user-cards'));
        }

        $keys = array_values(array_filter(array_map('sanitize_key', $keys)));
        if (empty($keys)) {
            throw new \InvalidArgumentException(__('ترتیب متغیرهای پیامک نامعتبر است.', 'user-cards'));
        }

        $text_variables = array_map('strval', $text_variables);

        if (count($text_variables) < count($keys)) {
            $text_variables = array_pad($text_variables, count($keys), '');
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'text'     => $text_variables,
            'to'       => $phone,
            'bodyId'   => is_numeric($pattern_code) ? (int) $pattern_code : $pattern_code,
        ];

        $context = [
            'username'       => $username,
            'password'       => $password,
            'sender_number'  => $sender_number,
            'phone'          => $phone,
            'pattern_code'   => $pattern_code,
            'variable_keys'  => $keys,
            'variables_map'  => $variables_map,
            'text_variables' => $text_variables,
            'payload'        => $payload,
        ];

        $response = self::dispatch($gateway, $payload, $context);

        return $response;
    }

    protected static function get_gateway_slug() {
        $gateway = get_option('uc_sms_gateway', self::GATEWAY_PAYAMAK_PANEL);
        $gateway = is_string($gateway) ? sanitize_key($gateway) : self::GATEWAY_PAYAMAK_PANEL;

        if (!in_array($gateway, [self::GATEWAY_PAYAMAK_PANEL, self::GATEWAY_IRAN_PAYAMAK], true)) {
            $gateway = self::GATEWAY_PAYAMAK_PANEL;
        }

        return (string) apply_filters('uc_sms_active_gateway', $gateway);
    }

    /**
     * Send confirmation SMS after a submission is created.
     *
     * @param int    $submission_id Submission post ID.
     * @param int    $card_id       Card post ID.
     * @param int    $user_id       User ID.
     * @param string $surprise      Surprise code generated for the submission.
     * @param string $date          Selected date (Jalali string).
     * @param string $time          Selected time string.
     * @param string $card_code     Redemption code entered by the customer.
     * @param array  $context       Additional sanitized context captured from the submission.
     */
    public static function send_submission_confirmation($submission_id, $card_id, $user_id, $surprise, $date, $time, $card_code = '', array $context = []) {
        $pattern_code = get_post_meta($card_id, '_uc_sms_normal_pattern_code', true);
        $pattern_vars = get_post_meta($card_id, '_uc_sms_normal_pattern_vars', true);

        if (empty($pattern_code)) {
            $pattern_code = get_option('uc_sms_default_pattern_code', '');
        }

        if (empty($pattern_vars)) {
            $pattern_vars = get_option('uc_sms_default_pattern_vars', '');
        }

        if (empty($pattern_vars)) {
            $pattern_vars = self::DEFAULT_VARIABLE_ORDER;
        }

        $pattern_vars = apply_filters('uc_sms_pattern_vars', (string) $pattern_vars, $submission_id, $card_id, $context);

        if (empty($pattern_code) || empty($pattern_vars)) {
            return;
        }

        $username = get_option('ucb_sms_username', '');
        $password = get_option('ucb_sms_password', '');

        if (empty($username)) {
            $username = get_option('uc_sms_username', '');
        }

        if (empty($password)) {
            $password = get_option('uc_sms_password', '');
        }

        $sender_number = trim((string) get_option('uc_sms_sender_number', ''));

        $username = apply_filters('uc_sms_username', $username, $submission_id, $card_id, $context);
        $password = apply_filters('uc_sms_password', $password, $submission_id, $card_id, $context);
        $sender_number = apply_filters('uc_sms_sender_number', $sender_number, $submission_id, $card_id, $context);

        if (empty($username) || empty($password)) {
            return;
        }

        $phone = self::resolve_recipient_phone($user_id, $context);
        if (empty($phone)) {
            return;
        }

        $variables_map = self::build_variables($submission_id, $card_id, $user_id, $surprise, $date, $time, $card_code, $phone, $context);
        $keys = self::normalize_pattern_vars($pattern_vars);

        if (empty($keys)) {
            return;
        }

        $text_variables = [];
        foreach ($keys as $key) {
            $text_variables[] = isset($variables_map[$key]) ? (string) $variables_map[$key] : '';
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'text'     => array_map('strval', $text_variables),
            'to'       => $phone,
            'bodyId'   => is_numeric($pattern_code) ? (int) $pattern_code : $pattern_code,
        ];

        $payload = apply_filters('uc_sms_payload', $payload, $variables_map, $submission_id, $card_id, $context);

        $gateway = self::get_gateway_slug();

        if (self::GATEWAY_PAYAMAK_PANEL === $gateway) {
            if (empty($payload['username']) || empty($payload['password']) || empty($payload['to']) || empty($payload['bodyId'])) {
                return;
            }
        }

        try {
            $response = self::dispatch($gateway, $payload, [
                'username'       => $username,
                'password'       => $password,
                'sender_number'  => $sender_number,
                'phone'          => $phone,
                'pattern_code'   => $pattern_code,
                'variables_map'  => $variables_map,
                'variable_keys'  => $keys,
                'text_variables' => $text_variables,
                'payload'        => $payload,
            ]);
            do_action('uc_sms_dispatched', $submission_id, $card_id, $response, $payload, $variables_map, $context);
        } catch (\Throwable $exception) {
            error_log('UC_SMS: Failed to send SMS - ' . $exception->getMessage());
            do_action('uc_sms_failed', $submission_id, $card_id, $exception, $payload, $variables_map, $context);
        }
    }

    /**
     * Prepare available variables for templated SMS.
     *
     * @return array<string, string>
     */
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

        $variables = [
            'user_name'      => $full_name,
            'user_family'    => $last_name,
            'user_mobile'    => $phone,
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

    /**
     * Fetch user phone number from meta.
     */
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

    protected static function sanitize_phone_value($value) {
        $clean = preg_replace('/[^0-9+]/', '', (string) $value);

        if ($clean === '') {
            return '';
        }

        if (strpos($clean, '0098') === 0) {
            $clean = '+98' . substr($clean, 4);
        } elseif (strpos($clean, '98') === 0 && strpos($clean, '+98') !== 0) {
            $clean = '+' . $clean;
        }

        if ($clean[0] === '9' && strlen($clean) === 10) {
            $clean = '0' . $clean;
        }

        return $clean;
    }

    protected static function normalize_pattern_vars($pattern_vars) {
        $parts = array_filter(array_map('trim', explode(',', (string) $pattern_vars)));
        $normalized = [];

        foreach ($parts as $part) {
            $key = sanitize_key($part);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    protected static function dispatch($gateway, array $payload, array $context) {
        if (self::GATEWAY_IRAN_PAYAMAK === $gateway) {
            return self::dispatch_with_iran_payamak($context);
        }

        return self::dispatch_with_payamak_panel($payload);
    }

    protected static function test_with_payamak_panel($username, $password) {
        $credit = null;

        if (class_exists('SoapClient')) {
            try {
                $client = new \SoapClient(self::WSDL, [
                    'encoding' => 'UTF-8',
                    'exceptions' => true,
                    'cache_wsdl' => defined('WSDL_CACHE_MEMORY') ? WSDL_CACHE_MEMORY : 1,
                    'connection_timeout' => 15,
                ]);

                $response = $client->GetCredit([
                    'username' => $username,
                    'password' => $password,
                ]);

                if (is_object($response) && isset($response->GetCreditResult)) {
                    $credit = (string) $response->GetCreditResult;
                } elseif (is_scalar($response)) {
                    $credit = (string) $response;
                }
            } catch (\Throwable $exception) {
                $credit = null;
            }
        }

        if ($credit === null) {
            if (!function_exists('wp_remote_post')) {
                throw new \RuntimeException('UC_SMS: wp_remote_post is not available.');
            }

            $body = self::build_credit_envelope($username, $password);
            $response = wp_remote_post(self::SERVICE_ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => self::SOAP_ACTION_GET_CREDIT,
                ],
                'body'    => $body,
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                throw new \RuntimeException('UC_SMS: HTTP transport error - ' . $response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = (string) wp_remote_retrieve_body($response);

            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('UC_SMS: Gateway HTTP status ' . $code);
            }

            $credit = self::parse_credit_response($raw_body);

            if ($credit === '') {
                $credit = self::parse_response_value($raw_body);
            }
        }

        if ($credit === null || $credit === '') {
            throw new \RuntimeException(__('پاسخی از درگاه پیامک دریافت نشد.', 'user-cards'));
        }

        if (is_numeric($credit)) {
            $numeric = (float) $credit;
            if ($numeric < 0) {
                throw new \RuntimeException(__('درگاه پیامک خطا بازگرداند.', 'user-cards'));
            }
            $display = $numeric;
        } else {
            $display = $credit;
        }

        return [
            'gateway' => self::GATEWAY_PAYAMAK_PANEL,
            'credit'  => $display,
            'message' => sprintf(__('اتصال برقرار شد. موجودی: %s', 'user-cards'), $display),
        ];
    }

    protected static function test_with_iran_payamak($username, $password) {
        if (!function_exists('wp_remote_post')) {
            throw new \RuntimeException('UC_SMS: wp_remote_post is not available.');
        }

        $response = wp_remote_post('https://rest.iranpayamak.com/api/Account/GetCredit', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode([
                'username' => $username,
                'password' => $password,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('UC_SMS: IranPayamak transport error - ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('UC_SMS: IranPayamak HTTP status ' . $code);
        }

        $data = null;
        if ($raw_body !== '') {
            $decoded = json_decode($raw_body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        $success = true;
        $message = '';
        $credit = '';

        if (is_array($data)) {
            if (array_key_exists('status', $data)) {
                $success = $success && (bool) $data['status'];
            }
            if (array_key_exists('Status', $data)) {
                $success = $success && (bool) $data['Status'];
            }
            if (isset($data['code']) && is_numeric($data['code']) && (int) $data['code'] < 0) {
                $success = false;
            }
            if (isset($data['Code']) && is_numeric($data['Code']) && (int) $data['Code'] < 0) {
                $success = false;
            }
            if (isset($data['credit'])) {
                $credit = (string) $data['credit'];
            } elseif (isset($data['Credit'])) {
                $credit = (string) $data['Credit'];
            } elseif (isset($data['value'])) {
                $credit = (string) $data['value'];
            }
            if (isset($data['message'])) {
                $message = (string) $data['message'];
            } elseif (isset($data['Message'])) {
                $message = (string) $data['Message'];
            }
        } else {
            $credit = $raw_body;
        }

        if (!$success) {
            if ($message === '') {
                $message = __('پاسخی ناموفق از درگاه دریافت شد.', 'user-cards');
            }
            throw new \RuntimeException('UC_SMS: IranPayamak error - ' . $message);
        }

        $display = $credit;
        if ($display === '' && $message !== '') {
            $display = $message;
        }

        $final_message = $display !== ''
            ? sprintf(__('اتصال برقرار شد. موجودی: %s', 'user-cards'), $display)
            : __('اتصال با موفقیت برقرار شد.', 'user-cards');

        return [
            'gateway'  => self::GATEWAY_IRAN_PAYAMAK,
            'credit'   => $display,
            'message'  => $final_message,
            'response' => $data !== null ? $data : $raw_body,
        ];
    }

    protected static function dispatch_with_payamak_panel(array $payload) {
        if (empty($payload['text']) || !is_array($payload['text'])) {
            throw new \InvalidArgumentException('UC_SMS: Payload text must be an array.');
        }

        if (class_exists('SoapClient')) {
            try {
                $result = self::call_with_soap($payload);
                return [
                    'transport' => 'soap',
                    'result'    => $result,
                ];
            } catch (\Throwable $exception) {
                do_action('uc_sms_transport_failed', 'soap', $exception, $payload);
            }
        }

        $result = self::call_with_http($payload);

        return [
            'transport' => 'http',
            'result'    => $result,
        ];
    }

    protected static function dispatch_with_iran_payamak(array $context) {
        if (!function_exists('wp_remote_post')) {
            throw new \RuntimeException('UC_SMS: wp_remote_post is not available.');
        }

        $username = isset($context['username']) ? (string) $context['username'] : '';
        $password = isset($context['password']) ? (string) $context['password'] : '';
        $sender   = isset($context['sender_number']) ? (string) $context['sender_number'] : '';
        $phone    = isset($context['phone']) ? (string) $context['phone'] : '';
        $pattern  = isset($context['pattern_code']) ? (string) $context['pattern_code'] : '';
        $keys     = isset($context['variable_keys']) && is_array($context['variable_keys']) ? $context['variable_keys'] : [];
        $variables_map = isset($context['variables_map']) && is_array($context['variables_map']) ? $context['variables_map'] : [];
        $text_variables = isset($context['text_variables']) && is_array($context['text_variables']) ? $context['text_variables'] : [];

        if ($username === '' || $password === '' || $phone === '' || $pattern === '') {
            throw new \RuntimeException('UC_SMS: Missing required IranPayamak parameters.');
        }

        $input_data = [];

        if (!empty($keys)) {
            foreach ($keys as $index => $key) {
                $value = '';
                if (isset($variables_map[$key])) {
                    $value = (string) $variables_map[$key];
                } elseif (isset($text_variables[$index])) {
                    $value = (string) $text_variables[$index];
                }

                $input_data[] = [
                    'Parameter' => $key,
                    'Name'      => $key,
                    'Value'     => $value,
                ];
            }
        } else {
            foreach ($text_variables as $index => $value) {
                $input_data[] = [
                    'Parameter' => 'value' . ($index + 1),
                    'Name'      => 'value' . ($index + 1),
                    'Value'     => (string) $value,
                ];
            }
        }

        $values = array_map('strval', $text_variables);

        $request_body = [
            'username'     => $username,
            'password'     => $password,
            'to'           => [$phone],
            'mobile'       => [$phone],
            'recipient'    => $phone,
            'pattern_code' => $pattern,
            'patternCode'  => $pattern,
            'input_data'   => $input_data,
            'inputData'    => $input_data,
            'values'       => $values,
        ];

        if ($sender !== '') {
            $request_body['from'] = $sender;
            $request_body['originator'] = $sender;
        }

        $request_body = apply_filters('uc_sms_iranpayamak_payload', $request_body, $context);

        $response = wp_remote_post(self::IRAN_PAYAMAK_PATTERN_ENDPOINT, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($request_body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('UC_SMS: IranPayamak transport error - ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('UC_SMS: IranPayamak HTTP status ' . $code . ' - ' . $raw_body);
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
            $status_candidates = [];
            if (array_key_exists('status', $data)) {
                $status_candidates[] = $data['status'];
            }
            if (array_key_exists('Status', $data)) {
                $status_candidates[] = $data['Status'];
            }

            foreach ($status_candidates as $status_value) {
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
        } else {
            $result_code = $raw_body !== '' ? $raw_body : 'success';
        }

        if (!$success) {
            if ($message === '') {
                $message = $result_code !== '' ? $result_code : 'Gateway reported failure.';
            }

            throw new \RuntimeException('UC_SMS: IranPayamak error - ' . $message);
        }

        if ($result_code === '') {
            $result_code = 'success';
        }

        return [
            'transport' => 'rest',
            'result'    => $result_code,
            'response'  => $data !== null ? $data : $raw_body,
        ];
    }

    protected static function call_with_soap(array $payload) {
        $client = new \SoapClient(self::WSDL, [
            'encoding' => 'UTF-8',
            'exceptions' => true,
            'cache_wsdl' => defined('WSDL_CACHE_MEMORY') ? WSDL_CACHE_MEMORY : 1,
            'connection_timeout' => 15,
        ]);

        $response = $client->SendByBaseNumber($payload);

        if (is_object($response) && isset($response->SendByBaseNumberResult)) {
            $result = (string) $response->SendByBaseNumberResult;
        } else {
            $result = is_scalar($response) ? (string) $response : '';
        }

        self::validate_gateway_result($result);

        return $result;
    }

    protected static function call_with_http(array $payload) {
        if (!function_exists('wp_remote_post')) {
            throw new \RuntimeException('UC_SMS: wp_remote_post is not available.');
        }

        $body = self::build_soap_envelope($payload);
        $response = wp_remote_post(self::SERVICE_ENDPOINT, [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => self::SOAP_ACTION,
            ],
            'body'    => $body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('UC_SMS: HTTP transport error - ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('UC_SMS: Gateway HTTP status ' . $code);
        }

        $result = self::parse_response_value($raw_body);

        if ($result === '') {
            throw new \RuntimeException('UC_SMS: Empty response from SMS gateway.');
        }

        self::validate_gateway_result($result);

        return $result;
    }

    protected static function build_credit_envelope($username, $password) {
        $escape = static function ($value) {
            $value = (string) $value;

            if (function_exists('esc_html')) {
                return esc_html($value);
            }

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<GetCredit xmlns="http://tempuri.org/">'
            . '<username>' . $escape($username) . '</username>'
            . '<password>' . $escape($password) . '</password>'
            . '</GetCredit>'
            . '</soap:Body>'
            . '</soap:Envelope>';

        return $body;
    }

    protected static function build_soap_envelope(array $payload) {
        $escape = static function ($value) {
            $value = (string) $value;

            if (function_exists('esc_html')) {
                return esc_html($value);
            }

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };

        $text_xml = '';
        foreach ($payload['text'] as $value) {
            $text_xml .= '<text>' . $escape($value) . '</text>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<SendByBaseNumber xmlns="http://tempuri.org/">'
            . '<username>' . $escape($payload['username']) . '</username>'
            . '<password>' . $escape($payload['password']) . '</password>'
            . $text_xml
            . '<to>' . $escape($payload['to']) . '</to>'
            . '<bodyId>' . $escape($payload['bodyId']) . '</bodyId>'
            . '</SendByBaseNumber>'
            . '</soap:Body>'
            . '</soap:Envelope>';

        return $body;
    }

    protected static function parse_credit_response($body) {
        if ($body === '') {
            return '';
        }

        if (!function_exists('simplexml_load_string')) {
            return '';
        }

        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return '';
        }

        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('tns', 'http://tempuri.org/');
        $nodes = $xml->xpath('//tns:GetCreditResult');

        if (is_array($nodes) && isset($nodes[0])) {
            return trim((string) $nodes[0]);
        }

        return '';
    }

    protected static function parse_response_value($body) {
        if ($body === '') {
            return '';
        }

        if (!function_exists('simplexml_load_string')) {
            return '';
        }

        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return '';
        }

        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('tns', 'http://tempuri.org/');
        $nodes = $xml->xpath('//tns:SendByBaseNumberResult');

        if (is_array($nodes) && isset($nodes[0])) {
            return trim((string) $nodes[0]);
        }

        return '';
    }

    protected static function validate_gateway_result($result) {
        if ($result === '') {
            return;
        }

        if (is_numeric($result)) {
            $value = (int) $result;
            if ($value < 0) {
                throw new \RuntimeException('UC_SMS: Gateway returned error code ' . $result);
            }
        }
    }
}

