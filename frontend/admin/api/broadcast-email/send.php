<?php
/**
 * Send a broadcast email to every approved examinee for a chosen exam date.
 *
 * POST /admin/api/broadcast-email/send.php
 *   csrf_token
 *   exam_date_id      exam_dates.id (UUID)
 *   subject           email subject (may contain {full_name}, {exam_date})
 *   body              HTML email body (may contain {full_name}, {exam_date})
 *   previewed_count   recipient count the admin saw on the preview step
 *
 * Recipients are re-queried here (never trusted from the form) so approvals
 * that happened between preview and send are correctly included. One email
 * per unique address (de-duped), personalised via _substituteTemplateVars().
 *
 * Each send goes through the existing sendEmail() helper, which already
 * writes one email_log row per recipient (email_type='broadcast') with the
 * admin's sent_by and sent/failed status. There is no separate queue —
 * sending is synchronous, matching the admission-ticket bulk-send pattern.
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/email-templates.php';
require_once __DIR__ . '/../../lib/broadcast-email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Broadcast send requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/broadcast-email.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/broadcast-email.php');
    exit;
}

$examDateId    = trim($_POST['exam_date_id'] ?? '');
$subject       = trim($_POST['subject'] ?? '');
$body          = $_POST['body'] ?? '';
$previewedCount = (int) ($_POST['previewed_count'] ?? 0);

$pageUrl = BASE_URL . '/pages/broadcast-email.php';

// Repopulate the compose form if we bounce back.
$stashDraft = function () use ($examDateId, $subject, $body) {
    $_SESSION['broadcast_draft'] = [
        'exam_date_id' => $examDateId,
        'subject'      => $subject,
        'body'         => $body,
    ];
};

if ($examDateId === '' || $subject === '' || $body === '') {
    $stashDraft();
    setFlashMessage('Exam date, subject, and body are all required', 'error');
    header('Location: ' . $pageUrl);
    exit;
}

$conn = getDbConnection();

// Resolve the DATE for this exam_dates.id.
$dateStmt = $conn->prepare('SELECT exam_date FROM exam_dates WHERE id = ?');
$dateStmt->bind_param('s', $examDateId);
$dateStmt->execute();
$examDate = ($dateStmt->get_result()->fetch_assoc() ?? [])['exam_date'] ?? null;
$dateStmt->close();

if ($examDate === null) {
    $stashDraft();
    setFlashMessage('Selected exam date no longer exists', 'error');
    header('Location: ' . $pageUrl);
    exit;
}

// De-duped recipients: one row per unique email, earliest created_at wins.
$recipients = fetchBroadcastRecipients($conn, $examDate);
$liveCount  = count($recipients);

if ($liveCount === 0) {
    $stashDraft();
    setFlashMessage('No approved examinees found for ' . formatDate($examDate), 'error');
    header('Location: ' . $pageUrl);
    exit;
}

// Guard against significant drift since preview (new approvals / deletions).
if ($previewedCount > 0 && abs($liveCount - $previewedCount) > 5) {
    $stashDraft();
    setFlashMessage(
        'Recipient count changed from ' . $previewedCount . ' to ' . $liveCount
        . ' since preview — please review the updated list and confirm again.',
        'error'
    );
    header('Location: ' . $pageUrl);
    exit;
}

// Synchronous bulk send — match the admission-ticket precedent. Raise the
// time limit and keep running even if the client disconnects mid-batch.
set_time_limit(0);
ignore_user_abort(true);

$formattedDate = formatDate($examDate);

$sent   = 0;
$failed = 0;
$failedRecipients = [];

foreach ($recipients as $r) {
    $vars = [
        'full_name' => $r['full_name'],
        'exam_date' => $formattedDate,
    ];
    $renderedSubject = _substituteTemplateVars($subject, $vars);
    $renderedBody    = _substituteTemplateVars($body, $vars);

    $ok = sendEmail($r['email'], $renderedSubject, $renderedBody, $r['id'], 'broadcast');

    if ($ok) {
        $sent++;
    } else {
        $failed++;
        $failedRecipients[] = [
            'name'  => $r['full_name'],
            'email' => $r['email'],
        ];
    }
}

// Audit is best-effort — never blocks the send result.
try {
    logAudit(
        'broadcast_send',
        null,
        null,
        null,
        [
            'exam_date'      => $examDate,
            'subject'        => $subject,
            'previewed'      => $previewedCount,
            'recipients'     => $liveCount,
            'sent'           => $sent,
            'failed'         => $failed,
        ]
    );
} catch (Throwable $e) {
    error_log('broadcast send audit failed: ' . $e->getMessage());
}

// Draft is no longer needed once the send actually happened.
unset($_SESSION['broadcast_draft']);

if ($failed > 0) {
    $_SESSION['broadcast_failures'] = $failedRecipients;
}

$msg = "Broadcast sent: {$sent} of {$liveCount} succeeded";
if ($failed > 0) {
    $msg .= ", {$failed} failed";
}
$msg .= '.';
setFlashMessage($msg, $failed > 0 ? 'error' : 'success');
header('Location: ' . $pageUrl . '?last_send=1');
exit;
