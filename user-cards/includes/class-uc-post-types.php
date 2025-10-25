<?php
if (!defined('ABSPATH')) { exit; }

class UC_Post_Types {
    public static function register() {
        // Cards CPT
        register_post_type('uc_card', [
            'labels' => [
                'name' => __('Cards', 'user-cards'),
                'singular_name' => __('Card', 'user-cards'),
            ],
            'public' => true,
            'has_archive' => false,
            'show_in_rest' => true,
            'supports' => ['title','editor','thumbnail'],
            'menu_icon' => 'dashicons-screenoptions',
        ]);

        // Register meta for card pricings (array of {label, amount}) and expose via REST
        if (function_exists('register_post_meta')) {
            register_post_meta('uc_card', '_uc_pricings', [
                'single' => true,
                'type' => 'array',
                'show_in_rest' => [
                    'schema' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'amount' => ['type' => 'number'],
                            ],
                        ],
                    ],
                ],
                'sanitize_callback' => ['UC_Post_Types', 'sanitize_pricings'],
                'auth_callback' => '__return_true',
            ]);

            register_post_meta('uc_card', 'wallet_amount', [
                'single' => true,
                'type' => 'number',
                'show_in_rest' => true,
                'sanitize_callback' => ['UC_Post_Types', 'sanitize_wallet_amount'],
                'auth_callback' => ['UC_Post_Types', 'meta_auth_callback'],
            ]);

            register_post_meta('uc_card', 'code_type', [
                'single' => true,
                'type' => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => ['UC_Post_Types', 'sanitize_code_type'],
                'auth_callback' => ['UC_Post_Types', 'meta_auth_callback'],
            ]);

            register_post_meta('uc_card', 'store_url', [
                'single' => true,
                'type' => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => ['UC_Post_Types', 'sanitize_store_url'],
                'auth_callback' => ['UC_Post_Types', 'meta_auth_callback'],
            ]);
        }

        // Taxonomy: Card Collections (مجموعه کارت‌ها)
        register_taxonomy('uc_card_group', ['uc_card'], [
            'hierarchical' => true,
            'labels' => [
                'name' => __('مجموعه کارت‌ها', 'user-cards'),
                'singular_name' => __('مجموعه کارت', 'user-cards'),
                'search_items' => __('جستجوی مجموعه', 'user-cards'),
                'all_items' => __('همه مجموعه‌ها', 'user-cards'),
                'edit_item' => __('ویرایش مجموعه', 'user-cards'),
                'update_item' => __('به‌روزرسانی مجموعه', 'user-cards'),
                'add_new_item' => __('افزودن مجموعه جدید', 'user-cards'),
                'new_item_name' => __('نام مجموعه جدید', 'user-cards'),
                'menu_name' => __('مجموعه کارت‌ها', 'user-cards'),
            ],
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'card-group'],
        ]);

        // Submissions CPT
        register_post_type('uc_submission', [
            'labels' => [
                'name' => __('Submissions', 'user-cards'),
                'singular_name' => __('Submission', 'user-cards'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'supports' => ['title'],
            'menu_icon' => 'dashicons-yes-alt',
        ]);
    }

    public static function sanitize_pricings($value, $post_id, $key) {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                if (!is_array($row)) continue;
                $label = isset($row['label']) ? wp_strip_all_tags((string)$row['label']) : '';
                $amount = isset($row['amount']) ? floatval($row['amount']) : 0;
                if ($label === '' && $amount === 0.0) continue;
                $out[] = ['label' => $label, 'amount' => $amount];
            }
        }
        return $out;
    }

    public static function sanitize_wallet_amount($value) {
        $value = is_numeric($value) ? (float) $value : 0.0;
        return max(0, $value);
    }

    public static function sanitize_code_type($value) {
        $value = is_string($value) ? strtolower($value) : '';
        return in_array($value, ['wallet', 'coupon'], true) ? $value : 'coupon';
    }

    public static function sanitize_store_url($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        $sanitized = esc_url_raw($value);
        if (empty($sanitized) || strpos($sanitized, 'https://') !== 0) {
            return '';
        }
        return $sanitized;
    }

    public static function meta_auth_callback() {
        return current_user_can('edit_posts');
    }
}
