<?php
if (!defined('ABSPATH')) { exit; }

class UC_Metaboxes {
    public static function register() {
        add_meta_box('uc_card_details', __('Card Settings', 'user-cards'), [__CLASS__, 'render_card_box'], 'uc_card', 'normal', 'high');
        add_meta_box('uc_card_codes', __('Codes (CSV Import)', 'user-cards'), [__CLASS__, 'render_codes_box'], 'uc_card', 'normal', 'default');
        add_meta_box('uc_card_pricing', __('Pricing (Normal + Upsells)', 'user-cards'), [__CLASS__, 'render_pricing_box'], 'uc_card', 'normal', 'default');
        add_meta_box('uc_card_schedule', __('Weekly Schedule', 'user-cards'), [__CLASS__, 'render_schedule_box'], 'uc_card', 'normal', 'default');
    }

    public static function render_card_box($post) {
        wp_nonce_field('uc_card_save', 'uc_card_nonce');
        $related_post_id = (int) get_post_meta($post->ID, '_uc_related_post_id', true);
        $wallet_amount = (float) get_post_meta($post->ID, 'wallet_amount', true);
        $code_type = get_post_meta($post->ID, 'code_type', true);
        if (!in_array($code_type, ['wallet', 'coupon'], true)) {
            $code_type = 'coupon';
        }
        $store_url = get_post_meta($post->ID, 'store_url', true);
        $related_post_type = $related_post_id ? get_post_type($related_post_id) : 'post';
        $public_types = get_post_types(['public' => true], 'objects');
        unset($public_types['attachment']);

        echo '<style>.uc-admin-row{display:flex;gap:10px;align-items:center;margin:8px 0;} .uc-admin-select{min-width:220px;} .uc-note{color:#666} .uc-progress{height:10px;background:#eee;border-radius:8px;overflow:hidden} .uc-progress>span{display:block;height:100%;background:#111;width:0}</style>';
        echo '<div class="uc-admin-row">';
        echo '<label><strong>' . esc_html__('Post Type', 'user-cards') . '</strong></label>';
        echo '<select id="uc_related_post_type" class="uc-admin-select">';
        foreach ($public_types as $type => $obj) {
            printf('<option value="%s" %s>%s</option>', esc_attr($type), selected($related_post_type, $type, false), esc_html($obj->labels->singular_name));
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="uc-admin-row">';
        echo '<label><strong>' . esc_html__('Related Post', 'user-cards') . '</strong></label>';
        echo '<select id="uc_related_post_id" name="uc_related_post_id" class="uc-admin-select"><option value="">' . esc_html__('-- Select --', 'user-cards') . '</option>';
        // Preload current type posts (limited)
        $pre = get_posts(['post_type' => $related_post_type, 'posts_per_page' => 200, 'post_status' => 'publish']);
        foreach ($pre as $p) {
            printf('<option value="%d" %s>%s</option>', $p->ID, selected($related_post_id, $p->ID, false), esc_html(get_the_title($p)));
        }
        echo '</select>';
        echo '</div>';
        echo '<p class="uc-note">' . esc_html__('Select the post type, then the specific post to associate with this card.', 'user-cards') . '</p>';

        echo '<hr />';
        echo '<div class="uc-admin-row">';
        echo '<label for="uc_wallet_amount"><strong>' . esc_html__('مبلغ کیف پول', 'user-cards') . '</strong></label>';
        printf(
            '<input id="uc_wallet_amount" type="number" step="0.01" min="0" name="uc_wallet_amount" value="%s" class="uc-admin-select" />',
            esc_attr($wallet_amount)
        );
        echo '</div>';

        echo '<div class="uc-admin-row">';
        echo '<label><strong>' . esc_html__('نوع کد', 'user-cards') . '</strong></label>';
        foreach ([
            'coupon' => __('کد تخفیف', 'user-cards'),
            'wallet' => __('کیف پول', 'user-cards'),
        ] as $value => $label) {
            printf(
                '<label style="margin-inline-end:15px"><input type="radio" name="uc_code_type" value="%1$s" %2$s /> %3$s</label>',
                esc_attr($value),
                checked($code_type, $value, false),
                esc_html($label)
            );
        }
        echo '</div>';

        echo '<div class="uc-admin-row">';
        echo '<label for="uc_store_url"><strong>' . esc_html__('لینک فروشگاه مقصد', 'user-cards') . '</strong></label>';
        printf(
            '<input id="uc_store_url" type="url" name="uc_store_url" value="%s" class="widefat" placeholder="https://shop.example.com" />',
            esc_attr($store_url)
        );
        echo '</div>';
        echo '<p class="uc-note">' . esc_html__('آدرس فروشگاه باید یک لینک معتبر HTTPS برای فروشگاه ووکامرس مقصد باشد.', 'user-cards') . '</p>';
    }

    public static function render_schedule_box($post) {
        echo '<div class="uc-schedule-meta">';

        if (!class_exists('\\UCB\\Services\\ScheduleService')) {
            echo '<p class="uc-note">' . esc_html__('برای نمایش جدول زمان‌بندی، افزونه وب‌سرویس باید فعال باشد.', 'user-cards') . '</p>';
            echo '</div>';
            return;
        }

        $card_id = (int) $post->ID;
        $supervisors = self::get_supervisors_for_card($card_id);

        if (empty($supervisors)) {
            echo '<p class="uc-note">' . esc_html__('هنوز سرپرستی برای این کارت تعیین نشده است. ابتدا کارت را به یک سرپرست در افزونه وب‌سرویس اختصاص دهید.', 'user-cards') . '</p>';
            echo '</div>';
            return;
        }

        $schedule_service = new \UCB\Services\ScheduleService();
        $hours = range(8, 18);
        $weekday_labels = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'];
        $timestamp = current_time('timestamp');

        if (function_exists('wp_date')) {
            $availability_date = wp_date('Y-m-d', $timestamp);
            $availability_display = wp_date('Y/m/d', $timestamp);
        } else {
            $availability_date = gmdate('Y-m-d', $timestamp);
            $availability_display = gmdate('Y/m/d', $timestamp);
        }

        $show_usage = method_exists($schedule_service, 'get_availability');
        $sections = [];

        foreach ($supervisors as $supervisor) {
            $matrix = $schedule_service->get_matrix($supervisor['id'], $card_id);
            $availability = $show_usage
                ? $schedule_service->get_availability($card_id, $supervisor['id'], $availability_date)
                : [];

            $grid = self::build_schedule_grid($hours, $matrix, $availability);

            $sections[] = [
                'supervisor' => $supervisor,
                'grid' => $grid,
                'has_data' => self::has_schedule_data($grid),
            ];
        }

        if ($show_usage) {
            echo '<p class="uc-note">' . sprintf(esc_html__('ظرفیت و آمار رزرو هر اسلات زمانی برای تاریخ %s نمایش داده شده است.', 'user-cards'), esc_html($availability_display)) . '</p>';
        } else {
            echo '<p class="uc-note">' . esc_html__('ظرفیت مجاز هر ساعت در جدول زیر نمایش داده شده است.', 'user-cards') . '</p>';
        }

        if (count($sections) > 1) {
            echo '<div class="uc-schedule-toolbar">';
            echo '<label for="uc-schedule-supervisor"><strong>' . esc_html__('سرپرست', 'user-cards') . '</strong></label>';
            echo '<select id="uc-schedule-supervisor" class="uc-admin-select">';
            foreach ($sections as $index => $section) {
                $label = $section['supervisor']['name'];
                if ($section['supervisor']['is_default']) {
                    $label .= ' (' . esc_html__('پیش‌فرض', 'user-cards') . ')';
                }
                printf(
                    '<option value="%1$d" %2$s>%3$s</option>',
                    (int) $section['supervisor']['id'],
                    selected($index === 0, true, false),
                    esc_html($label)
                );
            }
            echo '</select>';
            echo '</div>';
        }

        foreach ($sections as $index => $section) {
            $supervisor = $section['supervisor'];
            $style = ($index === 0) ? '' : ' style="display:none"';
            echo '<div class="uc-schedule-section" data-supervisor="' . esc_attr($supervisor['id']) . '"' . $style . '>';
            echo '<div class="uc-schedule-header">';
            echo '<h4>' . esc_html($supervisor['name']);
            if ($supervisor['is_default']) {
                echo ' <span class="uc-schedule-badge">' . esc_html__('سرپرست پیش‌فرض', 'user-cards') . '</span>';
            }
            echo '</h4>';
            echo '</div>';

            if (!$section['has_data']) {
                echo '<p class="uc-note uc-schedule-empty">' . esc_html__('هنوز ظرفیتی برای این سرپرست ثبت نشده است.', 'user-cards') . '</p>';
            }

            echo '<div class="uc-schedule-table-wrapper">';
            echo '<table class="uc-schedule-grid">';
            echo '<thead><tr><th>' . esc_html__('روز / ساعت', 'user-cards') . '</th>';
            foreach ($hours as $hour) {
                echo '<th>' . esc_html($hour . ':00') . '</th>';
            }
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($weekday_labels as $weekday => $label) {
                echo '<tr>';
                echo '<th>' . esc_html($label) . '</th>';
                foreach ($hours as $hour) {
                    $slot = $section['grid'][$weekday][$hour];
                    $overbooked = $slot['used'] > $slot['capacity'] && $slot['capacity'] > 0;
                    $td_class = $overbooked ? ' class="uc-schedule-overbooked"' : '';
                    echo '<td' . $td_class . '>';
                    echo '<div class="uc-schedule-cell">';
                    echo '<strong>' . esc_html(number_format_i18n($slot['capacity'])) . '</strong>';
                    echo '<span>' . sprintf(esc_html__('رزرو: %s', 'user-cards'), esc_html(number_format_i18n($slot['used']))) . '</span>';
                    echo '<span>' . sprintf(esc_html__('خالی: %s', 'user-cards'), esc_html(number_format_i18n($slot['remaining']))) . '</span>';
                    echo '</div>';
                    echo '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    private static function get_supervisors_for_card(int $card_id): array {
        $supervisors = [];
        $default_supervisor = (int) get_post_meta($card_id, 'ucb_default_supervisor', true);

        $users = get_users([
            'role__in' => ['supervisor'],
            'number' => -1,
            'fields' => ['ID', 'display_name', 'user_login'],
        ]);

        foreach ($users as $user) {
            $cards = get_user_meta($user->ID, 'ucb_supervisor_cards', true);
            if (!is_array($cards)) {
                continue;
            }
            $cards = array_map('intval', $cards);
            if (in_array($card_id, $cards, true)) {
                $supervisors[$user->ID] = [
                    'id' => (int) $user->ID,
                    'name' => self::format_user_name($user),
                    'is_default' => ((int) $user->ID === $default_supervisor),
                ];
            }
        }

        if ($default_supervisor > 0 && !isset($supervisors[$default_supervisor])) {
            $user = get_user_by('id', $default_supervisor);
            $supervisors[$default_supervisor] = [
                'id' => $default_supervisor,
                'name' => $user ? self::format_user_name($user) : sprintf(esc_html__('سرپرست #%d', 'user-cards'), $default_supervisor),
                'is_default' => true,
            ];
        }

        uasort($supervisors, function ($a, $b) {
            if ($a['is_default'] === $b['is_default']) {
                return strcasecmp($a['name'], $b['name']);
            }
            return $a['is_default'] ? -1 : 1;
        });

        return array_values($supervisors);
    }

    private static function format_user_name($user): string {
        if (!$user) {
            return '';
        }

        $first = trim((string) get_user_meta($user->ID, 'first_name', true));
        $last = trim((string) get_user_meta($user->ID, 'last_name', true));
        $name = trim($first . ' ' . $last);

        if ($name === '') {
            $name = trim((string) $user->display_name);
        }

        if ($name === '') {
            $name = (string) $user->user_login;
        }

        return $name;
    }

    private static function build_schedule_grid(array $hours, array $matrix, array $availability): array {
        $grid = [];
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $grid[$weekday] = [];
            foreach ($hours as $hour) {
                $grid[$weekday][$hour] = [
                    'capacity' => 0,
                    'used' => 0,
                    'remaining' => 0,
                ];
            }
        }

        foreach ($matrix as $slot) {
            $weekday = isset($slot['weekday']) ? (int) $slot['weekday'] : null;
            $hour = isset($slot['hour']) ? (int) $slot['hour'] : null;
            if ($weekday === null || $hour === null) {
                continue;
            }
            if (!isset($grid[$weekday][$hour])) {
                continue;
            }
            $grid[$weekday][$hour]['capacity'] = max(0, (int) ($slot['capacity'] ?? 0));
        }

        foreach ($availability as $slot) {
            $weekday = isset($slot['weekday']) ? (int) $slot['weekday'] : null;
            $hour = isset($slot['hour']) ? (int) $slot['hour'] : null;
            if ($weekday === null || $hour === null) {
                continue;
            }
            if (!isset($grid[$weekday][$hour])) {
                continue;
            }

            $grid[$weekday][$hour]['capacity'] = max($grid[$weekday][$hour]['capacity'], (int) ($slot['capacity'] ?? 0));
            $grid[$weekday][$hour]['used'] = max(0, (int) ($slot['used'] ?? 0));
            $grid[$weekday][$hour]['remaining'] = max(0, (int) ($slot['remaining'] ?? 0));
        }

        return $grid;
    }

    private static function has_schedule_data(array $grid): bool {
        foreach ($grid as $weekday) {
            foreach ($weekday as $slot) {
                if ($slot['capacity'] > 0 || $slot['used'] > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function render_codes_box($post) {
        wp_nonce_field('uc_codes_admin', 'uc_codes_admin_nonce');
        $count = UC_DB::count_codes($post->ID);
        echo '<div class="uc-admin-row" style="justify-content:space-between">';
        echo '<div><strong>' . esc_html__('Codes', 'user-cards') . ':</strong> ' . sprintf(esc_html__('%d codes stored', 'user-cards'), $count) . '</div>';
        echo '</div>';
        echo '<div class="uc-admin-row">';
        echo '<input type="file" id="uc_codes_csv" accept=".csv" />';
        echo '<button type="button" class="button button-primary" id="uc_upload_start" data-card="' . esc_attr($post->ID) . '">' . esc_html__('Upload & Start Import', 'user-cards') . '</button>';
        echo '</div>';
        echo '<div class="uc-progress" id="uc_import_progress" style="display:none"><span style="width:0"></span></div>';
        echo '<p class="uc-note">' . esc_html__('CSV with first column = code. Large files are imported in batches without timing out.', 'user-cards') . '</p>';
    }

    public static function render_pricing_box($post) {
        wp_nonce_field('uc_pricing_save', 'uc_pricing_nonce');
        $rows = get_post_meta($post->ID, '_uc_pricings', true);
        if (!is_array($rows) || empty($rows)) {
            $rows = [
                ['label' => 'فروش نرمال', 'amount' => 0],
                ['label' => 'فروش افزایشی 1', 'amount' => 0],
                ['label' => 'فروش افزایشی 2', 'amount' => 0],
                ['label' => 'فروش افزایشی 3', 'amount' => 0],
                ['label' => 'فروش افزایشی 4', 'amount' => 0],
            ];
        }
        echo '<style>.uc-price-row{display:flex;gap:8px;align-items:center;margin-bottom:8px} .uc-price-row input[type=text]{min-width:240px} .uc-price-row input[type=number]{width:160px} .uc-price-row .button{vertical-align:middle} .uc-price-rows{margin-top:6px} .uc-admin-note{color:#666}</style>';
        echo '<div class="uc-price-rows" id="uc-price-rows">';
        foreach ($rows as $r) {
            $label = isset($r['label']) ? $r['label'] : '';
            $amount = isset($r['amount']) ? $r['amount'] : '';
            echo '<div class="uc-price-row">';
            echo '<input type="text" name="uc_price_label[]" value="' . esc_attr($label) . '" placeholder="عنوان" />';
            echo '<input type="number" step="0.01" name="uc_price_amount[]" value="' . esc_attr($amount) . '" placeholder="مبلغ" />';
            echo '<button type="button" class="button uc-price-remove">' . esc_html__('حذف', 'user-cards') . '</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '<button type="button" class="button button-secondary" id="uc-price-add">' . esc_html__('افزودن ردیف', 'user-cards') . '</button>';
        echo '<p class="uc-admin-note">' . esc_html__('این فیلدها به صورت آرایه ذخیره می‌شوند و از API قابل دریافت/ارسال هستند.', 'user-cards') . '</p>';
        echo '<template id="uc-price-template"><div class="uc-price-row"><input type="text" name="uc_price_label[]" placeholder="عنوان" /><input type="number" step="0.01" name="uc_price_amount[]" placeholder="مبلغ" /><button type="button" class="button uc-price-remove">' . esc_html__('حذف', 'user-cards') . '</button></div></template>';
    }

    public static function save($post_id, $post) {
        if ($post->post_type !== 'uc_card') return;

        // Related post
        if (isset($_POST['uc_card_nonce']) && wp_verify_nonce($_POST['uc_card_nonce'], 'uc_card_save')) {
            $rel = isset($_POST['uc_related_post_id']) ? (int) $_POST['uc_related_post_id'] : 0;
            if ($rel > 0) {
                update_post_meta($post_id, '_uc_related_post_id', $rel);
            } else {
                delete_post_meta($post_id, '_uc_related_post_id');
            }
        }
        
        // Pricing save
        if (isset($_POST['uc_pricing_nonce']) && wp_verify_nonce($_POST['uc_pricing_nonce'], 'uc_pricing_save')) {
            $labels = isset($_POST['uc_price_label']) && is_array($_POST['uc_price_label']) ? array_map('sanitize_text_field', (array) $_POST['uc_price_label']) : [];
            $amounts = isset($_POST['uc_price_amount']) && is_array($_POST['uc_price_amount']) ? (array) $_POST['uc_price_amount'] : [];
            $rows = [];
            $count = max(count($labels), count($amounts));
            for ($i=0; $i<$count; $i++) {
                $label = isset($labels[$i]) ? wp_strip_all_tags($labels[$i]) : '';
                $amount = isset($amounts[$i]) ? floatval($amounts[$i]) : 0;
                if ($label === '' && $amount === 0.0) continue;
                $rows[] = ['label' => $label, 'amount' => $amount];
            }
            update_post_meta($post_id, '_uc_pricings', $rows);
        }

        // Wallet integration meta fields
        if (isset($_POST['uc_card_nonce']) && wp_verify_nonce($_POST['uc_card_nonce'], 'uc_card_save')) {
            if (isset($_POST['uc_wallet_amount'])) {
                $amount = UC_Post_Types::sanitize_wallet_amount($_POST['uc_wallet_amount']);
                if ($amount > 0) {
                    update_post_meta($post_id, 'wallet_amount', $amount);
                } else {
                    delete_post_meta($post_id, 'wallet_amount');
                }
            }

            if (isset($_POST['uc_code_type'])) {
                $type = UC_Post_Types::sanitize_code_type($_POST['uc_code_type']);
                update_post_meta($post_id, 'code_type', $type);
            }

            if (isset($_POST['uc_store_url'])) {
                $store_url = UC_Post_Types::sanitize_store_url($_POST['uc_store_url']);
                if ($store_url !== '') {
                    update_post_meta($post_id, 'store_url', $store_url);
                } else {
                    delete_post_meta($post_id, 'store_url');
                }
            }
        }
    }

    // Admin AJAX: fetch posts by type
    public static function ajax_fetch_posts() {
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'forbidden'], 403);
        $type = sanitize_key($_GET['post_type'] ?? 'post');
        $items = get_posts(['post_type' => $type, 'posts_per_page' => 200, 'post_status' => 'publish']);
        $out = [];
        foreach ($items as $p) $out[] = ['id' => $p->ID, 'text' => get_the_title($p->ID)];
        wp_send_json_success(['posts' => $out]);
    }

    // Admin AJAX: init import (upload file)
    public static function ajax_import_init() {
        check_admin_referer('uc_codes_admin', 'nonce');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!$card_id || !current_user_can('edit_post', $card_id)) wp_send_json_error(['message' => 'forbidden'], 403);
        if (empty($_FILES['file'])) wp_send_json_error(['message' => 'no file'], 400);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        UC_DB::maybe_install();
        $uploaded = wp_handle_upload($_FILES['file'], ['test_form' => false]);
        if (isset($uploaded['error'])) wp_send_json_error(['message' => $uploaded['error']], 400);
        $file = $uploaded['file'];
        $state = [
            'file' => $file,
            'offset' => 0,
            'imported' => 0,
            'batch' => 2000,
            'done' => false,
            'size' => @filesize($file) ?: 0,
        ];
        update_post_meta($card_id, '_uc_import_state', $state);
        wp_send_json_success(['message' => 'uploaded', 'state' => ['offset' => 0, 'imported' => 0, 'size' => $state['size']]]);
    }

    // Admin AJAX: process next batch
    public static function ajax_import_batch() {
        check_admin_referer('uc_codes_admin', 'nonce');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!$card_id || !current_user_can('edit_post', $card_id)) wp_send_json_error(['message' => 'forbidden'], 403);
        $state = get_post_meta($card_id, '_uc_import_state', true);
        if (empty($state) || empty($state['file']) || !file_exists($state['file'])) wp_send_json_error(['message' => 'no state'], 400);

        $offset = (int) ($state['offset'] ?? 0);
        $batch = (int) ($state['batch'] ?? 2000);
        $file = $state['file'];
        $size = (int) ($state['size'] ?? (@filesize($file) ?: 0));

        $fh = fopen($file, 'r');
        if (!$fh) wp_send_json_error(['message' => 'cannot open'], 400);
        // Seek to previous byte offset
        if ($offset > 0) fseek($fh, $offset);

        $codes = [];
        $processed = 0;
        while ($processed < $batch && ($row = fgetcsv($fh)) !== false) {
            if (!isset($row[0])) { $processed++; continue; }
            $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) trim($row[0]));
            if ($code !== '') $codes[] = $code;
            $processed++;
        }
        $new_offset = ftell($fh);
        fclose($fh);

        $inserted = 0;
        if (!empty($codes)) {
            // De-duplicate within batch
            $codes = array_values(array_unique($codes));
            $inserted = UC_DB::insert_codes($card_id, $codes);
        }

        $state['offset'] = $new_offset;
        $state['imported'] = (int) ($state['imported'] ?? 0) + $inserted;
        $done = ($processed < $batch); // we hit EOF if processed < batch
        $state['done'] = $done;
        update_post_meta($card_id, '_uc_import_state', $state);

        $total = UC_DB::count_codes($card_id);
        $progress = $size > 0 ? min(100, (int) floor(($state['offset'] / $size) * 100)) : 0;
        wp_send_json_success([
            'imported' => $state['imported'],
            'done' => $done,
            'offset' => $state['offset'],
            'total' => $total,
            'progress' => $progress,
        ]);
    }
}
