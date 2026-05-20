<?php
/**
 * Session Test - Debug authentication issues
 */

// Require config first (this will start the session)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

echo "<h2>Session Debug Test</h2>";

echo "<h3>Session Status:</h3>";
echo "Session Status: " . session_status() . "<br>";
echo "Session Name: " . session_name() . "<br>";
echo "SESSION_NAME Constant: " . SESSION_NAME . "<br>";
echo "BASE_URL: " . BASE_URL . "<br>";
echo "Session ID: " . session_id() . "<br>";

echo "<h3>Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Current User:</h3>";
if (isset($_SESSION['user_id'])) {
    echo "✅ User ID: " . $_SESSION['user_id'] . "<br>";
    echo "✅ Username: " . ($_SESSION['username'] ?? 'not set') . "<br>";
    echo "✅ Email: " . ($_SESSION['email'] ?? 'not set') . "<br>";
    echo "✅ Role: " . ($_SESSION['role'] ?? 'not set') . "<br>";
    echo "✅ Last Activity: " . ($_SESSION['last_activity'] ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'not set') . "<br>";
} else {
    echo "❌ Not logged in - No user_id in session<br>";
}

echo "<h3>Test Actions:</h3>";
echo "<a href='/admin/index.php'>Go to Login Page</a><br>";
echo "<a href='/admin/dashboard.php'>Go to Dashboard</a><br>";
echo "<a href='/admin/logout.php'>Logout / Clear Session</a><br>";

echo "<h3>Function Test:</h3>";
echo "isLoggedIn(): " . (isLoggedIn() ? 'true' : 'false') . "<br>";

?>