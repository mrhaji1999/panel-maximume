<?php

namespace UCB\API;

use UCB\Roles;
use UCB\Security;
use UCB\Services\CustomerService;
use UCB\Services\NotificationService;
use UCB\Services\StatusManager;
use UCB\Services\UserService;
use WP_REST_Request;

class Customers extends BaseController {
    protected CustomerService $customers;
    protected StatusManager $statuses;
    protected UserService $users;
    protected NotificationService $notifications;

    public function __construct() {
        $this->customers = new CustomerService();
        $this->statuses = new StatusManager();
        $this->users = new UserService();
        $this->notifications = new NotificationService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/customers', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_customers'],
            'permission_callback' => [$this, 'require_access'],
        ]);

        register_rest_route($this->namespace, '/customers/assignable', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_assignable_customers'],
            'permission_callback' => [$this, 'require_supervisor'],
        ]);

        register_rest_route($this->namespace, '/customers/assign-agent-bulk', [
            'methods'  => 'POST',
            'callback' => [$this, 'assign_agent_bulk'],
            'permission_callback' => [$this, 'require_supervisor'],
        ]);

        register_rest_route($this->namespace, '/customers/tabs', [
            'methods'  => 'GET',
            'callback' => [$this, 'customer_tabs'],
            'permission_callback' => [$this, 'require_access'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_customer'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/status', [
            'methods'  => 'PATCH',
            'callback' => [$this, 'update_status'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/notes', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_note'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/assign-supervisor', [
            'methods'  => 'POST',
            'callback' => [$this, 'assign_supervisor'],
            'permission_callback' => [$this, 'require_manager'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/assign-agent', [
            'methods'  => 'POST',
            'callback' => [$this, 'assign_agent'],
            'permission_callback' => [$this, 'require_manager_or_supervisor'],
        ]);

        register_rest_route($this->namespace, '/customers/(?P<id>\d+)/normal/send-code', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_normal_code'],
            'permission_callback' => [$this, 'require_customer_access'],
        ]);
    }

    public function list_customers(WP_REST_Request $request) {
        $filters = [
            'status'        => $request->get_param('status'),
            'card_id'       => $request->get_param('card_id'),
            'supervisor_id' => $request->get_param('supervisor_id'),
            'agent_id'      => $request->get_param('agent_id'),
            'search'        => $request->get_param('search'),
            'registered_date' => $request->get_param('registered_date'),
            'order'         => $request->get_param('order'),
        ];

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $user_id = get_current_user_id();
        $role = Roles::get_user_role($user_id);

        if ('supervisor' === $role) {
            $filters['supervisor_id'] = $user_id;
            $supervisor_cards = get_user_meta($user_id, 'ucb_supervisor_cards', true);
            if (!empty($supervisor_cards) && is_array($supervisor_cards)) {
                $filters['card_id_in'] = $supervisor_cards;
            }
        } elseif ('agent' === $role) {
            $filters['agent_id'] = $user_id;
        }

        $result = $this->customers->list_customers($filters, $page, $per_page);

        return $this->success([
            'items' => $result['items'],
            'pagination' => $this->paginate($page, $per_page, $result['total']),
        ]);
    }

    public function list_assignable_customers(WP_REST_Request $request) {
        $filters = [
            'supervisor_id' => get_current_user_id(),
            'agent_id' => 0, // Unassigned
        ];

        $supervisor_cards = get_user_meta(get_current_user_id(), 'ucb_supervisor_cards', true);
        if (!empty($supervisor_cards) && is_array($supervisor_cards)) {
            $filters['card_id_in'] = $supervisor_cards;
        }

        $result = $this->customers->list_customers($filters, 1, 1000);

        $items = array_values(array_filter(
            $result['items'],
            static function ($item) {
                $agent = $item['assigned_agent'] ?? null;

                return empty($agent);
            }
        ));

        return $this->success([
            'items' => $items,
        ]);
    }

    public function assign_agent_bulk(WP_REST_Request $request) {
        $submission_ids = $request->get_param('submission_ids');
        $agent_id = (int) $request->get_param('agent_id');

        if (empty($submission_ids) || !is_array($submission_ids) || $agent_id <= 0) {
            return $this->error('ucb_invalid_params', __('Invalid parameters.', 'user-cards-bridge'), 400);
        }

        foreach ($submission_ids as $submission_id) {
            $customer_id = (int) get_post_meta($submission_id, '_uc_user_id', true);
            $this->customers->assign_agent($customer_id, $agent_id, null, $submission_id);
        }

        return $this->success(['message' => __('Agents assigned successfully.', 'user-cards-bridge')]);
    }

    public function customer_tabs(WP_REST_Request $request) {
        $tabs = ['unassigned', 'upsell_pending', 'upsell_paid'];
        $data = [];
        $role = Roles::get_user_role(get_current_user_id());

        $base_filters = [];

        if ('supervisor' === $role) {
            $base_filters['supervisor_id'] = get_current_user_id();
        } elseif ('agent' === $role) {
            $base_filters['agent_id'] = get_current_user_id();
        }

        foreach ($tabs as $tab) {
            $result = $this->customers->list_customers(
                array_merge($base_filters, ['status' => $tab]),
                1,
                50
            );
            $data[$tab] = [
                'items' => $result['items'],
                'total' => $result['total'],
            ];
        }

        return $this->success(['tabs' => $data]);
    }

    public function get_customer(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $card_id = $request->get_param('card_id');
        $card_id = $card_id !== null ? (int) $card_id : null;
        $customer = $this->customers->get_customer($customer_id);

        if (!$customer) {
            return $this->error('ucb_customer_not_found', __('Customer not found.', 'user-cards-bridge'), 404);
        }

        $data = $this->customers->format_customer($customer, $card_id);
        $data['notes'] = $this->customers->get_notes($customer_id);
        $data['statuses'] = $this->statuses->get_statuses();

        return $this->success($data);
    }

    public function update_status(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $status = sanitize_key($request->get_param('status'));
        $meta = (array) $request->get_param('meta');
        $card_id = $request->get_param('card_id');
        $card_id = $card_id !== null ? (int) $card_id : null;
        $submission_id = $request->get_param('submission_id');
        $submission_id = $submission_id !== null ? (int) $submission_id : null;

        if (!Security::can_manage_customer($customer_id, $card_id, $submission_id)) {
            return $this->error('ucb_forbidden', __('Insufficient permissions.', 'user-cards-bridge'), 403);
        }

        $change_result = $this->statuses->change_status(
            $customer_id,
            $status,
            get_current_user_id(),
            $meta,
            $card_id,
            $submission_id
        );

        if (is_wp_error($change_result)) {
            return $this->from_wp_error($change_result);
        }

        $response = [
            'changed' => (bool) $change_result,
            'status'  => $status,
        ];

        if ('normal' === $status && $response['changed']) {
            $send_result = $this->send_normal_code_internal($customer_id, $card_id, $submission_id);

            if (is_wp_error($send_result)) {
                return $this->from_wp_error($send_result);
            }

            $response['normal_sms'] = $send_result;
        }

        return $this->success($response);
    }

    public function add_note(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $note = $request->get_param('note');

        if (!$note) {
            return $this->error('ucb_empty_note', __('Note cannot be empty.', 'user-cards-bridge'), 400);
        }

        $this->customers->add_note($customer_id, get_current_user_id(), $note);

        return $this->success([
            'customer_id' => $customer_id,
            'notes'       => $this->customers->get_notes($customer_id),
        ], 201);
    }

    public function assign_supervisor(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $card_id = $request->get_param('card_id');
        $card_id = $card_id !== null ? (int) $card_id : null;
        $supervisor_id = (int) $request->get_param('supervisor_id');
        $submission_id = $request->get_param('submission_id');
        $submission_id = $submission_id !== null ? (int) $submission_id : null;

        $supervisor = $this->users->ensure_role($supervisor_id, ['supervisor']);
        if (is_wp_error($supervisor)) {
            return $this->from_wp_error($supervisor);
        }

        $this->customers->assign_supervisor($customer_id, $supervisor_id, $card_id, $submission_id);

        return $this->success([
            'customer_id' => $customer_id,
            'supervisor_id' => $supervisor_id,
        ]);
    }

    public function assign_agent(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $card_id = $request->get_param('card_id');
        $card_id = $card_id !== null ? (int) $card_id : null;
        $agent_id = (int) $request->get_param('agent_id');
        $submission_id = $request->get_param('submission_id');
        $submission_id = $submission_id !== null ? (int) $submission_id : null;

        $role = Roles::get_user_role(get_current_user_id());

        if ('supervisor' === $role && (int) get_user_meta($agent_id, 'ucb_agent_supervisor_id', true) !== get_current_user_id()) {
            return $this->error('ucb_forbidden', __('You can assign only your agents.', 'user-cards-bridge'), 403);
        }

        $agent = $this->users->ensure_role($agent_id, ['agent']);
        if (is_wp_error($agent)) {
            return $this->from_wp_error($agent);
        }

        $this->customers->assign_agent($customer_id, $agent_id, $card_id, $submission_id);

        return $this->success([
            'customer_id' => $customer_id,
            'agent_id' => $agent_id,
        ]);
    }

    public function send_normal_code(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('id');
        $card_id = $request->get_param('card_id');
        $card_id = $card_id !== null ? (int) $card_id : null;
        $submission_id = $request->get_param('submission_id');
        $submission_id = $submission_id !== null ? (int) $submission_id : null;

        if (!Security::can_manage_customer($customer_id, $card_id, $submission_id)) {
            return $this->error('ucb_forbidden', __('Insufficient permissions.', 'user-cards-bridge'), 403);
        }

        $result = $this->notifications->send_normal_code($customer_id, $card_id, $submission_id);

        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }

        return $this->success($result);
    }

    protected function send_normal_code_internal(int $customer_id, ?int $card_id = null, ?int $submission_id = null) {
        return $this->notifications->send_normal_code($customer_id, $card_id, $submission_id);
    }

    public function require_access(WP_REST_Request $request): bool {
        if (!$this->is_authenticated()) {
            return false;
        }
        
        return Security::current_user_has_role(['company_manager', 'supervisor', 'agent']) || current_user_can('ucb_manage_all');
    }

    public function require_supervisor(WP_REST_Request $request): bool {
        return $this->is_authenticated() && Security::current_user_has_role(['supervisor']);
    }

    public function require_customer_access(WP_REST_Request $request): bool {
        $customer_id = (int) $request->get_param('id');
        $card_id = $request->get_param('card_id');
        $card_id = $card_id !== null ? (int) $card_id : null;
        $submission_id = $request->get_param('submission_id');
        $submission_id = $submission_id !== null ? (int) $submission_id : null;
        return $this->require_access($request) && Security::can_manage_customer($customer_id, $card_id, $submission_id);
    }

    public function require_manager(WP_REST_Request $request): bool {
        return current_user_can('ucb_manage_all') || current_user_can('manage_options');
    }

    public function require_manager_or_supervisor(WP_REST_Request $request): bool {
        return $this->require_manager($request) || Security::current_user_has_role(['supervisor']);
    }
}
