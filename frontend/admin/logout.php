<?php
/**
 * Logout Handler
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Log logout
if (isLoggedIn()) {
    logAudit('logout', 'admin_users', $_SESSION['user_id']);
}

// Destroy session
$_SESSION = [];
session_destroy();

// Redirect to login
header('Location: ' . BASE_URL . '/index.php');
exit;
