<?php

namespace UCB\API;

use WP_REST_Request;

class Health extends BaseController {
	public function register_routes(): void {
		// Public health check (no auth)
		\register_rest_route($this->namespace, '/health', [
			'methods'  => 'GET',
			'callback' => [$this, 'health'],
			'permission_callback' => '__return_true',
		]);

		// Authenticated identity check
		\register_rest_route($this->namespace, '/auth/me', [
			'methods'  => 'GET',
			'callback' => [$this, 'me'],
			'permission_callback' => [$this, 'require_authenticated'],
		]);
	}

	public function health(WP_REST_Request $request) {
		return $this->success([
			'status' => 'ok',
			'plugin' => 'user-cards-bridge',
		]);
	}

	public function me(WP_REST_Request $request) {
		$user = \wp_get_current_user();
		if (!$user || 0 === (int) $user->ID) {
			return $this->error('ucb_no_user', __('No authenticated user.', UCB_TEXT_DOMAIN), 401);
		}
		return $this->success([
			'id' => (int) $user->ID,
			'login' => $user->user_login,
			'roles' => $user->roles,
		]);
	}

	public function require_authenticated(WP_REST_Request $request): bool {
		return \is_user_logged_in();
	}
}


