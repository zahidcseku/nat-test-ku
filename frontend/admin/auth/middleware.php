<?php
/**
 * Authentication Middleware
 * Include this at the top of any protected page
 */

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require config first
require_once __DIR__ . '/../config.php';

// Then require functions
require_once __DIR__ . '/../functions.php';

// Check if user is authenticated
requireAuth();

// Regenerate CSRF token for each request
$csrfToken = generateCsrfToken();
