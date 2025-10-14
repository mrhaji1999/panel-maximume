<?php
/**
 * JWT Test
 * Test JWT token generation and validation
 */

// Include WordPress
require_once('../../../wp-load.php');

use UCB\JWT\JWTHandler;

echo "<h1>JWT Test</h1>";

$jwt_handler = new JWTHandler();

// Test token creation
$user_id = 1;
$user = get_user_by('id', $user_id);
if (!$user) {
    die("User not found");
}

echo "<h2>Creating JWT Token</h2>";
$token = $jwt_handler->create_token($user_id, $user->user_login, $user->roles);
echo "<p><strong>Token:</strong> " . $token . "</p>";

// Test token validation
echo "<h2>Validating JWT Token</h2>";
$payload = $jwt_handler->validate_token($token);
if ($payload) {
    echo "<p style='color: green;'><strong>✅ Token is valid</strong></p>";
    echo "<pre>" . print_r($payload, true) . "</pre>";
} else {
    echo "<p style='color: red;'><strong>❌ Token is invalid</strong></p>";
}

// Test user retrieval
echo "<h2>Getting User from Token</h2>";
$user_from_token = $jwt_handler->get_user_from_token($token);
if ($user_from_token) {
    echo "<p style='color: green;'><strong>✅ User retrieved successfully</strong></p>";
    echo "<p><strong>User ID:</strong> " . $user_from_token->ID . "</p>";
    echo "<p><strong>Username:</strong> " . $user_from_token->user_login . "</p>";
} else {
    echo "<p style='color: red;'><strong>❌ Failed to retrieve user</strong></p>";
}

// Test with a different token (simulate external panel)
echo "<h2>Testing with External Panel Token</h2>";
$external_token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL21heGltdW0uc3Rvb3IuaXIiLCJpYXQiOjE3NjAzOTY3NjgsImV4cCI6MTc2MDQ4MzE2OCwidXNlcl9pZCI6MSwidXNlcl9sb2dpbiI6Im1yaGFqaSIsInVzZXJfcm9sZXMiOlsiYWRtaW5pc3RyYXRvciJdfQ.test";

$external_payload = $jwt_handler->validate_token($external_token);
if ($external_payload) {
    echo "<p style='color: green;'><strong>✅ External token is valid</strong></p>";
    echo "<pre>" . print_r($external_payload, true) . "</pre>";
} else {
    echo "<p style='color: red;'><strong>❌ External token is invalid</strong></p>";
    echo "<p>This is expected if the external panel uses a different secret key.</p>";
}

// Check secret key
echo "<h2>Secret Key Info</h2>";
$secret_key = get_option('ucb_jwt_secret_key');
if ($secret_key) {
    echo "<p><strong>Secret Key exists:</strong> ✅</p>";
    echo "<p><strong>Secret Key length:</strong> " . strlen($secret_key) . "</p>";
    echo "<p><strong>Secret Key (first 20 chars):</strong> " . substr($secret_key, 0, 20) . "...</p>";
} else {
    echo "<p><strong>Secret Key exists:</strong> ❌</p>";
}
?>
