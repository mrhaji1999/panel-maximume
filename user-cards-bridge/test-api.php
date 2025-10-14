<?php
/**
 * Simple API test file
 * This file can be used to test the API endpoints
 */

// Include WordPress
require_once('../../../wp-load.php');

// Test JWT functionality
use UCB\JWT\JWTHandler;
use UCB\JWT\JWTAuth;

// Initialize JWT
$jwt_handler = new JWTHandler();

// Test user (you can change this to an existing user)
$test_user_id = 1; // Change this to an existing user ID
$test_user = get_user_by('id', $test_user_id);

if (!$test_user) {
    die("Test user not found. Please change \$test_user_id to an existing user ID.");
}

echo "<h2>JWT Authentication Test</h2>";

// Test token creation
$token = $jwt_handler->create_token($test_user->ID, $test_user->user_login, $test_user->roles);
echo "<p><strong>Generated Token:</strong> " . substr($token, 0, 50) . "...</p>";

// Test token validation
$payload = $jwt_handler->validate_token($token);
if ($payload) {
    echo "<p><strong>Token Validation:</strong> ✅ Valid</p>";
    echo "<p><strong>User ID:</strong> " . $payload['user_id'] . "</p>";
    echo "<p><strong>User Login:</strong> " . $payload['user_login'] . "</p>";
    echo "<p><strong>User Roles:</strong> " . implode(', ', $payload['user_roles']) . "</p>";
} else {
    echo "<p><strong>Token Validation:</strong> ❌ Invalid</p>";
}

// Test API endpoints
echo "<h3>API Endpoints Test</h3>";

$api_base = home_url('/wp-json/user-cards-bridge/v1/');

echo "<p><strong>Available Endpoints:</strong></p>";
echo "<ul>";
echo "<li><a href='{$api_base}auth/login' target='_blank'>{$api_base}auth/login</a> (POST)</li>";
echo "<li><a href='{$api_base}auth/register' target='_blank'>{$api_base}auth/register</a> (POST)</li>";
echo "<li><a href='{$api_base}users' target='_blank'>{$api_base}users</a> (GET)</li>";
echo "<li><a href='{$api_base}customers' target='_blank'>{$api_base}customers</a> (GET)</li>";
echo "<li><a href='{$api_base}cards' target='_blank'>{$api_base}cards</a> (GET)</li>";
echo "</ul>";

echo "<h3>Test Login</h3>";
echo "<form method='post'>";
echo "<p>Username: <input type='text' name='username' value='{$test_user->user_login}'></p>";
echo "<p>Password: <input type='password' name='password'></p>";
echo "<p><input type='submit' name='test_login' value='Test Login'></p>";
echo "</form>";

if (isset($_POST['test_login'])) {
    $username = sanitize_text_field($_POST['username']);
    $password = $_POST['password'];
    
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        echo "<p style='color: red;'>❌ Authentication failed: " . $user->get_error_message() . "</p>";
    } else {
        $token = $jwt_handler->create_token($user->ID, $user->user_login, $user->roles);
        echo "<p style='color: green;'>✅ Authentication successful!</p>";
        echo "<p><strong>Token:</strong> " . $token . "</p>";
        
        // Test API call with token
        echo "<h4>Test API Call with Token</h4>";
        $response = wp_remote_get($api_base . 'users', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            echo "<p style='color: red;'>❌ API call failed: " . $response->get_error_message() . "</p>";
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            echo "<p style='color: green;'>✅ API call successful!</p>";
            echo "<pre>" . print_r($data, true) . "</pre>";
        }
    }
}

echo "<h3>Plugin Status</h3>";
echo "<p><strong>WooCommerce:</strong> " . (class_exists('WooCommerce') ? '✅ Active' : '❌ Not Active') . "</p>";
echo "<p><strong>User Cards Bridge:</strong> " . (class_exists('UCB\\Plugin') ? '✅ Active' : '❌ Not Active') . "</p>";
echo "<p><strong>JWT Handler:</strong> " . (class_exists('UCB\\JWT\\JWTHandler') ? '✅ Available' : '❌ Not Available') . "</p>";
?>
