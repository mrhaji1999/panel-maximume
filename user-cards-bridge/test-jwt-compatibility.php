<?php
/**
 * Test JWT Compatibility
 * Test API endpoints with JWT plugin compatibility
 */

// Include WordPress
require_once('../../../wp-load.php');

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<h1>JWT Compatibility Test</h1>";
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";
echo "<p>Roles: <strong>" . implode(', ', $current_user->roles) . "</strong></p>";

// Check JWT plugin status
echo "<h2>JWT Plugin Status</h2>";
if (class_exists('JWT_Auth_Public')) {
    echo "<p style='color: green;'><strong>✅ JWT Plugin is active</strong></p>";
} else {
    echo "<p style='color: red;'><strong>❌ JWT Plugin is not active</strong></p>";
}

// Test our API endpoints
echo "<h2>Testing Our API Endpoints</h2>";
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
        
        if (isset($data['message'])) {
            echo "<p><strong>Message:</strong> " . $data['message'] . "</p>";
        }
    }
    
    echo "<hr>";
}

// Summary
echo "<h2>Test Summary</h2>";
echo "<p><strong>Successful:</strong> {$success_count}/{$total_count}</p>";
echo "<p><strong>Success Rate:</strong> " . round(($success_count / $total_count) * 100, 2) . "%</p>";

if ($success_count === $total_count) {
    echo "<p style='color: green; font-size: 18px;'><strong>🎉 All tests passed! The API is working correctly with JWT plugin.</strong></p>";
} else {
    echo "<p style='color: orange; font-size: 18px;'><strong>⚠️ Some tests failed. Check the individual results above.</strong></p>";
}

// Test JWT plugin endpoints
echo "<h2>Testing JWT Plugin Endpoints</h2>";
$jwt_endpoints = [
    'jwt-auth/v1/token/validate',
    'jwt-auth/v1/token/refresh'
];

foreach ($jwt_endpoints as $endpoint) {
    $api_url = home_url('/wp-json/' . $endpoint);
    echo "<h3>Testing JWT: {$endpoint}</h3>";
    
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ]
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'><strong>❌ Error:</strong> " . $response->get_error_message() . "</p>";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            echo "<p style='color: green;'><strong>✅ JWT Endpoint Works (200)</strong></p>";
        } else {
            echo "<p style='color: orange;'><strong>⚠️ JWT Endpoint Response ({$status_code})</strong></p>";
        }
    }
}
?>
