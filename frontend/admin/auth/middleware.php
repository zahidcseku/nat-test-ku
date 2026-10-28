<?php
/**
 * Authentication Middleware
 * Include this at the top of any protected page
 */

// Require config first (this will start the session)
require_once __DIR__ . '/../config.php';

// Then require functions
require_once __DIR__ . '/../functions.php';

// Require authentication unconditionally. The login page (index.php) includes
// auth/login.php instead of this middleware, so no redirect loop is possible.
// Never gate this on the request path: REQUEST_URI is attacker-controlled
// (e.g. PATH_INFO like /dashboard.php/x/index.php) and requireAuth() also
// enforces the session timeout.
requireAuth();

// Regenerate CSRF token for each request (authenticated sessions only)
$csrfToken = generateCsrfToken();
