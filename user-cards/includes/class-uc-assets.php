<?php
if (!defined('ABSPATH')) { exit; }

class UC_Assets {
    protected static function asset_version($relative_path) {
        $base_dir = defined('UC_PLUGIN_DIR') ? UC_PLUGIN_DIR : plugin_dir_path(UC_PLUGIN_FILE);
        if (function_exists('trailingslashit')) {
            $base_dir = trailingslashit($base_dir);
        } else {
            $base_dir = rtrim($base_dir, '/\\') . '/';
        }

        $path = $base_dir . ltrim($relative_path, '/');
        if (file_exists($path)) {
            $timestamp = filemtime($path);
            if ($timestamp !== false) {
                return UC_VERSION . '.' . $timestamp;
            }
        }

        return UC_VERSION;
    }

    public static function admin($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_card = $screen && isset($screen->post_type) && $screen->post_type === 'uc_card';
        $is_card_edit = ($hook === 'post-new.php' || $hook === 'post.php') && $is_card;
        $is_sms_settings = ($hook === 'user-cards_page_uc-sms-settings');

        if ($is_card_edit) {
            wp_register_style(
                'uc-admin',
                UC_PLUGIN_URL . 'assets/css/uc-admin.css',
                [],
                self::asset_version('assets/css/uc-admin.css')
            );
            wp_enqueue_style('uc-admin');
        }

        if (!$is_card_edit && !$is_sms_settings) {
            return;
        }

        if (!wp_script_is('uc-admin', 'registered')) {
            wp_register_script(
                'uc-admin',
                UC_PLUGIN_URL . 'assets/js/uc-admin.js',
                ['jquery'],
                self::asset_version('assets/js/uc-admin.js'),
                true
            );
        }

        $localize = [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('uc_codes_admin'),
            'sms_nonce' => wp_create_nonce('uc_sms_settings'),
            'i18n'      => [
                'testing'               => __('در حال بررسی...', 'user-cards'),
                'testConnectionSuccess' => __('اتصال با موفقیت برقرار شد.', 'user-cards'),
                'testConnectionFailed'  => __('اتصال برقرار نشد. خطا را بررسی کنید.', 'user-cards'),
                'testSendSuccess'       => __('پیامک تستی با موفقیت ارسال شد.', 'user-cards'),
                'testSendFailed'        => __('ارسال پیامک تستی با خطا مواجه شد.', 'user-cards'),
            ],
        ];

        wp_localize_script('uc-admin', 'UC_Admin', $localize);
        wp_enqueue_script('uc-admin');
    }

    public static function frontend() {
        // Styles
        wp_register_style(
            'uc-frontend',
            UC_PLUGIN_URL . 'assets/css/uc-frontend.css',
            [],
            self::asset_version('assets/css/uc-frontend.css')
        );
        wp_enqueue_style('uc-frontend');

        // Shamsi datepicker (from user snippet), local assets
        wp_register_style(
            'uc-shamsi-snippet',
            UC_PLUGIN_URL . 'assets/css/uc-shamsi-snippet.css',
            [],
            self::asset_version('assets/css/uc-shamsi-snippet.css')
        );
        wp_enqueue_style('uc-shamsi-snippet');

        wp_register_script(
            'uc-frontend',
            UC_PLUGIN_URL . 'assets/js/uc-frontend.js',
            ['jquery'],
            self::asset_version('assets/js/uc-frontend.js'),
            true
        );
        wp_localize_script('uc-frontend', 'UC_Ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('uc_ajax'),
            'api_url' => esc_url_raw(rest_url('user-cards-bridge/v1/')),
            'i18n' => [
                'invalidCode' => __('کد وارد شده نامعتبر است.', 'user-cards'),
                'usedCode' => __('این کد قبلا استفاده شده است.', 'user-cards'),
                'serverError' => __('خطایی رخ داد. مجدد تلاش کنید.', 'user-cards'),
                'invalidDate' => __('تاریخ انتخابی نامعتبر است.', 'user-cards'),
                'slotFull' => __('این ساعت برای این تاریخ تکمیل شده است.', 'user-cards'),
                'availabilityDayLabel' => __('روز انتخابی:', 'user-cards'),
                'availabilityUsage' => __('رزرو شده {used} از {capacity} (باقی‌مانده {remaining})', 'user-cards'),
                'availabilityNoData' => __('برای این تاریخ ظرفیتی ثبت نشده است.', 'user-cards'),
            ],
        ]);
        wp_enqueue_script('uc-frontend');

        wp_register_script(
            'uc-shamsi-snippet',
            UC_PLUGIN_URL . 'assets/js/uc-shamsi-snippet.js',
            [],
            self::asset_version('assets/js/uc-shamsi-snippet.js'),
            true
        );
        wp_enqueue_script('uc-shamsi-snippet');
    }
}
