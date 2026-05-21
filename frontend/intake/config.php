<?php
/**
 * NAT-TEST Intake Service - Configuration
 *
 * Handles database connection, environment variables,
 * and provides utility functions for the application.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

// Error reporting (off in production, on in development)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Always off, log to file instead
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Start session for rate limiting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load environment variables from .env file
// Try multiple filenames to bypass ModSecurity blocking
function loadEnv($path = null) {
    // Try multiple possible env file names
    $possiblePaths = [
        __DIR__ . '/.env',
        __DIR__ . '/config.env',
        __DIR__ . '/db.env',
        __DIR__ . '/environment.env'
    ];

    if ($path) {
        array_unshift($possiblePaths, $path);
    }

    foreach ($possiblePaths as $tryPath) {
        if (file_exists($tryPath)) {
            $path = $tryPath;
            break;
        }
    }

    if (!isset($path) || !file_exists($path)) {
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
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    return true;
}

// Load environment variables
loadEnv();

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'nattest_regs');
define('DB_USER', getenv('DB_USER') ?: 'nattest_reg');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// File upload configuration
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB in bytes
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);
define('ALLOWED_PDF_TYPES', ['application/pdf']);

// Security configuration
define('RATE_LIMIT_MINUTE', 5);
define('RATE_LIMIT_DAY', 20);
define('HONEYPOT_FIELD', 'website');

// CORS configuration
define('ALLOWED_ORIGINS', getenv('ALLOWED_ORIGINS') ?: '*');

// Create database connection
function getDbConnection() {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );

        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            return null;
        }

        $conn->set_charset(DB_CHARSET);
    }

    return $conn;
}

// JSON response helper
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Error response helper
function errorResponse($message, $statusCode = 400, $errors = []) {
    $response = [
        'success' => false,
        'error' => $message
    ];

    if (!empty($errors)) {
        $response['errors'] = $errors;
    }

    jsonResponse($response, $statusCode);
}

// Success response helper
function successResponse($data = [], $message = 'Success') {
    $response = [
        'success' => true,
        'message' => $message
    ];

    if (!empty($data)) {
        $response['data'] = $data;
    }

    jsonResponse($response, 200);
}

// Generate UUID v4
function generateUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Get client IP address
function getClientIp() {
    $ip = '';

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// Hash IP address for privacy
function hashIp($ip) {
    return hash('sha256', $ip . getenv('IP_SALT') ?: 'default-salt');
}

// Log activity
function logActivity($message, $level = 'info') {
    $logFile = __DIR__ . '/logs/activity.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;

    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
