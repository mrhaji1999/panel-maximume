<?php
/**
 * Debug Errors
 * Enable error reporting and test API endpoints
 */

// Include WordPress
require_once('../../../wp-load.php');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Debug Errors</h1>";

// Check if user is logged in
if (!is_user_logged_in()) {
    die("Please log in to WordPress admin first. <a href='" . wp_login_url() . "'>Login</a>");
}

$current_user = wp_get_current_user();
echo "<p>Logged in as: <strong>" . $current_user->display_name . "</strong> (ID: " . $current_user->ID . ")</p>";

// Test a simple API call with error handling
$api_url = home_url('/wp-json/user-cards-bridge/v1/dashboard/summary');
echo "<h2>Testing API: {$api_url}</h2>";

try {
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'cookies' => $_COOKIE,
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color: red;'><strong>❌ WP Error:</strong> " . $response->get_error_message() . "</p>";
        echo "<p><strong>Error Code:</strong> " . $response->get_error_code() . "</p>";
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
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>❌ Exception:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
}

// Check if our plugin classes exist
echo "<h2>Plugin Classes Check</h2>";
$classes = [
    'UCB\\Plugin',
    'UCB\\Security',
    'UCB\\JWT\\JWTAuth',
    'UCB\\JWT\\JWTHandler',
    'UCB\\API\\Authentication',
    'UCB\\API\\Users',
    'UCB\\API\\Customers',
    'UCB\\API\\Cards',
    'UCB\\API\\Stats'
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "<p style='color: green;'><strong>✅ {$class}</strong> exists</p>";
    } else {
        echo "<p style='color: red;'><strong>❌ {$class}</strong> does not exist</p>";
    }
}

// Check if our API endpoints are registered
echo "<h2>API Endpoints Check</h2>";
$rest_server = rest_get_server();
$routes = $rest_server->get_routes();

$our_routes = [
    '/user-cards-bridge/v1/dashboard/summary',
    '/user-cards-bridge/v1/customers',
    '/user-cards-bridge/v1/cards',
    '/user-cards-bridge/v1/supervisors',
    '/user-cards-bridge/v1/agents'
];

foreach ($our_routes as $route) {
    if (isset($routes[$route])) {
        echo "<p style='color: green;'><strong>✅ {$route}</strong> is registered</p>";
    } else {
        echo "<p style='color: red;'><strong>❌ {$route}</strong> is not registered</p>";
    }
}

// Check WordPress error log
echo "<h2>WordPress Error Log</h2>";
$error_log = ini_get('error_log');
if ($error_log && file_exists($error_log)) {
    echo "<p><strong>Error Log File:</strong> {$error_log}</p>";
    $log_content = file_get_contents($error_log);
    $recent_errors = array_slice(explode("\n", $log_content), -20);
    echo "<h3>Recent Errors:</h3>";
    echo "<pre>" . htmlspecialchars(implode("\n", $recent_errors)) . "</pre>";
} else {
    echo "<p>No error log file found or accessible.</p>";
}
?>
