<?php

namespace UCB\Services;

use UCB\Database;
use UCB\Logger;
use UCB\Services\StatusManager;
use WP_Error;

/**
 * Handles form submissions from user-cards plugin
 */
class FormSubmissionService {
    
    /**
     * @var Database
     */
    protected $db;
    
    /**
     * @var Logger
     */
    protected $logger;
    
    /**
     * @var StatusManager
     */
    protected $status_manager;
    
    public function __construct() {
        $this->db = new Database();
        $this->logger = Logger::get_instance();
        $this->status_manager = new StatusManager();
    }
    
    /**
     * Process form submission from user-cards
     * 
     * @param array $form_data
     * @param int $card_id
     * @return array|WP_Error
     */
    public function process_form_submission($form_data, $card_id) {
        // Get supervisor responsible for this card
        $supervisor_id = $this->get_card_supervisor($card_id);
        
        if (!$supervisor_id) {
            return new WP_Error('ucb_no_supervisor', __('No supervisor assigned to this card.', 'user-cards-bridge'));
        }
        
        // Create customer record
        $customer_data = $this->prepare_customer_data($form_data, $card_id, $supervisor_id);
        $customer_id = $this->create_customer($customer_data);
        
        if (is_wp_error($customer_id)) {
            return $customer_id;
        }
        
        // Log the form submission
        $this->logger->info('Form submitted', [
            'customer_id' => $customer_id,
            'card_id' => $card_id,
            'supervisor_id' => $supervisor_id,
            'form_data' => $form_data
        ]);
        
        // Set initial status
        $this->set_customer_status($customer_id, 'unassigned', 'Form submitted');
        
        // Notify supervisor (if notification system is available)
        $this->notify_supervisor($supervisor_id, $customer_id, $card_id);
        
        return [
            'customer_id' => $customer_id,
            'supervisor_id' => $supervisor_id,
            'status' => 'unassigned'
        ];
    }
    
    /**
     * Get supervisor responsible for a card
     */
    protected function get_card_supervisor($card_id) {
        // First check if there's a specific supervisor assigned to this card
        $supervisor_id = get_post_meta($card_id, '_ucb_supervisor_id', true);
        
        if ($supervisor_id) {
            return (int) $supervisor_id;
        }
        
        // If no specific supervisor, get the first supervisor who has this card
        $supervisors = get_users([
            'role' => 'supervisor',
            'meta_query' => [
                [
                    'key' => 'ucb_supervisor_cards',
                    'value' => $card_id,
                    'compare' => 'LIKE'
                ]
            ]
        ]);
        
        if (!empty($supervisors)) {
            return $supervisors[0]->ID;
        }
        
        return false;
    }
    
    /**
     * Prepare customer data from form submission
     */
    protected function prepare_customer_data($form_data, $card_id, $supervisor_id) {
        return [
            'first_name' => sanitize_text_field($form_data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($form_data['last_name'] ?? ''),
            'email' => sanitize_email($form_data['email'] ?? ''),
            'phone' => sanitize_text_field($form_data['phone'] ?? ''),
            'card_id' => $card_id,
            'supervisor_id' => $supervisor_id,
            'form_data' => $form_data,
            'status' => 'unassigned',
            'created_at' => current_time('mysql')
        ];
    }
    
    /**
     * Create customer record
     */
    protected function create_customer($customer_data) {
        // Check if customer already exists by email
        $existing_customer = get_user_by('email', $customer_data['email']);
        
        if ($existing_customer) {
            $customer_id = $existing_customer->ID;
        } else {
            // Create new WordPress user
            $user_id = wp_create_user(
                $customer_data['email'],
                wp_generate_password(),
                $customer_data['email']
            );
            
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            
            $customer_id = $user_id;
            
            // Update user data
            wp_update_user([
                'ID' => $customer_id,
                'first_name' => $customer_data['first_name'],
                'last_name' => $customer_data['last_name'],
                'display_name' => $customer_data['first_name'] . ' ' . $customer_data['last_name']
            ]);
        }
        
        // Store customer-specific data
        update_user_meta($customer_id, 'ucb_customer_status', $customer_data['status']);
        update_user_meta($customer_id, 'ucb_customer_card_id', $customer_data['card_id']);
        update_user_meta($customer_id, 'ucb_customer_supervisor_id', $customer_data['supervisor_id']);
        update_user_meta($customer_id, 'ucb_customer_phone', $customer_data['phone']);
        update_user_meta($customer_id, 'ucb_customer_form_data', $customer_data['form_data']);
        
        return $customer_id;
    }
    
    /**
     * Set customer status
     */
    protected function set_customer_status($customer_id, $status, $reason = '') {
        $old_status = get_user_meta($customer_id, 'ucb_customer_status', true);
        
        update_user_meta($customer_id, 'ucb_customer_status', $status);
        
        // Log status change
        $this->db->log_status_change([
            'customer_id' => $customer_id,
            'old_status' => $old_status,
            'new_status' => $status,
            'changed_by' => get_current_user_id(),
            'reason' => $reason
        ]);
    }
    
    /**
     * Notify supervisor about new form submission
     */
    protected function notify_supervisor($supervisor_id, $customer_id, $card_id) {
        // This could be expanded to send email notifications
        // For now, we'll just log it
        $this->logger->info('Supervisor notified', [
            'supervisor_id' => $supervisor_id,
            'customer_id' => $customer_id,
            'card_id' => $card_id
        ]);
    }
    
    /**
     * Get form submissions for a supervisor
     */
    public function get_supervisor_forms($supervisor_id, $filters = []) {
        $args = [
            'role' => 'customer',
            'meta_query' => [
                [
                    'key' => 'ucb_customer_supervisor_id',
                    'value' => $supervisor_id,
                    'compare' => '='
                ]
            ]
        ];
        
        if (!empty($filters['status'])) {
            $args['meta_query'][] = [
                'key' => 'ucb_customer_status',
                'value' => $filters['status'],
                'compare' => '='
            ];
        }
        
        if (!empty($filters['card_id'])) {
            $args['meta_query'][] = [
                'key' => 'ucb_customer_card_id',
                'value' => $filters['card_id'],
                'compare' => '='
            ];
        }
        
        $users = get_users($args);
        $forms = [];
        
        foreach ($users as $user) {
            $forms[] = [
                'id' => $user->ID,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'phone' => get_user_meta($user->ID, 'ucb_customer_phone', true),
                'status' => get_user_meta($user->ID, 'ucb_customer_status', true),
                'card_id' => get_user_meta($user->ID, 'ucb_customer_card_id', true),
                'form_data' => get_user_meta($user->ID, 'ucb_customer_form_data', true),
                'created_at' => $user->user_registered
            ];
        }
        
        return $forms;
    }
}
