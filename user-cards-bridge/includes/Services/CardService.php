<?php

namespace UCB\Services;

use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Handles interaction with user-cards CPT.
 */
class CardService {
    /**
     * Fetch cards with optional filters.
     *
     * @param array<string, mixed> $args
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function list_cards(array $args = [], int $page = 1, int $per_page = 20): array {
        $query_args = wp_parse_args($args, [
            'post_type'      => 'uc_card',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $query = new WP_Query($query_args);

        $items = array_map(function (WP_Post $post) {
            return $this->format_card($post);
        }, $query->posts);

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
        ];
    }

    /**
     * Fetch single card.
     */
    public function get_card(int $card_id): ?WP_Post {
        $post = get_post($card_id);

        if (!$post || 'uc_card' !== $post->post_type) {
            return null;
        }

        return $post;
    }

    /**
     * Formats card for API.
     *
     * @return array<string, mixed>
     */
    public function format_card(WP_Post $post): array {
        $pricings = get_post_meta($post->ID, '_uc_pricings', true);

        if (!is_array($pricings)) {
            $pricings = [];
        }

        $pricings = array_map(function ($row) {
            return [
                'label'  => isset($row['label']) ? sanitize_text_field($row['label']) : '',
                'amount' => isset($row['amount']) ? floatval($row['amount']) : 0.0,
            ];
        }, $pricings);

        return [
            'id'                 => $post->ID,
            'title'              => get_the_title($post),
            'slug'               => $post->post_name,
            'excerpt'            => wp_trim_words($post->post_content, 40),
            'pricings'           => $pricings,
            'default_supervisor' => (int) get_post_meta($post->ID, 'ucb_default_supervisor', true),
        ];
    }

    /**
     * Returns card pricings (fields) for upsell.
     *
     * @return array<int, array<string,mixed>>
     */
    public function get_card_fields(int $card_id): array|WP_Error {
        $card = $this->get_card($card_id);
        if (!$card) {
            return new WP_Error('ucb_card_not_found', __('Card not found.', 'user-cards-bridge'));
        }

        $pricings = get_post_meta($card_id, '_uc_pricings', true);

        if (!is_array($pricings)) {
            $pricings = [];
        }

        $fields = [];
        foreach ($pricings as $index => $pricing) {
            $fields[] = [
                'key'    => 'field_' . ($index + 1),
                'label'  => isset($pricing['label']) ? sanitize_text_field($pricing['label']) : '',
                'amount' => isset($pricing['amount']) ? floatval($pricing['amount']) : 0.0,
            ];
        }

        return $fields;
    }

    /**
     * Assign cards to supervisor meta.
     */
    public function set_supervisor_cards(int $supervisor_id, array $card_ids): void {
        $sanitized = array_map('intval', $card_ids);
        update_user_meta($supervisor_id, 'ucb_supervisor_cards', array_unique($sanitized));
    }

    /**
     * Get supervisor card IDs.
     *
     * @return array<int, int>
     */
    public function get_supervisor_card_ids(int $supervisor_id): array {
        $cards = get_user_meta($supervisor_id, 'ucb_supervisor_cards', true);
        if (!is_array($cards)) {
            $cards = [];
        }
        return array_map('intval', $cards);
    }

    /**
     * Set default supervisor for a card.
     */
    public function set_default_supervisor(int $card_id, int $supervisor_id): void {
        update_post_meta($card_id, 'ucb_default_supervisor', $supervisor_id);
    }

    /**
     * Get default supervisor for card.
     */
    public function get_default_supervisor(int $card_id): int {
        return (int) get_post_meta($card_id, 'ucb_default_supervisor', true);
    }
}
