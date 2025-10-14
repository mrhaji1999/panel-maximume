<?php

namespace UCB\API;

use UCB\Roles;
use UCB\Security;
use UCB\Services\CardService;
use WP_REST_Request;

class Cards extends BaseController {
    /**
     * @var CardService
     */
    protected $cards;

    public function __construct() {
        $this->cards = new CardService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/cards', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_cards'],
            'permission_callback' => [$this, 'require_authenticated'],
        ]);

        register_rest_route($this->namespace, '/cards/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_card'],
            'permission_callback' => [$this, 'require_authenticated'],
        ]);

        register_rest_route($this->namespace, '/cards/(?P<id>\d+)/fields', [
            'methods'  => 'GET',
            'callback' => [$this, 'card_fields'],
            'permission_callback' => [$this, 'require_authenticated'],
        ]);

        register_rest_route($this->namespace, '/supervisors/(?P<id>\d+)/cards', [
            'methods'  => 'GET',
            'callback' => [$this, 'supervisor_cards'],
            'permission_callback' => [$this, 'require_supervisor_access'],
        ]);
    }

    public function list_cards(WP_REST_Request $request) {
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $args = [];

        if ($request->get_param('search')) {
            $args['s'] = sanitize_text_field($request->get_param('search'));
        }

        $result = $this->cards->list_cards($args, $page, $per_page);

        $role = Roles::get_user_role(get_current_user_id());

        if ('supervisor' === $role) {
            $allowed = $this->cards->get_supervisor_card_ids(get_current_user_id());
            $result['items'] = array_values(array_filter($result['items'], function ($card) use ($allowed) {
                return in_array((int) $card['id'], $allowed, true);
            }));
            $result['total'] = count($result['items']);
        }

        return $this->success([
            'items' => $result['items'],
            'pagination' => $this->paginate($page, $per_page, $result['total']),
        ]);
    }

    public function get_card(WP_REST_Request $request) {
        $card_id = (int) $request->get_param('id');
        $card = $this->cards->get_card($card_id);

        if (!$card) {
            return $this->error('ucb_card_not_found', __('Card not found.', 'user-cards-bridge'), 404);
        }

        $role = Roles::get_user_role(get_current_user_id());

        // Check if supervisor can access this card
        if ('supervisor' === $role) {
            $allowed_cards = $this->cards->get_supervisor_card_ids(get_current_user_id());
            if (!in_array($card_id, $allowed_cards, true)) {
                return $this->error('ucb_forbidden', __('You do not have access to this card.', 'user-cards-bridge'), 403);
            }
        }

        return $this->success($this->cards->format_card($card));
    }

    public function card_fields(WP_REST_Request $request) {
        $card_id = (int) $request->get_param('id');
        $fields = $this->cards->get_card_fields($card_id);

        if (is_wp_error($fields)) {
            return $this->from_wp_error($fields);
        }

        return $this->success(['fields' => $fields]);
    }

    public function supervisor_cards(WP_REST_Request $request) {
        $supervisor_id = (int) $request->get_param('id');

        if (!Security::can_access_supervisor($supervisor_id) && !current_user_can('ucb_manage_all')) {
            return $this->error('ucb_forbidden', __('Insufficient permissions.', 'user-cards-bridge'), 403);
        }

        $card_ids = $this->cards->get_supervisor_card_ids($supervisor_id);
        $cards = array_map(function ($card_id) {
            $card = $this->cards->get_card($card_id);
            return $card ? $this->cards->format_card($card) : null;
        }, $card_ids);

        $cards = array_values(array_filter($cards));

        return $this->success(['items' => $cards]);
    }

    public function require_authenticated(WP_REST_Request $request = null): bool {
        return $this->is_authenticated();
    }

    public function require_supervisor_access(WP_REST_Request $request): bool {
        return $this->require_authenticated($request);
    }
}
