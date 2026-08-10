<?php
/**
 * Send staged admission tickets using progressive batch processing.
 *
 * Each HTTP request processes at most BATCH_SIZE tickets, then renders a
 * minimal auto-submitting progress page that triggers the next batch.
 * This avoids Apache's "Script timed out before returning headers" error
 * that kills any single request sending 100+ SMTP emails.
 *
 * State is carried in $_SESSION['at_batch'] between requests:
 *   - action:       'send_selected' | 'send_all'
 *   - exam_date_id: UUID
 *   - total:        initial count (for progress display)
 *   - sent/failed:  running totals
 *   - remaining:    remaining IDs (send_selected only; send_all re-queries DB)
 *
 * POST parameters:
 *   First request:   csrf_token, exam_date_id, action, ticket_ids[] (selected only)
 *   Continuation:    csrf_token, continue_batch=1
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/ticket-staging.php';

/** @var int Tickets per HTTP request. */
const BATCH_SIZE = 15;

$redirectUrl = BASE_URL . '/pages/admission-tickets.php';

// --- Validation (runs on every request) ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Send requires POST', 'error');
    header('Location: ' . $redirectUrl);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    // Clear any stale batch state
    unset($_SESSION['at_batch']);
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . $redirectUrl);
    exit;
}

$sentBy        = (int) ($_SESSION['user_id'] ?? 0);
$isContinue    = ($_POST['continue_batch'] ?? '') === '1';
$conn          = getDbConnection();

// --- Load or initialise batch state ---
if ($isContinue && isset($_SESSION['at_batch'])) {
    $batch  = $_SESSION['at_batch'];
    $action = $batch['action'];
    $examDateId = $batch['exam_date_id'];
    $total      = $batch['total'];
    $totalSent  = $batch['sent'];
    $totalFailed = $batch['failed'];

    // Get remaining IDs for this batch
    if ($action === 'send_all') {
        $stmt = $conn->prepare("SELECT id FROM admission_tickets WHERE exam_date_id = ? AND send_status IN ('staged','failed') ORDER BY id ASC");
        $stmt->bind_param('s', $examDateId);
        $stmt->execute();
        $remainingIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();
    } else {
        $remainingIds = $batch['remaining'] ?? [];
    }
} else {
    // --- First request ---
    $action     = $_POST['action'] ?? '';
    $examDateId = trim($_POST['exam_date_id'] ?? '');

    if ($examDateId === '') {
        setFlashMessage('Exam date is required', 'error');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'send_all') {
        $stmt = $conn->prepare("SELECT id FROM admission_tickets WHERE exam_date_id = ? AND send_status IN ('staged','failed') ORDER BY id ASC");
        $stmt->bind_param('s', $examDateId);
        $stmt->execute();
        $remainingIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();
    } else {
        $raw = $_POST['ticket_ids'] ?? [];
        if (!is_array($raw) || empty($raw)) {
            setFlashMessage('No tickets selected. Tick the checkbox next to each row, or use "Send All Staged".', 'error');
            header('Location: ' . $redirectUrl . '?exam_date_id=' . urlencode($examDateId));
            exit;
        }
        $remainingIds = array_map('intval', array_filter($raw, fn($v) => (int) $v > 0));
        if (empty($remainingIds)) {
            setFlashMessage('No valid ticket IDs in selection', 'error');
            header('Location: ' . $redirectUrl . '?exam_date_id=' . urlencode($examDateId));
            exit;
        }
    }

    $total = count($remainingIds);
    if ($total === 0) {
        setFlashMessage('No staged or failed tickets to send.', 'error');
        header('Location: ' . $redirectUrl . '?exam_date_id=' . urlencode($examDateId));
        exit;
    }

    $totalSent   = 0;
    $totalFailed = 0;

    $batch = [
        'action'      => $action,
        'exam_date_id'=> $examDateId,
        'total'       => $total,
        'sent'        => 0,
        'failed'      => 0,
    ];
}

// --- Process this batch ---
$batchIds = array_slice($remainingIds, 0, BATCH_SIZE);

set_time_limit(120);

try {
    $result = sendTickets($batchIds, $sentBy, $examDateId);
} catch (Throwable $e) {
    error_log('Admission ticket send FAILED: ' . $e->getMessage());
    unset($_SESSION['at_batch']);
    setFlashMessage('Send failed: ' . $e->getMessage(), 'error');
    header('Location: ' . $redirectUrl . '?exam_date_id=' . urlencode($examDateId));
    exit;
}

$totalSent   += $result['sent'];
$totalFailed += $result['failed'];

// --- Compute remaining ---
if ($action === 'send_all') {
    // Recount staged/failed from DB (successful sends changed to 'sent')
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM admission_tickets WHERE exam_date_id = ? AND send_status IN ('staged','failed')");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $remainingCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
} else {
    $remainingIds = array_slice($remainingIds, BATCH_SIZE);
    $remainingCount = count($remainingIds);
    $batch['remaining'] = $remainingIds;
}

// --- Check completion ---
$stopReason = null;
if ($remainingCount === 0) {
    $stopReason = 'done';
} elseif ($result['sent'] === 0 && $result['failed'] > 0) {
    // Entire batch failed — likely SMTP is down. Don't keep retrying.
    $stopReason = 'smtp_error';
}

if ($stopReason !== null) {
    unset($_SESSION['at_batch']);
    $msg = "Sent {$totalSent} of {$total} ticket(s).";
    if ($totalFailed > 0) {
        $msg .= " {$totalFailed} failed.";
        if (!empty($result['errors'])) {
            $msg .= ' First error: ' . implode(' | ', array_slice($result['errors'], 0, 3));
        }
    }
    setFlashMessage($msg, $totalFailed > 0 ? 'error' : 'success');
    header('Location: ' . $redirectUrl . '?exam_date_id=' . urlencode($examDateId));
    exit;
}

// --- Save state and render progress page ---
$batch['sent']   = $totalSent;
$batch['failed'] = $totalFailed;
$_SESSION['at_batch'] = $batch;

// Release the session lock so the next request (auto-submit) can proceed
// immediately without waiting for this request's session to close.
session_write_close();

$processed = $total - $remainingCount;
$pct       = $total > 0 ? round(($processed / $total) * 100) : 100;
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sending admission tickets…</title>
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
        <h2>Sending admission tickets…</h2>
        <div class="count"><?php echo $totalSent; ?> sent<?php echo $totalFailed ? ', ' . $totalFailed . ' failed' : ''; ?></div>
        <div class="sub"><?php echo $processed; ?> of <?php echo $total; ?> processed</div>
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
