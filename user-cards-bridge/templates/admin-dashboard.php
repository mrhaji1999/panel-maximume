<?php
/**
 * Admin Dashboard Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$user_role = $current_user->roles[0] ?? '';

// Get statistics
$stats_service = new UCB\Services\StatsService();
$stats = $stats_service->get_summary($current_user->ID);
?>

<div class="wrap">
    <h1><?php _e('User Cards Bridge Dashboard', UCB_TEXT_DOMAIN); ?></h1>
    
    <div class="ucb-dashboard">
        <!-- Welcome Section -->
        <div class="ucb-welcome">
            <h2><?php printf(__('Welcome, %s!', UCB_TEXT_DOMAIN), $current_user->display_name); ?></h2>
            <p><?php printf(__('You are logged in as: %s', UCB_TEXT_DOMAIN), ucfirst($user_role)); ?></p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="ucb-stats-grid">
            <div class="ucb-stat-card">
                <h3><?php _e('Total Customers', UCB_TEXT_DOMAIN); ?></h3>
                <div class="ucb-stat-number"><?php echo number_format($stats['counts']['customers']); ?></div>
            </div>
            
            <div class="ucb-stat-card">
                <h3><?php _e('Active Supervisors', UCB_TEXT_DOMAIN); ?></h3>
                <div class="ucb-stat-number"><?php echo number_format($stats['counts']['supervisors']); ?></div>
            </div>
            
            <div class="ucb-stat-card">
                <h3><?php _e('Active Agents', UCB_TEXT_DOMAIN); ?></h3>
                <div class="ucb-stat-number"><?php echo number_format($stats['counts']['agents']); ?></div>
            </div>
            
            <div class="ucb-stat-card">
                <h3><?php _e('Today\'s Reservations', UCB_TEXT_DOMAIN); ?></h3>
                <div class="ucb-stat-number"><?php echo number_format($stats['counts']['reservations_today']); ?></div>
            </div>
        </div>
        
        <!-- Role-specific Content -->
        <?php if ($user_role === 'company_manager'): ?>
            <div class="ucb-manager-dashboard">
                <h2><?php _e('Management Overview', UCB_TEXT_DOMAIN); ?></h2>
                
                <div class="ucb-dashboard-sections">
                    <!-- Supervisors and Cards -->
                    <div class="ucb-section">
                        <h3><?php _e('Supervisors & Cards', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('Manage supervisors and their assigned cards.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-supervisors'); ?>" class="button button-primary">
                            <?php _e('Manage Supervisors', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                    
                    <!-- Agents Management -->
                    <div class="ucb-section">
                        <h3><?php _e('Agents', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('View and manage all agents.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-agents'); ?>" class="button button-primary">
                            <?php _e('Manage Agents', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                    
                    <!-- Form Submissions -->
                    <div class="ucb-section">
                        <h3><?php _e('Form Submissions', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('View all form submissions from the main site.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-forms'); ?>" class="button button-primary">
                            <?php _e('View Forms', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                </div>
            </div>
            
        <?php elseif ($user_role === 'supervisor'): ?>
            <div class="ucb-supervisor-dashboard">
                <h2><?php _e('Supervisor Dashboard', UCB_TEXT_DOMAIN); ?></h2>
                
                <div class="ucb-dashboard-sections">
                    <!-- My Agents -->
                    <div class="ucb-section">
                        <h3><?php _e('My Agents', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('Manage agents assigned to you.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-my-agents'); ?>" class="button button-primary">
                            <?php _e('Manage Agents', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                    
                    <!-- Schedule Management -->
                    <div class="ucb-section">
                        <h3><?php _e('Schedule Management', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('Set capacity and availability for your cards.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-schedule'); ?>" class="button button-primary">
                            <?php _e('Manage Schedule', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                    
                    <!-- My Customers -->
                    <div class="ucb-section">
                        <h3><?php _e('My Customers', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('View customers assigned to your cards.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-my-customers'); ?>" class="button button-primary">
                            <?php _e('View Customers', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                </div>
            </div>
            
        <?php elseif ($user_role === 'agent'): ?>
            <div class="ucb-agent-dashboard">
                <h2><?php _e('Agent Dashboard', UCB_TEXT_DOMAIN); ?></h2>
                
                <div class="ucb-dashboard-sections">
                    <!-- My Customers -->
                    <div class="ucb-section">
                        <h3><?php _e('My Customers', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('Manage customers assigned to you.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-my-customers'); ?>" class="button button-primary">
                            <?php _e('View Customers', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                    
                    <!-- Customer Status Management -->
                    <div class="ucb-section">
                        <h3><?php _e('Status Management', UCB_TEXT_DOMAIN); ?></h3>
                        <p><?php _e('Update customer statuses and add notes.', UCB_TEXT_DOMAIN); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-customer-status'); ?>" class="button button-primary">
                            <?php _e('Manage Statuses', UCB_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Recent Activity -->
        <div class="ucb-recent-activity">
            <h2><?php _e('Recent Activity', UCB_TEXT_DOMAIN); ?></h2>
            <div class="ucb-activity-list">
                <?php if (!empty($stats['recent_activity'])): ?>
                    <?php foreach ($stats['recent_activity'] as $log): ?>
                        <div class="ucb-activity-item">
                            <div class="ucb-activity-time"><?php echo date('Y-m-d H:i:s', strtotime($log->created_at)); ?></div>
                            <div class="ucb-activity-message"><?php echo esc_html($log->message); ?></div>
                            <?php if ($log->context): ?>
                                <div class="ucb-activity-context"><?php echo esc_html($log->context); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php _e('No recent activity found.', UCB_TEXT_DOMAIN); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="ucb-quick-actions">
            <h2><?php _e('Quick Actions', UCB_TEXT_DOMAIN); ?></h2>
            <div class="ucb-action-buttons">
                <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-settings'); ?>" class="button">
                    <?php _e('Settings', UCB_TEXT_DOMAIN); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=user-cards-bridge-logs'); ?>" class="button">
                    <?php _e('View Logs', UCB_TEXT_DOMAIN); ?>
                </a>
                <a href="<?php echo rest_url('user-cards-bridge/v1/'); ?>" class="button" target="_blank">
                    <?php _e('API Documentation', UCB_TEXT_DOMAIN); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.ucb-dashboard {
    max-width: 1200px;
}

.ucb-welcome {
    background: #f1f1f1;
    padding: 20px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.ucb-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.ucb-stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ucb-stat-card h3 {
    margin: 0 0 10px 0;
    color: #666;
    font-size: 14px;
    text-transform: uppercase;
}

.ucb-stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #0073aa;
}

.ucb-dashboard-sections {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.ucb-section {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ucb-section h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.ucb-section p {
    margin: 0 0 15px 0;
    color: #666;
}

.ucb-recent-activity {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ucb-activity-list {
    max-height: 300px;
    overflow-y: auto;
}

.ucb-activity-item {
    border-bottom: 1px solid #eee;
    padding: 10px 0;
}

.ucb-activity-item:last-child {
    border-bottom: none;
}

.ucb-activity-time {
    font-size: 12px;
    color: #999;
    margin-bottom: 5px;
}

.ucb-activity-message {
    font-weight: 500;
    margin-bottom: 5px;
}

.ucb-activity-context {
    font-size: 12px;
    color: #666;
    font-style: italic;
}

.ucb-quick-actions {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ucb-action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
</style>