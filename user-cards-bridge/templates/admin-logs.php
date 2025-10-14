<?php
/**
 * Admin Logs Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get log parameters
$page = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
$per_page = 20;
$level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : '';

// Get logs
$logs = \UCB\Logger::get_logs($level, $user_id, $page, $per_page);
?>

<div class="wrap">
    <h1><?php _e('User Cards Bridge Logs', UCB_TEXT_DOMAIN); ?></h1>
    
    <!-- Filters -->
    <div class="ucb-log-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="user-cards-bridge-logs">
            
            <select name="level">
                <option value=""><?php _e('All Levels', UCB_TEXT_DOMAIN); ?></option>
                <option value="info" <?php selected($level, 'info'); ?>><?php _e('Info', UCB_TEXT_DOMAIN); ?></option>
                <option value="warning" <?php selected($level, 'warning'); ?>><?php _e('Warning', UCB_TEXT_DOMAIN); ?></option>
                <option value="error" <?php selected($level, 'error'); ?>><?php _e('Error', UCB_TEXT_DOMAIN); ?></option>
            </select>
            
            <select name="user_id">
                <option value=""><?php _e('All Users', UCB_TEXT_DOMAIN); ?></option>
                <?php
                $users = get_users(['fields' => ['ID', 'display_name']]);
                foreach ($users as $user) {
                    echo '<option value="' . $user->ID . '" ' . selected($user_id, $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
                }
                ?>
            </select>
            
            <input type="submit" class="button" value="<?php _e('Filter', UCB_TEXT_DOMAIN); ?>">
            <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-logs'); ?>" class="button"><?php _e('Clear Filters', UCB_TEXT_DOMAIN); ?></a>
        </form>
    </div>
    
    <!-- Logs Table -->
    <div class="ucb-logs-container">
        <?php if (empty($logs['logs'])): ?>
            <p><?php _e('No logs found.', UCB_TEXT_DOMAIN); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Level', UCB_TEXT_DOMAIN); ?></th>
                        <th><?php _e('Message', UCB_TEXT_DOMAIN); ?></th>
                        <th><?php _e('User', UCB_TEXT_DOMAIN); ?></th>
                        <th><?php _e('IP', UCB_TEXT_DOMAIN); ?></th>
                        <th><?php _e('Date', UCB_TEXT_DOMAIN); ?></th>
                        <th><?php _e('Actions', UCB_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs['logs'] as $log): ?>
                        <tr>
                            <td>
                                <span class="ucb-log-level ucb-log-level-<?php echo esc_attr($log->level); ?>">
                                    <?php echo esc_html(ucfirst($log->level)); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo esc_html($log->message); ?></strong>
                                <?php if ($log->context): ?>
                                    <button type="button" class="button button-small ucb-view-context" 
                                            data-context="<?php echo esc_attr($log->context); ?>">
                                        <?php _e('View Context', UCB_TEXT_DOMAIN); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log->user_id): ?>
                                    <?php
                                    $user = get_user_by('id', $log->user_id);
                                    echo $user ? esc_html($user->display_name) : __('Unknown', UCB_TEXT_DOMAIN);
                                    ?>
                                <?php else: ?>
                                    <?php _e('System', UCB_TEXT_DOMAIN); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($log->ip); ?></td>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td>
                                <button type="button" class="button button-small ucb-delete-log" 
                                        data-log-id="<?php echo $log->id; ?>">
                                    <?php _e('Delete', UCB_TEXT_DOMAIN); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($logs['total_pages'] > 1): ?>
                <div class="ucb-pagination">
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $logs['total_pages'],
                        'current' => $page,
                    ];
                    
                    if ($level) {
                        $pagination_args['add_args'] = ['level' => $level];
                    }
                    if ($user_id) {
                        $pagination_args['add_args']['user_id'] = $user_id;
                    }
                    
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Bulk Actions -->
    <div class="ucb-bulk-actions">
        <button type="button" class="button" id="ucb-cleanup-old-logs">
            <?php _e('Cleanup Old Logs', UCB_TEXT_DOMAIN); ?>
        </button>
        <button type="button" class="button" id="ucb-export-logs">
            <?php _e('Export Logs', UCB_TEXT_DOMAIN); ?>
        </button>
    </div>
</div>

<!-- Context Modal -->
<div id="ucb-context-modal" class="ucb-modal" style="display: none;">
    <div class="ucb-modal-content">
        <div class="ucb-modal-header">
            <h3><?php _e('Log Context', UCB_TEXT_DOMAIN); ?></h3>
            <span class="ucb-modal-close">&times;</span>
        </div>
        <div class="ucb-modal-body">
            <pre id="ucb-context-content"></pre>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // View context
    $('.ucb-view-context').on('click', function() {
        var context = $(this).data('context');
        $('#ucb-context-content').text(context);
        $('#ucb-context-modal').show();
    });
    
    // Close modal
    $('.ucb-modal-close, .ucb-modal').on('click', function(e) {
        if (e.target === this) {
            $('#ucb-context-modal').hide();
        }
    });
    
    // Delete log
    $('.ucb-delete-log').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to delete this log entry?', UCB_TEXT_DOMAIN); ?>')) {
            var logId = $(this).data('log-id');
            var row = $(this).closest('tr');
            
            $.post(ajaxurl, {
                action: 'ucb_delete_log',
                log_id: logId,
                nonce: ucbAdmin.nonce
            }, function(response) {
                if (response.success) {
                    row.fadeOut();
                } else {
                    alert('<?php _e('Failed to delete log entry: ', UCB_TEXT_DOMAIN); ?>' + response.data.message);
                }
            });
        }
    });
    
    // Cleanup old logs
    $('#ucb-cleanup-old-logs').on('click', function() {
        if (confirm('<?php _e('This will delete old log entries. Continue?', UCB_TEXT_DOMAIN); ?>')) {
            var button = $(this);
            button.prop('disabled', true).text('<?php _e('Cleaning up...', UCB_TEXT_DOMAIN); ?>');
            
            $.post(ajaxurl, {
                action: 'ucb_cleanup_logs',
                nonce: ucbAdmin.nonce
            }, function(response) {
                button.prop('disabled', false).text('<?php _e('Cleanup Old Logs', UCB_TEXT_DOMAIN); ?>');
                
                if (response.success) {
                    alert('<?php _e('Logs cleaned up successfully!', UCB_TEXT_DOMAIN); ?>');
                    location.reload();
                } else {
                    alert('<?php _e('Log cleanup failed: ', UCB_TEXT_DOMAIN); ?>' + response.data.message);
                }
            });
        }
    });
    
    // Export logs
    $('#ucb-export-logs').on('click', function() {
        var params = new URLSearchParams(window.location.search);
        params.set('action', 'ucb_export_logs');
        params.set('nonce', ucbAdmin.nonce);
        
        window.location.href = ajaxurl + '?' + params.toString();
    });
});
</script>

<style>
.ucb-log-filters {
    background: #fff;
    padding: 15px;
    margin: 20px 0;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.ucb-log-filters select,
.ucb-log-filters input[type="submit"] {
    margin-right: 10px;
}

.ucb-log-level {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.ucb-log-level-info {
    background: #d1ecf1;
    color: #0c5460;
}

.ucb-log-level-warning {
    background: #fff3cd;
    color: #856404;
}

.ucb-log-level-error {
    background: #f8d7da;
    color: #721c24;
}

.ucb-pagination {
    margin: 20px 0;
    text-align: center;
}

.ucb-bulk-actions {
    margin: 20px 0;
}

.ucb-modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.ucb-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    width: 80%;
    max-width: 600px;
    border-radius: 4px;
}

.ucb-modal-header {
    padding: 15px;
    background: #f1f1f1;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ucb-modal-close {
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
}

.ucb-modal-body {
    padding: 15px;
    max-height: 400px;
    overflow-y: auto;
}

.ucb-modal-body pre {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 3px;
    overflow-x: auto;
}
</style>
