<?php

namespace WWB;

use WWB\Admin\CodesPage;
use WWB\Admin\SettingsPage;
use WWB\Admin\TransactionsPage;
use WWB\API\Redeem;
use WWB\API\WalletCodes;
use WWB\Frontend\Account;
use WWB\Frontend\Checkout;
use WWB\Frontend\Topup;

class Plugin {
    private static $instance;

    protected $wallet_service;

    public static function instance(): self {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->wallet_service = new WalletService();

        $this->init_hooks();
        $this->init_components();
    }

    protected function init_hooks(): void {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_endpoints']);
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
    }

    protected function init_components(): void {
        new Security();

        if (is_admin()) {
            new SettingsPage();
            new CodesPage($this->wallet_service);
            new TransactionsPage($this->wallet_service);
        }

        new Account($this->wallet_service);
        new Checkout($this->wallet_service);
        new Topup($this->wallet_service);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('woo-wallet-bridge', false, dirname(plugin_basename(WWB_PLUGIN_FILE)) . '/languages/');
    }

    public function register_endpoints(): void {
        add_rewrite_endpoint('my-wallet', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('wallet-topup', EP_ROOT | EP_PAGES);
    }

    public function register_rest_endpoints(): void {
        new WalletCodes($this->wallet_service);
        new Redeem($this->wallet_service);
    }

    public function wallet_service(): WalletService {
        return $this->wallet_service;
    }
}
