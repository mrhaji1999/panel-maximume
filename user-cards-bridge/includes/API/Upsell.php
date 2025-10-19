<?php

namespace UCB\API;

use UCB\Plugin;
use UCB\Security;
use UCB\Services\CardService;
use UCB\Services\CustomerService;
use UCB\Services\StatusManager;
use UCB\SMS\PayamakPanel;
use UCB\WooCommerce\Integration;
use WP_Error;
use WP_REST_Request;

class Upsell extends BaseController {
    protected CardService $cards;
    protected CustomerService $customers;
    protected PayamakPanel $sms;
    protected Integration $woocommerce;
    protected StatusManager $statuses;

    public function __construct() {
        $this->cards = new CardService();
        $this->customers = new CustomerService();
        $this->sms = new PayamakPanel();
        $this->woocommerce = Plugin::get_instance()->get_woocommerce_integration();
        $this->statuses = new StatusManager();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/upsell/init', [
            'methods'  => 'POST',
            'callback' => [$this, 'initiate'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);
        
        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/normal/send-code', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_normal_code'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);
    }

    public function initiate(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $card_id = (int) $request->get_param('card_id');
        $field_key = sanitize_text_field($request->get_param('field_key'));

        if (!$card_id || !$field_key) {
            return $this->error('ucb_missing_params', __('card_id and field_key are required.', 'user-cards-bridge'), 400);
        }

        $fields = $this->cards->get_card_fields($card_id);
        if (is_wp_error($fields)) {
            return $this->from_wp_error($fields);
        }

        $selected = null;
        foreach ($fields as $field) {
            if ($field['key'] === $field_key) {
                $selected = $field;
                break;
            }
        }

        if (!$selected) {
            return $this->error('ucb_field_not_found', __('Selected field not found on card.', 'user-cards-bridge'), 404);
        }

        $order = $this->woocommerce->create_upsell_order([
            'customer_id' => $customer_id,
            'card_id'     => $card_id,
            'field_key'   => $field_key,
            'label'       => $selected['label'],
            'amount'      => $selected['amount'],
        ]);

        if (is_wp_error($order)) {
            return $this->from_wp_error($order);
        }

        $phone = get_user_meta($customer_id, 'phone', true) ?: get_user_meta($customer_id, 'billing_phone', true);
        if (!$phone) {
            return $this->error('ucb_phone_missing', __('Customer does not have a phone number.', 'user-cards-bridge'), 400);
        }

        $formatted_amount = function_exists('wc_price') ? wc_price($selected['amount']) : number_format((float) $selected['amount'], 2, '.', ',');
        $sms_result = $this->sms->send_upsell($customer_id, $phone, $order['pay_link'], $selected['label'], $formatted_amount);
        if (is_wp_error($sms_result)) {
            return $this->from_wp_error($sms_result);
        }

        update_user_meta($customer_id, 'ucb_upsell_field_key', $selected['key']);
        update_user_meta($customer_id, 'ucb_upsell_field_label', $selected['label']);
        update_user_meta($customer_id, 'ucb_upsell_amount', (float) $selected['amount']);
        update_user_meta($customer_id, 'ucb_upsell_pay_link', $order['pay_link']);

        $this->statuses->change_status($customer_id, 'upsell_pending', get_current_user_id(), [
            'order_id'   => $order['order_id'],
            'field_key'  => $selected['key'],
            'field_label'=> $selected['label'],
            'amount'     => (float) $selected['amount'],
            'pay_link'   => $order['pay_link'],
        ]);

        return $this->success([
            'order_id'  => $order['order_id'],
            'pay_link'  => $order['pay_link'],
            'sms_result'=> $sms_result,
            'token'     => $order['token'],
            'expires_at'=> $order['expires_at'],
        ]);
    }

    public function send_normal_code(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        
        // Change status to normal (this will trigger code generation and SMS)
        $result = $this->statuses->change_status($customer_id, 'normal', get_current_user_id());
        
        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }
        
        // Get the generated code
        $random_code = get_user_meta($customer_id, 'ucb_customer_random_code', true);
        
        return $this->success([
            'message' => __('Random code sent to customer.', 'user-cards-bridge'),
            'code' => $random_code,
            'status' => 'normal'
        ]);
    }

    public function require_customer_access(WP_REST_Request $request): bool {
        $customer_id = (int) $request->get_param('id');
        return is_user_logged_in() && Security::can_manage_customer($customer_id);
    }
}
