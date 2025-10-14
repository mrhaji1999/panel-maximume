<?php
/**
 * Test Permissions
 * Test API endpoints after fixing permission callbacks
 */

// Include WordPress
require_once('../../../wp-load.php');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Permissions</h1>";

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";
echo "<p>Roles: <strong>" . implode(', ', $current_user->roles) . "</strong></p>";

// Test authentication methods
echo "<h2>Authentication Test</h2>";
echo "<p><strong>is_user_logged_in():</strong> " . (is_user_logged_in() ? 'TRUE' : 'FALSE') . "</p>";

$user_id = wp_validate_auth_cookie();
echo "<p><strong>wp_validate_auth_cookie():</strong> " . ($user_id ? $user_id : 'FALSE') . "</p>";

// Test API endpoints
echo "<h2>Testing API Endpoints</h2>";
$endpoints = [
    'dashboard/summary',
    'customers',
    'cards',
    'supervisors',
    'agents'
];

$success_count = 0;
$total_count = count($endpoints);

foreach ($endpoints as $endpoint) {
    $api_url = home_url('/wp-json/user-cards-bridge/v1/' . $endpoint);
    echo "<h3>Testing: {$endpoint}</h3>";
    echo "<p><strong>URL:</strong> {$api_url}</p>";
    
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'cookies' => $_COOKIE,
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'><strong>❌ WP Error:</strong> " . $response->get_error_message() . "</p>";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        echo "<p><strong>Status Code:</strong> {$status_code}</p>";
        
        if ($status_code === 200) {
            echo "<p style='color: green;'><strong>✅ Success!</strong></p>";
            $success_count++;
            
            if (isset($data['success']) && $data['success']) {
                echo "<p style='color: green;'>Response: Success</p>";
            } else {
                echo "<p style='color: orange;'>Response: " . ($data['message'] ?? 'No message') . "</p>";
            }
        } else {
            echo "<p style='color: red;'><strong>❌ Failed</strong></p>";
            if (isset($data['message'])) {
                echo "<p><strong>Error Message:</strong> " . $data['message'] . "</p>";
            }
            if (isset($data['code'])) {
                echo "<p><strong>Error Code:</strong> " . $data['code'] . "</p>";
            }
        }
    }
    
    echo "<hr>";
}

// Summary
echo "<h2>Test Summary</h2>";
echo "<p><strong>Successful:</strong> {$success_count}/{$total_count}</p>";
echo "<p><strong>Success Rate:</strong> " . round(($success_count / $total_count) * 100, 2) . "%</p>";

if ($success_count === $total_count) {
    echo "<p style='color: green; font-size: 18px;'><strong>🎉 All tests passed! The API is working correctly.</strong></p>";
} elseif ($success_count > 0) {
    echo "<p style='color: orange; font-size: 18px;'><strong>⚠️ Some tests passed. Progress made!</strong></p>";
} else {
    echo "<p style='color: red; font-size: 18px;'><strong>❌ All tests failed. Need more debugging.</strong></p>";
}

// Test user capabilities
echo "<h2>User Capabilities Test</h2>";
$capabilities = [
    'ucb_manage_all',
    'ucb_manage_supervisors',
    'ucb_manage_agents',
    'ucb_manage_customers',
    'ucb_send_sms'
];

foreach ($capabilities as $cap) {
    $has_cap = current_user_can($cap);
    echo "<p><strong>{$cap}:</strong> " . ($has_cap ? '✅ Yes' : '❌ No') . "</p>";
}

// Test user roles
echo "<h2>User Roles Test</h2>";
$roles = ['company_manager', 'supervisor', 'agent'];
foreach ($roles as $role) {
    $has_role = in_array($role, $current_user->roles);
    echo "<p><strong>{$role}:</strong> " . ($has_role ? '✅ Yes' : '❌ No') . "</p>";
}
?>
