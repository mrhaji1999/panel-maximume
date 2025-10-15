<?php
if (!defined('ABSPATH')) { exit; }

class UC_Metaboxes {
    public static function register() {
        add_meta_box('uc_card_details', __('Card Settings', 'user-cards'), [__CLASS__, 'render_card_box'], 'uc_card', 'normal', 'high');
        add_meta_box('uc_card_codes', __('Codes (CSV Import)', 'user-cards'), [__CLASS__, 'render_codes_box'], 'uc_card', 'normal', 'default');
        add_meta_box('uc_card_pricing', __('Pricing (Normal + Upsells)', 'user-cards'), [__CLASS__, 'render_pricing_box'], 'uc_card', 'normal', 'default');
    }

    public static function render_card_box($post) {
        wp_nonce_field('uc_card_save', 'uc_card_nonce');
        $related_post_id = (int) get_post_meta($post->ID, '_uc_related_post_id', true);
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
