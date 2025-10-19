<?php

namespace UCB\Services;

class StatusManager {
    
    private $statuses = [];
    
    public function __construct() {
        $this->load_statuses();
    }
    
    /**
     * Load statuses from options
     */
    private function load_statuses() {
        $this->statuses = get_option('ucb_customer_statuses', $this->get_default_statuses());
    }
    
    /**
     * Get default customer statuses
     */
    public function get_default_statuses() {
        return [
            'no_answer' => [
                'label' => __('No Answer', UCB_TEXT_DOMAIN),
                'description' => __('Customer did not answer the call', UCB_TEXT_DOMAIN),
                'color' => '#ffc107',
                'icon' => 'dashicons-phone',
                'order' => 1,
            ],
            'canceled' => [
                'label' => __('Canceled', UCB_TEXT_DOMAIN),
                'description' => __('Customer canceled the service', UCB_TEXT_DOMAIN),
                'color' => '#dc3545',
                'icon' => 'dashicons-no-alt',
                'order' => 2,
            ],
            'upsell' => [
                'label' => __('Upsell', UCB_TEXT_DOMAIN),
                'description' => __('Customer interested in additional services', UCB_TEXT_DOMAIN),
                'color' => '#17a2b8',
                'icon' => 'dashicons-arrow-up-alt',
                'order' => 3,
            ],
            'normal' => [
                'label' => __('Normal', UCB_TEXT_DOMAIN),
                'description' => __('Regular customer status', UCB_TEXT_DOMAIN),
                'color' => '#28a745',
                'icon' => 'dashicons-yes',
                'order' => 4,
            ],
            'upsell_pending' => [
                'label' => __('Upsell Pending', UCB_TEXT_DOMAIN),
                'description' => __('Waiting for upsell payment', UCB_TEXT_DOMAIN),
                'color' => '#fd7e14',
                'icon' => 'dashicons-clock',
                'order' => 5,
            ],
            'upsell_paid' => [
                'label' => __('Upsell Paid', UCB_TEXT_DOMAIN),
                'description' => __('Upsell payment completed', UCB_TEXT_DOMAIN),
                'color' => '#6f42c1',
                'icon' => 'dashicons-yes-alt',
                'order' => 6,
            ],
        ];
    }
    
    /**
     * Get all statuses
     */
    public function get_statuses() {
        return $this->statuses;
    }
    
    /**
     * Get status by slug
     */
    public function get_status($slug) {
        return $this->statuses[$slug] ?? null;
    }
    
    /**
     * Check if status exists
     */
    public function status_exists($slug) {
        return isset($this->statuses[$slug]);
    }
    
