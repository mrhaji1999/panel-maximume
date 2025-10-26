<?php

namespace WWB\API;

use WWB\Security;
use WWB\WalletService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Redeem {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('wwb/v1', '/redeem', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => [$this, 'permission_check'],
            'callback' => [$this, 'redeem_code'],
            'args' => [
                'code' => [
                    'type' => 'string',
                    'required' => true,
                ],
                'user_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);
    }

    public function permission_check(WP_REST_Request $request) {
        $verification = Security::verify_request($request);
        return is_wp_error($verification) ? $verification : true;
    }

    public function redeem_code(WP_REST_Request $request) {
        $code = $request->get_param('code');
        $user_id = (int) $request->get_param('user_id');

        if ($user_id <= 0) {
            return new WP_Error('wwb_invalid_user', __('Valid user_id is required.', 'woo-wallet-bridge'));
        }

        $result = $this->service->redeem_code($code, $user_id);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $result,
        ]);
    }
}
