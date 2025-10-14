<?php
/**
 * WordPress Authentication Test
 * Test if WordPress authentication works with our API
 */

// Include WordPress
require_once('../../../wp-load.php');

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<h1>WordPress Authentication Test</h1>";
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";
echo "<p>Roles: <strong>" . implode(', ', $current_user->roles) . "</strong></p>";

// Test API endpoints with WordPress authentication
$endpoints = [
    'dashboard/summary',
    'customers',
    'cards',
    'supervisors',
    'agents'
];

foreach ($endpoints as $endpoint) {
    $api_url = home_url('/wp-json/user-cards-bridge/v1/' . $endpoint);
    echo "<h2>Testing: {$endpoint}</h2>";
    echo "<p><strong>URL:</strong> {$api_url}</p>";
    
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'cookies' => $_COOKIE // Pass WordPress cookies
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'><strong>❌ Error:</strong> " . $response->get_error_message() . "</p>";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        echo "<p><strong>Status Code:</strong> {$status_code}</p>";
        
        if ($status_code === 200) {
            echo "<p style='color: green;'><strong>✅ Success!</strong></p>";
        } else {
            echo "<p style='color: red;'><strong>❌ Failed</strong></p>";
        }
        
        echo "<h3>Response:</h3>";
        echo "<pre>" . print_r($data, true) . "</pre>";
    }
    
    echo "<hr>";
}

// Test if we can access the API directly
echo "<h2>Direct API Access Test</h2>";
$api_base = home_url('/wp-json/user-cards-bridge/v1/');
echo "<p><strong>API Base URL:</strong> {$api_base}</p>";

// Test with a simple endpoint
$test_url = $api_base . 'dashboard/summary';
echo "<p><strong>Test URL:</strong> {$test_url}</p>";

$response = wp_remote_get($test_url, [
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
    
    echo "<p><strong>Status Code:</strong> {$status_code}</p>";
    
    if ($status_code === 200) {
        echo "<p style='color: green;'><strong>✅ Direct access works!</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>❌ Direct access failed</strong></p>";
    }
    
    echo "<h3>Response:</h3>";
    echo "<pre>" . print_r($data, true) . "</pre>";
}
?>
