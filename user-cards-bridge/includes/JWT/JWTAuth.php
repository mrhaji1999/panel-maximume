<?php

namespace UCB\JWT;

class JWTAuth {
    
    private static $jwt_handler;
    
    public static function init() {
        self::$jwt_handler = new JWTHandler();
    }
    
    /**
     * Get current authenticated user from JWT token
     */
    public static function get_current_user() {
        // Prefer our own implementation; external JWT plugin support removed to avoid type issues
        
        // Fallback to our custom implementation
        $token = self::get_token_from_request();
        
        if (!$token) {
            return false;
        }
        
        return self::$jwt_handler->get_user_from_token($token);
    }
    
    /**
     * Get token from request headers
     */
    public static function get_token_from_request() {
        $headers = function_exists('getallheaders') ? \getallheaders() : [];
        
        // Normalize header keys for case-insensitive lookup
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower($k)] = $v;
        }
        
        // Check Authorization header across various server environments
        $auth_header = null;
        if (isset($normalized['authorization'])) {
            $auth_header = $normalized['authorization'];
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            return $matches[1];
        }
        
        // Check for token in query parameter (fallback)
        if (isset($_GET['token'])) {
            return function_exists('sanitize_text_field') ? \sanitize_text_field($_GET['token']) : (string) $_GET['token'];
        }
        
        return false;
    }
    
    /**
     * Validate JWT token
     */
    public static function validate_token($token = null) {
        if (!$token) {
            $token = self::get_token_from_request();
        }
        
        if (!$token) {
            return false;
        }
        
        return self::$jwt_handler->validate_token($token);
    }
    
    /**
     * Check if user has specific role
     */
    public static function user_has_role($role) {
        $user = self::get_current_user();
        
        if (!$user) {
            // Fallback to WordPress user
            if (function_exists('is_user_logged_in') && \is_user_logged_in()) {
                $wp_user = function_exists('wp_get_current_user') ? \wp_get_current_user() : null;
                if (!$wp_user) {
                    return false;
                }
                return in_array($role, $wp_user->roles, true);
            }
            return false;
        }
        
        return in_array($role, $user->roles, true);
    }
    
    /**
     * Check if user has any of the specified roles
     */
    public static function user_has_any_role($roles) {
        $user = self::get_current_user();
        
        if (!$user) {
            // Fallback to WordPress user
            if (function_exists('is_user_logged_in') && \is_user_logged_in()) {
                $wp_user = function_exists('wp_get_current_user') ? \wp_get_current_user() : null;
                if (!$wp_user) {
                    return false;
                }
                return !empty(array_intersect($roles, $wp_user->roles));
            }
            return false;
        }
        
        return !empty(array_intersect($roles, $user->roles));
    }
    
    /**
     * Get user ID from token
     */
    public static function get_user_id() {
        $payload = self::validate_token();
        
        if (!$payload) {
            return false;
        }

        $user_id = $payload['user_id'] ?? $payload['sub'] ?? null;

        if (!$user_id && isset($payload['data']['user']['id'])) {
            $user_id = $payload['data']['user']['id'];
        }

        if (!$user_id) {
            return false;
        }

        return (int) $user_id;
    }
}
