<?php

namespace UCB\JWT;

use Exception;

class JWTHandler {
    
    private $secret_key;
    private $algorithm = 'HS256';
    
    public function __construct() {
        $this->secret_key = $this->get_secret_key();
    }
    
    /**
     * Get JWT secret key from WordPress options or generate one
     */
    private function get_secret_key() {
        // 1) Prefer the external JWT plugin secret if defined (ensures cross-plugin compatibility)
        if (defined('JWT_AUTH_SECRET_KEY') && JWT_AUTH_SECRET_KEY) {
            return JWT_AUTH_SECRET_KEY;
        }

        // 2) Fall back to WordPress AUTH_KEY for consistency across components
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return AUTH_KEY;
        }

        // 3) Finally, use (or create) our own stored secret option
        $key = get_option('ucb_jwt_secret_key');
        if (!$key) {
            $key = $this->generate_secret_key();
            update_option('ucb_jwt_secret_key', $key);
        }

        return $key ?: 'default-secret-key-for-testing';
    }
    
    /**
     * Generate a secure secret key
     */
    private function generate_secret_key() {
        return wp_generate_password(64, true, true);
    }
    
    /**
     * Create JWT token
     */
    public function create_token($user_id, $user_login, $user_roles = []) {
        $header = json_encode(['typ' => 'JWT', 'alg' => $this->algorithm]);
        
        $payload = json_encode([
            'iss' => get_site_url(),
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60), // 24 hours
            'sub' => $user_id,
            'user_id' => $user_id,
            'user_login' => $user_login,
            'user_roles' => $user_roles,
            'data' => [
                'user' => [
                    'id' => $user_id,
                    'login' => $user_login,
                    'roles' => $user_roles,
                ],
            ],
        ]);
        
        $base64_header = $this->base64url_encode($header);
        $base64_payload = $this->base64url_encode($payload);
        
        $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, $this->secret_key, true);
        $base64_signature = $this->base64url_encode($signature);
        
        return $base64_header . "." . $base64_payload . "." . $base64_signature;
    }
    
    /**
     * Validate JWT token
     */
    public function validate_token($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verify signature
        $expected_signature = $this->base64url_encode(
            hash_hmac('sha256', $header . "." . $payload, $this->secret_key, true)
        );
        
        if (!hash_equals($expected_signature, $signature)) {
            return false;
        }
        
        // Decode payload
        $payload_data = json_decode($this->base64url_decode($payload), true);
        
        if (!$payload_data) {
            return false;
        }
        
        // Check expiration
        if (isset($payload_data['exp']) && $payload_data['exp'] < time()) {
            return false;
        }
        
        return $payload_data;
    }
    
    /**
     * Get user from JWT token
     */
    public function get_user_from_token($token) {
        $payload = $this->validate_token($token);
        
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

        return get_user_by('id', $user_id);
    }
    
    /**
     * Base64 URL encode
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}
