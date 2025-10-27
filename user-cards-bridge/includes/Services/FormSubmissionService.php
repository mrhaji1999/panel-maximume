<?php

namespace UCB\Services;

use UCB\Database;
use UCB\Logger;
use UCB\Services\StatusManager;
use WP_Error;
use WP_User;

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
        $username = $this->generate_unique_username($customer_data);
        $user_email = $this->generate_unique_email($customer_data['email'] ?? '');

        $user_id = wp_create_user(
            $username,
            wp_generate_password(),
            $user_email
        );

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $this->update_customer_profile($user_id, $customer_data);

        return $user_id;
    }

    /**
     * Ensure the WordPress user reflects the latest submission data.
     */
    protected function update_customer_profile(int $customer_id, array $customer_data): void {
        $user_update = ['ID' => $customer_id];

        if ('' !== $customer_data['first_name']) {
            $user_update['first_name'] = $customer_data['first_name'];
        }

        if ('' !== $customer_data['last_name']) {
            $user_update['last_name'] = $customer_data['last_name'];
        }

        $display_name = trim($customer_data['first_name'] . ' ' . $customer_data['last_name']);
        if ('' !== $display_name) {
            $user_update['display_name'] = $display_name;
        }

        if (count($user_update) > 1) {
            wp_update_user($user_update);
        }

        $this->ensure_customer_role($customer_id);

        // Store customer-specific data used across the panel
        update_user_meta($customer_id, 'ucb_customer_status', $customer_data['status']);
        update_user_meta($customer_id, 'ucb_customer_card_id', (int) $customer_data['card_id']);

        $supervisor_id = (int) $customer_data['supervisor_id'];
        update_user_meta($customer_id, 'ucb_customer_assigned_supervisor', $supervisor_id);
        // Maintain legacy meta key for backwards compatibility
        update_user_meta($customer_id, 'ucb_customer_supervisor_id', $supervisor_id);

        update_user_meta($customer_id, 'ucb_customer_phone', $customer_data['phone']);
        update_user_meta($customer_id, 'ucb_customer_form_data', $customer_data['form_data']);
        update_user_meta($customer_id, 'ucb_customer_email', $customer_data['email']);
        if (!empty($customer_data['email'])) {
            update_user_meta(
                $customer_id,
                'ucb_customer_email_normalized',
                $this->normalize_email($customer_data['email'])
            );
        }
    }

    /**
     * Generate a unique username for the customer record.
     */
    protected function generate_unique_username(array $customer_data): string {
        $base = sanitize_user($customer_data['first_name'] . '.' . $customer_data['last_name'], true);

        if ($base === '') {
            $base = sanitize_user($customer_data['email'] ?? '', true);
        }

        if ($base === '') {
            $base = 'uc_customer';
        }

        $username = $base;
        $suffix = 1;

        while (username_exists($username)) {
            $username = $base . '_' . $suffix;
            $suffix++;
        }

        return $username;
    }

    /**
     * Generate a unique email address for storing inside WordPress.
     */
    protected function generate_unique_email(string $raw_email): string {
        $email = is_email($raw_email) ? strtolower($raw_email) : '';

        if ($email !== '' && !email_exists($email)) {
            return $email;
        }

        $local = 'customer';
        $domain = 'example.com';

        if ($email !== '') {
            [$local_part, $domain_part] = array_pad(explode('@', $email, 2), 2, '');
            if ($local_part !== '') {
                $candidate = sanitize_title(str_replace(['+', '.'], '_', $local_part));
                if ($candidate !== '') {
                    $local = $candidate;
                }
            }
            if ($domain_part !== '') {
                $domain = $domain_part;
            }
        }

        do {
            $generated = sprintf('%s+%s@%s', $local, wp_generate_password(8, false), $domain);
        } while (email_exists($generated));

        return $generated;
    }

    /**
     * Normalize email for consistent searching.
     */
    protected function normalize_email(string $email): string {
        return strtolower(trim($email));
    }

    /**
     * Guarantee that the created user is treated as a customer inside WordPress.
     */
    protected function ensure_customer_role(int $customer_id): void {
        $user = get_user_by('id', $customer_id);
        if (!$user instanceof WP_User) {
            return;
        }

        $roles = (array) $user->roles;

        if (empty($roles)) {
            $user->set_role('customer');
            return;
        }

        if (1 === count($roles) && in_array('subscriber', $roles, true)) {
            $user->set_role('customer');
            return;
        }

        if (!in_array('customer', $roles, true)) {
            $user->add_role('customer');
        }
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
        $meta_query = [
            'relation' => 'AND',
            [
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
        ];

        $args = [
            'role' => 'customer',
            'meta_query' => $meta_query,
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
            $email_meta = get_user_meta($user->ID, 'ucb_customer_email', true);
            $email_value = null;
            if (is_string($email_meta) && $email_meta !== '') {
                $email_value = sanitize_email($email_meta) ?: $email_meta;
            }
            $forms[] = [
                'id' => $user->ID,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $email_value ?: $user->user_email,
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
