<?php

namespace UCB\API;

use UCB\Roles;
use UCB\Security;
use UCB\Services\ReservationService;
use WP_REST_Request;

class Reservations extends BaseController {
    protected ReservationService $reservations;

    public function __construct() {
        $this->reservations = new ReservationService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/reservations', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_reservation'],
            'permission_callback' => [$this, 'require_create'],
        ]);

        register_rest_route($this->namespace, '/reservations', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_reservations'],
            'permission_callback' => [$this, 'require_view'],
        ]);

        register_rest_route($this->namespace, '/reservations/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_reservation'],
            'permission_callback' => [$this, 'require_view'],
        ]);

        register_rest_route($this->namespace, '/reservations/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'cancel_reservation'],
            'permission_callback' => [$this, 'require_cancel'],
        ]);
    }

    public function create_reservation(WP_REST_Request $request) {
        $customer_id = (int) $request->get_param('customer_id');
        $card_id = (int) $request->get_param('card_id');
        $supervisor_id = (int) $request->get_param('supervisor_id');
        $weekday = (int) $request->get_param('weekday');
        $hour = (int) $request->get_param('hour');

        $result = $this->reservations->create($customer_id, $card_id, $supervisor_id, $weekday, $hour);

        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }

        return $this->success($result, 201);
    }

    public function list_reservations(WP_REST_Request $request) {
        $filters = [
            'card_id'       => $request->get_param('card_id'),
            'supervisor_id' => $request->get_param('supervisor_id'),
            'customer_id'   => $request->get_param('customer_id'),
        ];

        $role = Roles::get_user_role(get_current_user_id());

        if ('supervisor' === $role) {
            $filters['supervisor_id'] = get_current_user_id();
        } elseif ('agent' === $role) {
            $filters['agent_id'] = get_current_user_id();
        }

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $result = $this->reservations->list($filters, $page, $per_page);

        return $this->success([
            'items' => $result['items'],
            'pagination' => $this->paginate($page, $per_page, $result['total']),
        ]);
    }

    public function get_reservation(WP_REST_Request $request) {
        $reservation_id = (int) $request->get_param('id');
        $reservation = $this->reservations->get($reservation_id);

        if (!$reservation) {
            return $this->error('ucb_reservation_not_found', __('Reservation not found.', 'user-cards-bridge'), 404);
        }

        return $this->success($reservation);
    }

    public function cancel_reservation(WP_REST_Request $request) {
        $reservation_id = (int) $request->get_param('id');
        $result = $this->reservations->cancel($reservation_id);

        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }

        return $this->success([
            'message' => __('Reservation cancelled successfully.', 'user-cards-bridge'),
            'reservation_id' => $reservation_id,
        ]);
    }

    public function require_create(WP_REST_Request $request): bool {
        return is_user_logged_in() && Security::current_user_has_role(['company_manager', 'supervisor']);
    }

    public function require_view(WP_REST_Request $request): bool {
        return is_user_logged_in() && Security::current_user_has_role(['company_manager', 'supervisor', 'agent']);
    }

    public function require_cancel(WP_REST_Request $request): bool {
        return is_user_logged_in() && Security::current_user_has_role(['company_manager', 'supervisor']);
    }
}