    /**
     * Get statuses for API
     */
    public function get_statuses_for_api() {
        $api_statuses = [];
        
        foreach ($this->statuses as $slug => $status) {
            $api_statuses[$slug] = [
                'slug' => $slug,
                'label' => $status['label'],
                'description' => $status['description'],
                'color' => $status['color'],
                'icon' => $status['icon'],
                'order' => $status['order'],
            ];
        }
        
        // Sort by order
        uasort($api_statuses, function($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        
        return $api_statuses;
    }
    
    /**
     * Add new status
     */
    public function add_status($slug, $status_data) {
        if ($this->status_exists($slug)) {
            return new \WP_Error(
                'status_exists',
                __('Status already exists.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }
        
        $required_fields = ['label', 'description', 'color'];
        foreach ($required_fields as $field) {
            if (empty($status_data[$field])) {
                return new \WP_Error(
                    'missing_field',
                    sprintf(__('Field %s is required.', UCB_TEXT_DOMAIN), $field),
                    ['status' => 400]
                );
            }
        }
        
        $this->statuses[$slug] = [
            'label' => sanitize_text_field($status_data['label']),
            'description' => sanitize_textarea_field($status_data['description']),
            'color' => sanitize_hex_color($status_data['color']),
            'icon' => sanitize_text_field($status_data['icon'] ?? 'dashicons-admin-generic'),
            'order' => (int) ($status_data['order'] ?? count($this->statuses) + 1),
        ];
        
        $this->save_statuses();
        
        \UCB\Logger::log('info', 'Status added', [
            'slug' => $slug,
            'label' => $this->statuses[$slug]['label'],
            'added_by' => get_current_user_id(),
        ]);
        
        return true;
    }
    
    /**
     * Update status
     */
    public function update_status($slug, $status_data) {
        if (!$this->status_exists($slug)) {
            return new \WP_Error(
                'status_not_found',
                __('Status not found.', UCB_TEXT_DOMAIN),
                ['status' => 404]
            );
        }
        
        $updated_status = $this->statuses[$slug];
        
        if (isset($status_data['label'])) {
            $updated_status['label'] = sanitize_text_field($status_data['label']);
        }
        
        if (isset($status_data['description'])) {
            $updated_status['description'] = sanitize_textarea_field($status_data['description']);
        }
        
        if (isset($status_data['color'])) {
            $updated_status['color'] = sanitize_hex_color($status_data['color']);
        }
        
        if (isset($status_data['icon'])) {
            $updated_status['icon'] = sanitize_text_field($status_data['icon']);
        }
        
        if (isset($status_data['order'])) {
            $updated_status['order'] = (int) $status_data['order'];
        }
        
        $this->statuses[$slug] = $updated_status;
        $this->save_statuses();
        
        \UCB\Logger::log('info', 'Status updated', [
            'slug' => $slug,
            'updated_by' => get_current_user_id(),
        ]);
        
        return true;
    }
    
    /**
     * Delete status
     */
    public function delete_status($slug) {
        if (!$this->status_exists($slug)) {
            return new \WP_Error(
                'status_not_found',
                __('Status not found.', UCB_TEXT_DOMAIN),
                ['status' => 404]
            );
        }
        
        // Check if status is in use
        $users_with_status = get_users([
            'meta_key' => 'ucb_customer_status',
            'meta_value' => $slug,
            'fields' => 'ID',
        ]);
        
        if (!empty($users_with_status)) {
            return new \WP_Error(
                'status_in_use',
                __('Cannot delete status that is in use by customers.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }
        
        unset($this->statuses[$slug]);
        $this->save_statuses();
        
        \UCB\Logger::log('info', 'Status deleted', [
            'slug' => $slug,
            'deleted_by' => get_current_user_id(),
        ]);
        
        return true;
    }
    
    /**
     * Save statuses to database
     */
    private function save_statuses() {
        update_option('ucb_customer_statuses', $this->statuses);
    }
    
    /**
     * Get status statistics
     */
    public function get_status_statistics($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value as status, COUNT(*) as count 
             FROM {$wpdb->usermeta} 
             WHERE meta_key = 'ucb_customer_status' 
             AND user_id IN (
                 SELECT ID FROM {$wpdb->users} 
                 WHERE user_registered >= %s
             )
             GROUP BY meta_value",
            $cutoff_date
        ));
        
        $status_counts = [];
        foreach ($stats as $stat) {
            $status_counts[$stat->status] = (int) $stat->count;
        }
        
        // Add zero counts for statuses not in use
        foreach ($this->statuses as $slug => $status) {
            if (!isset($status_counts[$slug])) {
                $status_counts[$slug] = 0;
            }
        }
        
        return $status_counts;
    }
    
    /**
     * Get status transition rules
     */
    public function get_transition_rules() {
        return [
            'no_answer' => ['canceled', 'normal', 'upsell'],
            'canceled' => ['normal', 'upsell'],
            'upsell' => ['upsell_pending', 'normal', 'canceled'],
            'normal' => ['upsell', 'canceled', 'no_answer'],
            'upsell_pending' => ['upsell_paid', 'upsell', 'canceled', 'normal'],
            'upsell_paid' => ['normal', 'upsell'],
        ];
    }
    
    /**
     * Check if status transition is allowed
     */
    public function can_transition($from_status, $to_status) {
        $rules = $this->get_transition_rules();
        
        if (!isset($rules[$from_status])) {
            return false;
        }
        
        return in_array($to_status, $rules[$from_status]);
    }
    
    /**
     * Get next possible statuses
     */
    public function get_next_statuses($current_status) {
        $rules = $this->get_transition_rules();
        
        if (!isset($rules[$current_status])) {
            return [];
        }
        
        $next_statuses = [];
        foreach ($rules[$current_status] as $status_slug) {
            if ($this->status_exists($status_slug)) {
                $next_statuses[$status_slug] = $this->get_status($status_slug);
            }
        }
        
        return $next_statuses;
    }
    
    /**
     * Change customer status
     */
    public function change_status($customer_id, $new_status, $changed_by = null, $meta = []) {
        if (!$this->status_exists($new_status)) {
            return new \WP_Error(
                'invalid_status',
                __('Invalid status provided.', UCB_TEXT_DOMAIN),
                ['status' => 400]
            );
        }

        if (!is_array($meta)) {
            $meta = [];
        }

        $sanitized_meta = $this->sanitize_meta($meta);
        $old_status = get_user_meta($customer_id, 'ucb_customer_status', true);

        // Check if transition is allowed
        if ($old_status && !$this->can_transition($old_status, $new_status)) {
            return new \WP_Error(
                'invalid_transition',
                sprintf(__('Cannot change status from %s to %s.', UCB_TEXT_DOMAIN), $old_status, $new_status),
                ['status' => 400]
            );
        }

        // Update status
        update_user_meta($customer_id, 'ucb_customer_status', $new_status);

        // Log status change
        $this->log_status_change($customer_id, $old_status, $new_status, $changed_by, $sanitized_meta);

        // Handle special status actions
        $details = $this->handle_status_actions($customer_id, $new_status, $sanitized_meta);

        $response = [
            'customer_id' => (int) $customer_id,
            'old_status'  => $old_status ?: null,
            'new_status'  => $new_status,
        ];

        if (!empty($sanitized_meta)) {
            $response['meta'] = $sanitized_meta;
        }

        if (!empty($details)) {
            $response['details'] = $details;
        }

        $status_config = $this->get_status($new_status);
        if (is_array($status_config)) {
            $response['status'] = array_merge(['slug' => $new_status], $status_config);
        }

        return $response;
    }

    private function sanitize_meta(array $meta): array {
        $sanitized = [];

        foreach ($meta as $key => $value) {
            switch ($key) {
                case 'reason':
                    $sanitized[$key] = sanitize_textarea_field((string) $value);
                    break;
                case 'order_id':
                    $sanitized[$key] = (int) $value;
                    break;
                case 'amount':
                    $sanitized[$key] = (float) $value;
                    break;
                case 'pay_link':
                    $sanitized[$key] = esc_url_raw((string) $value);
                    break;
                case 'field_key':
                case 'field_label':
                    $sanitized[$key] = sanitize_text_field((string) $value);
                    break;
                default:
                    if (is_scalar($value)) {
                        $sanitized[$key] = sanitize_text_field((string) $value);
                    }
                    break;
            }
        }

        return $sanitized;
    }

    /**
     * Log status change
     */
    private function log_status_change($customer_id, $old_status, $new_status, $changed_by, array $meta) {
        $actor = $changed_by ?: get_current_user_id();
        $db = new \UCB\Database();

        $insert_id = $db->log_status_change([
            'customer_id' => $customer_id,
            'old_status'  => $old_status,
            'new_status'  => $new_status,
            'changed_by'  => $actor,
            'reason'      => $meta['reason'] ?? '',
        ]);

        if (is_wp_error($insert_id)) {
            $error_data = $insert_id->get_error_data();
            \UCB\Logger::log('error', 'Failed to store status change log', [
                'customer_id' => $customer_id,
                'old_status'  => $old_status,
                'new_status'  => $new_status,
                'error'       => $insert_id->get_error_message(),
                'db_error'    => is_array($error_data) ? ($error_data['db_error'] ?? null) : null,
            ], $actor);
        }

        \UCB\Logger::log('info', 'Customer status changed', [
            'customer_id' => $customer_id,
            'old_status'  => $old_status,
            'new_status'  => $new_status,
            'meta'        => $meta,
        ], $actor);
    }

    /**
     * Handle special status actions
     */
    private function handle_status_actions($customer_id, $status, array $meta) {
        switch ($status) {
            case 'normal':
                return $this->handle_normal_status($customer_id, $meta);
            case 'upsell_pending':
                return $this->handle_upsell_pending_status($customer_id, $meta);
            case 'upsell_paid':
                return $this->handle_upsell_paid_status($customer_id, $meta);
        }

        return [];
    }

    /**
     * Handle normal status - send random code
     */
    private function handle_normal_status($customer_id, array $meta) {
        $pending_order_id = (int) get_user_meta($customer_id, 'ucb_upsell_order_id', true);
        $revoked_token = false;
        $details = [];

        if ($pending_order_id > 0) {
            $token_value = '';

            if (function_exists('wc_get_order')) {
                $order = wc_get_order($pending_order_id);

                if ($order) {
                    $token_value = (string) $order->get_meta('_ucb_payment_token');

                    if (in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                        $order->update_status('cancelled', __('Cancelled after status reset', UCB_TEXT_DOMAIN));
                        $details['cancelled_order_id'] = $pending_order_id;
                    }
                }
            }

            if ($token_value) {
                $token_service = new PaymentTokenService();
                $revoked_token = (bool) $token_service->revoke_token($token_value);
                $details['revoked_token'] = $revoked_token;
            }

            delete_user_meta($customer_id, 'ucb_upsell_order_id');

            $details['pending_order_id'] = $pending_order_id;
        }

        delete_user_meta($customer_id, 'ucb_upsell_field_key');
        delete_user_meta($customer_id, 'ucb_upsell_field_label');
        delete_user_meta($customer_id, 'ucb_upsell_amount');
        delete_user_meta($customer_id, 'ucb_upsell_pay_link');

        if ($pending_order_id > 0) {
            \UCB\Logger::log('info', 'Upsell pending status reset to normal', [
                'customer_id'   => $customer_id,
                'order_id'      => $pending_order_id,
                'token_revoked' => $revoked_token,
            ]);
        }

        $random_code = strtoupper(wp_generate_password(8, false, false));
        update_user_meta($customer_id, 'ucb_customer_random_code', $random_code);
        $details['normal_code'] = $random_code;

        $phone = get_user_meta($customer_id, 'phone', true);
        if (!$phone) {
            $phone = get_user_meta($customer_id, 'billing_phone', true);
        }

        if ($phone) {
            update_user_meta($customer_id, 'ucb_customer_phone', $phone);
            $details['phone'] = $phone;
        }

        return $details;
    }

    /**
     * Handle upsell pending status
     */
    private function handle_upsell_pending_status($customer_id, array $meta) {
        $details = [];

        // Store order information
        if (isset($meta['order_id'])) {
            update_user_meta($customer_id, 'ucb_upsell_order_id', $meta['order_id']);
            $details['order_id'] = (int) $meta['order_id'];
        }

        if (isset($meta['field_key'])) {
            update_user_meta($customer_id, 'ucb_upsell_field_key', sanitize_text_field($meta['field_key']));
            $details['field_key'] = sanitize_text_field($meta['field_key']);
        }

        if (isset($meta['field_label'])) {
            update_user_meta($customer_id, 'ucb_upsell_field_label', sanitize_text_field($meta['field_label']));
            $details['field_label'] = sanitize_text_field($meta['field_label']);
        }

        if (isset($meta['amount'])) {
            update_user_meta($customer_id, 'ucb_upsell_amount', floatval($meta['amount']));
            $details['amount'] = floatval($meta['amount']);
        }

        if (isset($meta['pay_link'])) {
            update_user_meta($customer_id, 'ucb_upsell_pay_link', esc_url_raw($meta['pay_link']));
            $details['pay_link'] = esc_url_raw($meta['pay_link']);
        }

        return $details;
    }

    /**
     * Handle upsell paid status
     */
    private function handle_upsell_paid_status($customer_id, array $meta) {
        $details = [];

        // Clear pending order info but keep audit trail
        if (isset($meta['order_id'])) {
            update_user_meta($customer_id, 'ucb_upsell_last_order_id', $meta['order_id']);
            $details['order_id'] = (int) $meta['order_id'];
        }

        delete_user_meta($customer_id, 'ucb_upsell_order_id');
        delete_user_meta($customer_id, 'ucb_upsell_pay_link');

        // Log successful payment
        \UCB\Logger::log('info', 'Upsell payment completed', [
            'customer_id' => $customer_id,
            'order_id' => $meta['order_id'] ?? null
        ]);

        if (!isset($details['order_id'])) {
            $last_order = (int) get_user_meta($customer_id, 'ucb_upsell_last_order_id', true);
            if ($last_order) {
                $details['order_id'] = $last_order;
            }
        }

        return $details;
    }
}