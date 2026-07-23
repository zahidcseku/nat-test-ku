<?php
/**
 * Reset a single email template back to its defaults.
 *
 * POST /admin/api/email-templates/reset.php
 *   csrf_token
 *   template_key   — must be one of the keys in emailTemplateDefaults()
 *
 * Redirects back to the editor with a flash.
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/email-templates.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Reset requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php');
    exit;
}

$key    = trim($_POST['template_key'] ?? '');
$sentBy = (int) ($_SESSION['user_id'] ?? 0);

$defaults = emailTemplateDefaults();
if (!isset($defaults[$key])) {
    setFlashMessage("Unknown template key: {$key}", 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php');
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    setFlashMessage('Database connection failed', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
    exit;
}

$def = $defaults[$key];
$old = loadEmailTemplateRow($key);

// UPSERT the default values back into the row.
$stmt = $conn->prepare("
    INSERT INTO email_templates
        (template_key, name, description, subject, body_html, available_variables, is_system, updated_by)
    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ON DUPLICATE KEY UPDATE
        subject    = VALUES(subject),
        body_html  = VALUES(body_html),
        updated_by = VALUES(updated_by)
");
$varsJson = json_encode($def['variables'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt->bind_param(
    'ssssssi',
    $key,
    $def['name'],
    $def['description'],
    $def['subject'],
    $def['body'],
    $varsJson,
    $sentBy
);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    setFlashMessage('Failed to reset template: ' . $conn->error, 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
    exit;
}

try {
    logAudit(
        'reset_email_template',
        'email_templates',
        null,
        $old ? ['subject' => $old['subject']] : null,
        ['template_key' => $key]
    );
} catch (Throwable $e) {
    error_log('template reset audit failed: ' . $e->getMessage());
}

setFlashMessage("Template '{$def['name']}' reset to default.", 'success');
header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
exit;
