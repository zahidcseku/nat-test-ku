<?php
/**
 * Database Connection Diagnostic
 * Run this to check database connection issues
 */

echo "=== Database Connection Diagnostic ===\n\n";

// Load .env
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "❌ ERROR: .env file not found at: $envFile\n\n";
    echo "Please create it:\n";
    echo "  cp .env.example .env\n";
    echo "  nano .env\n\n";
    exit(1);
}

echo "✅ .env file found\n\n";

// Parse .env
$envVars = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    list($name, $value) = explode('=', $line, 2);
    $envVars[trim($name)] = trim($value);
}

echo "Environment variables loaded:\n";
echo "  DB_HOST: " . ($envVars['DB_HOST'] ?? 'NOT SET') . "\n";
echo "  DB_NAME: " . ($envVars['DB_NAME'] ?? 'NOT SET') . "\n";
echo "  DB_USER: " . ($envVars['DB_USER'] ?? 'NOT SET') . "\n";
echo "  DB_PASS: " . (isset($envVars['DB_PASS']) ? '***SET***' : 'NOT SET') . "\n";
echo "\n";

// Test connection
echo "Testing database connection...\n";

$conn = new mysqli(
    $envVars['DB_HOST'] ?? 'localhost',
    $envVars['DB_USER'] ?? '',
    $envVars['DB_PASS'] ?? '',
    $envVars['DB_NAME'] ?? ''
);

if ($conn->connect_error) {
    echo "❌ Database connection FAILED!\n";
    echo "Error: " . $conn->connect_error . "\n\n";
    echo "Possible issues:\n";
    echo "1. MySQL server is not running\n";
    echo "2. Wrong database credentials in .env\n";
    echo "3. Database 'nattest_regs' doesn't exist\n";
    echo "4. User 'nattest_reg' doesn't have permissions\n\n";
    echo "Try this:\n";
    echo "  mysql -u " . ($envVars['DB_USER'] ?? 'root') . " -p\n";
    exit(1);
}

echo "✅ Database connection successful!\n\n";

// Check required tables
echo "Checking required tables...\n";
$requiredTables = [
    'admin_users',
    'audit_log',
    'email_log',
    'login_attempts',
    'admission_tickets',
    'registrations',
    'exam_dates',
    'exam_levels'
];

foreach ($requiredTables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "  ✅ $table exists\n";
    } else {
        echo "  ❌ $table MISSING\n";
    }
}

echo "\n";

// Check admin users
echo "Checking admin users...\n";
$result = $conn->query("SELECT username, email FROM admin_users");
if ($result && $result->num_rows > 0) {
    echo "  Found users:\n";
    while ($user = $result->fetch_assoc()) {
        echo "    - {$user['username']} ({$user['email']})\n";
    }
} else {
    echo "  ❌ No admin users found!\n";
}

echo "\n=== Diagnostic Complete ===\n";
echo "If all checks passed, try accessing: https://nat-test.ku.ac.bd/admin/\n";
