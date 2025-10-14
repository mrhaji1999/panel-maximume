<?php

namespace UCB\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use UCB\JWT\JWTAuth;

abstract class BaseController {
    
    protected $namespace = 'user-cards-bridge/v1';
    
    public function __construct() {
        if (!method_exists($this, 'register_routes')) {
            return;
        }

        if (\function_exists('did_action') && \did_action('rest_api_init')) {
            $this->register_routes();
            return;
        }

        if (\function_exists('add_action')) {
            \add_action('rest_api_init', [$this, 'register_routes']);
        }
    }
    
    /**
     * Return success response
     */
    protected function success($data = null, $status = 200) {
        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
            'error' => null,
        ], $status);
    }
    
    /**
     * Return error response
     */
    protected function error($code, $message, $status = 400, $data = null) {
        return new WP_Error($code, $message, [
            'status' => $status,
            'data' => $data,
        ]);
    }
    
    /**
     * Convert WP_Error to our error format
     */
    protected function from_wp_error(WP_Error $error) {
        return $this->error(
            $error->get_error_code(),
            $error->get_error_message(),
            $error->get_error_data()['status'] ?? 400
        );
    }
    
    /**
     * Validate required parameters
     */
    protected function validate_required_params($request, $required_params) {
        $missing = [];
        
        foreach ($required_params as $param) {
            if ($request->get_param($param) === null) {
                $missing[] = $param;
            }
        }
        
        if (!empty($missing)) {
            return $this->error(
                'missing_parameters',
                sprintf(__('Missing required parameters: %s', UCB_TEXT_DOMAIN), implode(', ', $missing)),
                400
            );
        }
        
        return true;
    }
    
    /**
     * Sanitize and validate parameters
     */
    protected function sanitize_params($request, $param_rules) {
        $sanitized = [];
        $errors = [];
        
        foreach ($param_rules as $param => $rules) {
            $value = $request->get_param($param);
            
            // Check if required
            if (isset($rules['required']) && $rules['required'] && ($value === null || $value === '')) {
                $errors[$param] = sprintf(__('Parameter %s is required.', UCB_TEXT_DOMAIN), $param);
                continue;
            }
            
            // Skip validation if value is empty and not required
            if (($value === null || $value === '') && !isset($rules['required'])) {
                continue;
            }
            
            // Sanitize based on type
            switch ($rules['type'] ?? 'string') {
                case 'email':
                    $value = sanitize_email($value);
                    if (!is_email($value)) {
                        $errors[$param] = sprintf(__('Parameter %s must be a valid email address.', UCB_TEXT_DOMAIN), $param);
                    }
                    break;
                    
                case 'int':
                    $value = (int) $value;
                    if (isset($rules['min']) && $value < $rules['min']) {
                        $errors[$param] = sprintf(__('Parameter %s must be at least %d.', UCB_TEXT_DOMAIN), $param, $rules['min']);
                    }
                    if (isset($rules['max']) && $value > $rules['max']) {
                        $errors[$param] = sprintf(__('Parameter %s must be no more than %d.', UCB_TEXT_DOMAIN), $param, $rules['max']);
                    }
                    break;
                    
                case 'float':
                    $value = (float) $value;
                    break;
                    
                case 'url':
                    $value = esc_url_raw($value);
                    break;
                    
                case 'text':
                    $value = sanitize_textarea_field($value);
                    break;
                    
                case 'array':
                    if (!is_array($value)) {
                        $errors[$param] = sprintf(__('Parameter %s must be an array.', UCB_TEXT_DOMAIN), $param);
                    } else {
                        $value = array_map('sanitize_text_field', $value);
                    }
                    break;
                    
                default:
                    $value = sanitize_text_field($value);
                    break;
            }
            
            // Check enum values
            if (isset($rules['enum']) && !in_array($value, $rules['enum'])) {
                $errors[$param] = sprintf(__('Parameter %s must be one of: %s', UCB_TEXT_DOMAIN), $param, implode(', ', $rules['enum']));
            }
            
            $sanitized[$param] = $value;
        }
        
        if (!empty($errors)) {
            return $this->error(
                'validation_failed',
                __('Parameter validation failed.', UCB_TEXT_DOMAIN),
                400,
                $errors
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Check if user has permission
     */
    protected function check_permission($capability) {
        if (!current_user_can($capability)) {
            return $this->error(
                'insufficient_permissions',
                __('You do not have permission to perform this action.', UCB_TEXT_DOMAIN),
                403
            );
        }
        
        return true;
    }
    
    /**
     * Authenticate user via JWT token
     */
    protected function authenticate_user() {
        // First try JWT authentication
        $jwt_user = JWTAuth::get_current_user();
        if ($jwt_user) {
            return $jwt_user;
        }
        
        // Fallback to WordPress authentication for admin panel
        if (is_user_logged_in()) {
            return wp_get_current_user();
        }
        
        return $this->error(
            'authentication_required',
            __('Authentication required. Please provide a valid JWT token.', UCB_TEXT_DOMAIN),
            401
        );
    }
    
    /**
     * Check if user has specific role
     */
    protected function check_user_role($role) {
        $user = $this->authenticate_user();
        
        if (is_wp_error($user)) {
            return $user;
        }
        
        if (!JWTAuth::user_has_role($role)) {
            return $this->error(
                'insufficient_role',
                sprintf(__('This action requires %s role.', UCB_TEXT_DOMAIN), $role),
                403
            );
        }
        
        return $user;
    }
    
    /**
     * Check if user has any of the specified roles
     */
    protected function check_user_roles($roles) {
        $user = $this->authenticate_user();
        
        if (is_wp_error($user)) {
            return $user;
        }
        
        if (!JWTAuth::user_has_any_role($roles)) {
            return $this->error(
                'insufficient_role',
                sprintf(__('This action requires one of the following roles: %s', UCB_TEXT_DOMAIN), implode(', ', $roles)),
                403
            );
        }
        
        return $user;
    }
    
    /**
     * Get pagination parameters
     */
    protected function get_pagination_params($request) {
        return [
            'page' => max(1, (int) $request->get_param('page')),
            'per_page' => min(100, max(1, (int) $request->get_param('per_page'))),
        ];
    }
    
    /**
     * Format paginated response
     */
    protected function format_paginated_response($data, $total, $page, $per_page) {
        return [
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page),
            ],
        ];
    }

    /**
     * Format pagination data
     */
    public function paginate($page, $per_page, $total) {
        return [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
        ];
    }

    /**
     * Check if user is authenticated (for REST API context)
     */
    public function is_authenticated(): bool {
        // Check if user is logged in via cookies
        $user_id = wp_validate_auth_cookie();
        if ($user_id) {
            wp_set_current_user($user_id);
            return true;
        }
        
        // Check if user is logged in via session
        if (is_user_logged_in()) {
            return true;
        }
        
        return false;
    }
}
