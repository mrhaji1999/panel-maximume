<?php

namespace UCB;

use UCB\Services\CustomerCardRepository;
use WP_Error;
use WP_REST_Request;

/**
 * Security helpers: CORS, RBAC and lightweight rate limiting.
 */
class Security {
    /**
     * Constructor.
     */
    public function __construct() {
        add_filter('rest_pre_serve_request', [$this, 'apply_cors_headers'], 11, 4);
        
        // Use a different approach to avoid conflicts with JWT plugin
        add_action('rest_api_init', [$this, 'add_authentication_middleware'], 5);
        
        // Disable JWT plugin authentication for our namespace to avoid conflicts
        add_filter('jwt_auth_whitelist', [$this, 'whitelist_our_routes'], 10, 1);
    }

    /**
     * Adds CORS headers to REST responses.
     *
     * @param bool             $served
     * @param mixed            $result
     * @param WP_REST_Request  $request
     * @param \WP_REST_Server  $server
     */
    public function apply_cors_headers($served, $result, $request, $server) {
        if (0 !== strpos($request->get_route(), '/user-cards-bridge/')) {
            return $served;
        }

        $allowed_origins = array_values(array_filter(array_map([self::class, 'sanitize_origin'], (array) get_option('ucb_cors_allowed_origins', []))));
        $raw_origin = isset($_SERVER['HTTP_ORIGIN']) ? wp_unslash($_SERVER['HTTP_ORIGIN']) : '';
        $normalized_origin = self::sanitize_origin($raw_origin);

        $allow_all = empty($allowed_origins);
        $origin_is_allowed = $normalized_origin && in_array($normalized_origin, $allowed_origins, true);
        $effective_origin = '';
        $allow_credentials = false;

        if ($allow_all) {
            $effective_origin = $normalized_origin ?: '*';
        } elseif ($origin_is_allowed) {
            $effective_origin = $normalized_origin;
            $allow_credentials = true;
        }

        if ($effective_origin) {
            $this->output_cors_headers($effective_origin, $allow_credentials);
        }

        if ('OPTIONS' === $request->get_method()) {
            if ($effective_origin) {
                $server->send_header('Access-Control-Allow-Origin', '*' === $effective_origin ? '*' : esc_url_raw($effective_origin));
                if ($allow_credentials) {
                    $server->send_header('Access-Control-Allow-Credentials', 'true');
                }
                if ('*' !== $effective_origin) {
                    $server->send_header('Vary', 'Origin');
                }
            }
            $server->send_header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $server->send_header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-Requested-With');
            $server->send_header('Access-Control-Max-Age', '86400');
            $served = true;
        }

        return $served;
    }

    /**
     * Normalize and sanitize an origin string for comparisons.
     */
    public static function sanitize_origin($origin): string {
        if (empty($origin) || !is_string($origin)) {
            return '';
        }

        $origin = trim($origin);
        if ('*' === $origin) {
            return '*';
        }

        $parsed = wp_parse_url($origin);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return '';
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);
        $port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';

