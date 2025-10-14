<?php
/**
 * Admin Settings Template
 */

use UCB\Utils\Cors;

if (!defined('ABSPATH')) {
    exit;
}

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['ucb_settings_nonce'], 'ucb_settings')) {
    // SMS Settings
    update_option('ucb_sms_username', sanitize_text_field($_POST['ucb_sms_username']));
    update_option('ucb_sms_password', sanitize_text_field($_POST['ucb_sms_password']));
    update_option('ucb_sms_normal_body_id', sanitize_text_field($_POST['ucb_sms_normal_body_id']));
    update_option('ucb_sms_upsell_body_id', sanitize_text_field($_POST['ucb_sms_upsell_body_id']));
    
    // CORS Settings
    $cors_origins = Cors::sanitize_origins($_POST['ucb_cors_origins'] ?? []);
    update_option('ucb_cors_allowed_origins', $cors_origins);
    
    // Other Settings
    update_option('ucb_payment_token_expiry', (int) $_POST['ucb_payment_token_expiry']);
    update_option('ucb_log_retention_days', (int) $_POST['ucb_log_retention_days']);
    
    // Webhook secret
    if (!empty($_POST['ucb_webhook_secret'])) {
        update_option('ucb_webhook_secret', sanitize_text_field($_POST['ucb_webhook_secret']));
    }
    
    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', UCB_TEXT_DOMAIN) . '</p></div>';
}

// Get current settings
$sms_username = get_option('ucb_sms_username', '');
$sms_password = get_option('ucb_sms_password', '');
$sms_normal_body_id = get_option('ucb_sms_normal_body_id', '');
$sms_upsell_body_id = get_option('ucb_sms_upsell_body_id', '');
$cors_origins = Cors::sanitize_origins((array) get_option('ucb_cors_allowed_origins', []));
$payment_token_expiry = get_option('ucb_payment_token_expiry', 24);
$log_retention_days = get_option('ucb_log_retention_days', 30);
$webhook_secret = get_option('ucb_webhook_secret', '');
?>

