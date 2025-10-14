<?php

namespace UCB\API;

use UCB\Security;
use UCB\Services\UserService;
use UCB\JWT\JWTHandler;
use WP_Error;
use WP_REST_Request;

class Authentication extends BaseController {
    /**
     * @var UserService
     */
    protected $users;
    
    /**
     * @var JWTHandler
     */
    protected $jwt;

    public function __construct() {
        $this->users = new UserService();
        $this->jwt = new JWTHandler();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/auth/login', [
            'methods'  => 'POST',
            'callback' => [$this, 'login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/auth/register', [
            'methods'  => 'POST',
            'callback' => [$this, 'register'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/auth/logout', [
            'methods'  => 'POST',
            'callback' => [$this, 'logout'],
            'permission_callback' => [$this, 'require_authenticated'],
        ]);
    }

    /**
     * Authenticate user and return JWT token.
     */
    public function login(WP_REST_Request $request) {
        Security::rate_limit('login_' . $request->get_param('username'), 10, 300);

        $username = sanitize_user($request->get_param('username'));
        $password = $request->get_param('password');
        $requested_role = sanitize_key($request->get_param('role'));

        if (!$username || !$password) {
            return $this->error('ucb_invalid_credentials', __('Username and password are required.', 'user-cards-bridge'), 400);
        }

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return $this->from_wp_error($user);
        }

        if ($requested_role && !in_array($requested_role, $user->roles, true)) {
            return $this->error('ucb_role_mismatch', __('User does not have the requested role.', 'user-cards-bridge'), 403);
        }

        $token = $this->jwt->create_token($user->ID, $user->user_login, $user->roles);
        if (!$token) {
            return $this->error('ucb_jwt_failed', __('JWT token could not be generated.', 'user-cards-bridge'), 500);
        }

        $data = [
            'token' => $token,
            'user'  => $this->users->format_user($user),
            'role'  => current($user->roles),
        ];

        return $this->success($data);
    }

    /**
     * Registers a new user for the requested role.
     */
    public function register(WP_REST_Request $request) {
        Security::rate_limit('register_' . ($request->get_param('email') ?: ''), 5, HOUR_IN_SECONDS);

        $role = sanitize_key($request->get_param('role'));

        if (!in_array($role, ['company_manager', 'supervisor', 'agent'], true)) {
            return $this->error('ucb_invalid_role', __('Invalid role supplied.', 'user-cards-bridge'), 400);
        }

        $user_id = $this->users->register([
            'username'      => $request->get_param('username'),
            'email'         => $request->get_param('email'),
            'password'      => $request->get_param('password'),
            'display_name'  => $request->get_param('display_name'),
            'supervisor_id' => (int) $request->get_param('supervisor_id'),
            'cards'         => (array) $request->get_param('cards'),
        ], $role);

        if (is_wp_error($user_id)) {
            return $this->from_wp_error($user_id);
        }

        $password = $request->get_param('password');
        if (empty($password)) {
            $password = wp_generate_password(12, true);
            wp_set_password($password, $user_id);
        }

        $token = $this->jwt->create_token($user_id, $request->get_param('username'), [$role]);
        if (!$token) {
            return $this->error('ucb_jwt_failed', __('JWT token could not be generated.', 'user-cards-bridge'), 500);
        }

        $user = get_user_by('id', $user_id);

        return $this->success([
            'token' => $token,
            'user'  => $this->users->format_user($user),
            'role'  => $role,
        ], 201);
    }

    /**
     * Logout user and invalidate token.
     */
    public function logout(WP_REST_Request $request) {
        // For JWT tokens, we can't really "invalidate" them server-side
        // The client should remove the token from storage
        // We could implement a blacklist if needed
        
        return $this->success([
            'message' => __('Successfully logged out.', 'user-cards-bridge'),
        ]);
    }

    public function require_authenticated(WP_REST_Request $request = null): bool {
        return is_user_logged_in();
    }

}
