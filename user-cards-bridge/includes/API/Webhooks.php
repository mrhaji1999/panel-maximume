<?php

namespace UCB\API;

use UCB\Services\PaymentTokenService;
use UCB\Services\StatusManager;
use WP_REST_Request;

class Webhooks extends BaseController {
    protected StatusManager $statuses;
    protected PaymentTokenService $tokens;

    public function __construct() {
        $this->statuses = new StatusManager();
        $this->tokens = new PaymentTokenService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/webhooks/woocommerce/payment', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_payment_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_payment_webhook(WP_REST_Request $request) {
        $body = $request->get_body();

        if (!$this->verify_signature($request, $body)) {
            return $this->error('ucb_webhook_invalid_signature', __('Invalid webhook signature.', 'user-cards-bridge'), 403);
        }

        $payload = json_decode($body, true);

        if (!is_array($payload) || empty($payload['id'])) {
            return $this->error('ucb_webhook_invalid_payload', __('Invalid payload.', 'user-cards-bridge'), 400);
        }

        $order_id = (int) $payload['id'];
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        if (!$order) {
            return $this->error('ucb_webhook_order_not_found', __('Order not found.', 'user-cards-bridge'), 404);
        }

        $customer_id = (int) $order->get_meta('_ucb_customer_id');
        $token = (string) $order->get_meta('_ucb_payment_token');
        $card_id = (int) $order->get_meta('_ucb_card_id');

        if ($token) {
            $this->tokens->consume($token);
        }

        if ($customer_id > 0) {
            $this->statuses->change_status($customer_id, 'upsell_paid', 0, [
                'webhook' => true,
                'order_id' => $order_id,
            ], $card_id ?: null);
        }

        return $this->success([
            'order_id'    => $order_id,
            'customer_id' => $customer_id,
            'status'      => $customer_id ? get_user_meta($customer_id, 'ucb_customer_status', true) : null,
        ]);
    }

    protected function verify_signature(WP_REST_Request $request, string $body): bool {
        $secret = (string) get_option('ucb_webhook_secret', '');

        if (empty($secret)) {
            return true;
        }

        $header = $request->get_header('x-wc-webhook-signature');

        if (!$header) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return hash_equals($expected, $header);
    }
}
