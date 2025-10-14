<?php

namespace UCB;

/**
 * Structured logging helper.
 */
class Logger {
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * @var Database
     */
    protected $database;

    public function __construct() {
        $this->database = new Database();
        $this->database->ensure_logs_table();

        if (!self::$instance) {
            self::$instance = $this;
        }
    }

    /**
     * Retrieve the shared instance.
     */
    public static function get_instance(): self {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Write generic log entry.
     *
     * @return int Inserted log ID.
     */
    public static function log(string $level, string $message, array $context = [], ?int $user_id = null): int {
        return self::get_instance()->write_log($level, $message, $context, $user_id);
    }

    /**
     * Retrieve paginated logs.
     *
     * @return array{logs: array<int, object>, total: int, total_pages: int}
     */
    public static function get_logs(string $level = '', $user_id = '', int $page = 1, int $per_page = 20): array {
        $filters = [];

        if ($level !== '') {
            $filters['level'] = $level;
        }

        if ($user_id !== '' && $user_id !== null) {
            $filters['user_id'] = (int) $user_id;
        }

        $result = self::get_instance()->database->get_logs($filters, $page, $per_page);

        // Normalise context column to string for output consistency.
        foreach ($result['logs'] as $log) {
            if (isset($log->context) && is_array($log->context)) {
                $log->context = wp_json_encode($log->context);
            }
        }

        return $result;
    }

    /**
     * Aggregate log statistics.
     *
     * @return array{total_logs:int,status_changes:array<string,int>}
     */
    public static function get_log_statistics(int $days = 7): array {
        return self::get_instance()->database->get_log_statistics($days);
    }

    /**
     * Log customer status change.
     *
     * @param int         $customer_id
     * @param string|null $old_status
     * @param string      $new_status
     * @param int         $user_id
     * @param array       $meta
     */
    public function status_change(int $customer_id, ?string $old_status, string $new_status, int $user_id, array $meta = []): void {
        $reason = isset($meta['reason']) ? wp_kses_post($meta['reason']) : null;

        $this->database->log_status_change([
            'customer_id' => $customer_id,
            'old_status'  => $old_status,
            'new_status'  => $new_status,
            'changed_by'  => $user_id,
            'reason'      => $reason,
        ]);
    }

    /**
     * Persist SMS log.
     */
    public function sms(array $payload): void {
        $this->database->log_sms($payload);
    }

    /**
     * Cleanup logs based on retention policy.
     */
    public function cleanup_old_logs(): array {
        $retention = (int) get_option('ucb_log_retention_days', 30);
        if ($retention <= 0) {
            $retention = 30;
        }

        return $this->database->cleanup_logs($retention);
    }

    /**
     * Retrieve status logs for display.
     */
    public function get_status_logs(array $filters = [], int $page = 1, int $per_page = 20): array {
        return $this->database->get_status_logs($filters, $page, $per_page);
    }

    /**
     * Retrieve sms logs.
     */
    public function get_sms_logs(array $filters = [], int $page = 1, int $per_page = 20): array {
        return $this->database->get_sms_logs($filters, $page, $per_page);
    }

    /**
     * Persist the given message with context.
     */
    protected function write_log(string $level, string $message, array $context = [], ?int $user_id = null): int {
        $ip = $this->get_request_ip();

        return $this->database->insert_log([
            'level'   => strtolower($level),
            'message' => $message,
            'context' => $context,
            'user_id' => $user_id ?? get_current_user_id() ?: null,
            'ip'      => $ip,
        ]);
    }

    /**
     * Detect request IP if available.
     */
    protected function get_request_ip(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return (string) $_SERVER['REMOTE_ADDR'];
        }

        return '';
    }
}
