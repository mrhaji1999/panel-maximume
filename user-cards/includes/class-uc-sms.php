<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Simple SMS helper for Payamak Panel SOAP API.
 */
class UC_SMS {
    /** @var string */
    const WSDL = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';

    /**
     * Send confirmation SMS after a submission is created.
     *
     * @param int    $submission_id Submission post ID.
     * @param int    $card_id       Card post ID.
     * @param int    $user_id       User ID.
     * @param string $surprise      Surprise code generated for the submission.
     * @param string $date          Selected date (Jalali string).
     * @param string $time          Selected time string.
     */
    public static function send_submission_confirmation($submission_id, $card_id, $user_id, $surprise, $date, $time) {
        $pattern_code = get_post_meta($card_id, '_uc_sms_normal_pattern_code', true);
        $pattern_vars = get_post_meta($card_id, '_uc_sms_normal_pattern_vars', true);

        if (empty($pattern_code)) {
            $pattern_code = get_option('uc_sms_default_pattern_code', '');
        }

        if (empty($pattern_vars)) {
            $pattern_vars = get_option('uc_sms_default_pattern_vars', '');
        }

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

        if (empty($username) || empty($password)) {
            return;
        }

        $phone = self::get_user_phone($user_id);
        if (empty($phone)) {
            return;
        }

        if (!class_exists('SoapClient')) {
            error_log('UC_SMS: SOAP extension is required to send messages.');
            return;
        }

        $variables_map = self::build_variables($submission_id, $card_id, $user_id, $surprise, $date, $time, $phone);
        $keys = array_filter(array_map('trim', explode(',', (string) $pattern_vars)));

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

        try {
            $client = new \SoapClient(self::WSDL, [
                'encoding' => 'UTF-8',
                'exceptions' => true,
                'cache_wsdl' => defined('WSDL_CACHE_MEMORY') ? WSDL_CACHE_MEMORY : 1,
                'connection_timeout' => 10,
            ]);

            $client->SendByBaseNumber($payload);
        } catch (\Throwable $exception) {
            error_log('UC_SMS: Failed to send SMS - ' . $exception->getMessage());
        }
    }

    /**
     * Prepare available variables for templated SMS.
     *
     * @return array<string, string>
     */
    protected static function build_variables($submission_id, $card_id, $user_id, $surprise, $date, $time, $phone) {
        $user = get_userdata($user_id);
        $first_name = trim((string) get_user_meta($user_id, 'first_name', true));
        $last_name  = trim((string) get_user_meta($user_id, 'last_name', true));
        $full_name  = trim($first_name . ' ' . $last_name);

        if ($full_name === '' && $user) {
            $full_name = $user->display_name;
        }

        $card_title = get_the_title($card_id);

        return [
            'user_name'      => $full_name,
            'user_family'    => $last_name,
            'user_mobile'    => $phone,
            'card_title'     => $card_title,
            'submission_id'  => (string) $submission_id,
            'jalali_date'    => $date,
            'selected_time'  => $time,
            'surprise_code'  => $surprise,
            'upsell_items'   => '',
        ];
    }

    /**
     * Fetch user phone number from meta.
     */
    protected static function get_user_phone($user_id) {
        $meta_keys = ['billing_phone', 'phone', 'mobile', 'user_phone', 'user_mobile', 'cellphone', 'user_cellphone'];

        foreach ($meta_keys as $key) {
            $phone = get_user_meta($user_id, $key, true);
            if (!empty($phone)) {
                $clean = preg_replace('/[^0-9+]/', '', (string) $phone);
                if (!empty($clean)) {
                    return $clean;
                }
            }
        }

        return '';
    }
}

