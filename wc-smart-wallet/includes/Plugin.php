<?php

namespace WCW;

class Plugin {
    private static ?Plugin $instance = null;
    private Services\WalletService $wallet_service;

    public static function instance(): Plugin {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        (new Database())->maybe_upgrade();
        $this->wallet_service = new Services\WalletService();

        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        if (is_admin()) {
            new Admin();
        }

        new Frontend\MyAccount($this->wallet_service);
        new Frontend\Checkout($this->wallet_service);
        new Frontend\Topup($this->wallet_service);

        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('wc-smart-wallet', false, dirname(plugin_basename(WCW_PLUGIN_FILE)) . '/languages');
    }

    public function init(): void {
        $this->wallet_service->register_hooks();
    }

    public function register_rest(): void {
        (new REST\CodesController())->register_routes();
    }

    public function register_shortcodes(): void {
        add_shortcode('wcw_wallet_topup', [Frontend\Topup::class, 'render_shortcode']);
    }

    public function register_gateway(array $gateways): array {
        $gateways[] = Payment\Gateway::class;
        return $gateways;
    }
}
