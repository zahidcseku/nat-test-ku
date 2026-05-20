<?php
/**
 * Simple test - just to see if PHP is working
 */

echo "<h1>PHP Test</h1>";
echo "PHP is working! Current time: " . date('Y-m-d H:i:s');

// Test database connection without any dependencies
echo "<h2>Database Test</h2>";
try {
    $conn = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'nattest_reg',
        getenv('DB_PASS') ?: '',
        getenv('DB_NAME') ?: 'nattest_regs'
    );

    if ($conn->connect_error) {
        echo "❌ Connection failed: " . $conn->connect_error;
    } else {
        echo "✅ Database connection successful<br>";

        // Simple query
        $result = $conn->query("SELECT COUNT(*) as count FROM registrations");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "✅ Total registrations: " . $row['count'];
        } else {
            echo "❌ Query failed: " . $conn->error;
        }
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}

?>