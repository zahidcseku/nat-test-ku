<?php
/**
 * Check .env file loading
 */

echo "<h2>Environment File Check</h2>";

// Check if .env file exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    echo "✅ .env file exists at: $envFile<br>";
    echo "File size: " . filesize($envFile) . " bytes<br>";
    echo "Readable: " . (is_readable($envFile) ? 'Yes' : 'No') . "<br>";

    echo "<h3>.env file contents (sanitized):</h3>";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<ul>";
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') !== 0) {
            $parts = explode('=', $line, 2);
            if (count($parts) == 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                // Show first few chars of value to verify it's loaded
                $displayValue = strlen($value) > 0 ? substr($value, 0, 3) . '...' : '(empty)';
                echo "<li>$name = $displayValue</li>";
            }
        } else {
            echo "<li><em>$line</em></li>";
        }
    }
    echo "</ul>";
} else {
    echo "❌ .env file NOT found at: $envFile<br>";
    echo "Looking in: " . __DIR__ . "<br>";
}

echo "<h3>Current Environment Variables:</h3>";
$vars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
foreach ($vars as $var) {
    $envValue = getenv($var);
    $displayValue = $envValue ? (strlen($envValue) > 3 ? substr($envValue, 0, 3) . '...' : $envValue) : '(not set)';
    echo "$var = $displayValue<br>";
}

echo "<h3>File Permissions:</h3>";
if (file_exists($envFile)) {
    $perms = substr(sprintf('%o', fileperms($envFile)), -4);
    echo "Permissions: $perms<br>";
    echo "Should be: 0600 or 0644<br>";
}

?>