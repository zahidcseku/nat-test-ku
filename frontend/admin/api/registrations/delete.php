<?php
/**
 * Delete Registration (DB row + uploaded files)
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/pages/registrations.php');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/registrations.php');
    exit;
}

// Destructive: super admins only (UI hides the button, but enforce here)
if (!isSuperAdmin()) {
    setFlashMessage('Only a super admin can delete registrations', 'error');
    header('Location: ' . BASE_URL . '/pages/registrations.php');
    exit;
}

$id = $_POST['id'] ?? '';
$result = deleteRegistrationCompletely($id);
setFlashMessage($result['message'], $result['success'] ? 'success' : 'error');

// Preserve the list's filter query (sanitized; header() rejects CRLF anyway)
$qs = preg_replace('/[^a-zA-Z0-9=&_\-%.]/', '', $_POST['return_query'] ?? '');
header('Location: ' . BASE_URL . '/pages/registrations.php' . ($qs !== '' ? ('?' . $qs) : ''));
exit;
