<?php
/**
 * Authentication Middleware
 * Include this at the top of any protected page
 */

// Require config first (this will start the session)
require_once __DIR__ . '/../config.php';

// Then require functions
require_once __DIR__ . '/../functions.php';

// Check if user is authenticated
if (!isLoggedIn()) {
    // Only redirect if not already on login page to prevent loop
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (strpos($currentPath, '/index.php') === false && strpos($currentPath, '/admin/') !== false) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

// Regenerate CSRF token for each request
$csrfToken = generateCsrfToken();
