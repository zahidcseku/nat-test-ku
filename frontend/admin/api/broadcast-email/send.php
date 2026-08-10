<?php
/**
 * Send a broadcast email using progressive batch processing.
 *
 * Each HTTP request processes at most BATCH_SIZE recipients, then renders
 * a minimal auto-submitting progress page that triggers the next batch.
 * This avoids Apache's "Script timed out before returning headers" error.
 *
 * State is carried in $_SESSION['be_batch'] between requests.
 *
 * POST parameters:
 *   First request:   csrf_token, exam_date_id, subject, body, previewed_count
 *   Continuation:    csrf_token, continue_batch=1
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/email-templates.php';
require_once __DIR__ . '/../../lib/broadcast-email.php';

/** @var int Recipients per HTTP request. */
const BATCH_SIZE = 15;

$pageUrl     = BASE_URL . '/pages/broadcast-email.php';
$redirectUrl = $pageUrl;

// --- Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Broadcast send requires POST', 'error');
    header('Location: ' . $redirectUrl);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    unset($_SESSION['be_batch'], $_SESSION['broadcast_draft']);
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . $redirectUrl);
    exit;
}

$sentBy     = (int) ($_SESSION['user_id'] ?? 0);
$isContinue = ($_POST['continue_batch'] ?? '') === '1';
$conn       = getDbConnection();

