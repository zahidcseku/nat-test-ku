<?php
/**
 * NAT-TEST Intake Service - Security Functions
 *
 * Handles rate limiting, IP hashing, honeypot protection,
 * and CSRF token generation/validation.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * Initialize rate limiting session
 */
function initRateLimit() {
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [
            'minute' => [
                'count' => 0,
                'reset' => time() + 60
            ],
            'day' => [
                'count' => 0,
                'reset' => time() + 86400 // 24 hours
            ]
        ];
    }

    // Check if we need to reset counters
    $now = time();

    if ($now >= $_SESSION['rate_limit']['minute']['reset']) {
        $_SESSION['rate_limit']['minute'] = [
            'count' => 0,
            'reset' => $now + 60
        ];
    }

    if ($now >= $_SESSION['rate_limit']['day']['reset']) {
        $_SESSION['rate_limit']['day'] = [
            'count' => 0,
            'reset' => $now + 86400
        ];
    }
}

/**
 * Check rate limit
 * Returns true if request should be allowed, false otherwise
 */
function checkRateLimit() {
    initRateLimit();

    // Check minute limit
    if ($_SESSION['rate_limit']['minute']['count'] >= RATE_LIMIT_MINUTE) {
        $retryAfter = $_SESSION['rate_limit']['minute']['reset'] - time();
        header('Retry-After: ' . $retryAfter);
        logActivity("Rate limit exceeded (minute): " . getClientIp(), 'warning');
        return false;
    }

    // Check day limit
    if ($_SESSION['rate_limit']['day']['count'] >= RATE_LIMIT_DAY) {
        $retryAfter = $_SESSION['rate_limit']['day']['reset'] - time();
        header('Retry-After: ' . $retryAfter);
        logActivity("Rate limit exceeded (day): " . getClientIp(), 'warning');
        return false;
    }

    // Increment counters
    $_SESSION['rate_limit']['minute']['count']++;
    $_SESSION['rate_limit']['day']['count']++;

    return true;
}

/**
 * Check honeypot field
 * Returns true if honeypot was not triggered (legitimate request)
 * Returns false if honeypot was triggered (bot detected)
 */
function checkHoneypot($data) {
    $honeypotField = HONEYPOT_FIELD;
    // POST values can be arrays (website[]=x); only strings count here
    $honeypotValue = $data[$honeypotField] ?? '';
    $honeypotValue = is_string($honeypotValue) ? trim($honeypotValue) : '';

    // If honeypot field is filled, it's a bot
    if (!empty($honeypotValue)) {
        logActivity("Honeypot triggered: " . getClientIp() . " | Value: " . $honeypotValue, 'warning');
        return [
            'valid' => false,
            'tripped' => true,
            'value' => $honeypotValue
        ];
    }

    return [
        'valid' => true,
        'tripped' => false,
        'value' => null
    ];
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    $sanitized = [];

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitizeInput($value);
        } else {
            $sanitized[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
    }

    return $sanitized;
}

/**
 * Check if request is POST
 */
function isPostRequest() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Validate request method
 */
function validateRequestMethod() {
    if (!isPostRequest()) {
        errorResponse('Only POST requests are allowed', 405);
    }
}

/**
 * Set CORS headers
 */
function setCorsHeaders() {
    $allowedOrigins = ALLOWED_ORIGINS;

    if ($allowedOrigins !== '*') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedList = explode(',', $allowedOrigins);

        if (in_array($origin, $allowedList)) {
            header("Access-Control-Allow-Origin: $origin");
        }
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Handle preflight OPTIONS request
 */
function handlePreflight() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        setCorsHeaders();
        http_response_code(200);
        exit;
    }
}

/**
 * Set security headers
 */
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Validate content type
 */
function validateContentType() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'multipart/form-data') === false) {
        errorResponse('Content-Type must be multipart/form-data', 415);
    }
}

/**
 * Get request IP (for logging purposes)
 */
function getRequestIp() {
    return getClientIp();
}

/**
 * Check for suspicious patterns in user agent
 */
function checkUserAgent() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Common bot patterns
    $botPatterns = [
        '/bot/i',
        '/crawler/i',
        '/spider/i',
        '/scraper/i'
    ];

    foreach ($botPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            logActivity("Suspicious user agent detected: $userAgent", 'warning');
            return false;
        }
    }

    return true;
}

/**
 * Initialize all security checks
 */
function initSecurity() {
    setCorsHeaders();
    handlePreflight();
    setSecurityHeaders();
    validateRequestMethod();
    validateContentType();

    if (!checkRateLimit()) {
        errorResponse('Rate limit exceeded. Please try again later.', 429);
    }

    if (!checkUserAgent()) {
        errorResponse('Invalid request', 403);
    }
}
