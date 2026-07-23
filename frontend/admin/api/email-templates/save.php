<?php
/**
 * Save edits to an email template.
 *
 * POST /admin/api/email-templates/save.php
 *   csrf_token
 *   template_key   — must be one of the keys in emailTemplateDefaults()
 *   subject
 *   body
 *
 * Redirects back to the editor with a flash.
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/email-templates.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Save requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php');
    exit;
}

$key    = trim($_POST['template_key'] ?? '');
$subj   = trim($_POST['subject'] ?? '');
$body   = $_POST['body'] ?? '';  // do NOT trim — body is HTML, leading whitespace is fine
$sentBy = (int) ($_SESSION['user_id'] ?? 0);

$defaults = emailTemplateDefaults();
if (!isset($defaults[$key])) {
    setFlashMessage("Unknown template key: {$key}", 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php');
    exit;
}
if ($subj === '' || $body === '') {
    setFlashMessage('Subject and body cannot be empty', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    setFlashMessage('Database connection failed', 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
    exit;
}

// Capture the old values for the audit log.
$old = loadEmailTemplateRow($key);

// UPSERT so this works whether or not the row was seeded yet.
$stmt = $conn->prepare("
    INSERT INTO email_templates
        (template_key, name, description, subject, body_html, available_variables, is_system, updated_by)
    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ON DUPLICATE KEY UPDATE
        subject    = VALUES(subject),
        body_html  = VALUES(body_html),
        updated_by = VALUES(updated_by)
");
$def       = $defaults[$key];
$varsJson  = json_encode($def['variables'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt->bind_param(
    'ssssssi',
    $key,
    $def['name'],
    $def['description'],
    $subj,
    $body,
    $varsJson,
    $sentBy
);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    setFlashMessage('Failed to save template: ' . $conn->error, 'error');
    header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
    exit;
}

try {
    logAudit(
        'update_email_template',
        'email_templates',
        null,
        $old ? ['subject' => $old['subject']] : null,
        ['template_key' => $key, 'subject' => $subj]
    );
} catch (Throwable $e) {
    error_log('template save audit failed: ' . $e->getMessage());
}

setFlashMessage("Template '{$def['name']}' saved.", 'success');
header('Location: ' . BASE_URL . '/pages/email-templates.php?key=' . urlencode($key));
exit;
