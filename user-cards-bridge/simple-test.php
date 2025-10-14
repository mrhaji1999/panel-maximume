<?php
/**
 * Simple Test
 * Test API endpoints with minimal error handling
 */

// Include WordPress
require_once('../../../wp-load.php');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Simple API Test</h1>";

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";

// Test a simple API call
$api_url = home_url('/wp-json/user-cards-bridge/v1/dashboard/summary');
echo "<h2>Testing API: {$api_url}</h2>";

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
    
    echo "<p><strong>Status Code:</strong> {$status_code}</p>";
    
    if ($status_code === 200) {
        echo "<p style='color: green;'><strong>✅ Success!</strong></p>";
        $data = json_decode($body, true);
        echo "<pre>" . print_r($data, true) . "</pre>";
    } else {
        echo "<p style='color: red;'><strong>❌ Failed</strong></p>";
        echo "<h3>Response Body:</h3>";
        echo "<pre>" . htmlspecialchars($body) . "</pre>";
    }
}

// Test another endpoint
$api_url2 = home_url('/wp-json/user-cards-bridge/v1/customers');
echo "<h2>Testing API: {$api_url2}</h2>";

$response2 = wp_remote_get($api_url2, [
    'headers' => [
        'Content-Type' => 'application/json'
    ],
    'cookies' => $_COOKIE
]);

if (is_wp_error($response2)) {
    echo "<p style='color: red;'><strong>❌ Error:</strong> " . $response2->get_error_message() . "</p>";
} else {
    $status_code2 = wp_remote_retrieve_response_code($response2);
    $body2 = wp_remote_retrieve_body($response2);
    
    echo "<p><strong>Status Code:</strong> {$status_code2}</p>";
    
    if ($status_code2 === 200) {
        echo "<p style='color: green;'><strong>✅ Success!</strong></p>";
        $data2 = json_decode($body2, true);
        echo "<pre>" . print_r($data2, true) . "</pre>";
    } else {
        echo "<p style='color: red;'><strong>❌ Failed</strong></p>";
        echo "<h3>Response Body:</h3>";
        echo "<pre>" . htmlspecialchars($body2) . "</pre>";
    }
}
?>
