<?php

namespace UCB\API;

use UCB\Security;
use UCB\Services\CardService;
use UCB\Services\ScheduleService;
use WP_REST_Request;

class Schedule extends BaseController {
    protected ScheduleService $schedule;
    protected CardService $cards;

    public function __construct() {
        $this->schedule = new ScheduleService();
        $this->cards = new CardService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/schedule/(?P<supervisor_id>\d+)/(?P<card_id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_schedule'],
            'permission_callback' => [$this, 'require_schedule_view'],
        ]);

        register_rest_route($this->namespace, '/schedule/(?P<supervisor_id>\d+)/(?P<card_id>\d+)', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_schedule'],
            'permission_callback' => [$this, 'require_schedule_manage'],
        ]);

        register_rest_route($this->namespace, '/availability/(?P<card_id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'availability'],
            'permission_callback' => [$this, 'require_authenticated'],
        ]);
    }

    public function get_schedule(WP_REST_Request $request) {
        $supervisor_id = (int) $request->get_param('supervisor_id');
        $card_id = (int) $request->get_param('card_id');

        $matrix = $this->schedule->get_matrix($supervisor_id, $card_id);

        return $this->success([
            'supervisor_id' => $supervisor_id,
            'card_id'       => $card_id,
            'matrix'        => $matrix,
        ]);
    }

    public function update_schedule(WP_REST_Request $request) {
        $supervisor_id = (int) $request->get_param('supervisor_id');
        $card_id = (int) $request->get_param('card_id');
        $matrix = (array) $request->get_param('matrix');

        $this->schedule->save_matrix($supervisor_id, $card_id, $matrix);

        return $this->success([
            'supervisor_id' => $supervisor_id,
            'card_id'       => $card_id,
            'matrix'        => $this->schedule->get_matrix($supervisor_id, $card_id),
        ]);
    }

    public function availability(WP_REST_Request $request) {
        $card_id = (int) $request->get_param('card_id');
        $supervisor_id = (int) $request->get_param('supervisor_id');

        if (!$supervisor_id) {
            $supervisor_id = $this->cards->get_default_supervisor($card_id);
        }

        if (!$supervisor_id) {
            return $this->error('ucb_supervisor_missing', __('Supervisor not assigned to this card.', 'user-cards-bridge'), 404);
        }

        $availability = $this->schedule->get_availability($card_id, $supervisor_id);

        return $this->success([
            'card_id'       => $card_id,
            'supervisor_id' => $supervisor_id,
            'slots'         => $availability,
        ]);
    }

    public function require_schedule_view(WP_REST_Request $request): bool {
        $supervisor_id = (int) $request->get_param('supervisor_id');
        return is_user_logged_in() && Security::can_access_supervisor($supervisor_id);
    }

    public function require_schedule_manage(WP_REST_Request $request): bool {
        $supervisor_id = (int) $request->get_param('supervisor_id');
        return current_user_can('ucb_manage_all') || Security::can_access_supervisor($supervisor_id);
    }

    public function require_authenticated(WP_REST_Request $request): bool {
        return is_user_logged_in();
    }
}
