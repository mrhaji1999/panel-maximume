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
            'default' => 'payamak_panel',
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

        $gateway = get_option('uc_sms_gateway', 'payamak_panel');
        $username = get_option('uc_sms_username', '');
        $password = get_option('uc_sms_password', '');
        $sender_number = get_option('uc_sms_sender_number', '');
        $pattern_code = get_option('uc_sms_default_pattern_code', '');
        $pattern_vars = get_option('uc_sms_default_pattern_vars', '');
        $available_vars = [
            'user_name'     => 'نام مشتری',
            'user_family'   => 'نام خانوادگی مشتری',
            'user_mobile'   => 'موبایل مشتری',
            'card_title'    => 'عنوان کارت',
            'submission_id' => 'شناسه فرم',
            'card_code'     => 'کد وارد شده توسط کاربر',
            'jalali_date'   => 'تاریخ انتخابی (شمسی)',
            'selected_time' => 'ساعت انتخابی',
            'surprise_code' => 'کد سوپرایز',
            'upsell_items'  => 'لیست خرید افزایشی',
            'gregorian_date' => 'تاریخ انتخابی (میلادی)',
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('تنظیمات پیامک', 'user-cards'); ?></h1>
            <p class="description"><?php esc_html_e('نام کاربری و گذرواژه سرویس پیامک خود را وارد کنید. در صورت نیاز می‌توانید یک کد الگوی پیش‌فرض (bodyId) و ترتیب متغیرها را نیز برای تمام کارت‌ها تنظیم نمایید.', 'user-cards'); ?></p>
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
                                    <option value="payamak_panel" <?php selected($gateway, 'payamak_panel'); ?>><?php esc_html_e('Payamak Panel', 'user-cards'); ?></option>
                                    <option value="iran_payamak" <?php selected($gateway, 'iran_payamak'); ?>><?php esc_html_e('IranPayamak', 'user-cards'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('درگاه موردنظر برای ارسال پیامک را انتخاب کنید.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_username"><?php esc_html_e('نام کاربری پنل پیامک', 'user-cards'); ?></label></th>
                            <td><input name="uc_sms_username" type="text" id="uc_sms_username" value="<?php echo esc_attr($username); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_password"><?php esc_html_e('کلمه عبور پنل پیامک', 'user-cards'); ?></label></th>
                            <td><input name="uc_sms_password" type="password" id="uc_sms_password" value="<?php echo esc_attr($password); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_sender_number"><?php esc_html_e('شماره ارسال‌کننده', 'user-cards'); ?></label></th>
                            <td>
                                <input name="uc_sms_sender_number" type="text" id="uc_sms_sender_number" value="<?php echo esc_attr($sender_number); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('در صورت استفاده از درگاه‌هایی که نیاز به شماره فرستنده اختصاصی دارند، این مقدار را وارد کنید.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_default_pattern_code"><?php esc_html_e('کد الگوی پیش‌فرض (bodyId)', 'user-cards'); ?></label></th>
                            <td>
                                <input name="uc_sms_default_pattern_code" type="text" id="uc_sms_default_pattern_code" value="<?php echo esc_attr($pattern_code); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('اگر برای کارت خاصی کد الگو تنظیم نشده باشد، از این مقدار استفاده خواهد شد.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uc_sms_default_pattern_vars"><?php esc_html_e('ترتیب متغیرهای پیش‌فرض', 'user-cards'); ?></label></th>
                            <td>
                                <input name="uc_sms_default_pattern_vars" type="text" id="uc_sms_default_pattern_vars" value="<?php echo esc_attr($pattern_vars); ?>" class="regular-text" placeholder="مثال: user_name,card_title,jalali_date">
                                <p class="description"><?php esc_html_e('متغیرها را دقیقاً مطابق با الگوی ثبت شده در سامانه پیامک و با جداکننده ویرگول وارد کنید.', 'user-cards'); ?></p>
                                <p class="description"><?php esc_html_e('اگر این بخش خالی باشد، ترتیب پیش‌فرض user_name,card_title,jalali_date,selected_time,surprise_code استفاده خواهد شد.', 'user-cards'); ?></p>
                                <div class="uc-sms-vars-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                                    <?php foreach ($available_vars as $key => $label) : ?>
                                        <span class="uc-sms-var-tag" data-target="uc_sms_default_pattern_vars" data-var="<?php echo esc_attr($key); ?>" style="background:#eee;padding:4px 8px;border-radius:4px;cursor:pointer;display:inline-block;">
                                            <?php echo esc_html($label); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description"><?php esc_html_e('برای افزودن هر متغیر به ورودی بالا می‌توانید روی برچسب آن کلیک کنید.', 'user-cards'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.uc-sms-var-tag').forEach(function(tag) {
                tag.addEventListener('click', function() {
                    var input = document.getElementById(this.getAttribute('data-target'));
                    if (!input) return;
                    var value = this.getAttribute('data-var');
                    if (!input.value) {
                        input.value = value;
                    } else {
                        input.value = input.value + ',' + value;
                    }
                    input.focus();
                });
            });
        });
        </script>
        <?php
    }

    public static function sanitize_gateway($value) {
        $value = sanitize_key((string) $value);
        $allowed = ['payamak_panel', 'iran_payamak'];

        if (!in_array($value, $allowed, true)) {
            return 'payamak_panel';
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
        if (!is_string($value)) {
            return '';
        }
        $parts = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $value))));
        return implode(',', $parts);
    }
}
