<?php
/**
 * Quick diagnostic script to identify the issue
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Admin Panel Diagnostic</h2>";

// Test 1: Check if files exist
echo "<h3>1. File Check</h3>";
$files = [
    'config.php',
    'functions.php',
    'auth/middleware.php',
    '.env'
];

foreach ($files as $file) {
    $exists = file_exists(__DIR__ . '/' . $file);
    echo "$file: " . ($exists ? '✅ EXISTS' : '❌ MISSING') . "<br>";
}

// Test 2: Check if required functions exist
echo "<h3>2. Function Check</h3>";
require_once __DIR__ . '/config.php';
$functions = ['getDbConnection', 'generateCsrfToken', 'isLoggedIn', 'requireAuth'];
foreach ($functions as $func) {
    $exists = function_exists($func);
    echo "$func: " . ($exists ? '✅ EXISTS' : '❌ MISSING') . "<br>";
}

// Test 3: Database connection
echo "<h3>3. Database Connection</h3>";
try {
    $conn = getDbConnection();
    if ($conn) {
        echo "Database connection: ✅ SUCCESS<br>";
        echo "MySQL error: " . $conn->error . "<br>";

        // Test 4: Check if required tables exist
        echo "<h3>4. Table Check</h3>";
        $tables = ['registrations', 'admin_users', 'exam_dates', 'audit_log', 'email_log'];
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $exists = $result->num_rows > 0;
            echo "$table: " . ($exists ? '✅ EXISTS' : '❌ MISSING') . "<br>";
        }

        // Test 5: Check registrations table structure
        echo "<h3>5. Registrations Table Structure</h3>";
        $result = $conn->query("DESCRIBE registrations");
        $columns = $result->fetch_all(MYSQLI_ASSOC);
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td></tr>";
        }
        echo "</table>";

        // Test 6: Check exam_dates table structure
        echo "<h3>6. Exam Dates Table Structure</h3>";
        $result = $conn->query("DESCRIBE exam_dates");
        if ($result) {
            $columns = $result->fetch_all(MYSQLI_ASSOC);
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th></tr>";
            foreach ($columns as $col) {
                echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "❌ Error describing exam_dates table: " . $conn->error . "<br>";
        }

        // Test 7: Sample query
        echo "<h3>7. Sample Query Test</h3>";
        $stmt = $conn->prepare("SELECT id, full_name, email FROM registrations LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                if ($row) {
                    echo "✅ Sample query successful<br>";
                    echo "Sample registration: {$row['full_name']} ({$row['email']})<br>";
                } else {
                    echo "⚠️ No registrations found<br>";
                }
            } else {
                echo "❌ Query execution failed<br>";
            }
        } else {
            echo "❌ Statement preparation failed: " . $conn->error . "<br>";
        }

    } else {
        echo "Database connection: ❌ FAILED<br>";
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "<br>";
}

// Test 8: PHP info
echo "<h3>8. PHP Environment</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "MySQLi: " . (extension_loaded('mysqli') ? '✅ ENABLED' : '❌ DISABLED') . "<br>";
echo "Session: " . (extension_loaded('session') ? '✅ ENABLED' : '❌ DISABLED') . "<br>";

?>