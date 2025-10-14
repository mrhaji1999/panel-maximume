<?php

namespace UCB\Services;

use WP_Post;
use WP_Query;

/**
 * Provides access to form submissions created by user-cards plugin.
 */
class FormService {
    /**
     * Paginate forms.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function list(array $filters = [], int $page = 1, int $per_page = 20): array {
        $meta_query = [];

        if (!empty($filters['card_id'])) {
            $meta_query[] = [
                'key'   => '_uc_card_id',
                'value' => (int) $filters['card_id'],
            ];
        }

        if (!empty($filters['customer_id'])) {
            $meta_query[] = [
                'key'   => '_uc_user_id',
                'value' => (int) $filters['customer_id'],
            ];
        }

        if (!empty($filters['supervisor_id'])) {
            $meta_query[] = [
                'key'   => '_uc_supervisor_id',
                'value' => (int) $filters['supervisor_id'],
            ];
        }

        if (!empty($filters['agent_id'])) {
            $meta_query[] = [
                'key'   => '_uc_agent_id',
                'value' => (int) $filters['agent_id'],
            ];
        }

        $query_args = [
            'post_type'      => 'uc_submission',
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query,
        ];

        $query = new WP_Query($query_args);

        $items = array_map([$this, 'format'], $query->posts);

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
        ];
    }

    /**
     * Fetch single form entry.
     */
    public function get(int $form_id): ?array {
        $post = get_post($form_id);
        if (!$post || 'uc_submission' !== $post->post_type) {
            return null;
        }

        return $this->format($post);
    }

    /**
     * Format form for API.
     *
     * @return array<string, mixed>
     */
    public function format(WP_Post $post): array {
        $meta = [
            'card_id'   => (int) get_post_meta($post->ID, '_uc_card_id', true),
            'user_id'   => (int) get_post_meta($post->ID, '_uc_user_id', true),
            'code'      => get_post_meta($post->ID, '_uc_code', true),
            'date'      => get_post_meta($post->ID, '_uc_date', true),
            'time'      => get_post_meta($post->ID, '_uc_time', true),
            'surprise'  => get_post_meta($post->ID, '_uc_surprise', true),
            'meta'      => [],
            'supervisor_id' => (int) get_post_meta($post->ID, '_uc_supervisor_id', true),
            'agent_id'      => (int) get_post_meta($post->ID, '_uc_agent_id', true),
        ];

        $custom_fields = get_post_meta($post->ID, '_uc_meta_fields', true);
        if (is_array($custom_fields)) {
            $meta['meta'] = $custom_fields;
        }

        return [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'created_at'  => mysql_to_rfc3339($post->post_date),
            'meta'        => $meta,
        ];
    }
}
