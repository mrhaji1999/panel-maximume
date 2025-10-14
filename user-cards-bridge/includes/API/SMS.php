<?php

namespace UCB\API;

use UCB\Security;
use UCB\Services\NotificationService;
use UCB\SMS\PayamakPanel;
use UCB\Database;
use WP_Error;
use WP_REST_Request;

class SMS extends BaseController {
    protected PayamakPanel $sms;
    protected NotificationService $notifications;
    protected Database $database;

    public function __construct() {
        $this->sms = new PayamakPanel();
        $this->notifications = new NotificationService();
        $this->database = new Database();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/sms/send', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_sms'],
            'permission_callback' => [$this, 'require_sms_permission'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/normal/send-code', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_normal_code'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);

        register_rest_route($this->namespace, '/sms/logs', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_logs'],
            'permission_callback' => [$this, 'require_sms_permission'],
        ]);

        register_rest_route($this->namespace, '/sms/statistics', [
            'methods'  => 'GET',
            'callback' => [$this, 'statistics'],
            'permission_callback' => [$this, 'require_sms_permission'],
        ]);

        register_rest_route($this->namespace, '/sms/test', [
            'methods'  => 'POST',
            'callback' => [$this, 'test_configuration'],
            'permission_callback' => [$this, 'require_sms_permission'],
        ]);
    }

    public function send_sms(WP_REST_Request $request) {
        $to = sanitize_text_field($request->get_param('to'));
        $body_id = sanitize_text_field($request->get_param('bodyId'));
        $text = (array) $request->get_param('text');

        if (empty($to) || empty($body_id)) {
            return $this->error('ucb_invalid_sms', __('Missing phone or bodyId.', 'user-cards-bridge'), 400);
        }

        $result = $this->sms->send(null, $to, $body_id, $text, get_current_user_id());

        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }

        return $this->success([
            'message' => __('SMS sent successfully.', 'user-cards-bridge'),
            'result'  => $result,
        ]);
    }

    public function send_normal_code(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $result = $this->notifications->send_normal_code($customer_id);

        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }

        return $this->success($result);
    }

    public function list_logs(WP_REST_Request $request) {
        $filters = [
            'customer_id' => $request->get_param('customer_id'),
            'phone'       => $request->get_param('phone'),
            'status'      => $request->get_param('status'),
            'search'      => $request->get_param('search'),
        ];

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $rows = $this->database->get_sms_logs($filters, $page, $per_page);

        $items = array_map([$this, 'format_log'], $rows);

        return $this->success([
            'items' => $items,
            'pagination' => $this->paginate($page, $per_page, $this->database->count_sms_logs($filters)),
        ]);
    }

    public function statistics(WP_REST_Request $request) {
        $days = max(1, (int) $request->get_param('days') ?: 7);
        $stats = $this->database->get_sms_statistics($days);

        return $this->success($stats);
    }

    public function test_configuration(WP_REST_Request $request) {
        $result = $this->sms->test_configuration();

        if (!$result['success']) {
            return $this->error('ucb_sms_test_failed', $result['message'], 400);
        }

        return $this->success($result);
    }

    public function require_sms_permission(WP_REST_Request $request): bool {
        return is_user_logged_in() && current_user_can('ucb_send_sms');
    }

    public function require_customer_access(WP_REST_Request $request): bool {
        $customer_id = (int) $request->get_param('id');
        return is_user_logged_in() && Security::can_manage_customer($customer_id);
    }

    protected function format_log(array $row): array {
        $customer_id = isset($row['customer_id']) ? (int) $row['customer_id'] : 0;
        $customer = $customer_id ? get_user_by('id', $customer_id) : null;

        $sent_by = isset($row['sent_by']) ? (int) $row['sent_by'] : 0;
        $sender = $sent_by ? get_user_by('id', $sent_by) : null;

        $result_code = isset($row['result_code']) ? strtolower((string) $row['result_code']) : '';
        $status = 'failed' === $result_code || 'error' === $result_code ? 'failed' : 'sent';

        return [
            'id'              => isset($row['id']) ? (int) $row['id'] : 0,
            'customer_id'     => $customer_id ?: null,
            'customer_name'   => $customer ? $customer->display_name : null,
            'phone'           => $row['phone'] ?? '',
            'bodyId'          => $row['body_id'] ?? '',
            'message'         => $row['message'] ?? '',
            'result_code'     => $row['result_code'] ?? null,
            'result_message'  => $row['result_message'] ?? null,
            'rec_id'          => $row['rec_id'] ?? null,
            'sent_by'         => $sent_by ?: null,
            'sent_by_name'    => $sender ? $sender->display_name : null,
            'status'          => $status,
            'error_message'   => 'failed' === $status ? ($row['result_message'] ?? null) : null,
            'created_at'      => isset($row['created_at']) ? mysql_to_rfc3339($row['created_at']) : null,
        ];
    }
}
