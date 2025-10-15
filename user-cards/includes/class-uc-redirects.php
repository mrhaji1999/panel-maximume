<?php
if (!defined('ABSPATH')) { exit; }

class UC_Redirects {
    public static function init() {
        add_action('template_redirect', [__CLASS__, 'guard_dashboard_page']);
    }

    private static function current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST);
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $url    = $scheme . '://' . $host . $uri;
        return esc_url_raw($url);
    }

    public static function guard_dashboard_page() {
        if (is_user_logged_in()) return;

        // Try to detect the shortcode on the currently queried content
        global $post;
        $has_shortcode = false;
        if ($post && isset($post->post_content)) {
            $has_shortcode = has_shortcode($post->post_content, 'uc_dashboard');
        }

        if (!$has_shortcode) return;

        // Build login URL per request
        $current = self::current_url();
        // Use the exact pattern the user requested, but with dynamic redirect_to
        $login_url = add_query_arg([
            'login' => 'true',
            'page' => '1',
            'redirect_to' => rawurlencode($current),
        ], home_url('/'));

        wp_safe_redirect($login_url, 302);
        exit;
    }
}

