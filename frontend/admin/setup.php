#!/usr/bin/env php
<?php
/**
 * Admin Panel Setup Script
 * Run this to initialize the admin panel database and create first admin user
 */

echo "NAT-TEST Admin Panel Setup\n";
echo "==========================\n\n";

// Load .env file manually (don't use config.php to avoid session issues)
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "❌ ERROR: .env file not found!\n";
    echo "Please copy .env.example to .env and configure it:\n";
    echo "  cp .env.example .env\n";
    echo "  nano .env  # Edit with your database and SMTP credentials\n\n";
    exit(1);
}

// Parse .env file manually
$envVars = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    list($name, $value) = explode('=', $line, 2);
    $envVars[trim($name)] = trim($value);
}

// Database connection
$conn = new mysqli(
    $envVars['DB_HOST'] ?? 'localhost',
    $envVars['DB_USER'] ?? '',
    $envVars['DB_PASS'] ?? '',
    $envVars['DB_NAME'] ?? ''
);

if ($conn->connect_error) {
    echo "❌ ERROR: Could not connect to database!\n";
    echo "Error: " . $conn->connect_error . "\n\n";
    exit(1);
}

echo "✅ Database connection successful!\n\n";

// Check if schema already imported
echo "📋 Checking database schema...\n";
$result = $conn->query("SHOW TABLES LIKE 'admin_users'");

if ($result->num_rows > 0) {
    echo "⚠️  Schema already exists. Skipping schema import.\n\n";
} else {
    echo "📥 Importing schema...\n";

    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        echo "❌ ERROR: schema.sql not found!\n\n";
        exit(1);
    }

    // Read and execute schema
    $sql = file_get_contents($schemaFile);

    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (empty($statement) || strpos(trim($statement), '--') === 0) {
            continue;
        }

        if (!$conn->query($statement)) {
            echo "❌ ERROR executing schema: " . $conn->error . "\n\n";
            exit(1);
        }
    }

    echo "✅ Schema imported successfully!\n\n";
}

// Check if admin users exist
echo "👤 Checking admin users...\n";
$result = $conn->query("SELECT COUNT(*) as count FROM admin_users");

if (!$result) {
    echo "❌ ERROR querying admin_users: " . $conn->error . "\n\n";
    exit(1);
}

$count = $result->fetch_assoc()['count'];

if ($count > 0) {
    echo "⚠️  Admin users already exist ($count found). Skipping user creation.\n\n";

    // List existing users
    $result = $conn->query("SELECT username, email, role, is_active FROM admin_users");
    if ($result) {
        echo "Existing users:\n";
        while ($user = $result->fetch_assoc()) {
            $status = $user['is_active'] ? '✅' : '❌';
            echo "  $status {$user['username']} ({$user['role']}) - {$user['email']}\n";
        }
        echo "\n";
    }
} else {
    echo "👤 Creating first admin user...\n\n";

    // Get username
    echo "Enter username: ";
    $username = trim(fgets(STDIN));

    if (empty($username)) {
        echo "❌ Username cannot be empty!\n\n";
        exit(1);
    }

    // Validate username
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        echo "❌ Username must be 3-50 characters (letters, numbers, underscore only)!\n\n";
        exit(1);
    }

    // Get email
    echo "Enter email: ";
    $email = trim(fgets(STDIN));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "❌ Invalid email address!\n\n";
        exit(1);
    }

    // Get password
    echo "Enter password (min 8 chars, must include uppercase, lowercase, and number): ";
    $password = trim(fgets(STDIN));

    // Simple password validation
    if (strlen($password) < 8) {
        echo "❌ Password too weak! Requirements:\n";
        echo "   - At least 8 characters\n";
        echo "   - At least one uppercase letter\n";
        echo "   - At least one lowercase letter\n";
        echo "   - At least one number\n\n";
        exit(1);
    }

    // Confirm password
    echo "Confirm password: ";
    $confirm = trim(fgets(STDIN));

    if ($password !== $confirm) {
        echo "❌ Passwords do not match!\n\n";
        exit(1);
    }

    // Create user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, email, role) VALUES (?, ?, ?, 'super_admin')");

    if (!$stmt->bind_param('sss', $username, $passwordHash, $email) || !$stmt->execute()) {
        echo "❌ ERROR creating user: " . $conn->error . "\n\n";
        exit(1);
    }

    echo "✅ Admin user created successfully!\n\n";
}

// Create upload directories
echo "📁 Creating upload directories...\n";
$uploadsDir = __DIR__ . '/uploads';
$dirs = [
    $uploadsDir,
    $uploadsDir . '/tickets/',
    $uploadsDir . '/photos/',
    $uploadsDir . '/id_documents/',
    $uploadsDir . '/payment_receipts/'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "  ✅ Created: $dir\n";
    } else {
        echo "  ✓ Exists: $dir\n";
    }
}

echo "\n";
echo "🎉 Setup complete!\n\n";
echo "===========================================\n";
echo "Next steps:\n";
echo "1. Set proper file permissions:\n";
echo "   chmod 644 *.php\n";
echo "   chmod 755 api/ pages/ templates/ auth/\n";
echo "   chmod 600 .env\n";
echo "   chmod 755 uploads/\n";
echo "2. Login at: https://nat-test.ku.ac.bd/admin/\n";
echo "3. If you see errors, check: /var/log/apache2/error.log\n\n";

$conn->close();
