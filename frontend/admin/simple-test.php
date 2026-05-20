<?php
/**
 * Simple test - just to see if PHP is working
 */

echo "<h1>PHP Test</h1>";
echo "PHP is working! Current time: " . date('Y-m-d H:i:s');

// Test database connection without any dependencies
echo "<h2>Database Test</h2>";

echo "<h3>First, let's check if we can load the .env file:</h3>";

// Try to load .env manually
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    echo "✅ .env file found<br>";

    // Load it manually
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $envVars = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $envVars[trim($name)] = trim($value);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }

    echo "✅ Loaded " . count($envVars) . " environment variables<br>";

    $dbHost = $envVars['DB_HOST'] ?? 'not set';
    $dbName = $envVars['DB_NAME'] ?? 'not set';
    $dbUser = $envVars['DB_USER'] ?? 'not set';
    $dbPass = isset($envVars['DB_PASS']) ? 'set (hidden)' : 'not set';

    echo "DB_HOST: $dbHost<br>";
    echo "DB_NAME: $dbName<br>";
    echo "DB_USER: $dbUser<br>";
    echo "DB_PASS: $dbPass<br>";

    echo "<h3>Now attempting database connection:</h3>";

    try {
        $conn = new mysqli(
            $envVars['DB_HOST'] ?? 'localhost',
            $envVars['DB_USER'] ?? '',
            $envVars['DB_PASS'] ?? '',
            $envVars['DB_NAME'] ?? ''
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

} else {
    echo "❌ .env file NOT found at: $envFile<br>";
    echo "Current directory: " . __DIR__ . "<br>";
    echo "Looking for: $envFile<br>";
}

?>