<?php

namespace UCB\API;

use UCB\Roles;
use UCB\Security;
use UCB\Services\CardService;
use UCB\Services\UserService;
use WP_Error;
use WP_REST_Request;

class Users extends BaseController {
    /**
     * @var UserService
     */
    protected $users;

    /**
     * @var CardService
     */
    protected $cards;

    public function __construct() {
        $this->users = new UserService();
        $this->cards = new CardService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/managers', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_managers'],
            'permission_callback' => [$this, 'require_manager'],
        ]);

        register_rest_route($this->namespace, '/supervisors', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_supervisors'],
            'permission_callback' => [$this, 'require_manager_or_supervisor'],
        ]);

        register_rest_route($this->namespace, '/supervisors/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_supervisor'],
            'permission_callback' => [$this, 'require_supervisor_access'],
        ]);

        register_rest_route($this->namespace, '/supervisors/(?P<id>\d+)/cards', [
            'methods'  => ['POST'],
            'callback' => [$this, 'assign_cards'],
            'permission_callback' => [$this, 'require_manager'],
        ]);

        register_rest_route($this->namespace, '/agents', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_agents'],
            'permission_callback' => [$this, 'require_manager_or_supervisor'],
        ]);

        register_rest_route($this->namespace, '/agents/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_agent'],
            'permission_callback' => [$this, 'require_agent_access'],
        ]);

        register_rest_route($this->namespace, '/agents', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_agent'],
            'permission_callback' => [$this, 'require_manager_or_supervisor'],
        ]);

        register_rest_route($this->namespace, '/agents/(?P<id>\d+)/supervisor', [
            'methods'  => 'PATCH',
            'callback' => [$this, 'change_agent_supervisor'],
            'permission_callback' => [$this, 'require_manager_or_supervisor'],
        ]);
    }

    public function list_managers(WP_REST_Request $request) {
        $query = new \WP_User_Query([
            'role' => 'company_manager',
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $items = array_map([$this->users, 'format_user'], $query->get_results());

        return $this->success([
            'items' => $items,
            'total' => $query->get_total(),
        ]);
    }

    public function list_supervisors(WP_REST_Request $request) {
        $current_role = Roles::get_user_role(get_current_user_id());
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $result = $this->users->list_supervisors(['search' => $request->get_param('search')], $page, $per_page);

        if ('supervisor' === $current_role) {
            $result['items'] = array_values(array_filter($result['items'], function ($item) {
                return (int) $item['id'] === get_current_user_id();
            }));
            $result['total'] = count($result['items']);
        }

        return $this->success([
            'items' => $result['items'],
            'pagination' => $this->paginate($page, $per_page, $result['total']),
        ]);
    }

    public function assign_cards(WP_REST_Request $request) {
        $supervisor_id = (int) $request->get_param('id');
        $cards = (array) $request->get_param('cards');

        $user = get_user_by('id', $supervisor_id);
        if (!$user || !in_array('supervisor', $user->roles, true)) {
            return $this->error('ucb_supervisor_not_found', __('Supervisor not found.', 'user-cards-bridge'), 404);
        }

        $this->cards->set_supervisor_cards($supervisor_id, $cards);

        foreach ($cards as $card_id) {
            if ((int) $request->get_param('set_default')) {
                $this->cards->set_default_supervisor((int) $card_id, $supervisor_id);
            }
        }

        return $this->success(['cards' => $this->cards->get_supervisor_card_ids($supervisor_id)]);
    }

    public function list_agents(WP_REST_Request $request) {
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));
        $filters = [
            'search' => $request->get_param('search'),
        ];

        $role = Roles::get_user_role(get_current_user_id());

        if ('supervisor' === $role) {
            $filters['supervisor_id'] = get_current_user_id();
        } elseif ($request->get_param('supervisor_id')) {
            $filters['supervisor_id'] = (int) $request->get_param('supervisor_id');
        }

        $result = $this->users->list_agents($filters, $page, $per_page);

        return $this->success([
            'items' => $result['items'],
            'pagination' => $this->paginate($page, $per_page, $result['total']),
        ]);
    }

    public function create_agent(WP_REST_Request $request) {
        $role = Roles::get_user_role(get_current_user_id());
        $supervisor_id = (int) $request->get_param('supervisor_id');

        if ('supervisor' === $role) {
            $supervisor_id = get_current_user_id();
        }

        if (!$supervisor_id) {
            return $this->error('ucb_supervisor_required', __('Supervisor is required.', 'user-cards-bridge'), 400);
        }

        $user_id = $this->users->register([
            'username'      => $request->get_param('username'),
            'email'         => $request->get_param('email'),
            'password'      => $request->get_param('password'),
            'display_name'  => $request->get_param('display_name'),
            'supervisor_id' => $supervisor_id,
        ], 'agent');

        if (is_wp_error($user_id)) {
            return $this->from_wp_error($user_id);
        }

        $user = get_user_by('id', $user_id);

        return $this->success($this->users->format_user($user), 201);
    }

    public function change_agent_supervisor(WP_REST_Request $request) {
        $agent_id = (int) $request->get_param('id');
        $new_supervisor = (int) $request->get_param('supervisor_id');

        $agent = get_user_by('id', $agent_id);
        if (!$agent || !in_array('agent', $agent->roles, true)) {
            return $this->error('ucb_agent_not_found', __('Agent not found.', 'user-cards-bridge'), 404);
        }

        $current_role = Roles::get_user_role(get_current_user_id());

        if ('supervisor' === $current_role && (int) get_user_meta($agent_id, 'ucb_agent_supervisor_id', true) !== get_current_user_id()) {
            return $this->error('ucb_forbidden', __('You cannot reassign agents that are not yours.', 'user-cards-bridge'), 403);
        }

        $this->users->set_agent_supervisor($agent_id, $new_supervisor);

        return $this->success([
            'agent_id' => $agent_id,
            'supervisor_id' => $new_supervisor,
        ]);
    }

    public function get_supervisor(WP_REST_Request $request) {
        $supervisor_id = (int) $request->get_param('id');
        
        $user = get_user_by('id', $supervisor_id);
        if (!$user || !in_array('supervisor', $user->roles, true)) {
            return $this->error('ucb_supervisor_not_found', __('Supervisor not found.', 'user-cards-bridge'), 404);
        }

        $formatted_user = $this->users->format_user($user);
        
        // Add supervisor-specific data
        $formatted_user['assigned_cards'] = $this->cards->get_supervisor_card_ids($supervisor_id);
        $formatted_user['assigned_card_titles'] = array_map(function($card_id) {
            $card = $this->cards->get_card($card_id);
            return $card ? $card->post_title : '';
        }, $formatted_user['assigned_cards']);
        
        // Get agents count
        $agents_query = new \WP_User_Query([
            'meta_key' => 'ucb_agent_supervisor_id',
            'meta_value' => $supervisor_id,
            'role' => 'agent',
        ]);
        $formatted_user['agents_count'] = $agents_query->get_total();
        
        // Get customers count
        $customers_query = new \WP_User_Query([
            'role' => 'customer',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'ucb_customer_assigned_supervisor',
                    'value' => $supervisor_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'ucb_customer_supervisor_id',
                    'value' => $supervisor_id,
                    'compare' => '=',
                ],
            ],
        ]);
        $formatted_user['customers_count'] = $customers_query->get_total();

        return $this->success($formatted_user);
    }

    public function get_agent(WP_REST_Request $request) {
        $agent_id = (int) $request->get_param('id');
        
        $user = get_user_by('id', $agent_id);
        if (!$user || !in_array('agent', $user->roles, true)) {
            return $this->error('ucb_agent_not_found', __('Agent not found.', 'user-cards-bridge'), 404);
        }

        $formatted_user = $this->users->format_user($user);
        
        // Add agent-specific data
        $supervisor_id = get_user_meta($agent_id, 'ucb_agent_supervisor_id', true);
        if ($supervisor_id) {
            $supervisor = get_user_by('id', $supervisor_id);
            $formatted_user['supervisor_id'] = (int) $supervisor_id;
            $formatted_user['supervisor_name'] = $supervisor ? $supervisor->display_name : null;
        }
        
        // Get customers count
        $customers_query = new \WP_User_Query([
            'role' => 'customer',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'ucb_customer_assigned_agent',
                    'value' => $agent_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'ucb_customer_agent_id',
                    'value' => $agent_id,
                    'compare' => '=',
                ],
            ],
        ]);
        $formatted_user['customers_count'] = $customers_query->get_total();

        return $this->success($formatted_user);
    }

    public function require_manager(WP_REST_Request $request = null): bool {
        return current_user_can('ucb_manage_all') || current_user_can('manage_options');
    }

    public function require_manager_or_supervisor(WP_REST_Request $request = null): bool {
        return $this->require_manager($request) || Security::current_user_has_role(['supervisor']);
    }

    public function require_supervisor_access(WP_REST_Request $request): bool {
        $supervisor_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();
        
        // Manager can access all supervisors
        if ($this->require_manager($request)) {
            return true;
        }
        
        // Supervisor can only access their own data
        return $supervisor_id === $current_user_id;
    }

    public function require_agent_access(WP_REST_Request $request): bool {
        $agent_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();
        $current_role = Roles::get_user_role($current_user_id);
        
        // Manager can access all agents
        if ($this->require_manager($request)) {
            return true;
        }
        
        // Supervisor can access their own agents
        if ('supervisor' === $current_role) {
            $agent_supervisor_id = get_user_meta($agent_id, 'ucb_agent_supervisor_id', true);
            return (int) $agent_supervisor_id === $current_user_id;
        }
        
        // Agent can access their own data
        return $agent_id === $current_user_id;
    }
}
