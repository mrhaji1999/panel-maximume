<?php

namespace UCB\API;

use UCB\Roles;
use UCB\Security;
use UCB\Services\FormService;
use UCB\Services\FormSubmissionService;
use WP_REST_Request;

class Forms extends BaseController {
    protected FormService $forms;
    protected FormSubmissionService $form_submission;

    public function __construct() {
        $this->forms = new FormService();
        $this->form_submission = new FormSubmissionService();
        parent::__construct();
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/forms', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_forms'],
            'permission_callback' => [$this, 'require_access'],
        ]);

        register_rest_route($this->namespace, '/forms/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_form'],
            'permission_callback' => [$this, 'require_access'],
        ]);
        
        // Form submission from main site
        register_rest_route($this->namespace, '/forms/submit', [
            'methods'  => 'POST',
            'callback' => [$this, 'submit_form'],
            'permission_callback' => '__return_true', // Public endpoint for form submission
        ]);
        
        // Get supervisor forms
        register_rest_route($this->namespace, '/supervisors/(?P<id>\d+)/forms', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_supervisor_forms'],
            'permission_callback' => [$this, 'require_supervisor_access'],
        ]);

        // Update submission status
        register_rest_route($this->namespace, '/submissions/(?P<id>\d+)/status', [
            'methods'  => 'PATCH',
            'callback' => [$this, 'update_status'],
            'permission_callback' => [$this, 'require_submission_access'],
        ]);

        // Init upsell from submission
        register_rest_route($this->namespace, '/submissions/(?P<id>\d+)/upsell/init', [
            'methods'  => 'POST',
            'callback' => [$this, 'init_upsell'],
            'permission_callback' => [$this, 'require_submission_access'],
        ]);
    }

    public function list_forms(WP_REST_Request $request) {
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $filters = [
            'card_id'     => $request->get_param('card_id'),
            'customer_id' => $request->get_param('customer_id'),
        ];

        $role = Roles::get_user_role(get_current_user_id());
        if ('supervisor' === $role) {
            $filters['supervisor_id'] = get_current_user_id();
        } elseif ('agent' === $role) {
            $filters['agent_id'] = get_current_user_id();
        }

        $result = $this->forms->list($filters, $page, $per_page);

        return $this->success([
            'items' => $result['items'],
            'pagination' => $this->paginate($page, $per_page, $result['total']),
        ]);
    }

    public function get_form(WP_REST_Request $request) {
        $form_id = (int) $request->get_param('id');
        $form = $this->forms->get($form_id);

        if (!$form) {
            return $this->error('ucb_form_not_found', __('Form not found.', 'user-cards-bridge'), 404);
        }

        return $this->success($form);
    }

    public function submit_form(WP_REST_Request $request) {
        $form_data = $request->get_json_params();
        $card_id = (int) $request->get_param('card_id');
        
        if (!$card_id) {
            return $this->error('ucb_missing_card_id', __('Card ID is required.', 'user-cards-bridge'), 400);
        }
        
        $result = $this->form_submission->process_form_submission($form_data, $card_id);
        
        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }
        
        return $this->success($result, 201);
    }
    
    public function get_supervisor_forms(WP_REST_Request $request) {
        $supervisor_id = (int) $request->get_param('id');
        $filters = [
            'status' => $request->get_param('status'),
            'card_id' => $request->get_param('card_id'),
        ];
        
        $forms = $this->form_submission->get_supervisor_forms($supervisor_id, $filters);
        
        return $this->success([
            'items' => $forms,
            'total' => count($forms)
        ]);
    }

    public function require_access(WP_REST_Request $request): bool {
        return is_user_logged_in() && (current_user_can('ucb_manage_all') || Security::current_user_has_role(['company_manager', 'supervisor', 'agent']));
    }
    
    public function require_supervisor_access(WP_REST_Request $request): bool {
        $supervisor_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();
        
        // Manager can access all supervisors
        if (current_user_can('ucb_manage_all')) {
            return true;
        }
        
        // Supervisor can only access their own data
        return $supervisor_id === $current_user_id;
    }

    public function update_status(WP_REST_Request $request) {
        $submission_id = (int) $request->get_param('id');
        $status = sanitize_key($request->get_param('status'));

        update_post_meta($submission_id, '_uc_status', $status);

        return $this->success([
            'changed' => true,
            'status'  => $status,
        ]);
    }

    public function require_submission_access(WP_REST_Request $request): bool {
        $submission_id = (int) $request->get_param('id');
        $submission = get_post($submission_id);

        if (!$submission || 'uc_submission' !== $submission->post_type) {
            return false;
        }

        $customer_id = (int) get_post_meta($submission_id, '_uc_user_id', true);

        return Security::can_manage_customer($customer_id);
    }

    public function init_upsell(WP_REST_Request $request) {
        $submission_id = (int) $request->get_param('id');
        $field_key = sanitize_key($request->get_param('field_key'));

        if (empty($field_key)) {
            return $this->error('ucb_missing_field_key', __('Field key is required.', 'user-cards-bridge'), 400);
        }

        $customer_id = (int) get_post_meta($submission_id, '_uc_user_id', true);
        $card_id = (int) get_post_meta($submission_id, '_uc_card_id', true);

        if ($customer_id <= 0 || $card_id <= 0) {
            return $this->error('ucb_invalid_submission', __('Submission is missing customer or card reference.', 'user-cards-bridge'), 400);
        }

        $notifications = new \UCB\Services\NotificationService();
        $result = $notifications->send_upsell_code($customer_id, $card_id, $field_key);

        if (is_wp_error($result)) {
            return $this->from_wp_error($result);
        }

        update_post_meta($submission_id, '_uc_status', 'upsell_pending');

        return $this->success($result);
    }
}
