<?php

namespace UCB\Services;

use WP_Error;
use WP_User;

class UserService {
    
    /**
     * Register a new user
     */
    public function register($user_data, $role) {
        // Validate required fields
        $required_fields = ['username', 'email', 'password', 'display_name'];
        foreach ($required_fields as $field) {
            if (empty($user_data[$field])) {
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Field %s is required.', UCB_TEXT_DOMAIN), $field),
                    ['status' => 400]
                );
            }
        }
        
        // Check if username already exists
        if (username_exists($user_data['username'])) {
            return new WP_Error(
                'username_exists',
                __('Username already exists.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }
        
        // Check if email already exists
        if (email_exists($user_data['email'])) {
            return new WP_Error(
                'email_exists',
                __('Email already exists.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }
        
        // Create user
        $user_id = wp_create_user(
            $user_data['username'],
            $user_data['password'],
            $user_data['email']
        );
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Update user display name
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $user_data['display_name'],
        ]);
        
        // Assign role
        $user = new WP_User($user_id);
        $user->set_role($role);
        
        // Handle role-specific assignments
        switch ($role) {
            case 'agent':
                if (!empty($user_data['supervisor_id'])) {
                    $this->assign_agent_supervisor($user_id, $user_data['supervisor_id']);
                }
                break;
                
            case 'supervisor':
                if (!empty($user_data['cards'])) {
                    $this->assign_supervisor_cards($user_id, $user_data['cards']);
                }
                break;
        }
        
        \UCB\Logger::log('info', 'User registered', [
            'user_id' => $user_id,
            'username' => $user_data['username'],
            'email' => $user_data['email'],
            'role' => $role,
        ]);
        
        return $user_id;
    }
    
    /**
     * Format user data for API response
     */
    public function format_user(WP_User $user) {
        $role = \UCB\Roles::get_user_role($user->ID);
        
        $formatted = [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,
            'role' => $role,
        ];
        
        // Add role-specific data
        switch ($role) {
            case 'supervisor':
                $assigned_cards = get_user_meta($user->ID, 'ucb_supervisor_cards', true);
                $formatted['assigned_cards'] = is_array($assigned_cards) ? $assigned_cards : [];
                break;
                
            case 'agent':
                $supervisor_id = get_user_meta($user->ID, 'ucb_agent_supervisor_id', true);
                $formatted['supervisor_id'] = $supervisor_id;
                if ($supervisor_id) {
                    $supervisor = get_user_by('id', $supervisor_id);
                    $formatted['supervisor_name'] = $supervisor ? $supervisor->display_name : null;
                }
                break;
        }
        
        return $formatted;
    }

    /**
     * Retrieve paginated supervisors with aggregates.
     *
     * @param array<string,mixed> $filters
     * @return array{items: array<int, array<string,mixed>>, total: int}
     */
    public function list_supervisors(array $filters = [], int $page = 1, int $per_page = 20): array {
        $args = [
            'role'         => 'supervisor',
            'number'       => $per_page,
            'paged'        => $page,
            'orderby'      => 'display_name',
            'order'        => 'ASC',
            'count_total'  => true,
        ];

        if (!empty($filters['search'])) {
            $search = sanitize_text_field($filters['search']);
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query = new \WP_User_Query($args);

        $items = array_map(function (WP_User $user) {
            return $this->format_supervisor($user);
        }, $query->get_results());

        return [
            'items' => $items,
            'total' => (int) $query->get_total(),
        ];
    }

    /**
     * Retrieve paginated agents with aggregates.
     *
     * @param array<string,mixed> $filters
     * @return array{items: array<int, array<string,mixed>>, total: int}
     */
    public function list_agents(array $filters = [], int $page = 1, int $per_page = 20): array {
        $args = [
            'role'         => 'agent',
            'number'       => $per_page,
            'paged'        => $page,
            'orderby'      => 'display_name',
            'order'        => 'ASC',
            'count_total'  => true,
        ];

        $meta_query = [];

        if (!empty($filters['supervisor_id'])) {
            $meta_query[] = [
                'key'   => 'ucb_agent_supervisor_id',
                'value' => (int) $filters['supervisor_id'],
            ];
        }

        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        if (!empty($filters['search'])) {
            $search = sanitize_text_field($filters['search']);
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query = new \WP_User_Query($args);

        $items = array_map(function (WP_User $user) {
            return $this->format_agent($user);
        }, $query->get_results());

        return [
            'items' => $items,
            'total' => (int) $query->get_total(),
        ];
    }

    /**
     * Format supervisor specific payload.
     *
     * @return array<string,mixed>
     */
    public function format_supervisor(WP_User $user): array {
        $base = $this->format_user($user);
        $supervisor_id = (int) $user->ID;

        $card_ids = $this->get_supervisor_card_ids($supervisor_id);
        $card_titles = array_values(array_filter(array_map(function (int $card_id) {
            $title = get_the_title($card_id);
            return $title ?: null;
        }, $card_ids)));

        $base['assigned_cards'] = $card_ids;
        $base['assigned_card_titles'] = $card_titles;
        $base['agents_count'] = $this->count_supervisor_agents($supervisor_id);
        $base['customers_count'] = $this->count_supervisor_customers($supervisor_id);

        return $base;
    }

    /**
     * Format agent payload with aggregates.
     *
     * @return array<string,mixed>
     */
    public function format_agent(WP_User $user): array {
        $base = $this->format_user($user);
        $agent_id = (int) $user->ID;

        $supervisor_id = (int) get_user_meta($agent_id, 'ucb_agent_supervisor_id', true);
        $base['supervisor_id'] = $supervisor_id ?: null;
        $base['supervisor_name'] = $supervisor_id ? $this->get_user_display_name($supervisor_id) : null;
        $base['customers_count'] = $this->count_agent_customers($agent_id);
        $base['status'] = get_user_meta($agent_id, 'ucb_agent_status', true) ?: 'active';

        return $base;
    }

    /**
     * Return supervisor card ids.
     *
     * @return array<int,int>
     */
    public function get_supervisor_card_ids(int $supervisor_id): array {
        $cards = get_user_meta($supervisor_id, 'ucb_supervisor_cards', true);

        if (!is_array($cards)) {
            return [];
        }

        return array_values(array_map('intval', $cards));
    }
    
    /**
     * Assign supervisor to agent
     */
    public function assign_agent_supervisor($agent_id, $supervisor_id) {
        // Validate supervisor
        $supervisor = get_user_by('id', $supervisor_id);
        if (!$supervisor || \UCB\Roles::get_user_role($supervisor_id) !== 'supervisor') {
            return new WP_Error(
                'invalid_supervisor',
                __('Invalid supervisor ID.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }
        
        update_user_meta($agent_id, 'ucb_agent_supervisor_id', $supervisor_id);
        
        \UCB\Logger::log('info', 'Agent supervisor assigned', [
            'agent_id' => $agent_id,
            'supervisor_id' => $supervisor_id,
            'assigned_by' => get_current_user_id(),
        ]);

        return true;
    }

    /**
     * Update agent supervisor assignment.
     */
    public function set_agent_supervisor(int $agent_id, int $supervisor_id) {
        if ($supervisor_id <= 0) {
            delete_user_meta($agent_id, 'ucb_agent_supervisor_id');
            \UCB\Logger::log('info', 'Agent supervisor cleared', [
                'agent_id' => $agent_id,
                'updated_by' => get_current_user_id(),
            ]);
            return true;
        }

        return $this->assign_agent_supervisor($agent_id, $supervisor_id);
    }
    
    /**
     * Assign cards to supervisor
     */
    public function assign_supervisor_cards($supervisor_id, $card_ids) {
        // Validate cards
        $valid_cards = [];
        foreach ($card_ids as $card_id) {
            $card_id = (int) $card_id;
            $card = get_post($card_id);
            if ($card && $card->post_type === 'uc_card' && $card->post_status === 'publish') {
                $valid_cards[] = $card_id;
            }
        }
        
        update_user_meta($supervisor_id, 'ucb_supervisor_cards', $valid_cards);
        
        \UCB\Logger::log('info', 'Cards assigned to supervisor', [
            'supervisor_id' => $supervisor_id,
            'card_ids' => $valid_cards,
            'assigned_by' => get_current_user_id(),
        ]);
        
        return $valid_cards;
    }
    
    /**
     * Get users by role with pagination
     */
    public function get_users_by_role($role, $args = []) {
        $default_args = [
            'role' => $role,
            'number' => 20,
            'offset' => 0,
            'fields' => ['ID', 'user_login', 'user_email', 'first_name', 'last_name', 'display_name'],
        ];
        
        $args = wp_parse_args($args, $default_args);
        
        $users = get_users($args);
        $total = count_users()['avail_roles'][$role] ?? 0;

        return [
            'users' => $users,
            'total' => $total,
        ];
    }

    /**
     * Count agents assigned to supervisor.
     */
    protected function count_supervisor_agents(int $supervisor_id): int {
        $query = new \WP_User_Query([
            'role'         => 'agent',
            'meta_key'     => 'ucb_agent_supervisor_id',
            'meta_value'   => $supervisor_id,
            'fields'       => 'ID',
            'number'       => 1,
            'count_total'  => true,
        ]);

        return (int) $query->get_total();
    }

    /**
     * Count customers assigned to supervisor.
     */
    protected function count_supervisor_customers(int $supervisor_id): int {
        $query = new \WP_User_Query([
            'meta_key'     => 'ucb_customer_assigned_supervisor',
            'meta_value'   => $supervisor_id,
            'fields'       => 'ID',
            'number'       => 1,
            'count_total'  => true,
        ]);

        return (int) $query->get_total();
    }

    /**
     * Count customers assigned to agent.
     */
    protected function count_agent_customers(int $agent_id): int {
        $query = new \WP_User_Query([
            'meta_key'     => 'ucb_customer_assigned_agent',
            'meta_value'   => $agent_id,
            'fields'       => 'ID',
            'number'       => 1,
            'count_total'  => true,
        ]);

        return (int) $query->get_total();
    }

    /**
     * Get agents for a supervisor
     */
    public function get_supervisor_agents($supervisor_id) {
        $agents = get_users([
            'role' => 'agent',
            'meta_key' => 'ucb_agent_supervisor_id',
            'meta_value' => $supervisor_id,
            'fields' => ['ID', 'user_login', 'user_email', 'first_name', 'last_name', 'display_name'],
        ]);
        
        return $agents;
    }
    
    /**
     * Update user profile
     */
    public function update_profile($user_id, $data) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error(
                'user_not_found',
                __('User not found.', UCB_TEXT_DOMAIN),
                ['status' => 404]
            );
        }
        
        $update_data = [];
        
        if (isset($data['display_name'])) {
            $update_data['display_name'] = sanitize_text_field($data['display_name']);
        }
        
        if (isset($data['first_name'])) {
            $update_data['first_name'] = sanitize_text_field($data['first_name']);
        }
        
        if (isset($data['last_name'])) {
            $update_data['last_name'] = sanitize_text_field($data['last_name']);
        }
        
        if (isset($data['email'])) {
            $email = sanitize_email($data['email']);
            if (!is_email($email)) {
                return new WP_Error(
                    'invalid_email',
                    __('Invalid email address.', UCB_TEXT_DOMAIN),
                    ['status' => 400]
                );
            }
            
            // Check if email is already in use by another user
            $existing_user = get_user_by('email', $email);
            if ($existing_user && $existing_user->ID != $user_id) {
                return new WP_Error(
                    'email_exists',
                    __('Email address is already in use.', UCB_TEXT_DOMAIN),
                    ['status' => 400]
                );
            }
            
            $update_data['user_email'] = $email;
        }
        
        if (!empty($update_data)) {
            $update_data['ID'] = $user_id;
            $result = wp_update_user($update_data);
            
            if (is_wp_error($result)) {
                return $result;
            }
        }
        
        \UCB\Logger::log('info', 'User profile updated', [
            'user_id' => $user_id,
            'updated_by' => get_current_user_id(),
        ]);
        
        return true;
    }
    
    /**
     * Delete user
     */
    public function delete_user($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error(
                'user_not_found',
                __('User not found.', UCB_TEXT_DOMAIN),
                ['status' => 404]
            );
        }
        
        // Check if user can be deleted
        $role = \UCB\Roles::get_user_role($user_id);
        if (!$role) {
            return new WP_Error(
                'invalid_user',
                __('User does not have a valid role for this system.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }
        
        // Clean up user meta
        $this->cleanup_user_meta($user_id);
        
        // Delete user
        $result = wp_delete_user($user_id);
        
        if (!$result) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete user.', UCB_TEXT_DOMAIN),
                ['status' => 500]
            );
        }
        
        \UCB\Logger::log('info', 'User deleted', [
            'user_id' => $user_id,
            'deleted_by' => get_current_user_id(),
        ]);
        
        return true;
    }
    
    /**
     * Get display name of a user by ID.
     */
    protected function get_user_display_name(int $user_id): ?string {
        $user = get_user_by('id', $user_id);
        return $user ? $user->display_name : null;
    }

    /**
     * Clean up user meta data
     */
    private function cleanup_user_meta($user_id) {
        $meta_keys = [
            'ucb_supervisor_cards',
            'ucb_agent_supervisor_id',
            'ucb_customer_status',
            'ucb_customer_assigned_supervisor',
            'ucb_customer_assigned_agent',
            'ucb_customer_card_id',
            'ucb_customer_random_code',
        ];
        
        foreach ($meta_keys as $meta_key) {
            delete_user_meta($user_id, $meta_key);
        }
    }
}
