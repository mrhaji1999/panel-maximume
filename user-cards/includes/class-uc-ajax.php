<?php
if (!defined('ABSPATH')) { exit; }

class UC_Ajax {
    private static function check_nonce() {
        if (empty($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'uc_ajax')) {
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

        if ($assigned_supervisor > 0) {
            update_user_meta($user_id, 'ucb_customer_assigned_supervisor', $assigned_supervisor);
            update_user_meta($user_id, 'ucb_customer_supervisor_id', $assigned_supervisor);
            update_post_meta($sub_id, '_uc_supervisor_id', $assigned_supervisor);
        }

        update_user_meta($user_id, 'ucb_customer_card_id', $card_id);

        $assigned_agent = (int) get_user_meta($user_id, 'ucb_customer_assigned_agent', true);
        if ($assigned_agent > 0) {
            update_user_meta($user_id, 'ucb_customer_assigned_agent', $assigned_agent);
            update_user_meta($user_id, 'ucb_customer_agent_id', $assigned_agent);
            update_post_meta($sub_id, '_uc_agent_id', $assigned_agent);
        }

        wp_send_json_success([
            'message' => __('با موفقیت ثبت شد.', 'user-cards'),
            'surprise' => $surprise,
        ]);
    }
}
