#!/usr/bin/env php
<?php
/**
 * Admin Panel Setup Script
 * Run this to initialize the admin panel database and create first admin user
 */

echo "NAT-TEST Admin Panel Setup\n";
echo "==========================\n\n";

// Load config
require_once __DIR__ . '/config.php';

// Check if .env file exists
if (!file_exists(__DIR__ . '/.env')) {
    echo "❌ ERROR: .env file not found!\n";
    echo "Please copy .env.example to .env and configure it:\n";
    echo "  cp .env.example .env\n";
    echo "  nano .env  # Edit with your database and SMTP credentials\n\n";
    exit(1);
}

// Test database connection
echo "🔗 Testing database connection...\n";
$conn = getDbConnection();

if (!$conn) {
    echo "❌ ERROR: Could not connect to database!\n";
    echo "Check your .env file and ensure MySQL is running.\n\n";
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
$count = $result->fetch_assoc()['count'];

if ($count > 0) {
    echo "⚠️  Admin users already exist ($count found). Skipping user creation.\n\n";

    // List existing users
    $result = $conn->query("SELECT username, email, role, is_active FROM admin_users");
    echo "Existing users:\n";
    while ($user = $result->fetch_assoc()) {
        $status = $user['is_active'] ? '✅' : '❌';
        echo "  $status {$user['username']} ({$user['role']}) - {$user['email']}\n";
    }
    echo "\n";
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

    if (!isStrongPassword($password)) {
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
$dirs = [
    UPLOAD_PATH,
    UPLOAD_PATH . 'tickets/',
    UPLOAD_PATH . 'photos/',
    UPLOAD_PATH . 'id_documents/',
    UPLOAD_PATH . 'payment_receipts/'
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
echo "1. Upload admin panel to: https://nat-test.ku.ac.bd/admin\n";
echo "2. Update .env with production credentials\n";
echo "3. Set proper file permissions:\n";
echo "   chmod 644 *.php\n";
echo "   chmod 755 api/ pages/ templates/\n";
echo "   chmod 600 .env\n";
echo "   chmod 755 uploads/\n";
echo "4. Login at: https://nat-test.ku.ac.bd/admin/\n\n";