if ($isContinue && isset($_SESSION['be_batch'])) {
    // --- Continuation: pull state from session ---
    $batch     = $_SESSION['be_batch'];
    $examDateId = $batch['exam_date_id'];
    $subject   = $batch['subject'];
    $body      = $batch['body'];
    $offset    = $batch['offset'];
    $total     = $batch['total'];
    $totalSent = $batch['sent'];
    $totalFailed = $batch['failed'];
} else {
    // --- First request ---
    $examDateId    = trim($_POST['exam_date_id'] ?? '');
    $subject       = trim($_POST['subject'] ?? '');
    $body          = $_POST['body'] ?? '';
    $previewedCount = (int) ($_POST['previewed_count'] ?? 0);

    if ($examDateId === '' || $subject === '' || $body === '') {
        $_SESSION['broadcast_draft'] = [
            'exam_date_id' => $examDateId,
            'subject'      => $subject,
            'body'         => $body,
        ];
        setFlashMessage('Exam date, subject, and body are all required', 'error');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Resolve exam date
    $dateStmt = $conn->prepare('SELECT exam_date FROM exam_dates WHERE id = ?');
    $dateStmt->bind_param('s', $examDateId);
    $dateStmt->execute();
    $examDate = ($dateStmt->get_result()->fetch_assoc() ?? [])['exam_date'] ?? null;
    $dateStmt->close();

    if ($examDate === null) {
        setFlashMessage('Selected exam date no longer exists', 'error');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Fetch recipients
    $recipients = fetchBroadcastRecipients($conn, $examDate);
    $liveCount  = count($recipients);

    if ($liveCount === 0) {
        setFlashMessage('No approved examinees found for ' . formatDate($examDate), 'error');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Count drift check (first request only)
    if ($previewedCount > 0 && abs($liveCount - $previewedCount) > 5) {
        $_SESSION['broadcast_draft'] = [
            'exam_date_id' => $examDateId,
            'subject'      => $subject,
            'body'         => $body,
        ];
        setFlashMessage(
            'Recipient count changed from ' . $previewedCount . ' to ' . $liveCount
            . ' since preview — please review and confirm again.',
            'error'
        );
        header('Location: ' . $redirectUrl);
        exit;
    }

    $total       = $liveCount;
    $offset      = 0;
    $totalSent   = 0;
    $totalFailed = 0;

    $batch = [
        'exam_date_id' => $examDateId,
        'subject'       => $subject,
        'body'          => $body,
        'total'         => $total,
        'offset'        => 0,
        'sent'          => 0,
        'failed'        => 0,
    ];

    // Clear draft — the send has started
    unset($_SESSION['broadcast_draft']);
}

// --- Fetch recipients for this batch ---
// Re-query each time so new approvals between batches are included.
// Use the stored offset to skip already-processed recipients.
$dateStmt = $conn->prepare('SELECT exam_date FROM exam_dates WHERE id = ?');
$dateStmt->bind_param('s', $examDateId);
$dateStmt->execute();
$examDate = ($dateStmt->get_result()->fetch_assoc() ?? [])['exam_date'] ?? null;
$dateStmt->close();

if ($examDate === null) {
    unset($_SESSION['be_batch']);
    setFlashMessage('Exam date no longer exists', 'error');
    header('Location: ' . $redirectUrl);
    exit;
}

$recipients    = fetchBroadcastRecipients($conn, $examDate);
$batchRecipients = array_slice($recipients, $offset, BATCH_SIZE);

set_time_limit(120);

$formattedDate = formatDate($examDate);
$sent   = 0;
$failed = 0;

foreach ($batchRecipients as $r) {
    $vars = [
        'full_name' => $r['full_name'],
        'exam_date' => $formattedDate,
    ];
    $renderedSubject = _substituteTemplateVars($subject, $vars);
    $renderedBody    = _substituteTemplateVars($body, $vars);

    try {
        $ok = sendEmail($r['email'], $renderedSubject, $renderedBody, $r['id'], 'broadcast');
    } catch (Throwable $e) {
        error_log('Broadcast send failed for ' . $r['email'] . ': ' . $e->getMessage());
        $ok = false;
    }

    if ($ok) {
        $sent++;
    } else {
        $failed++;
    }
}

$totalSent   += $sent;
$totalFailed += $failed;
$offset      += count($batchRecipients);

// --- Check completion ---
$isDone = $offset >= $total;
if (!$isDone && $sent === 0 && $failed > 0) {
    // Entire batch failed — likely SMTP issue. Stop.
    $isDone = true;
}

if ($isDone) {
    unset($_SESSION['be_batch']);

    // Audit
    try {
        logAudit('broadcast_send', null, null, null, [
            'exam_date' => $examDate,
            'subject'   => $subject,
            'recipients'=> $total,
            'sent'      => $totalSent,
            'failed'    => $totalFailed,
        ]);
    } catch (Throwable $e) {
        error_log('broadcast send audit failed: ' . $e->getMessage());
    }

    $msg = "Broadcast sent: {$totalSent} of {$total} succeeded";
    if ($totalFailed > 0) $msg .= ", {$totalFailed} failed";
    $msg .= '.';
    setFlashMessage($msg, $totalFailed > 0 ? 'error' : 'success');
    header('Location: ' . $redirectUrl . '?last_send=1');
    exit;
}

// --- Save state and render progress page ---
$batch['offset']  = $offset;
$batch['sent']    = $totalSent;
$batch['failed']  = $totalFailed;
$_SESSION['be_batch'] = $batch;

session_write_close();

$pct = $total > 0 ? round(($offset / $total) * 100) : 100;
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sending broadcast…</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f7fafc; color:#1a202c; display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .card { background:white; border-radius:12px; padding:40px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.1); min-width:380px; max-width:500px; }
        h2 { font-size:20px; margin-bottom:20px; }
        .count { font-size:28px; font-weight:700; margin-bottom:8px; }
        .sub { font-size:14px; color:#718096; margin-bottom:20px; }
        .bar-bg { background:#edf2f7; border-radius:8px; height:12px; overflow:hidden; margin-bottom:8px; }
        .bar { background:#667eea; height:100%; border-radius:8px; transition:width 0.3s; }
        .note { font-size:12px; color:#a0aec0; margin-top:16px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Sending broadcast…</h2>
        <div class="count"><?php echo $totalSent; ?> sent<?php echo $totalFailed ? ', ' . $totalFailed . ' failed' : ''; ?></div>
        <div class="sub"><?php echo $offset; ?> of <?php echo $total; ?> processed</div>
        <div class="bar-bg"><div class="bar" style="width:<?php echo $pct; ?>%"></div></div>
        <p class="note">Do not close this page.</p>
    </div>
    <form id="continue-form" method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="continue_batch" value="1">
    </form>
    <script>setTimeout(function(){ document.getElementById('continue-form').submit(); }, 300);</script>
</body>
</html>
