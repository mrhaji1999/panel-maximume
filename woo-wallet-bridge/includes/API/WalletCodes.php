<?php

namespace WWB\API;

use WWB\Security;
use WWB\WalletService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class WalletCodes {
    protected $service;

    public function __construct(WalletService $service) {
        $this->service = $service;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('wwb/v1', '/wallet-codes', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => [$this, 'permission_check'],
            'callback' => [$this, 'create_code'],
            'args' => [
                'code' => [
                    'type' => 'string',
                    'required' => true,
                ],
                'amount' => [
                    'type' => 'number',
                    'required' => true,
                ],
                'user_id' => [
                    'type' => 'integer',
                    'required' => false,
                ],
                'expires_at' => [
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ]);
    }

    public function permission_check(WP_REST_Request $request) {
        $verification = Security::verify_request($request);
        return is_wp_error($verification) ? $verification : true;
    }

    public function create_code(WP_REST_Request $request) {
        $data = [
            'code' => $request->get_param('code'),
            'amount' => $request->get_param('amount'),
            'user_id' => $request->get_param('user_id'),
            'expires_at' => $request->get_param('expires_at'),
            'meta' => $request->get_param('meta'),
        ];

        $result = $this->service->upsert_code($data);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $result,
        ]);
    }
}
