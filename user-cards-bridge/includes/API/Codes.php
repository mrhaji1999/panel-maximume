<?php

namespace UCB\API;

use UCB\Logger;
use UCB\Plugin;
use UCB\Services\CouponService;
use UCB\Services\WalletBridgeService;
use WP_Error;
use WP_REST_Request;

class Codes extends BaseController {
    public function register_routes() {
        register_rest_route($this->namespace, '/codes', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_forward'],
            'permission_callback' => [$this, 'permission_check'],
            'args' => [
                'user_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'minimum' => 1,
                ],
                'product_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'minimum' => 1,
                ],
                'amount' => [
                    'type' => 'number',
                    'required' => false,
                    'default' => 0,
                ],
                'wallet_amount' => [
                    'type' => 'number',
                    'required' => false,
                    'default' => 0,
                ],
                'code_type' => [
                    'type' => 'string',
                    'required' => true,
                    'enum' => ['wallet', 'coupon'],
                ],
                'store_link' => [
                    'type' => 'string',
                    'required' => true,
                ],
                'unique_code' => [
                    'type' => 'string',
                    'required' => true,
                ],
                'meta' => [
                    'type' => 'object',
                    'required' => false,
                ],
            ],
        ]);
    }

    public function permission_check($request) {
        return true;
    }

    public function handle_forward(WP_REST_Request $request) {
        $validation = $this->sanitize_params($request, [
            'user_id' => ['type' => 'int', 'required' => true, 'min' => 1],
            'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
            'amount' => ['type' => 'float'],
            'wallet_amount' => ['type' => 'float'],
            'code_type' => ['type' => 'string', 'required' => true, 'enum' => ['wallet', 'coupon']],
            'store_link' => ['type' => 'url', 'required' => true],
            'unique_code' => ['type' => 'string', 'required' => true],
        ]);

        if (is_wp_error($validation)) {
            return $validation;
        }

        $data = $validation;
        $data['meta'] = $request->get_param('meta');

        if ($data['code_type'] === 'wallet' && $data['wallet_amount'] <= 0) {
            return $this->error('invalid_wallet_amount', __('Wallet amount must be greater than zero for wallet codes.', UCB_TEXT_DOMAIN), 422);
        }

        if ($data['code_type'] === 'coupon' && $data['wallet_amount'] < 0) {
            return $this->error('invalid_coupon_amount', __('Coupon discount cannot be negative.', UCB_TEXT_DOMAIN), 422);
        }

        $logger_context = [
            'user_id' => $data['user_id'],
            'product_id' => $data['product_id'],
            'code_type' => $data['code_type'],
            'store_link' => $data['store_link'],
            'unique_code' => $data['unique_code'],
        ];

        if ($data['code_type'] === 'coupon') {
            $service = new CouponService();
            $result = $service->forward_coupon($data);
        } else {
            $service = new WalletBridgeService();
            $result = $service->push_wallet_code($data);
        }

        if (is_wp_error($result)) {
            Logger::log('error', 'Failed to forward code', array_merge($logger_context, [
                'error' => $result->get_error_message(),
            ]));
            return $this->from_wp_error($result);
        }

        Logger::log('info', 'Forwarded code successfully', array_merge($logger_context, [
            'response' => $result,
        ]));

        $sms_result = Plugin::get_instance()->send_code_sms(
            $data['code_type'],
            (int) $data['user_id'],
            (string) $data['unique_code'],
            (float) $data['wallet_amount'],
            (array) $data['meta']
        );

        if (is_wp_error($sms_result)) {
            Logger::log('warning', 'Forwarded code but SMS failed', array_merge($logger_context, [
                'sms_error' => $sms_result->get_error_message(),
            ]));
        }

        return $this->success([
            'ok' => true,
            'forwarded' => $data['code_type'],
            'details' => $result,
            'sms_sent' => !is_wp_error($sms_result),
            'sms_error' => is_wp_error($sms_result) ? $sms_result->get_error_message() : null,
        ]);
    }
}
