<?php
/**
 * Authentication Middleware
 * Include this at the top of any protected page
 */

// Require config and functions
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// Check if user is authenticated
requireAuth();

// Regenerate CSRF token for each request
$csrfToken = generateCsrfToken();
