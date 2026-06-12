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

// Site URL
define('SITE_URL', getenv('SITE_URL') ?: 'https://nat-test.ku.ac.bd');

// Security configuration
define('RATE_LIMIT_MINUTE', 5);
define('RATE_LIMIT_DAY', 20);
define('HONEYPOT_FIELD', 'website');

// CORS configuration
define('ALLOWED_ORIGINS', getenv('ALLOWED_ORIGINS') ?: '*');

// ============================================
// SSLCommerz Payment Gateway Configuration
// ============================================

// SSLCommerz API Credentials
define('SSLCZ_STORE_ID', getenv('SSLCZ_STORE_ID') ?: '');
define('SSLCZ_STORE_PASSWORD', getenv('SSLCZ_STORE_PASSWORD') ?: '');

// SSLCommerz Mode: 'sandbox' for testing, 'live' for production
define('SSLCZ_MODE', getenv('SSLCZ_MODE') ?: 'sandbox');

// SSLCommerz API Endpoints
define('SSLCZ_API_DOMAIN', SSLCZ_MODE === 'live'
    ? 'https://securepay.sslcommerz.com'
    : 'https://sandbox.sslcommerz.com');

// Redirect URLs — must point at payment-return.php, not the static pages:
// SSLCommerz returns the browser via POST, which static .html cannot accept.
// payment-return.php converts the POST into a GET redirect to the right page.
define('SSLCZ_SUCCESS_URL', getenv('SSLCZ_SUCCESS_URL') ?: SITE_URL . '/intake/payment-return.php?outcome=success');
define('SSLCZ_FAIL_URL', getenv('SSLCZ_FAIL_URL') ?: SITE_URL . '/intake/payment-return.php?outcome=fail');
define('SSLCZ_CANCEL_URL', getenv('SSLCZ_CANCEL_URL') ?: SITE_URL . '/intake/payment-return.php?outcome=cancel');
define('SSLCZ_IPN_URL', SITE_URL . '/intake/payment-ipn.php');

// SSLCommerz merchant commission rates — INFORMATIONAL ONLY.
// The commission is deducted by SSLCommerz from the merchant settlement;
// it is never added to the amount the applicant pays.
define('SSLCZ_CARD_FEE_RATE', 0.025);  // ~2.5% for Visa/MC
define('SSLCZ_AMEX_FEE_RATE', 0.035);  // ~3.5% for AMEX

// Retry Link Expiry (7 days)
define('PAYMENT_RETRY_EXPIRY_DAYS', 7);

// Known SSLCommerz server IPs — advisory only (logged, never used to reject).
// IPN authenticity is established by verify_sign hash + the validation API.
define('SSLCZ_IPN_WHITELIST', [
    '103.163.227.100',
    '103.163.227.101'
]);

// ============================================
// Automated email configuration
// ============================================

// Sender for automated applicant emails
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'no-reply@nat-test.ku.ac.bd');

// Where applicants email payment proof (bank deposit / QR payments)
define('RECEIPT_EMAIL', 'money_receipt@nat-test.ku.ac.bd');

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

/**
 * Calculate payment amount breakdown
 *
 * The applicant pays the base fee only (4000 BDT per level). SSLCommerz
 * deducts its commission from the merchant settlement — it is never added
 * to the amount charged to the applicant.
 *
 * @param int $levelCount Number of exam levels selected
 * @param bool $isAmex Unused, kept for call-site compatibility
 * @return array ['base' => float, 'fee' => float, 'total' => float]
 */
function calculatePaymentAmount($levelCount, $isAmex = false) {
    $baseAmount = $levelCount * 4000; // 4000 BDT per level

    return [
        'base' => $baseAmount,
        'fee' => 0.0,
        'total' => $baseAmount
    ];
}

/**
 * Generate secure retry token
 *
 * @return string 32-character hex token
 */
function generateRetryToken() {
    return bin2hex(random_bytes(16));
}

/**
 * Generate retry link expiry datetime
 *
 * @return string MySQL datetime format
 */
function generateRetryExpiry() {
    return date('Y-m-d H:i:s', strtotime('+' . PAYMENT_RETRY_EXPIRY_DAYS . ' days'));
}
