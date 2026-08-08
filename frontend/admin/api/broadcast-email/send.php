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
 *   batch_mode        '1' to return JSON instead of redirecting (AJAX)
 *   batch_offset      int offset for batch mode (which recipients to send)
 *
 * Recipients are re-queried here (never trusted from the form) so approvals
 * that happened between preview and send are correctly included. One email
 * per unique address (de-duped), personalised via _substituteTemplateVars().
 *
 * Batch mode: processes at most BATCH_SIZE recipients per request and
 * returns JSON {sent, failed, processed, total, offset, done}. The
 * frontend loops with increasing offsets until done. This is necessary
 * because Apache kills any single request exceeding its Timeout directive.
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/email-templates.php';
require_once __DIR__ . '/../../lib/broadcast-email.php';

/** @var int Max recipients per HTTP request in batch mode. */
const BATCH_SIZE = 15;

$isBatch     = ($_POST['batch_mode'] ?? '') === '1';
$batchOffset = max(0, (int) ($_POST['batch_offset'] ?? 0));
$pageUrl     = BASE_URL . '/pages/broadcast-email.php';

$jsonError = function (string $message) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isBatch) { $jsonError('Broadcast send requires POST'); }
    setFlashMessage('Broadcast send requires POST', 'error');
    header('Location: ' . $pageUrl);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if ($isBatch) { $jsonError('Invalid CSRF token'); }
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . $pageUrl);
    exit;
}

$examDateId    = trim($_POST['exam_date_id'] ?? '');
$subject       = trim($_POST['subject'] ?? '');
$body          = $_POST['body'] ?? '';
$previewedCount = (int) ($_POST['previewed_count'] ?? 0);

// Repopulate the compose form if we bounce back (non-batch only).
$stashDraft = function () use ($examDateId, $subject, $body) {
    $_SESSION['broadcast_draft'] = [
        'exam_date_id' => $examDateId,
        'subject'      => $subject,
        'body'         => $body,
    ];
};

if ($examDateId === '' || $subject === '' || $body === '') {
    if ($isBatch) { $jsonError('Exam date, subject, and body are all required'); }
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
    if ($isBatch) { $jsonError('Selected exam date no longer exists'); }
    $stashDraft();
    setFlashMessage('Selected exam date no longer exists', 'error');
    header('Location: ' . $pageUrl);
    exit;
}

// De-duped recipients: one row per unique email, earliest created_at wins.
$recipients = fetchBroadcastRecipients($conn, $examDate);
$liveCount  = count($recipients);

if ($liveCount === 0) {
    if ($isBatch) { $jsonError('No approved examinees found for ' . formatDate($examDate)); }
    $stashDraft();
    setFlashMessage('No approved examinees found for ' . formatDate($examDate), 'error');
    header('Location: ' . $pageUrl);
    exit;
}

// Guard against significant drift since preview (non-batch only — in batch
// mode the user already confirmed and we're mid-send).
if (!$isBatch && $previewedCount > 0 && abs($liveCount - $previewedCount) > 5) {
    $stashDraft();
    setFlashMessage(
        'Recipient count changed from ' . $previewedCount . ' to ' . $liveCount
        . ' since preview — please review the updated list and confirm again.',
        'error'
    );
    header('Location: ' . $pageUrl);
    exit;
}

// In batch mode, process only BATCH_SIZE recipients from the offset.
$batchRecipients = $recipients;
if ($isBatch) {
    $batchRecipients = array_slice($recipients, $batchOffset, BATCH_SIZE);
}

// Lift PHP's own time limit for this batch (15 SMTP connections ≈ 30-45s).
set_time_limit(120);

$formattedDate = formatDate($examDate);

$sent   = 0;
$failed = 0;
$failedRecipients = [];

try {
    foreach ($batchRecipients as $r) {
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
} catch (Throwable $e) {
    error_log(
        'Broadcast send FAILED after ' . $sent . ' sent, ' . $failed . ' failed'
        . ' (last recipient: ' . ($r['email'] ?? 'n/a') . '): '
        . $e->getMessage() . "\n" . $e->getTraceAsString()
    );
    if ($isBatch) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'  => $e->getMessage(),
            'sent'   => $sent,
            'failed' => $failed,
            'offset' => $batchOffset + count($batchRecipients),
            'total'  => $liveCount,
            'done'   => false,
        ]);
        exit;
    }
    $stashDraft();
    setFlashMessage('Broadcast failed after ' . $sent . ' sent: ' . $e->getMessage(), 'error');
    header('Location: ' . $pageUrl);
    exit;
}

if ($isBatch) {
    $nextOffset = $batchOffset + count($batchRecipients);
    header('Content-Type: application/json');
    echo json_encode([
        'sent'      => $sent,
        'failed'    => $failed,
        'processed' => count($batchRecipients),
        'total'     => $liveCount,
        'offset'    => $nextOffset,
        'done'      => $nextOffset >= $liveCount,
    ]);
    exit;
}

// --- Traditional (non-batch) completion path ---

// Audit is best-effort — never blocks the send result.
try {
    logAudit(
        'broadcast_send',
        null,
        null,
        null,
        [
            'exam_date'  => $examDate,
            'subject'    => $subject,
            'previewed'  => $previewedCount,
            'recipients' => $liveCount,
            'sent'       => $sent,
            'failed'     => $failed,
        ]
    );
} catch (Throwable $e) {
    error_log('broadcast send audit failed: ' . $e->getMessage());
}

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