        return untrailingslashit(sprintf('%s://%s%s', $scheme, $host, $port));
    }

    /**
     * Output the base CORS headers for responses.
     */
    private function output_cors_headers(string $origin, bool $allow_credentials): void {
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With');
        header('Access-Control-Allow-Origin: ' . ('*' === $origin ? '*' : esc_url_raw($origin)));

        if ('*' !== $origin) {
            header('Vary: Origin');
        }

        if ($allow_credentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    }

    /**
     * Add authentication middleware for our API routes
     */
    public function add_authentication_middleware() {
		// Run at the earliest possible priority so we can short-circuit other JWT plugins
		add_filter('rest_authentication_errors', [$this, 'check_authentication'], -100, 1);
    }

    /**
     * Check authentication for our API routes
     */
    public function check_authentication($errors) {
        // If there are already authentication errors, return them
        if (!empty($errors)) {
            return $errors;
        }

        // Get the current request
        $request = null;
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = $_SERVER['REQUEST_URI'];
            if (strpos($request_uri, '/wp-json/user-cards-bridge/') !== false) {
                // Always allow CORS preflight
                if (!empty($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
                    return true;
                }
                // Extract route from request URI
                $route = str_replace('/wp-json', '', $request_uri);
                $route = strtok($route, '?'); // Remove query parameters
                
                $public_routes = [
                    '/user-cards-bridge/v1/auth/login',
                    '/user-cards-bridge/v1/auth/register',
                    '/user-cards-bridge/v1/webhooks/woocommerce/payment',
                ];

                if (in_array($route, $public_routes, true)) {
                    // Tell WP that authentication for this request is handled/allowed
                    return true;
                }

				// Try to authenticate via JWT Bearer token (our own handler)
				$jwt_user = \UCB\JWT\JWTAuth::get_current_user();
                if ($jwt_user) {
                    // Ensure WordPress knows about the current user for capability checks
                    if (function_exists('wp_set_current_user')) {
                        wp_set_current_user($jwt_user->ID);
                    }
					// Prevent external JWT plugins from attempting to re-parse the token for this namespace
					if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
						unset($_SERVER['HTTP_AUTHORIZATION']);
					}
					if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
						unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
					}
                    // Short-circuit: auth succeeded; skip other authenticators
                    return true;
                }

                // Check if user is logged in via cookies (WP admin/browser)
                if (is_user_logged_in()) {
                    return true;
                }

                // Return authentication error
                return new WP_Error(
                    'ucb_auth_required',
                    __('Authentication required.', UCB_TEXT_DOMAIN),
                    ['status' => 401]
                );
            }
        }

        return $errors;
    }

    /**
     * Whitelist our routes for JWT plugin to avoid conflicts
     */
    public function whitelist_our_routes($whitelist) {
        $our_routes = [
            // Public endpoints (explicit)
            '/user-cards-bridge/v1/auth/login',
            '/user-cards-bridge/v1/auth/register',
            '/user-cards-bridge/v1/webhooks/woocommerce/payment',

            // Entire namespace (ensure external JWT plugin does not intercept our routes)
            '/user-cards-bridge/v1',
            '/user-cards-bridge/v1/*',
        ];

        return array_merge($whitelist, $our_routes);
    }


    /**
     * Check if current user has any of the given roles.
     *
     * @param array<int, string> $roles
     */
    public static function current_user_has_role(array $roles): bool {
        $user = wp_get_current_user();
        if (!$user || empty($user->roles)) {
            return false;
        }

        foreach ($user->roles as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if user can access supervisor scoped resources.
     */
    public static function can_access_supervisor(int $supervisor_id): bool {
        if (current_user_can('manage_options') || current_user_can('ucb_manage_all')) {
            return true;
        }

        $user_id = get_current_user_id();

        if (empty($user_id)) {
            return false;
        }

        if (in_array('supervisor', wp_get_current_user()->roles, true)) {
            return $user_id === $supervisor_id;
        }

        if (in_array('agent', wp_get_current_user()->roles, true)) {
            $assigned_supervisor = (int) get_user_meta($user_id, 'ucb_agent_supervisor_id', true);
            return $assigned_supervisor === $supervisor_id;
        }

        return false;
    }

    /**
     * Basic rate limiter using transients.
     *
     * @throws WP_Error When limit exceeded.
     */
    public static function rate_limit(string $key, int $limit, int $window): void {
        $transient_key = 'ucb_rl_' . md5($key);
        $data = get_transient($transient_key);

        if (!is_array($data)) {
            $data = ['count' => 0, 'expires' => time() + $window];
        }

        if ($data['expires'] < time()) {
            $data = ['count' => 0, 'expires' => time() + $window];
        }

        if ($data['count'] >= $limit) {
            throw new WP_Error(
                'ucb_rate_limited',
                __('Too many requests. Please slow down.', UCB_TEXT_DOMAIN),
                ['status' => 429]
            );
        }

        $data['count']++;
        set_transient($transient_key, $data, $window);
    }

    /**
     * Validate JWT presence on request.
     */
    public static function ensure_jwt(WP_REST_Request $request): void {
        $auth_header = $request->get_header('authorization');

        if (empty($auth_header)) {
            throw new WP_Error(
                'ucb_missing_token',
                __('JWT token is required.', UCB_TEXT_DOMAIN),
                ['status' => 401]
            );
        }
    }

    /**
     * Checks if user can manage customer.
     */
    public static function can_manage_customer(int $customer_id, ?int $card_id = null, ?int $submission_id = null): bool {
        if (current_user_can('ucb_manage_all') || current_user_can('manage_options')) {
            return true;
        }

        $user_id = get_current_user_id();

        if (!$user_id) {
            return false;
        }

        $role = Roles::get_user_role($user_id);

        $target_card_id = $card_id;

        if ($submission_id) {
            $submission = get_post($submission_id);

            if (!$submission || 'uc_submission' !== $submission->post_type) {
                return false;
            }

            $owner_id = (int) get_post_meta($submission_id, '_uc_user_id', true);
            if ($owner_id !== $customer_id) {
                return false;
            }

            $submission_card_id = (int) get_post_meta($submission_id, '_uc_card_id', true);
            if ($submission_card_id > 0) {
                $target_card_id = $submission_card_id;
            }

            if ('supervisor' === $role) {
                $assigned = (int) get_post_meta($submission_id, '_uc_supervisor_id', true);
                if ($assigned === $user_id) {
                    return true;
                }
            }

            if ('agent' === $role) {
                $assigned = (int) get_post_meta($submission_id, '_uc_agent_id', true);
                if ($assigned === $user_id) {
                    return true;
                }
            }
        }

        $card_repository = new CustomerCardRepository();
        $card_repository->ensure_legacy_migrated($customer_id);
        $cards = $card_repository->get_cards($customer_id);

        if ('supervisor' === $role) {
            if ($target_card_id && isset($cards[$target_card_id])) {
                $assigned = (int) ($cards[$target_card_id]['supervisor_id'] ?? 0);
                return $assigned === $user_id;
            }

            foreach ($cards as $meta) {
                if ((int) ($meta['supervisor_id'] ?? 0) === $user_id) {
                    return true;
                }
            }

            $assigned_supervisor = (int) get_user_meta($customer_id, 'ucb_customer_assigned_supervisor', true);

            return $assigned_supervisor === $user_id;
        }

        if ('agent' === $role) {
            if ($target_card_id && isset($cards[$target_card_id])) {
                $assigned = (int) ($cards[$target_card_id]['agent_id'] ?? 0);
                return $assigned === $user_id;
            }

            foreach ($cards as $meta) {
                if ((int) ($meta['agent_id'] ?? 0) === $user_id) {
                    return true;
                }
            }

            $assigned_agent = (int) get_user_meta($customer_id, 'ucb_customer_assigned_agent', true);

            return $assigned_agent === $user_id;
        }

        return false;
    }
}
