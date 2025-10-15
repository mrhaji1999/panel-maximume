<?php
if (!defined('ABSPATH')) { exit; }

class UC_Admin {
    public static function init() {
        add_filter('manage_edit-uc_submission_columns', [__CLASS__, 'columns']);
        add_action('manage_uc_submission_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_filter('manage_edit-uc_submission_sortable_columns', [__CLASS__, 'sortable']);
    }

    public static function columns($columns) {
        $new = [];
        $new['cb'] = isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />';
        $new['uc_user'] = __('نام و نام خانوادگی', 'user-cards');
        $new['uc_card_group'] = __('مجموعه کارت', 'user-cards');
        $new['uc_phone'] = __('شماره همراه', 'user-cards');
        $new['uc_card'] = __('نام کارت', 'user-cards');
        $new['uc_code'] = __('کد کارت', 'user-cards');
        $new['uc_day'] = __('روز انتخابی', 'user-cards');
        $new['uc_time'] = __('ساعت انتخابی', 'user-cards');
        $new['uc_surprise'] = __('کد سوپرایز', 'user-cards');
        $new['uc_created'] = __('تاریخ ثبت', 'user-cards');
        return $new;
    }

    public static function render_column($column, $post_id) {
        switch ($column) {
            case 'uc_user':
                $uid = (int) get_post_meta($post_id, '_uc_user_id', true);
                if ($uid) {
                    $first = get_user_meta($uid, 'first_name', true);
                    $last = get_user_meta($uid, 'last_name', true);
                    $name = trim($first . ' ' . $last);
                    if ($name === '') {
                        $u = get_userdata($uid);
                        $name = $u ? $u->display_name : ('#' . $uid);
                    }
                    echo esc_html($name);
                } else {
                    echo '—';
                }
                break;
            case 'uc_phone':
                $uid = (int) get_post_meta($post_id, '_uc_user_id', true);
                if ($uid) {
                    $keys = ['billing_phone','phone','mobile','user_phone','user_mobile','cellphone','user_cellphone'];
                    $phone = '';
                    foreach ($keys as $k) { $phone = get_user_meta($uid, $k, true); if (!empty($phone)) break; }
                    echo $phone ? esc_html($phone) : '—';
                } else {
                    echo '—';
                }
                break;
            case 'uc_card_group':
                $card_id = (int) get_post_meta($post_id, '_uc_card_id', true);
                if ($card_id) {
                    $terms = get_the_terms($card_id, 'uc_card_group');
                    if ($terms && !is_wp_error($terms)) {
                        echo esc_html(implode(', ', wp_list_pluck($terms, 'name')));
                    } else {
                        echo '—';
                    }
                } else {
                    echo '—';
                }
                break;
            case 'uc_card':
                $card_id = (int) get_post_meta($post_id, '_uc_card_id', true);
                if ($card_id) {
                    $title = get_the_title($card_id);
                    if ($title) echo esc_html($title);
                    else echo '#' . (int) $card_id;
                } else {
                    echo '—';
                }
                break;
            case 'uc_code':
                $code = get_post_meta($post_id, '_uc_code', true);
                echo $code ? '<code>' . esc_html($code) . '</code>' : '—';
                break;
            case 'uc_day':
                $day = get_post_meta($post_id, '_uc_date', true);
                echo $day ? esc_html($day) : '—';
                break;
            case 'uc_time':
                $time = get_post_meta($post_id, '_uc_time', true);
                echo $time ? esc_html($time) : '—';
                break;
            case 'uc_surprise':
                $s = get_post_meta($post_id, '_uc_surprise', true);
                echo $s ? '<code>' . esc_html($s) . '</code>' : '—';
                break;
            case 'uc_created':
                $p = get_post($post_id);
                if ($p) echo esc_html(get_date_from_gmt(get_gmt_from_date($p->post_date), 'Y/m/d H:i'));
                else echo '—';
                break;
        }
    }

    public static function sortable($columns) {
        $columns['uc_created'] = 'date';
        return $columns;
    }
}
