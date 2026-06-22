<?php
/**
 * Admin Panel Configuration
 * Database connection, constants, and utility functions
 */

// Prevent direct access
if (!defined('ADMIN_ACCESS')) {
    define('ADMIN_ACCESS', true);
}

// Load environment variables
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
    return true;
}

// Load .env file
loadEnv(__DIR__ . '/.env');

// Database connection
function getDbConnection() {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(
            getenv('DB_HOST'),
            getenv('DB_USER'),
            getenv('DB_PASS'),
            getenv('DB_NAME')
        );

        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            return null;
        }

        $conn->set_charset("utf8mb4");
    }

    return $conn;
}

// Configuration constants
define('SESSION_NAME', getenv('SESSION_NAME') ?: 'nat_test_admin');
define('SESSION_LIFETIME', (int)(getenv('SESSION_LIFETIME') ?: 1800));
define('CSRF_TOKEN_NAME', getenv('CSRF_TOKEN_NAME') ?: 'csrf_token');
define('PASSWORD_MIN_LENGTH', (int)(getenv('PASSWORD_MIN_LENGTH') ?: 8));
define('MAX_LOGIN_ATTEMPTS', (int)(getenv('MAX_LOGIN_ATTEMPTS') ?: 5));
define('LOGIN_ATTEMPT_WINDOW', (int)(getenv('LOGIN_ATTEMPT_WINDOW') ?: 900));
define('MAX_UPLOAD_SIZE', (int)(getenv('MAX_UPLOAD_SIZE') ?: 52428800));
define('UPLOAD_PATH', __DIR__ . '/' . (getenv('UPLOAD_PATH') ?: 'uploads/'));

// SMTP Configuration
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'localhost');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'NAT-TEST Khulna');

// Site URLs
define('BASE_URL', 'https://nat-test.ku.ac.bd/admin');
define('FRONTEND_URL', 'https://nat-test.ku.ac.bd');
// Registration Sheet template (used by api/registrations/registration-sheet-export.php)
define('REGISTRATION_SHEET_TEMPLATE', getenv('REGISTRATION_SHEET_TEMPLATE') ?: __DIR__ . '/templates/Registration_Sheet_ver.30.xlsx');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'use_cookies' => true,
        'use_only_cookies' => true
    ]);
}

// Timezone
date_default_timezone_set('Asia/Dhaka');
