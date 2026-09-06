<?php
/**
 * Admin Panel Configuration
 * Database connection, constants, and utility functions
 */

// Include guard: this file may be required from multiple paths
// (middleware, direct require, or a host's auto_prepend_file).
// Returning early on a second include prevents "Constant already
// defined" notices on every define() below.
if (defined('ADMIN_CONFIG_LOADED')) {
    return;
}
define('ADMIN_CONFIG_LOADED', true);

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
define('MAX_UPLOAD_SIZE', (int)(getenv('MAX_UPLOAD_SIZE') ?: 209715200));
define('UPLOAD_PATH', __DIR__ . '/' . (getenv('UPLOAD_PATH') ?: 'uploads/'));
// Pause between individual broadcast emails (milliseconds). Paces the burst
// of per-message SMTP connections so the relay doesn't throttle us (~90
// rapid messages was where it gave up). Tune via env if limits change.
define('BROADCAST_SEND_DELAY_MS', (int)(getenv('BROADCAST_SEND_DELAY_MS') ?: 1000));

// Intake uploads directory — where applicant photos/IDs/receipts live.
// Admin writes replacements here when correcting an applicant's uploads.
// Resolved relative to admin (../intake/uploads) so it works in dev and prod.
$_intakeUploadsBase = __DIR__ . '/../intake/uploads';
define('INTAKE_UPLOADS_DIR', is_dir($_intakeUploadsBase) ? realpath($_intakeUploadsBase) : $_intakeUploadsBase);

// SMTP Configuration
defined('SMTP_HOST')     || define('SMTP_HOST', getenv('SMTP_HOST') ?: 'localhost');
defined('SMTP_PORT')     || define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
defined('SMTP_USER')     || define('SMTP_USER', getenv('SMTP_USER') ?: '');
defined('SMTP_PASS')     || define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
defined('SMTP_FROM')     || define('SMTP_FROM', getenv('SMTP_FROM') ?: '');
defined('SMTP_FROM_NAME')|| define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'NAT-TEST Khulna');

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
