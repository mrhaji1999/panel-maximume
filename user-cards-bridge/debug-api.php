<?php
/**
 * Debug API endpoints
 * This file helps debug API authentication issues
 */

// Include WordPress
require_once('../../../wp-load.php');

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first.");
}

$current_user = wp_get_current_user();
echo "<h2>Current User Debug</h2>";
echo "<p><strong>User ID:</strong> " . $current_user->ID . "</p>";
echo "<p><strong>Username:</strong> " . $current_user->user_login . "</p>";
echo "<p><strong>Email:</strong> " . $current_user->user_email . "</p>";
echo "<p><strong>Roles:</strong> " . implode(', ', $current_user->roles) . "</p>";
echo "<p><strong>Capabilities:</strong> " . implode(', ', array_keys($current_user->allcaps)) . "</p>";

// Test JWT functionality
echo "<h2>JWT Test</h2>";
use UCB\JWT\JWTHandler;
use UCB\JWT\JWTAuth;

$jwt_handler = new JWTHandler();
$token = $jwt_handler->create_token($current_user->ID, $current_user->user_login, $current_user->roles);

if ($token) {
    echo "<p><strong>JWT Token Generated:</strong> ✅</p>";
    echo "<p><strong>Token (first 50 chars):</strong> " . substr($token, 0, 50) . "...</p>";
    
    // Test token validation
    $payload = $jwt_handler->validate_token($token);
    if ($payload) {
        echo "<p><strong>Token Validation:</strong> ✅</p>";
        echo "<p><strong>Payload:</strong> " . print_r($payload, true) . "</p>";
    } else {
        echo "<p><strong>Token Validation:</strong> ❌</p>";
    }
} else {
    echo "<p><strong>JWT Token Generation:</strong> ❌</p>";
}

// Test API endpoints
echo "<h2>API Endpoints Test</h2>";
$api_base = home_url('/wp-json/user-cards-bridge/v1/');

// Test with WordPress authentication
echo "<h3>Testing with WordPress Authentication</h3>";
$test_endpoints = [
    'dashboard/summary',
    'customers',
    'cards',
    'supervisors',
    'agents'
];

foreach ($test_endpoints as $endpoint) {
    $url = $api_base . $endpoint;
    echo "<h4>Testing: {$endpoint}</h4>";
    
    $response = wp_remote_get($url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'cookies' => $_COOKIE // Pass WordPress cookies
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'>❌ Error: " . $response->get_error_message() . "</p>";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200) {
            echo "<p style='color: green;'>✅ Success (200)</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed ({$status_code})</p>";
        }
        
        echo "<p><strong>Response:</strong></p>";
        echo "<pre>" . print_r($data, true) . "</pre>";
    }
}

// Test with JWT token
if ($token) {
    echo "<h3>Testing with JWT Token</h3>";
    
    foreach ($test_endpoints as $endpoint) {
        $url = $api_base . $endpoint;
        echo "<h4>Testing: {$endpoint}</h4>";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            echo "<p style='color: red;'>❌ Error: " . $response->get_error_message() . "</p>";
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($status_code === 200) {
                echo "<p style='color: green;'>✅ Success (200)</p>";
            } else {
                echo "<p style='color: red;'>❌ Failed ({$status_code})</p>";
            }
            
            echo "<p><strong>Response:</strong></p>";
            echo "<pre>" . print_r($data, true) . "</pre>";
        }
    }
}

// Check plugin status
echo "<h2>Plugin Status</h2>";
echo "<p><strong>WooCommerce:</strong> " . (class_exists('WooCommerce') ? '✅ Active' : '❌ Not Active') . "</p>";
echo "<p><strong>User Cards Bridge:</strong> " . (class_exists('UCB\\Plugin') ? '✅ Active' : '❌ Not Active') . "</p>";
echo "<p><strong>JWT Handler:</strong> " . (class_exists('UCB\\JWT\\JWTHandler') ? '✅ Available' : '❌ Not Available') . "</p>";
echo "<p><strong>Security Class:</strong> " . (class_exists('UCB\\Security') ? '✅ Available' : '❌ Not Available') . "</p>";
echo "<p><strong>Roles Class:</strong> " . (class_exists('UCB\\Roles') ? '✅ Available' : '❌ Not Available') . "</p>";

// Check user roles and capabilities
echo "<h2>User Role Check</h2>";
$custom_roles = ['company_manager', 'supervisor', 'agent'];
foreach ($custom_roles as $role) {
    $has_role = in_array($role, $current_user->roles);
    echo "<p><strong>{$role}:</strong> " . ($has_role ? '✅ Yes' : '❌ No') . "</p>";
}

// Check capabilities
echo "<h2>Capabilities Check</h2>";
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
?>
