<?php

namespace UCB\Services;

use UCB\Database;
use UCB\Roles;
use UCB\SMS\PayamakPanel;
use UCB\WooCommerce\Integration;
use UCB\Logger;

class StatsService {
    protected CustomerService $customers;
    protected UserService $users;
    protected StatusManager $statuses;
    protected Database $database;

    public function __construct() {
        $this->customers = new CustomerService();
        $this->users = new UserService();
        $this->statuses = new StatusManager();
        $this->database = new Database();
    }

    /**
     * Build dashboard summary for the given user.
     *
     * @return array<string, mixed>
     */
    public function get_summary(int $user_id, int $status_days = 30, int $activity_limit = 10): array {
        $role = Roles::get_user_role($user_id);
        $today = current_time('Y-m-d');

        $counts = [
            'supervisors' => 0,
            'agents' => 0,
            'customers' => 0,
            'reservations_today' => 0,
        ];

        $status_filters = [];
        $status_counts = [];

        switch ($role) {
            case 'supervisor':
                $counts['supervisors'] = 1;
                $counts['agents'] = $this->users->list_agents(['supervisor_id' => $user_id], 1, 1)['total'];
                $counts['customers'] = $this->customers->count_customers(['supervisor_id' => $user_id]);
                $counts['reservations_today'] = $this->database->count_reservations([
                    'supervisor_id' => $user_id,
                    'date' => $today,
                ]);
                $status_filters['supervisor_id'] = $user_id;
                break;

            case 'agent':
                $counts['supervisors'] = $this->determine_agent_supervisor_count($user_id);
                $counts['agents'] = 1;
                $counts['customers'] = $this->customers->count_customers(['agent_id' => $user_id]);
                $counts['reservations_today'] = 0;
                $status_filters['agent_id'] = $user_id;
                break;

            case 'company_manager':
            default:
                $counts['supervisors'] = $this->users->list_supervisors([], 1, 1)['total'];
                $counts['agents'] = $this->users->list_agents([], 1, 1)['total'];
                $counts['customers'] = $this->customers->count_customers([]);
                $counts['reservations_today'] = $this->database->count_reservations([
                    'date' => $today,
                ]);
                break;
        }

        $status_counts = $this->customers->get_status_counts($status_filters, $user_id);

        $summary = [
            'counts' => $counts,
            'status_counts' => $status_counts,
            'sms' => null,
            'upsell' => null,
            'logs' => null,
            'recent_activity' => [],
        ];

        if ('company_manager' === $role) {
            $summary['sms'] = PayamakPanel::get_statistics($status_days);
            $summary['upsell'] = Integration::get_upsell_statistics($status_days);
            $summary['logs'] = Logger::get_log_statistics($status_days);
            $summary['recent_activity'] = Logger::get_logs('', '', 1, $activity_limit)['logs'];
        } elseif ('supervisor' === $role) {
            $summary['recent_activity'] = Logger::get_logs('', '', 1, $activity_limit)['logs'];
        }

        return $summary;
    }

    /**
     * Determine supervisor count for agent role.
     */
    protected function determine_agent_supervisor_count(int $agent_id): int {
        $supervisor_id = (int) get_user_meta($agent_id, 'ucb_agent_supervisor_id', true);

        return $supervisor_id > 0 ? 1 : 0;
    }
}