<div class="wrap">
    <h1><?php _e('User Cards Bridge Settings', UCB_TEXT_DOMAIN); ?></h1>
    
    <div class="ucb-settings-tabs">
        <nav class="nav-tab-wrapper">
            <a href="#sms-settings" class="nav-tab nav-tab-active"><?php _e('SMS Settings', UCB_TEXT_DOMAIN); ?></a>
            <a href="#api-settings" class="nav-tab"><?php _e('API Settings', UCB_TEXT_DOMAIN); ?></a>
            <a href="#security-settings" class="nav-tab"><?php _e('Security Settings', UCB_TEXT_DOMAIN); ?></a>
            <a href="#api-docs" class="nav-tab"><?php _e('API Documentation', UCB_TEXT_DOMAIN); ?></a>
        </nav>
        
        <form method="post" action="">
            <?php wp_nonce_field('ucb_settings', 'ucb_settings_nonce'); ?>
            
            <!-- SMS Settings Tab -->
            <div id="sms-settings" class="tab-content active">
                <h2><?php _e('SMS Configuration', UCB_TEXT_DOMAIN); ?></h2>
                <p><?php _e('Configure SMS settings for Payamak Panel integration.', UCB_TEXT_DOMAIN); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ucb_sms_username"><?php _e('Username', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ucb_sms_username" name="ucb_sms_username" 
                                   value="<?php echo esc_attr($sms_username); ?>" class="regular-text" required>
                            <p class="description"><?php _e('Your Payamak Panel username.', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ucb_sms_password"><?php _e('Password', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ucb_sms_password" name="ucb_sms_password" 
                                   value="<?php echo esc_attr($sms_password); ?>" class="regular-text" required>
                            <p class="description"><?php _e('Your Payamak Panel password.', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ucb_sms_normal_body_id"><?php _e('Normal Status Body ID', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ucb_sms_normal_body_id" name="ucb_sms_normal_body_id" 
                                   value="<?php echo esc_attr($sms_normal_body_id); ?>" class="regular-text" required>
                            <p class="description"><?php _e('Body ID for normal status SMS messages.', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ucb_sms_upsell_body_id"><?php _e('Upsell Payment Body ID', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ucb_sms_upsell_body_id" name="ucb_sms_upsell_body_id" 
                                   value="<?php echo esc_attr($sms_upsell_body_id); ?>" class="regular-text" required>
                            <p class="description"><?php _e('Body ID for upsell payment SMS messages.', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div class="ucb-test-sms">
                    <button type="button" id="test-sms-config" class="button">
                        <?php _e('Test SMS Configuration', UCB_TEXT_DOMAIN); ?>
                    </button>
                    <div id="sms-test-result"></div>
                </div>
            </div>
            
            <!-- API Settings Tab -->
            <div id="api-settings" class="tab-content">
                <h2><?php _e('API Configuration', UCB_TEXT_DOMAIN); ?></h2>
                <p><?php _e('Configure API settings and CORS.', UCB_TEXT_DOMAIN); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ucb_payment_token_expiry"><?php _e('Payment Token Expiry (hours)', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ucb_payment_token_expiry" name="ucb_payment_token_expiry" 
                                   value="<?php echo esc_attr($payment_token_expiry); ?>" min="1" max="168" class="small-text">
                            <p class="description"><?php _e('How long payment tokens remain valid (1-168 hours).', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ucb_log_retention_days"><?php _e('Log Retention (days)', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ucb_log_retention_days" name="ucb_log_retention_days" 
                                   value="<?php echo esc_attr($log_retention_days); ?>" min="1" max="365" class="small-text">
                            <p class="description"><?php _e('How long to keep logs (1-365 days).', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Security Settings Tab -->
            <div id="security-settings" class="tab-content">
                <h2><?php _e('Security Configuration', UCB_TEXT_DOMAIN); ?></h2>
                <p><?php _e('Configure security settings for API access.', UCB_TEXT_DOMAIN); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ucb_cors_origins"><?php _e('CORS Allowed Origins', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <div id="cors-origins-container">
                                <?php foreach ($cors_origins as $index => $origin): ?>
                                    <div class="cors-origin-row">
                                        <input type="url" name="ucb_cors_origins[]" value="<?php echo esc_attr($origin); ?>" 
                                               class="regular-text" placeholder="https://example.com">
                                        <button type="button" class="button remove-cors-origin"><?php _e('Remove', UCB_TEXT_DOMAIN); ?></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-cors-origin" class="button"><?php _e('Add Origin', UCB_TEXT_DOMAIN); ?></button>
                            <p class="description"><?php _e('Allowed origins for CORS requests. Leave empty to disable CORS.', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ucb_webhook_secret"><?php _e('Webhook Secret', UCB_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ucb_webhook_secret" name="ucb_webhook_secret" 
                                   value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text">
                            <button type="button" id="generate-webhook-secret" class="button"><?php _e('Generate', UCB_TEXT_DOMAIN); ?></button>
                            <p class="description"><?php _e('Secret key for webhook verification. Leave empty to disable verification.', UCB_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- API Documentation Tab -->
            <div id="api-docs" class="tab-content">
                <h2><?php _e('API Documentation', UCB_TEXT_DOMAIN); ?></h2>
                <p><?php _e('Complete API documentation for external panel integration.', UCB_TEXT_DOMAIN); ?></p>
                
                <div class="ucb-api-docs">
                    <h3><?php _e('Base URL', UCB_TEXT_DOMAIN); ?></h3>
                    <code><?php echo rest_url('user-cards-bridge/v1/'); ?></code>
                    
                    <h3><?php _e('Authentication', UCB_TEXT_DOMAIN); ?></h3>
                    <p><?php _e('All API requests (except public endpoints) require JWT authentication via Authorization header:', UCB_TEXT_DOMAIN); ?></p>
                    <code>Authorization: Bearer YOUR_JWT_TOKEN</code>
                    
                    <h3><?php _e('Available Endpoints', UCB_TEXT_DOMAIN); ?></h3>
                    <div class="ucb-endpoints-list">
                        <h4><?php _e('Authentication', UCB_TEXT_DOMAIN); ?></h4>
                        <ul>
                            <li><code>POST /auth/login</code> - <?php _e('Login and get JWT token', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /auth/register</code> - <?php _e('Register new user', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /auth/refresh</code> - <?php _e('Refresh JWT token', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /auth/logout</code> - <?php _e('Logout', UCB_TEXT_DOMAIN); ?></li>
                        </ul>
                        
                        <h4><?php _e('Users', UCB_TEXT_DOMAIN); ?></h4>
                        <ul>
                            <li><code>GET /managers</code> - <?php _e('Get managers', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>GET /supervisors</code> - <?php _e('Get supervisors', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>GET /agents</code> - <?php _e('Get agents', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /agents</code> - <?php _e('Create agent', UCB_TEXT_DOMAIN); ?></li>
                        </ul>
                        
                        <h4><?php _e('Customers', UCB_TEXT_DOMAIN); ?></h4>
                        <ul>
                            <li><code>GET /customers</code> - <?php _e('Get customers with filters', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>GET /customers/{id}</code> - <?php _e('Get single customer', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>PATCH /customers/{id}/status</code> - <?php _e('Update customer status', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /customers/{id}/notes</code> - <?php _e('Add customer note', UCB_TEXT_DOMAIN); ?></li>
                        </ul>
                        
                        <h4><?php _e('Cards', UCB_TEXT_DOMAIN); ?></h4>
                        <ul>
                            <li><code>GET /cards</code> - <?php _e('Get cards', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>GET /cards/{id}/fields</code> - <?php _e('Get card fields for upsell', UCB_TEXT_DOMAIN); ?></li>
                        </ul>
                        
                        <h4><?php _e('Schedule & Reservations', UCB_TEXT_DOMAIN); ?></h4>
                        <ul>
                            <li><code>GET /schedule/{supervisor_id}/{card_id}</code> - <?php _e('Get schedule matrix', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>PUT /schedule/{supervisor_id}/{card_id}</code> - <?php _e('Update schedule matrix', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>GET /availability/{card_id}</code> - <?php _e('Get available slots', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /reservations</code> - <?php _e('Create reservation', UCB_TEXT_DOMAIN); ?></li>
                        </ul>
                        
                        <h4><?php _e('Upsell & SMS', UCB_TEXT_DOMAIN); ?></h4>
                        <ul>
                            <li><code>POST /customers/{id}/upsell/init</code> - <?php _e('Initialize upsell process', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /customers/{id}/normal/send-code</code> - <?php _e('Send normal status code', UCB_TEXT_DOMAIN); ?></li>
                            <li><code>POST /sms/send</code> - <?php _e('Send SMS', UCB_TEXT_DOMAIN); ?></li>
                        </ul>
                    </div>
                    
                    <h3><?php _e('Response Format', UCB_TEXT_DOMAIN); ?></h3>
                    <p><?php _e('All API responses follow this format:', UCB_TEXT_DOMAIN); ?></p>
                    <pre><code>{
    "success": true,
    "data": {
        // Response data
    },
    "error": null
}</code></pre>
                    
                    <h3><?php _e('Error Handling', UCB_TEXT_DOMAIN); ?></h3>
                    <p><?php _e('Errors are returned with appropriate HTTP status codes and error messages:', UCB_TEXT_DOMAIN); ?></p>
                    <pre><code>{
    "success": false,
    "data": null,
    "error": {
        "code": "error_code",
        "message": "Error message",
        "status": 400
    }
}</code></pre>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="<?php _e('Save Settings', UCB_TEXT_DOMAIN); ?>">
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        $(this).addClass('nav-tab-active');
        $($(this).attr('href')).addClass('active');
    });
    
    // CORS origins management
    $('#add-cors-origin').on('click', function() {
        var row = '<div class="cors-origin-row">' +
                  '<input type="url" name="ucb_cors_origins[]" value="" class="regular-text" placeholder="https://example.com">' +
                  '<button type="button" class="button remove-cors-origin"><?php _e('Remove', UCB_TEXT_DOMAIN); ?></button>' +
                  '</div>';
        $('#cors-origins-container').append(row);
    });
    
    $(document).on('click', '.remove-cors-origin', function() {
        $(this).parent().remove();
    });
    
    // Generate webhook secret
    $('#generate-webhook-secret').on('click', function() {
        var secret = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
        $('#ucb_webhook_secret').val(secret);
    });
    
    // Test SMS configuration
    $('#test-sms-config').on('click', function() {
        var button = $(this);
        var result = $('#sms-test-result');
        
        button.prop('disabled', true).text('<?php _e('Testing...', UCB_TEXT_DOMAIN); ?>');
        result.html('');
        
        $.post(ajaxurl, {
            action: 'ucb_test_sms',
            nonce: ucbAdmin.nonce
        }, function(response) {
            button.prop('disabled', false).text('<?php _e('Test SMS Configuration', UCB_TEXT_DOMAIN); ?>');
            
            if (response.success) {
                result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
        });
    });
});
</script>
