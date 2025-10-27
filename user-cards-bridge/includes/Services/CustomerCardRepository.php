<?php

namespace UCB\Services;

/**
 * Manages per-customer card metadata.
 */
class CustomerCardRepository {
    /**
     * Retrieve all card entries for a customer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_cards(int $customer_id): array {
        $cards = get_user_meta($customer_id, 'ucb_customer_cards', true);
        if (!is_array($cards)) {
            $cards = [];
        }
        $normalized = [];
        foreach ($cards as $card_id => $data) {
            $card_id = (int) $card_id;
            if ($card_id <= 0) {
                continue;
            }
            $normalized[$card_id] = $this->sanitize_card_data($data);
        }
        return $normalized;
    }

    /**
     * Retrieve a specific card entry.
     *
     * @return array<string, mixed>
     */
    public function get_card(int $customer_id, int $card_id): array {
        $cards = $this->get_cards($customer_id);
        return $cards[$card_id] ?? [];
    }

    /**
     * Persist card data for a customer.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function set_card(int $customer_id, int $card_id, array $data): array {
        if ($card_id <= 0) {
            return [];
        }
        $cards = $this->get_cards($customer_id);
        $existing = $cards[$card_id] ?? [];
        $cards[$card_id] = array_merge($existing, $this->sanitize_card_data($data));
        update_user_meta($customer_id, 'ucb_customer_cards', $cards);
        return $cards[$card_id];
    }

    /**
     * Update just the status field for a card.
     */
    public function update_status(int $customer_id, int $card_id, string $status): void {
        $this->set_card($customer_id, $card_id, ['status' => sanitize_key($status)]);
    }

    /**
     * Update supervisor mapping for a card.
     */
    public function update_supervisor(int $customer_id, int $card_id, int $supervisor_id): void {
        $this->set_card($customer_id, $card_id, ['supervisor_id' => $supervisor_id]);
    }

    /**
     * Update agent mapping for a card.
     */
    public function update_agent(int $customer_id, int $card_id, int $agent_id): void {
        $this->set_card($customer_id, $card_id, ['agent_id' => $agent_id]);
    }

    /**
     * Store submission reference for card.
     */
    public function update_submission(int $customer_id, int $card_id, int $submission_id): void {
        $this->set_card($customer_id, $card_id, ['submission_id' => $submission_id]);
    }

    /**
     * Store schedule info for a card.
     */
    public function update_schedule(int $customer_id, int $card_id, ?string $date, ?string $time): void {
        $schedule = ['date' => $date ?: null, 'time' => $time ?: null];
        $this->set_card($customer_id, $card_id, ['schedule' => $schedule]);
    }

    /**
     * Store random code for a card entry.
     */
    public function update_random_code(int $customer_id, int $card_id, string $code): void {
        $this->set_card($customer_id, $card_id, ['random_code' => sanitize_text_field($code)]);
    }

    /**
     * Merge upsell metadata.
     *
     * @param array<string, mixed> $upsell
     */
    public function update_upsell(int $customer_id, int $card_id, array $upsell): void {
        $allowed = ['field_key', 'field_label', 'amount', 'order_id', 'pay_link'];
        $filtered = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $upsell)) {
                continue;
            }
            $value = $upsell[$key];
            if (in_array($key, ['amount'], true)) {
                $filtered[$key] = (float) $value;
            } elseif (in_array($key, ['order_id'], true)) {
                $filtered[$key] = (int) $value;
            } else {
                $filtered[$key] = sanitize_text_field((string) $value);
            }
        }
        if (!empty($filtered)) {
            $this->set_card($customer_id, $card_id, ['upsell' => array_merge($this->get_card_upsell($customer_id, $card_id), $filtered)]);
        }
    }

    /**
     * Fetch upsell meta for card.
     *
     * @return array<string, mixed>
     */
    public function get_card_upsell(int $customer_id, int $card_id): array {
        $card = $this->get_card($customer_id, $card_id);
        return isset($card['upsell']) && is_array($card['upsell']) ? $card['upsell'] : [];
    }

    /**
     * Remove a card entry completely.
     */
    public function remove_card(int $customer_id, int $card_id): void {
        $cards = $this->get_cards($customer_id);
        if (!isset($cards[$card_id])) {
            return;
        }
        unset($cards[$card_id]);
        update_user_meta($customer_id, 'ucb_customer_cards', $cards);
    }

    /**
     * Ensure legacy meta (single card) is migrated into the cards map.
     */
    public function ensure_legacy_migrated(int $customer_id): void {
        $cards = $this->get_cards($customer_id);
        if (!empty($cards)) {
            return;
        }
        $legacy_card = (int) get_user_meta($customer_id, 'ucb_customer_card_id', true);
        if ($legacy_card <= 0) {
            return;
        }
        $status = get_user_meta($customer_id, 'ucb_customer_status', true) ?: 'unassigned';
        $supervisor = (int) get_user_meta($customer_id, 'ucb_customer_assigned_supervisor', true);
        $agent = (int) get_user_meta($customer_id, 'ucb_customer_assigned_agent', true);
        $random_code = get_user_meta($customer_id, 'ucb_customer_random_code', true);
        $this->set_card($customer_id, $legacy_card, [
            'status' => sanitize_key($status),
            'supervisor_id' => $supervisor,
            'agent_id' => $agent,
            'random_code' => $random_code ? sanitize_text_field((string) $random_code) : null,
        ]);
    }

    /**
     * Normalize card meta array.
     *
     * @param array<string, mixed>|mixed $data
     * @return array<string, mixed>
     */
    private function sanitize_card_data($data): array {
        if (!is_array($data)) {
            return [];
        }
        $sanitized = [];
        if (isset($data['status'])) {
            $sanitized['status'] = sanitize_key($data['status']);
        }
        if (isset($data['supervisor_id'])) {
            $sanitized['supervisor_id'] = (int) $data['supervisor_id'];
        }
        if (isset($data['agent_id'])) {
            $sanitized['agent_id'] = (int) $data['agent_id'];
        }
        if (isset($data['submission_id'])) {
            $sanitized['submission_id'] = (int) $data['submission_id'];
        }
        if (isset($data['random_code'])) {
            $sanitized['random_code'] = sanitize_text_field((string) $data['random_code']);
        }
        if (isset($data['schedule']) && is_array($data['schedule'])) {
            $sanitized['schedule'] = [
                'date' => isset($data['schedule']['date']) && $data['schedule']['date'] !== '' ? sanitize_text_field((string) $data['schedule']['date']) : null,
                'time' => isset($data['schedule']['time']) && $data['schedule']['time'] !== '' ? sanitize_text_field((string) $data['schedule']['time']) : null,
            ];
        }
        if (isset($data['form_data']) && is_array($data['form_data'])) {
            $sanitized['form_data'] = $data['form_data'];
        }
        if (isset($data['upsell']) && is_array($data['upsell'])) {
            $sanitized['upsell'] = $data['upsell'];
        }
        return $sanitized;
    }
}
