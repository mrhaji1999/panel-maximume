<?php
if (!defined('ABSPATH')) { exit; }

class UC_Ajax {
    private static function check_nonce() {
        if (empty($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'uc_ajax')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'user-cards')], 403);
        }
    }

    private static function check_sms_nonce() {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'uc_sms_settings')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'user-cards')], 403);
        }
    }

    public static function login() {
        self::check_nonce();
        $creds = [
            'user_login' => sanitize_text_field($_POST['username'] ?? ''),
            'user_password' => $_POST['password'] ?? '',
            'remember' => true,
        ];
        $user = wp_signon($creds, false);
        if (is_wp_error($user)) {
            wp_send_json_error(['message' => $user->get_error_message()], 400);
        }
        wp_send_json_success(['redirect' => home_url('/my-account/')]);
    }

    public static function register() {
        self::check_nonce();
        $username = sanitize_user($_POST['username'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$username || !$email || !$password) {
            wp_send_json_error(['message' => __('اطلاعات ناقص است.', 'user-cards')], 400);
        }
        if (username_exists($username) || email_exists($email)) {
            wp_send_json_error(['message' => __('این نام کاربری/ایمیل موجود است.', 'user-cards')], 400);
        }
        $uid = wp_create_user($username, $password, $email);
        if (is_wp_error($uid)) {
            wp_send_json_error(['message' => $uid->get_error_message()], 400);
        }
        // Auto login
        wp_set_current_user($uid);
        wp_set_auth_cookie($uid, true);
        wp_send_json_success(['redirect' => home_url('/my-account/')]);
    }

    public static function validate_code() {
        self::check_nonce();
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $code = isset($_POST['code']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_POST['code']) : '';
        if (!$card_id || !$code) {
            wp_send_json_error(['status' => 'invalid'], 400);
        }

        if (!UC_DB::code_exists($card_id, $code)) {
            wp_send_json_error(['status' => 'invalid'], 400);
        }

        // Check if used in submissions
        $exists = get_posts([
            'post_type' => 'uc_submission',
            'post_status' => 'any',
            'meta_query' => [[
                'key' => '_uc_code',
                'value' => $code,
                'compare' => '='
            ]],
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);
        if (!empty($exists)) {
            wp_send_json_error(['status' => 'used'], 400);
        }

        wp_send_json_success(['status' => 'ok']);
    }

    public static function sms_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز.', 'user-cards')], 403);
        }

        self::check_sms_nonce();

        $gateway = isset($_POST['gateway']) ? sanitize_key(wp_unslash($_POST['gateway'])) : UC_SMS::GATEWAY_PAYAMAK_PANEL;
        $username = isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';
        $sender   = isset($_POST['sender_number']) ? sanitize_text_field(wp_unslash($_POST['sender_number'])) : '';

        if ($username === '' || $password === '') {
            wp_send_json_error(['message' => __('نام کاربری و کلمه عبور الزامی است.', 'user-cards')], 400);
        }

        try {
            $result = UC_SMS::test_connection($gateway, $username, $password, $sender);
            $message = isset($result['message']) ? (string) $result['message'] : __('اتصال با موفقیت برقرار شد.', 'user-cards');
            wp_send_json_success([
                'message' => $message,
                'data'    => $result,
            ]);
        } catch (\Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }
    }

    public static function sms_send_test() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز.', 'user-cards')], 403);
        }

        self::check_sms_nonce();

        $gateway = isset($_POST['gateway']) ? sanitize_key(wp_unslash($_POST['gateway'])) : UC_SMS::GATEWAY_PAYAMAK_PANEL;
        $username = isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';
        $sender   = isset($_POST['sender_number']) ? sanitize_text_field(wp_unslash($_POST['sender_number'])) : '';
        $pattern_code_raw = isset($_POST['pattern_code']) ? wp_unslash($_POST['pattern_code']) : '';
        $pattern_code = sanitize_text_field($pattern_code_raw);
        $pattern_vars_raw = isset($_POST['pattern_vars']) ? wp_unslash($_POST['pattern_vars']) : '';
        $pattern_vars = UC_Settings::sanitize_vars($pattern_vars_raw);
        $test_phone_raw = isset($_POST['test_phone']) ? wp_unslash($_POST['test_phone']) : '';
        $test_phone = UC_SMS::sanitize_phone($test_phone_raw);
        $variables_raw = isset($_POST['variables']) ? (string) wp_unslash($_POST['variables']) : '';

        if ($username === '' || $password === '') {
            wp_send_json_error(['message' => __('نام کاربری و کلمه عبور الزامی است.', 'user-cards')], 400);
        }

        if ($pattern_code === '') {
            wp_send_json_error(['message' => __('لطفاً کد الگو را وارد کنید.', 'user-cards')], 400);
        }

        if ($test_phone === '') {
            wp_send_json_error(['message' => __('شماره موبایل تست نامعتبر است.', 'user-cards')], 400);
        }

        $keys = UC_SMS::normalize_pattern_variables($pattern_vars !== '' ? $pattern_vars : UC_SMS::DEFAULT_VARIABLE_ORDER);

        if (empty($keys)) {
            wp_send_json_error(['message' => __('ترتیب متغیرهای پیامک قابل استفاده نیست.', 'user-cards')], 400);
        }

        $parsed = UC_SMS::parse_manual_variables_input($variables_raw, $keys);
        $text_variables = [];
        $has_value = false;

        foreach ($keys as $index => $key) {
            if (isset($parsed['map'][$key])) {
                $value = (string) $parsed['map'][$key];
            } elseif (isset($parsed['ordered'][$index])) {
                $value = (string) $parsed['ordered'][$index];
            } else {
                $value = '';
            }

            if ($value !== '') {
                $has_value = true;
            }

            $text_variables[] = $value;
        }

        if (!$has_value) {
            wp_send_json_error(['message' => __('برای ارسال تست باید حداقل یک مقدار متغیر وارد شود.', 'user-cards')], 400);
        }

        try {
            $response = UC_SMS::send_manual_test(
                $gateway,
                $username,
                $password,
                $sender,
                $pattern_code,
                $keys,
                $parsed['map'],
                $text_variables,
                $test_phone
            );

            $message = __('پیامک تستی با موفقیت ارسال شد.', 'user-cards');
            if (is_array($response) && isset($response['result']) && $response['result'] !== '') {
                $message .= ' (' . $response['result'] . ')';
            }

            wp_send_json_success([
                'message' => $message,
                'data'    => $response,
            ]);
        } catch (\Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }
    }

    public static function submit_form() {
        self::check_nonce();
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('ابتدا وارد شوید.', 'user-cards')], 401);
        }
        $user_id = get_current_user_id();
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $code = isset($_POST['code']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_POST['code']) : '';
        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');
        $reservation_date = sanitize_text_field($_POST['reservation_date'] ?? '');
        $slot_hour = isset($_POST['slot_hour']) ? (int) $_POST['slot_hour'] : null;
        if (!$card_id || !$code || !$date || !$time) {
            wp_send_json_error(['message' => __('اطلاعات ناقص است.', 'user-cards')], 400);
        }

        // Re-validate code availability
        if (!UC_DB::code_exists($card_id, $code)) {
            wp_send_json_error(['message' => __('کد نامعتبر است.', 'user-cards')], 400);
        }
        $exists = get_posts([
            'post_type' => 'uc_submission',
            'post_status' => 'any',
            'meta_query' => [[
                'key' => '_uc_code',
                'value' => $code,
                'compare' => '='
            ]],
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);
        if (!empty($exists)) {
            wp_send_json_error(['message' => __('این کد قبلا استفاده شده است.', 'user-cards')], 400);
        }

        $assigned_supervisor = (int) get_user_meta($user_id, 'ucb_customer_assigned_supervisor', true);
        if ($assigned_supervisor <= 0) {
            $assigned_supervisor = (int) get_post_meta((int)$card_id, 'ucb_default_supervisor', true);
        }
        if ($assigned_supervisor <= 0 && class_exists('\\UCB\\Services\\CardService')) {
            $card_service = new \UCB\Services\CardService();
            $default_supervisor = (int) $card_service->get_default_supervisor((int) $card_id);
            if ($default_supervisor > 0) {
                $assigned_supervisor = $default_supervisor;
            } elseif (method_exists($card_service, 'get_card_supervisors')) {
                $supervisors = $card_service->get_card_supervisors((int) $card_id);
                if (!empty($supervisors)) {
                    $assigned_supervisor = (int) $supervisors[0];
                }
            }
        }
        if ($assigned_supervisor <= 0) {
            $assigned_supervisor = (int) get_post_meta((int)$card_id, '_uc_supervisor_id', true);
        }

        // Capacity enforcement based on schedule (User Cards Bridge)
        if (class_exists('UCB_Schedules')) {
            $sup_id = $assigned_supervisor;
            if ($sup_id > 0) {
                $schedule = UCB_Schedules::get_schedule((int)$card_id, (int)$sup_id);
                if ($schedule && !empty($schedule['grid'])) {
                    $dow = strtolower(date('D', strtotime($date)));
                    $map = ['mon'=>'mon','tue'=>'tue','wed'=>'wed','thu'=>'thu','fri'=>'fri','sat'=>'sat','sun'=>'sun'];
                    $dayKey = isset($map[$dow]) ? $map[$dow] : $dow;
                    $grid = is_array($schedule['grid']) ? $schedule['grid'] : [];
                    $hours = (isset($grid[$dayKey]) && is_array($grid[$dayKey])) ? $grid[$dayKey] : [];
                    $hourKey = preg_replace('/[^0-9]/','', (string)$time);
                    if ($hourKey !== '' && isset($hours[$hourKey])) {
                        $capacity = (int) $hours[$hourKey];
                        $existing = get_posts([
                            'post_type' => 'uc_submission',
                            'post_status' => 'any',
                            'meta_query' => [
                                ['key' => '_uc_card_id','value' => (int)$card_id,'compare' => '='],
                                ['key' => '_uc_supervisor_id','value' => (int)$sup_id,'compare' => '='],
                                ['key' => '_uc_date','value' => (string)$date,'compare' => '='],
                                ['key' => '_uc_time','value' => (string)$time,'compare' => '='],
                            ],
                            'fields' => 'ids',
                            'posts_per_page' => 1,
                        ]);
                        if (count($existing) >= $capacity) {
                            wp_send_json_error(['message' => __('ظرفیت این ساعت تکمیل شده است.', 'user-cards')], 400);
                        }
                    }
                }
            }
        }
        $surprise = 'UC-' . strtoupper(wp_generate_password(8, false, false));
        $card_title = get_the_title($card_id);
        $sub_id = wp_insert_post([
            'post_type' => 'uc_submission',
            'post_status' => 'publish',
            'post_title' => sprintf(__('ثبت %s توسط %s', 'user-cards'), $card_title, wp_get_current_user()->user_login),
        ]);
        if (is_wp_error($sub_id) || !$sub_id) {
            wp_send_json_error(['message' => __('خطا در ثبت اطلاعات.', 'user-cards')], 500);
        }

        $related_post_id = (int) get_post_meta($card_id, '_uc_related_post_id', true);
        update_post_meta($sub_id, '_uc_user_id', $user_id);
        update_post_meta($sub_id, '_uc_card_id', $card_id);
        update_post_meta($sub_id, '_uc_related_post_id', $related_post_id);
        update_post_meta($sub_id, '_uc_code', $code);
        update_post_meta($sub_id, '_uc_date', $date);
        update_post_meta($sub_id, '_uc_time', $time);
        update_post_meta($sub_id, '_uc_surprise', $surprise);

        if (!empty($reservation_date)) {
            update_post_meta($sub_id, '_uc_reservation_date', $reservation_date);
        }

        if ($slot_hour !== null) {
            update_post_meta($sub_id, '_uc_slot_hour', $slot_hour);
        }

        if ($assigned_supervisor > 0) {
            update_post_meta($sub_id, '_uc_supervisor_id', $assigned_supervisor);
        }

        $assigned_agent = (int) get_user_meta($user_id, 'ucb_customer_assigned_agent', true);
        if ($assigned_agent > 0) {
            update_post_meta($sub_id, '_uc_agent_id', $assigned_agent);
        }

        if (class_exists('UC_SMS')) {
            $request_context = [
                'date' => $date,
                'time' => $time,
                'reservation_date' => $reservation_date,
                'slot_hour' => $slot_hour,
                'card_code' => $code,
                'form' => self::sanitize_form_context($_POST ?? []),
            ];

            if (empty($request_context['form']['phone']) && !empty($request_context['form']['mobile'])) {
                $request_context['phone'] = $request_context['form']['mobile'];
            } elseif (!empty($request_context['form']['phone'])) {
                $request_context['phone'] = $request_context['form']['phone'];
            }

            if (!empty($request_context['form']['name'])) {
                $request_context['customer_name'] = $request_context['form']['name'];
            } elseif (!empty($request_context['form']['customer_name'])) {
                $request_context['customer_name'] = $request_context['form']['customer_name'];
            }

            UC_SMS::send_submission_confirmation($sub_id, $card_id, $user_id, $surprise, $date, $time, $code, $request_context);
        }

        wp_send_json_success([
            'message' => __('با موفقیت ثبت شد.', 'user-cards'),
            'surprise' => $surprise,
        ]);
    }

    private static function sanitize_form_context($data) {
        $clean = [];

        if (!is_array($data)) {
            return $clean;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $clean_key = sanitize_key($key);
            if ($clean_key === '') {
                continue;
            }

            if (is_string($value)) {
                $value = wp_unslash($value);
            }

            if ($clean_key === 'code') {
                $clean[$clean_key] = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
                continue;
            }

            $clean[$clean_key] = sanitize_text_field($value);
        }

        return $clean;
    }
}
