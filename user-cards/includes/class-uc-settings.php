<?php
if (!defined('ABSPATH')) { exit; }

class UC_Settings {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_menu() {
        add_submenu_page(
            'edit.php?post_type=uc_card',
            __('تنظیمات پیامک', 'user-cards'),
            __('تنظیمات پیامک', 'user-cards'),
            'manage_options',
            'uc-sms-settings',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('uc_sms_settings', 'uc_sms_gateway', [
            'sanitize_callback' => [__CLASS__, 'sanitize_gateway'],
            'default' => 'ippanel',
        ]);
        register_setting('uc_sms_settings', 'uc_sms_username', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('uc_sms_settings', 'uc_sms_password', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('uc_sms_settings', 'uc_sms_sender_number', ['sanitize_callback' => [__CLASS__, 'sanitize_sender_number']]);
        register_setting('uc_sms_settings', 'uc_sms_default_pattern_code', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('uc_sms_settings', 'uc_sms_default_pattern_vars', ['sanitize_callback' => [__CLASS__, 'sanitize_vars']]);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $gateway        = get_option('uc_sms_gateway', 'ippanel');
        $username       = get_option('uc_sms_username', '');
        $password       = get_option('uc_sms_password', '');
        $sender_number  = get_option('uc_sms_sender_number', '');
        $pattern_code   = get_option('uc_sms_default_pattern_code', '');
        $pattern_raw    = get_option('uc_sms_default_pattern_vars', '');
        $pattern_pairs  = self::decode_pattern_vars($pattern_raw, true);
        $pattern_json   = !empty($pattern_pairs) ? wp_json_encode($pattern_pairs, JSON_UNESCAPED_UNICODE) : '';

        $available_sources = self::available_variables();
        $available_json    = wp_json_encode(array_map(
            static function ($key, $label) {
                return [
                    'value' => $key,
                    'label' => $label,
                ];
            },
            array_keys($available_sources),
            array_values($available_sources)
        ), JSON_UNESCAPED_UNICODE);

        $test_values = [];
        foreach ($pattern_pairs as $pair) {
            $test_values[] = [
                'placeholder' => $pair['placeholder'],
                'value'       => '',
            ];
        }
        if (empty($test_values)) {
            foreach (self::get_default_pattern_mapping() as $pair) {
                $test_values[] = [
                    'placeholder' => $pair['placeholder'],
                    'value'       => '',
                ];
            }
        }
        $test_json = !empty($test_values) ? wp_json_encode($test_values, JSON_UNESCAPED_UNICODE) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('تنظیمات پیامک', 'user-cards'); ?></h1>
            <p class="description"><?php esc_html_e('نام کاربری و توکن دسترسی وب‌سرویس را وارد کنید. در صورت نیاز می‌توانید یک کد الگوی پیش‌فرض و نگاشت متغیرها را نیز برای تمام کارت‌ها تنظیم نمایید.', 'user-cards'); ?></p>
            <form method="post" action="options.php">
                <?php
                settings_fields('uc_sms_settings');
                do_settings_sections('uc_sms_settings');
                ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="uc_sms_gateway"><?php esc_html_e('درگاه پیامک', 'user-cards'); ?></label></th>
                            <td>
                                <select name="uc_sms_gateway" id="uc_sms_gateway">
                                    <option value="ippanel" <?php selected($gateway, 'ippanel'); ?>><?php esc_html_e('IPPanel (Pattern API)', 'user-cards'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('درگاه فعال برای ارسال پیامک‌های الگو شده.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_username"><?php esc_html_e('نام کاربری پنل پیامک', 'user-cards'); ?></label></th>
                            <td><input name="uc_sms_username" type="text" id="uc_sms_username" value="<?php echo esc_attr($username); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_password"><?php esc_html_e('توکن دسترسی (AccessKey)', 'user-cards'); ?></label></th>
                            <td>
                                <input name="uc_sms_password" type="password" id="uc_sms_password" value="<?php echo esc_attr($password); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('AccessKey ایجاد شده در پنل آی‌پی‌پنل را وارد کنید. این مقدار برای احراز هویت درخواست‌ها استفاده می‌شود.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_sender_number"><?php esc_html_e('شماره ارسال‌کننده', 'user-cards'); ?></label></th>
                            <td>
                                <input name="uc_sms_sender_number" type="text" id="uc_sms_sender_number" value="<?php echo esc_attr($sender_number); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('در صورت داشتن خط اختصاصی، این مقدار به عنوان originator تنظیم می‌شود.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_default_pattern_code"><?php esc_html_e('کد الگوی پیش‌فرض (pattern_code)', 'user-cards'); ?></label></th>
                            <td>
                                <input name="uc_sms_default_pattern_code" type="text" id="uc_sms_default_pattern_code" value="<?php echo esc_attr($pattern_code); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('در صورت خالی بودن کد الگو در کارت، از این مقدار استفاده خواهد شد.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('نگاشت متغیرهای الگو', 'user-cards'); ?></th>
                            <td>
                                <style>
                                    .uc-sms-variable-row {display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap;}
                                    .uc-sms-variable-row input[type="text"], .uc-sms-variable-row select {min-width:160px;}
                                    .uc-sms-variable-row .button-link-delete {color:#a00;margin-top:4px;}
                                </style>
                                <div class="uc-sms-variables" style="max-width:720px;">
                                    <div class="uc-sms-variables-repeater" data-target="uc_sms_default_pattern_vars" data-mode="mapping" data-available='<?php echo esc_attr($available_json); ?>' data-defaults='<?php echo esc_attr(wp_json_encode(self::get_default_pattern_mapping(), JSON_UNESCAPED_UNICODE)); ?>'></div>
                                    <input type="hidden" name="uc_sms_default_pattern_vars" id="uc_sms_default_pattern_vars" value="<?php echo esc_attr($pattern_json); ?>">
                                    <button type="button" class="button uc-sms-variable-add" data-target="uc_sms_default_pattern_vars"><?php esc_html_e('افزودن متغیر', 'user-cards'); ?></button>
                                </div>
                                <p class="description"><?php esc_html_e('نام متغیر دقیقاً باید مطابق با الگوی ثبت شده در آی‌پی‌پنل (مانند name یا cardName) باشد و مقدار آن از فهرست داده‌های کارت انتخاب می‌شود.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('تست اتصال', 'user-cards'); ?></th>
                            <td>
                                <button type="button" class="button" id="uc_sms_test_connection"><?php esc_html_e('بررسی اتصال', 'user-cards'); ?></button>
                                <p class="description" id="uc_sms_test_connection_status" style="margin-top:8px;"></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_test_phone"><?php esc_html_e('شماره موبایل تست', 'user-cards'); ?></label></th>
                            <td>
                                <input type="text" id="uc_sms_test_phone" class="regular-text" placeholder="09xxxxxxxxx">
                                <p class="description"><?php esc_html_e('پیامک تستی به این شماره ارسال خواهد شد. لطفاً شماره را با قالب صحیح وارد کنید.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('مقادیر پیامک تستی', 'user-cards'); ?></th>
                            <td>
                                <div class="uc-sms-variables" style="max-width:720px;">
                                    <div class="uc-sms-variables-repeater" data-target="uc_sms_test_variables" data-mode="manual" data-defaults='<?php echo esc_attr($test_json); ?>'></div>
                                    <input type="hidden" id="uc_sms_test_variables" value="<?php echo esc_attr($test_json); ?>">
                                    <button type="button" class="button uc-sms-variable-add" data-target="uc_sms_test_variables"><?php esc_html_e('افزودن مقدار تستی', 'user-cards'); ?></button>
                                </div>
                                <p class="description"><?php esc_html_e('برای هر نام متغیر، مقدار دلخواه تستی را وارد کنید تا پیامک نمونه ارسال شود.', 'user-cards'); ?></p>
                                <button type="button" class="button button-primary" id="uc_sms_send_test" style="margin-top:10px;">
                                    <?php esc_html_e('ارسال پیامک تستی', 'user-cards'); ?>
                                </button>
                                <p class="description" id="uc_sms_send_test_status" style="margin-top:8px;"></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function sanitize_gateway($value) {
        $value = sanitize_key((string) $value);
        $allowed = ['ippanel'];

        if (!in_array($value, $allowed, true)) {
            return 'ippanel';
        }

        return $value;
    }

    public static function sanitize_sender_number($value) {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_text_field($value);
    }

    public static function sanitize_vars($value) {
        if (is_string($value)) {
            $value = wp_unslash($value);
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                $decoded = self::legacy_mapping_from_string($value);
            }
        } elseif (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return '';
        }

        $sanitized = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $placeholder = isset($row['placeholder']) ? self::sanitize_placeholder($row['placeholder']) : '';
            $source      = isset($row['source']) ? sanitize_key($row['source']) : '';
            if ($placeholder === '' || $source === '') {
                continue;
            }
            $sanitized[] = [
                'placeholder' => $placeholder,
                'source'      => $source,
            ];
        }

        if (empty($sanitized)) {
            return '';
        }

        return wp_json_encode(array_values($sanitized), JSON_UNESCAPED_UNICODE);
    }

    public static function decode_pattern_vars($value, $with_default = false) {
        if (is_string($value)) {
            $value = wp_unslash($value);
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                $decoded = self::legacy_mapping_from_string($value);
            }
        } elseif (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = [];
        }

        $result = [];
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $placeholder = isset($row['placeholder']) ? self::sanitize_placeholder($row['placeholder']) : '';
                $source      = isset($row['source']) ? sanitize_key($row['source']) : '';
                if ($placeholder === '' || $source === '') {
                    continue;
                }
                $result[] = [
                    'placeholder' => $placeholder,
                    'source'      => $source,
                ];
            }
        }

        if (empty($result) && $with_default) {
            $result = self::get_default_pattern_mapping();
        }

        return $result;
    }

    public static function get_default_pattern_mapping() {
        return [
            ['placeholder' => 'name',     'source' => 'user_name'],
            ['placeholder' => 'cardName', 'source' => 'card_title'],
            ['placeholder' => 'date',     'source' => 'jalali_date'],
            ['placeholder' => 'time',     'source' => 'selected_time'],
            ['placeholder' => 'surprise', 'source' => 'surprise_code'],
        ];
    }

    public static function available_variables() {
        return [
            'user_name'      => __('نام مشتری', 'user-cards'),
            'user_family'    => __('نام خانوادگی مشتری', 'user-cards'),
            'user_mobile'    => __('موبایل مشتری', 'user-cards'),
            'card_title'     => __('عنوان کارت', 'user-cards'),
            'submission_id'  => __('شناسه فرم', 'user-cards'),
            'card_code'      => __('کد وارد شده توسط کاربر', 'user-cards'),
            'jalali_date'    => __('تاریخ انتخابی (شمسی)', 'user-cards'),
            'selected_time'  => __('ساعت انتخابی', 'user-cards'),
            'surprise_code'  => __('کد سوپرایز', 'user-cards'),
            'upsell_items'   => __('لیست خرید افزایشی', 'user-cards'),
            'gregorian_date' => __('تاریخ انتخابی (میلادی)', 'user-cards'),
        ];
    }

    protected static function sanitize_placeholder($value) {
        $value = preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
        return $value !== null ? $value : '';
    }

    protected static function legacy_mapping_from_string($value) {
        if (!is_string($value)) {
            return [];
        }

        $parts = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $value))));
        if (empty($parts)) {
            return [];
        }

        $defaults = self::get_default_pattern_mapping();
        $result = [];

        foreach ($parts as $index => $source) {
            $placeholder = isset($defaults[$index]) ? $defaults[$index]['placeholder'] : ('value' . ($index + 1));
            if ($placeholder === '') {
                $placeholder = 'value' . ($index + 1);
            }
            $result[] = [
                'placeholder' => $placeholder,
                'source'      => $source,
            ];
        }

        return $result;
    }
}
