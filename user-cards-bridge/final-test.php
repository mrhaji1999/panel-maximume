<?php
/**
 * Final API Test
 * Test all API endpoints after fixes
 */

// Include WordPress
require_once('../../../wp-load.php');

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<h1>Final API Test</h1>";
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";
echo "<p>Roles: <strong>" . implode(', ', $current_user->roles) . "</strong></p>";

// Test all API endpoints
$endpoints = [
    'dashboard/summary' => 'GET',
    'customers' => 'GET',
    'cards' => 'GET',
    'supervisors' => 'GET',
    'agents' => 'GET',
    'forms' => 'GET',
    'reservations' => 'GET',
    'sms/logs' => 'GET',
    'sms/statistics' => 'GET'
];

$success_count = 0;
$total_count = count($endpoints);

foreach ($endpoints as $endpoint => $method) {
    $api_url = home_url('/wp-json/user-cards-bridge/v1/' . $endpoint);
    echo "<h2>Testing: {$endpoint} ({$method})</h2>";
    
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'cookies' => $_COOKIE
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'><strong>❌ Error:</strong> " . $response->get_error_message() . "</p>";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200) {
            echo "<p style='color: green;'><strong>✅ Success (200)</strong></p>";
            $success_count++;
        } else {
            echo "<p style='color: red;'><strong>❌ Failed ({$status_code})</strong></p>";
        }
        
        // Show response for debugging
        if (isset($data['success']) && $data['success']) {
            echo "<p style='color: green;'>Response: Success</p>";
        } else {
            echo "<p style='color: red;'>Response: " . ($data['message'] ?? 'Unknown error') . "</p>";
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
} else {
    echo "<p style='color: orange; font-size: 18px;'><strong>⚠️ Some tests failed. Check the individual results above.</strong></p>";
}

// Test JWT as well
echo "<h2>JWT Test</h2>";
use UCB\JWT\JWTHandler;

$jwt_handler = new JWTHandler();
$token = $jwt_handler->create_token($current_user->ID, $current_user->user_login, $current_user->roles);

if ($token) {
    echo "<p style='color: green;'><strong>✅ JWT Token Generated</strong></p>";
    
    // Test one endpoint with JWT
    $api_url = home_url('/wp-json/user-cards-bridge/v1/dashboard/summary');
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ]
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'><strong>❌ JWT Test Error:</strong> " . $response->get_error_message() . "</p>";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            echo "<p style='color: green;'><strong>✅ JWT Authentication Works</strong></p>";
        } else {
            echo "<p style='color: red;'><strong>❌ JWT Authentication Failed ({$status_code})</strong></p>";
        }
    }
} else {
    echo "<p style='color: red;'><strong>❌ JWT Token Generation Failed</strong></p>";
}
?>
