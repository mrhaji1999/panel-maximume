<?php

namespace UCB\API;

use UCB\Roles;
use UCB\Services\StatsService;
use WP_REST_Request;

class Stats extends BaseController {
    protected StatsService $stats;

    public function __construct() {
        $this->stats = new StatsService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/dashboard/summary', [
            'methods'  => 'GET',
            'callback' => [$this, 'summary'],
            'permission_callback' => [$this, 'require_authenticated'],
        ]);
    }

    public function summary(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return $this->error('ucb_forbidden', __('Authentication required.', 'user-cards-bridge'), 401);
        }

        $days = max(1, (int) $request->get_param('days') ?: 30);
        $activity = max(1, (int) $request->get_param('activity') ?: 10);
        $user_id = get_current_user_id();

        $data = $this->stats->get_summary($user_id, $days, $activity);

        return $this->success($data);
    }

    public function require_authenticated(WP_REST_Request $request = null): bool {
        return $this->is_authenticated();
    }
}
