<?php
if (!defined('ABSPATH')) { exit; }

class UC_Shortcodes {
    public static function auth($atts = []) {
        if (is_user_logged_in()) {
            $dash_url = isset($atts['dashboard']) ? esc_url($atts['dashboard']) : home_url('/my-account/');
            return '<div class="uc-auth-logged">' . esc_html__('شما وارد شده‌اید.', 'user-cards') . ' <a class="uc-btn" href="' . $dash_url . '">' . esc_html__('رفتن به حساب کاربری', 'user-cards') . '</a></div>';
        }

        ob_start();
        ?>
        <div class="uc-auth">
            <div class="uc-auth-tabs">
                <button class="uc-tab active" data-tab="login"><?php echo esc_html__('ورود', 'user-cards'); ?></button>
                <button class="uc-tab" data-tab="register"><?php echo esc_html__('ثبت‌نام', 'user-cards'); ?></button>
            </div>
            <div class="uc-tab-content active" id="uc-tab-login">
                <form class="uc-form" id="uc-login-form">
                    <input type="text" name="username" placeholder="<?php echo esc_attr__('نام کاربری یا ایمیل', 'user-cards'); ?>" required />
                    <input type="password" name="password" placeholder="<?php echo esc_attr__('رمز عبور', 'user-cards'); ?>" required />
                    <input type="hidden" name="action" value="uc_login" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('uc_ajax')); ?>" />
                    <button type="submit" class="uc-btn uc-primary"><?php echo esc_html__('ورود', 'user-cards'); ?></button>
                    <div class="uc-form-msg" aria-live="polite"></div>
                </form>
            </div>
            <div class="uc-tab-content" id="uc-tab-register">
                <form class="uc-form" id="uc-register-form">
                    <input type="text" name="username" placeholder="<?php echo esc_attr__('نام کاربری', 'user-cards'); ?>" required />
                    <input type="email" name="email" placeholder="<?php echo esc_attr__('ایمیل', 'user-cards'); ?>" required />
                    <input type="password" name="password" placeholder="<?php echo esc_attr__('رمز عبور', 'user-cards'); ?>" required />
                    <input type="hidden" name="action" value="uc_register" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('uc_ajax')); ?>" />
                    <button type="submit" class="uc-btn uc-primary"><?php echo esc_html__('ثبت‌نام', 'user-cards'); ?></button>
                    <div class="uc-form-msg" aria-live="polite"></div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function dashboard($atts = []) {
        $atts = shortcode_atts([
            'collection' => '', // taxonomy term slug(s) to filter by
        ], $atts, 'uc_dashboard');
        if (!is_user_logged_in()) {
            return '<div class="uc-need-auth">' . esc_html__('برای مشاهده کارت‌ها ابتدا وارد شوید.', 'user-cards') . '</div>';
        }

        $args = [
            'post_type' => 'uc_card',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if (!empty($atts['collection'])) {
            $slugs = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $atts['collection']))));
            if (!empty($slugs)) {
                $args['tax_query'] = [[
                    'taxonomy' => 'uc_card_group',
                    'field' => 'slug',
                    'terms' => $slugs,
                ]];
            }
        }
        $cards = get_posts($args);

        // Find used cards (submissions) for current user
        $user_id = get_current_user_id();
        $subs = get_posts([
            'post_type' => 'uc_submission',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [[
                'key' => '_uc_user_id',
                'value' => $user_id,
                'compare' => '=',
                'type' => 'NUMERIC'
            ]],
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        $used_map = [];
        foreach ($subs as $sid) {
            $cid = (int) get_post_meta($sid, '_uc_card_id', true);
            if (!$cid) continue;
            if (!isset($used_map[$cid])) {
                $used_map[$cid] = [
                    'date' => (string) get_post_meta($sid, '_uc_date', true),
                    'time' => (string) get_post_meta($sid, '_uc_time', true),
                    'sid' => $sid,
                ];
            }
        }

        $unused = [];
        $used = [];
        foreach ($cards as $card) {
            if (isset($used_map[$card->ID])) $used[] = $card; else $unused[] = $card;
        }

        ob_start();
        ?>
        <div class="uc-dashboard">
            <h2 class="uc-title"><?php echo esc_html__('کارت‌های شما', 'user-cards'); ?></h2>
            <div class="uc-card-grid">
                <?php foreach ($unused as $card):
                    $thumb = get_the_post_thumbnail_url($card, 'large');
                    $thumb = $thumb ? $thumb : UC_PLUGIN_URL . 'assets/img/placeholder.svg';
                    $content = apply_filters('the_content', $card->post_content);
                ?>
                    <div class="uc-card" data-card-id="<?php echo esc_attr($card->ID); ?>">
                        <div class="uc-card-bg" style="background-image:url('<?php echo esc_url($thumb); ?>');"></div>
                        <div class="uc-card-title"><?php echo esc_html(get_the_title($card)); ?></div>
                        <div class="uc-card-content" style="display:none;"><?php echo $content; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($used)): ?>
            <h2 class="uc-title" style="margin-top:24px;"><?php echo esc_html__('کارت‌های استفاده شده', 'user-cards'); ?></h2>
            <div class="uc-card-grid">
                <?php foreach ($used as $card):
                    $thumb = get_the_post_thumbnail_url($card, 'large');
                    $thumb = $thumb ? $thumb : UC_PLUGIN_URL . 'assets/img/placeholder.svg';
                    $content = apply_filters('the_content', $card->post_content);
                    $meta = $used_map[$card->ID] ?? ['date' => '', 'time' => ''];
                ?>
                    <div class="uc-card uc-card-used" data-card-id="<?php echo esc_attr($card->ID); ?>">
                        <div class="uc-card-bg" style="background-image:url('<?php echo esc_url($thumb); ?>');"></div>
                        <div class="uc-card-title"><?php echo esc_html(get_the_title($card)); ?></div>
                        <div class="uc-card-badge"><?php echo esc_html(trim(($meta['date'] ?? '') . ' • ' . ($meta['time'] ?? ''))); ?></div>
                        <div class="uc-card-content" style="display:none;"><?php echo $content; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="uc-modal" id="uc-card-modal" aria-hidden="true">
            <div class="uc-modal-backdrop"></div>
            <div class="uc-modal-dialog" role="dialog" aria-modal="true">
                <button class="uc-modal-close" aria-label="Close">×</button>
                <div class="uc-modal-body">
                    <div class="uc-stepper" aria-hidden="true">
                        <ol>
                            <li data-step="1">1</li>
                            <li data-step="2">2</li>
                            <li data-step="3">3</li>
                            <li data-step="4">4</li>
                        </ol>
                    </div>
                    <div class="uc-step uc-step-1">
                        <div class="uc-step-content"></div>
                        <div class="uc-step-actions">
                            <button class="uc-btn uc-primary" data-action="have-code"><?php echo esc_html__('کد این کارت را دارم', 'user-cards'); ?></button>
                            <button class="uc-btn" data-action="buy-code"><?php echo esc_html__('درخواست خرید کد', 'user-cards'); ?></button>
                        </div>
                    </div>
                    <div class="uc-step uc-step-2" style="display:none;">
                        <label><?php echo esc_html__('کد خود را وارد کنید', 'user-cards'); ?></label>
                        <input type="text" class="uc-input" id="uc-code-input" placeholder="ABC123" />
                        <div class="uc-inline-msg"></div>
                        <div class="uc-step-actions">
                            <button class="uc-btn uc-primary" data-action="validate-code"><?php echo esc_html__('بررسی کد', 'user-cards'); ?></button>
                            <button class="uc-btn uc-secondary" data-action="back-1"><?php echo esc_html__('بازگشت', 'user-cards'); ?></button>
                        </div>
                    </div>
                    <div class="uc-step uc-step-3" style="display:none;">
                        <label><?php echo esc_html__('انتخاب روز (تقویم شمسی)', 'user-cards'); ?></label>
                        <div class="shamsi-datepicker-container">
                            <input type="text" class="uc-input shamsi-datepicker-field" id="uc-date-input" placeholder="<?php echo esc_attr__('تاریخ را انتخاب کنید (از امروز به بعد)', 'user-cards'); ?>" readonly="readonly" />
                        </div>
                        <label style="margin-top:12px; display:block;"><?php echo esc_html__('انتخاب ساعت', 'user-cards'); ?></label>
                        <div class="uc-time-scroll">
                            <?php for ($h=9; $h<=18; $h++): $label = sprintf('%02d:00', $h); ?>
                                <button class="uc-time" data-time="<?php echo esc_attr($label); ?>" data-hour="<?php echo esc_attr($h); ?>" data-label="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></button>
                            <?php endfor; ?>
                        </div>
                        <div class="uc-availability-summary" aria-live="polite"></div>
                        <div class="uc-step-actions">
                            <button class="uc-btn uc-primary" data-action="submit-form"><?php echo esc_html__('تایید و ثبت', 'user-cards'); ?></button>
                            <button class="uc-btn uc-secondary" data-action="back-2"><?php echo esc_html__('بازگشت', 'user-cards'); ?></button>
                        </div>
                    </div>
                    <div class="uc-step uc-step-4" style="display:none;">
                        <div class="uc-success"></div>
                        <div class="uc-step-actions">
                            <button class="uc-btn uc-primary" data-action="close-modal"><?php echo esc_html__('بستن', 'user-cards'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}


