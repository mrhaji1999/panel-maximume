<?php

namespace UCB;

class Roles {
    
    public function init() {
        add_action('init', [$this, 'add_custom_roles']);
        add_action('init', [$this, 'add_custom_capabilities']);
    }
    
    public function add_custom_roles() {
        // Company Manager role
        add_role('company_manager', __('Company Manager', UCB_TEXT_DOMAIN), [
            'read' => true,
            'ucb_manage_all' => true,
            'ucb_manage_supervisors' => true,
            'ucb_manage_agents' => true,
            'ucb_manage_customers' => true,
            'ucb_manage_schedule' => true,
            'ucb_manage_upsell' => true,
            'ucb_send_sms' => true,
            'ucb_view_logs' => true,
        ]);
        
        // Supervisor role
        add_role('supervisor', __('Supervisor', UCB_TEXT_DOMAIN), [
            'read' => true,
            'ucb_manage_own_cards' => true,
            'ucb_manage_own_agents' => true,
            'ucb_manage_own_customers' => true,
            'ucb_manage_own_schedule' => true,
            'ucb_manage_upsell' => true,
            'ucb_send_sms' => true,
            'ucb_view_own_logs' => true,
        ]);
        
        // Agent role
        add_role('agent', __('Agent', UCB_TEXT_DOMAIN), [
            'read' => true,
            'ucb_manage_assigned_customers' => true,
            'ucb_change_customer_status' => true,
            'ucb_add_customer_notes' => true,
            'ucb_send_sms' => true,
            'ucb_view_assigned_customers' => true,
        ]);
    }
    
    public function add_custom_capabilities() {
        $capabilities = [
            'ucb_manage_all',
            'ucb_manage_supervisors',
            'ucb_manage_agents',
            'ucb_manage_customers',
            'ucb_manage_schedule',
            'ucb_manage_upsell',
            'ucb_send_sms',
            'ucb_view_logs',
            'ucb_manage_own_cards',
            'ucb_manage_own_agents',
            'ucb_manage_own_customers',
            'ucb_manage_own_schedule',
            'ucb_view_own_logs',
            'ucb_manage_assigned_customers',
            'ucb_change_customer_status',
            'ucb_add_customer_notes',
            'ucb_view_assigned_customers',
        ];
        
        $admin = get_role('administrator');
        if ($admin) {
            foreach ($capabilities as $cap) {
                $admin->add_cap($cap);
            }
        }
    }
    
    public static function get_user_role($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        
        $roles = $user->roles;
        $custom_roles = ['company_manager', 'supervisor', 'agent'];
        
        foreach ($roles as $role) {
            if (in_array($role, $custom_roles)) {
                return $role;
            }
        }
        
        return false;
    }
    
    public static function can_manage_customers($user_id, $customer_id = null) {
        $role = self::get_user_role($user_id);
        
        if (!$role) {
            return false;
        }
        
        switch ($role) {
            case 'company_manager':
                return true;
                
            case 'supervisor':
                if (!$customer_id) {
                    return true; // Can see all customers assigned to their cards
                }
                return self::is_customer_assigned_to_supervisor($customer_id, $user_id);
                
            case 'agent':
                if (!$customer_id) {
                    return true; // Can see assigned customers
                }
                return self::is_customer_assigned_to_agent($customer_id, $user_id);
                
            default:
                return false;
        }
    }
    
    public static function can_manage_schedule($user_id, $supervisor_id = null) {
        $role = self::get_user_role($user_id);
        
        if (!$role) {
            return false;
        }
        
        switch ($role) {
            case 'company_manager':
                return true;
                
            case 'supervisor':
                return $supervisor_id ? $supervisor_id == $user_id : true;
                
            default:
                return false;
        }
    }
    
    public static function can_manage_agents($user_id, $agent_id = null) {
        $role = self::get_user_role($user_id);
        
        if (!$role) {
            return false;
        }
        
        switch ($role) {
            case 'company_manager':
                return true;
                
            case 'supervisor':
                if (!$agent_id) {
                    return true; // Can see their agents
                }
                return self::is_agent_assigned_to_supervisor($agent_id, $user_id);
                
            default:
                return false;
        }
    }
    
    private static function is_customer_assigned_to_supervisor($customer_id, $supervisor_id) {
        $assigned_supervisor = get_user_meta($customer_id, 'ucb_customer_assigned_supervisor', true);
        return $assigned_supervisor == $supervisor_id;
    }
    
    private static function is_customer_assigned_to_agent($customer_id, $agent_id) {
        $assigned_agent = get_user_meta($customer_id, 'ucb_customer_assigned_agent', true);
        return $assigned_agent == $agent_id;
    }
    
    private static function is_agent_assigned_to_supervisor($agent_id, $supervisor_id) {
        $assigned_supervisor = get_user_meta($agent_id, 'ucb_agent_supervisor_id', true);
        return $assigned_supervisor == $supervisor_id;
    }
}
