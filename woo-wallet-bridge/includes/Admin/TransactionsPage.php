<?php

namespace WWB\Admin;

use WWB\WalletService;

class TransactionsPage {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'woo-wallet-bridge',
            __('Wallet Transactions', 'woo-wallet-bridge'),
            __('Transactions', 'woo-wallet-bridge'),
            'view_wallet_reports',
            'wwb-wallet-transactions',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('view_wallet_reports')) {
            wp_die(__('You do not have permission to view wallet transactions.', 'woo-wallet-bridge'));
        }

        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        $data = $this->service->get_transactions([
            'user_id' => $user_id,
            'paged' => $paged,
            'per_page' => 20,
        ]);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Wallet Transactions', 'woo-wallet-bridge') . '</h1>';
        echo '<form method="get" class="wwb-filters">';
        echo '<input type="hidden" name="page" value="wwb-wallet-transactions" />';
        echo '<input type="number" name="user_id" value="' . esc_attr($user_id) . '" placeholder="' . esc_attr__('User ID', 'woo-wallet-bridge') . '" class="small-text" /> ';
        submit_button(__('Filter', 'woo-wallet-bridge'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('User', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Type', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Amount', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Order', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Note', 'woo-wallet-bridge') . '</th>';
        echo '<th>' . esc_html__('Created', 'woo-wallet-bridge') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($data['items'])) {
            echo '<tr><td colspan="7">' . esc_html__('No transactions found.', 'woo-wallet-bridge') . '</td></tr>';
        } else {
            foreach ($data['items'] as $item) {
                $user_link = sprintf('<a href="%s">#%d</a>', esc_url(get_edit_user_link((int) $item->user_id)), (int) $item->user_id);
                $order_link = $item->order_id ? sprintf('<a href="%s">#%d</a>', esc_url(get_edit_post_link((int) $item->order_id)), (int) $item->order_id) : __('—', 'woo-wallet-bridge');
                echo '<tr>';
                echo '<td>' . esc_html($item->id) . '</td>';
                echo '<td>' . $user_link . '</td>';
                echo '<td>' . esc_html(ucfirst($item->type)) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $item->amount, 2)) . '</td>';
                echo '<td>' . $order_link . '</td>';
                echo '<td>' . esc_html($item->note) . '</td>';
                echo '<td>' . esc_html($item->created_at) . '</td>';
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
