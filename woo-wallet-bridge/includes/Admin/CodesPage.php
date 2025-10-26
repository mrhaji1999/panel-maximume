<?php

namespace WWB\Admin;

use WWB\WalletService;

class CodesPage {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'woo-wallet-bridge',
            __('Wallet Codes', 'woo-wallet-bridge'),
            __('Wallet Codes', 'woo-wallet-bridge'),
            'manage_wallet',
            'wwb-wallet-codes',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_wallet')) {
            wp_die(__('You do not have permission to view wallet codes.', 'woo-wallet-bridge'));
        }

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        $data = $this->service->get_codes([
            'status' => $status,
            'search' => $search,
            'user_id' => $user_id,
            'paged' => $paged,
            'per_page' => 20,
        ]);

        $statuses = [
            '' => __('All statuses', 'woo-wallet-bridge'),
            'unused' => __('Unused', 'woo-wallet-bridge'),
            'used' => __('Used', 'woo-wallet-bridge'),
            'expired' => __('Expired', 'woo-wallet-bridge'),
        ];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Wallet Codes', 'woo-wallet-bridge') . '</h1>';

        echo '<form method="get" class="wwb-filters">';
        echo '<input type="hidden" name="page" value="wwb-wallet-codes" />';
        echo '<input type="text" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search code', 'woo-wallet-bridge') . '" /> ';
        echo '<input type="number" name="user_id" value="' . esc_attr($user_id) . '" placeholder="' . esc_attr__('User ID', 'woo-wallet-bridge') . '" class="small-text" /> ';
        echo '<select name="status">';
        foreach ($statuses as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($status, $value, false), esc_html($label));
        }
        echo '</select> ';
        submit_button(__('Filter', 'woo-wallet-bridge'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Code', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Amount', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Status', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Type', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('User', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Created', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Expires', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Used', 'woo-wallet-bridge') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($data['items'])) {
            echo '<tr><td colspan="8">' . esc_html__('No codes found.', 'woo-wallet-bridge') . '</td></tr>';
        } else {
            foreach ($data['items'] as $item) {
                $user_link = $item->user_id ? sprintf('<a href="%s">#%d</a>', esc_url(get_edit_user_link((int) $item->user_id)), (int) $item->user_id) : __('—', 'woo-wallet-bridge');
                echo '<tr>';
                echo '<td><code>' . esc_html($item->code) . '</code></td>';
                echo '<td>' . esc_html(number_format_i18n((float) $item->amount, 2)) . '</td>';
                echo '<td>' . esc_html(ucfirst($item->status)) . '</td>';
                echo '<td>' . esc_html(ucfirst($item->type)) . '</td>';
                echo '<td>' . $user_link . '</td>';
                echo '<td>' . esc_html($item->created_at) . '</td>';
                echo '<td>' . esc_html($item->expires_at ?: __('—', 'woo-wallet-bridge')) . '</td>';
                echo '<td>' . esc_html($item->used_at ?: __('—', 'woo-wallet-bridge')) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        if ($data['pages'] > 1) {
            $base_url = remove_query_arg('paged');
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($i = 1; $i <= $data['pages']; $i++) {
                $link = esc_url(add_query_arg('paged', $i, $base_url));
                $class = $i === $paged ? ' class="page-numbers current"' : ' class="page-numbers"';
                echo '<a' . $class . ' href="' . $link . '">' . (int) $i . '</a> ';
            }
            echo '</div></div>';
        }

        echo '</div>';
    }
}
