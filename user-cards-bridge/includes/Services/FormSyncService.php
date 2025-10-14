<?php

namespace UCB\Services;

use WP_Post;

/**
 * Keeps form submissions in sync with bridge metadata.
 */
class FormSyncService {
    public function __construct() {
        add_action('save_post_uc_submission', [$this, 'sync_submission'], 20, 3);
    }

    /**
     * Ensure form meta reflects customer assignments.
     */
    public function sync_submission(int $post_id, WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ('auto-draft' === $post->post_status) {
            return;
        }

        $customer_id = (int) get_post_meta($post_id, '_uc_user_id', true);
        if ($customer_id <= 0) {
            return;
        }

        $card_id = (int) get_post_meta($post_id, '_uc_card_id', true);

        // Sync customer card meta
        if ($card_id > 0) {
            update_user_meta($customer_id, 'ucb_customer_card_id', $card_id);
        }

        // Supervisor assignment
        $supervisor_id = (int) get_user_meta($customer_id, 'ucb_customer_assigned_supervisor', true);
        if ($supervisor_id <= 0 && $card_id > 0) {
            $default_supervisor = (int) get_post_meta($card_id, 'ucb_default_supervisor', true);
            if ($default_supervisor > 0) {
                update_user_meta($customer_id, 'ucb_customer_assigned_supervisor', $default_supervisor);
                $supervisor_id = $default_supervisor;
            }
        }

        if ($supervisor_id > 0) {
            update_post_meta($post_id, '_uc_supervisor_id', $supervisor_id);
        }

        // Agent assignment
        $agent_id = (int) get_user_meta($customer_id, 'ucb_customer_assigned_agent', true);
        if ($agent_id > 0) {
            update_post_meta($post_id, '_uc_agent_id', $agent_id);
        }
    }
}
