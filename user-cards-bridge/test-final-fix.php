<?php
/**
 * Test Final Fix
 * Test API endpoints after fixing the REST server error
 */

// Include WordPress
require_once('../../../wp-load.php');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Final Fix</h1>";

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";
echo "<p>Roles: <strong>" . implode(', ', $current_user->roles) . "</strong></p>";

// Test all API endpoints
$endpoints = [
    'dashboard/summary',
    'customers',
    'cards',
    'supervisors',
    'agents',
    'forms',
    'reservations',
    'sms/logs',
    'sms/statistics'
];

$success_count = 0;
$total_count = count($endpoints);

echo "<h2>Testing API Endpoints</h2>";

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

// Test JWT plugin status
echo "<h2>JWT Plugin Status</h2>";
if (class_exists('JWT_Auth_Public')) {
    echo "<p style='color: green;'><strong>✅ JWT Plugin is active</strong></p>";
    
    // Test JWT endpoint
    $jwt_url = home_url('/wp-json/jwt-auth/v1/token/validate');
    echo "<h3>Testing JWT Endpoint</h3>";
    echo "<p><strong>URL:</strong> {$jwt_url}</p>";
    
    $jwt_response = wp_remote_get($jwt_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ]);
    
    if (is_wp_error($jwt_response)) {
        echo "<p style='color: red;'><strong>❌ JWT Error:</strong> " . $jwt_response->get_error_message() . "</p>";
    } else {
        $jwt_status = wp_remote_retrieve_response_code($jwt_response);
        echo "<p><strong>JWT Status Code:</strong> {$jwt_status}</p>";
        
        if ($jwt_status === 200) {
            echo "<p style='color: green;'><strong>✅ JWT Plugin is working</strong></p>";
        } else {
            echo "<p style='color: orange;'><strong>⚠️ JWT Plugin response: {$jwt_status}</strong></p>";
        }
    }
} else {
    echo "<p style='color: red;'><strong>❌ JWT Plugin is not active</strong></p>";
}
?>
