<?php
if (!defined('ABSPATH')) { exit; }

class UC_Assets {
    public static function admin($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_card = $screen && isset($screen->post_type) && $screen->post_type === 'uc_card';
        if (($hook === 'post-new.php' || $hook === 'post.php') && $is_card) {
            wp_register_style('uc-admin', UC_PLUGIN_URL . 'assets/css/uc-admin.css', [], UC_VERSION);
            wp_enqueue_style('uc-admin');

            wp_register_script('uc-admin', UC_PLUGIN_URL . 'assets/js/uc-admin.js', ['jquery'], UC_VERSION, true);
            wp_localize_script('uc-admin', 'UC_Admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('uc_codes_admin'),
            ]);
            wp_enqueue_script('uc-admin');
        }
    }

    public static function frontend() {
        // Styles
        wp_register_style('uc-frontend', UC_PLUGIN_URL . 'assets/css/uc-frontend.css', [], UC_VERSION);
        wp_enqueue_style('uc-frontend');

        // Shamsi datepicker (from user snippet), local assets
        wp_register_style('uc-shamsi-snippet', UC_PLUGIN_URL . 'assets/css/uc-shamsi-snippet.css', [], UC_VERSION);
        wp_enqueue_style('uc-shamsi-snippet');

        wp_register_script('uc-frontend', UC_PLUGIN_URL . 'assets/js/uc-frontend.js', ['jquery'], UC_VERSION, true);
        wp_localize_script('uc-frontend', 'UC_Ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('uc_ajax'),
            'i18n' => [
                'invalidCode' => __('کد وارد شده نامعتبر است.', 'user-cards'),
                'usedCode' => __('این کد قبلا استفاده شده است.', 'user-cards'),
                'serverError' => __('خطایی رخ داد. مجدد تلاش کنید.', 'user-cards'),
            ],
        ]);
        wp_enqueue_script('uc-frontend');

        wp_register_script('uc-shamsi-snippet', UC_PLUGIN_URL . 'assets/js/uc-shamsi-snippet.js', [], UC_VERSION, true);
        wp_enqueue_script('uc-shamsi-snippet');
    }
}
