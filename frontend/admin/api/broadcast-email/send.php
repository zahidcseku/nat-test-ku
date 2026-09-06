<?php
/**
 * Send a broadcast email using progressive batch processing.
 *
 * Each HTTP request processes at most BATCH_SIZE recipients, then renders
 * a minimal auto-submitting progress page that triggers the next batch.
 * This avoids Apache's "Script timed out before returning headers" error.
 *
 * Progress is tracked IN THE DATABASE, not the session: the first request
 * snapshots the recipient list into broadcast_recipients, and every send
 * marks its row sent/failed immediately. A resumed or re-confirmed
 * broadcast therefore never re-emails anyone already sent, and a mid-batch
 * PHP death loses at most the single in-flight recipient.
 *
 * POST parameters:
 *   First request:   csrf_token, exam_date_id, subject, body, previewed_count
 *   Continuation:    csrf_token, continue_batch=1
 *   Resume button:   csrf_token, resume=1, broadcast_id
 */

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../lib/email-templates.php';
require_once __DIR__ . '/../../lib/broadcast-email.php';

/** @var int Recipients per HTTP request. */
const BATCH_SIZE = 10;

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
$conn       = getDbConnection();
$adoptNotice = null;

if (($_POST['resume'] ?? '') === '1') {
    // --- Resume: continue an existing broadcast job ---
    $broadcastId = (int) ($_POST['broadcast_id'] ?? 0);

    $jobStmt = $conn->prepare('SELECT id FROM broadcasts WHERE id = ?');
    $jobStmt->bind_param('i', $broadcastId);
    $jobStmt->execute();
    $jobExists = $jobStmt->get_result()->fetch_assoc() !== null;
    $jobStmt->close();

    if (!$jobExists) {
        setFlashMessage('That broadcast no longer exists (its exam date may have been deleted)', 'error');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // A human clicking Resume means no send chain is alive: any rows stuck
    // in 'sending' came from an interrupted run and never completed.
    $sweep = $conn->prepare(
        "UPDATE broadcast_recipients
         SET status = 'failed', last_error = 'Interrupted mid-send (recovered by resume)'
         WHERE broadcast_id = ? AND status = 'sending'"
    );
    $sweep->bind_param('i', $broadcastId);
    $sweep->execute();
    $sweep->close();

    $adoptNotice = 'Resuming broadcast #' . $broadcastId;
} elseif (($_POST['continue_batch'] ?? '') === '1') {
    // --- Continuation: pull the job id from session ---
    $broadcastId = (int) ($_SESSION['be_batch']['broadcast_id'] ?? 0);
    if ($broadcastId <= 0) {
        // Stale auto-submit after the run stopped/finished — recoverable
        // from the Recent broadcasts table, so point the admin there.
        unset($_SESSION['be_batch']);
        setFlashMessage('Send state lost — use the Resume button on the Broadcast Email page', 'error');
        header('Location: ' . $redirectUrl);
        exit;
    }
} else {
    // --- First request: validate, then create (or adopt) the job ---
    $examDateId     = trim($_POST['exam_date_id'] ?? '');
    $subject        = trim($_POST['subject'] ?? '');
    $body           = $_POST['body'] ?? '';
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
    if (mb_strlen($subject) > 255) {
        $_SESSION['broadcast_draft'] = [
            'exam_date_id' => $examDateId,
            'subject'      => $subject,
            'body'         => $body,
        ];
        setFlashMessage('Subject must be 255 characters or fewer', 'error');
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

    // Duplicate guard: re-confirming an unfinished broadcast resumes it
    // instead of re-emailing everyone from scratch.
    $existingId = findResumableBroadcast($conn, $examDateId, $subject, $body);
    if ($existingId !== null) {
        $broadcastId = $existingId;
        $adoptNotice = 'Continuing unfinished broadcast #' . $existingId
            . ' — recipients already sent will be skipped';
    } else {
        $insert = $conn->prepare(
            'INSERT INTO broadcasts (exam_date_id, exam_date, subject, body, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->bind_param('ssssi', $examDateId, $examDate, $subject, $body, $sentBy);
        $insert->execute();
        $broadcastId = (int) $insert->insert_id;
        $insert->close();

        // Snapshot the recipient list. INSERT IGNORE + the UNIQUE(broadcast_id,
        // email) key dedupe rows the recipient query may return twice (two
        // registrations sharing an email AND a created_at timestamp).
        $snapped = 0;
        foreach (array_chunk($recipients, 100) as $chunk) {
            $values = [];
            $params = [];
            $types  = '';
            foreach ($chunk as $r) {
                $values[] = '(?, ?, ?, ?)';
                $params[] = $broadcastId;
                $params[] = $r['id'];
                $params[] = $r['email'];
                $params[] = $r['full_name'];
                $types   .= 'isss';
            }
            $snap = $conn->prepare(
                'INSERT IGNORE INTO broadcast_recipients (broadcast_id, registration_id, email, full_name)
                 VALUES ' . implode(', ', $values)
            );
            $snap->bind_param($types, ...$params);
            $snap->execute();
            $snapped += $snap->affected_rows;
            $snap->close();
        }

        if ($snapped === 0) {
            // Defensive: nothing snapshotted means nothing can ever be sent.
            $del = $conn->prepare('DELETE FROM broadcasts WHERE id = ?');
            $del->bind_param('i', $broadcastId);
            $del->execute();
            $del->close();
            setFlashMessage('Broadcast could not be prepared — no recipients were recorded', 'error');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Clear draft — the send has started
    unset($_SESSION['broadcast_draft']);
}

// --- Load the job ---
$jobStmt = $conn->prepare(
    'SELECT subject, body, exam_date, finished_at FROM broadcasts WHERE id = ?'
);
$jobStmt->bind_param('i', $broadcastId);
$jobStmt->execute();
$job = $jobStmt->get_result()->fetch_assoc();
$jobStmt->close();

if ($job === null) {
    // Exam date deleted mid-run cascades the job away — stop cleanly.
    unset($_SESSION['be_batch']);
    setFlashMessage('Exam date for this broadcast was deleted; broadcast cancelled', 'error');
    header('Location: ' . $redirectUrl);
    exit;
}
// Note: a job whose finished_at is already set is NOT bounced here — a
// finished job may still have failed recipients worth retrying via Resume,
// and the claim query + recount below resolve "nothing left" gracefully.

// --- Authoritative recount helper (no session counters) ---
$recount = static function (int $broadcastId) use ($conn): array {
    $counts = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'sending' => 0];
    $stmt = $conn->prepare(
        'SELECT status, COUNT(*) AS cnt FROM broadcast_recipients WHERE broadcast_id = ? GROUP BY status'
    );
    $stmt->bind_param('i', $broadcastId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $counts[$row['status']] = (int) $row['cnt'];
    }
    $stmt->close();
    $counts['total']     = array_sum($counts);
    $counts['remaining'] = $counts['pending'] + $counts['failed'] + $counts['sending'];
    return $counts;
};

$finishJob = function (int $broadcastId, array $job, array $counts) use ($conn, $redirectUrl): void {
    $finish = $conn->prepare('UPDATE broadcasts SET finished_at = NOW() WHERE id = ? AND finished_at IS NULL');
    $finish->bind_param('i', $broadcastId);
    $finish->execute();
    $wasOpen = $finish->affected_rows === 1;
    $finish->close();

    // Audit + success flash only for the request that actually closed the
    // job — a stale continue arriving after completion must not add a
    // second audit row for the same broadcast.
    if ($wasOpen) {
        try {
            logAudit('broadcast_send', 'broadcasts', $broadcastId, null, [
                'exam_date'  => $job['exam_date'],
                'subject'    => $job['subject'],
                'recipients' => $counts['total'],
                'sent'       => $counts['sent'],
                'failed'     => $counts['failed'],
            ]);
        } catch (Throwable $e) {
            error_log('broadcast send audit failed: ' . $e->getMessage());
        }

        $msg = "Broadcast sent: {$counts['sent']} of {$counts['total']} succeeded";
        if ($counts['failed'] > 0) $msg .= ", {$counts['failed']} failed";
        $msg .= '.';
        setFlashMessage($msg, $counts['failed'] > 0 ? 'error' : 'success');
    } else {
        setFlashMessage('That broadcast already finished — nothing left to send', 'info');
    }
    header('Location: ' . $redirectUrl . '?last_send=1&broadcast_id=' . $broadcastId);
    exit;
};

// Stale request (e.g. a continue posted after a concurrent tab completed the
// job, or adopting an unfinished job whose rows are all resolved already).
$preCounts = $recount($broadcastId);
if ($preCounts['remaining'] === 0) {
    unset($_SESSION['be_batch']);
    $finishJob($broadcastId, $job, $preCounts);
}

// --- Claim and send this batch ---
set_time_limit(180);

$claimStmt = $conn->prepare(
    "SELECT id, registration_id, email, full_name
     FROM broadcast_recipients
     WHERE broadcast_id = ? AND status IN ('pending','failed')
     ORDER BY id ASC
     LIMIT " . BATCH_SIZE
);
$claimStmt->bind_param('i', $broadcastId);
$claimStmt->execute();
$candidates = $claimStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$claimStmt->close();

if ($candidates === []) {
    // Nothing claimable, yet the job isn't finished: every remaining row is
    // 'sending' — held by a concurrent request (which will resolve it) or
    // stranded by one that died mid-batch. Either way this chain must not
    // auto-loop with nothing to do; Resume sweeps stranded rows.
    unset($_SESSION['be_batch']);
    setFlashMessage(
        'No pending recipients right now — ' . $preCounts['remaining']
        . ' send(s) still in flight. Refresh this page in a moment; if they stay unfinished, use Resume.',
        'info'
    );
    header('Location: ' . $redirectUrl);
    exit;
}

$takeStmt = $conn->prepare(
    "UPDATE broadcast_recipients
     SET status = 'sending', attempts = attempts + 1
     WHERE id = ? AND status IN ('pending','failed')"
);
$markSent = $conn->prepare(
    "UPDATE broadcast_recipients SET status = 'sent', sent_at = NOW() WHERE id = ?"
);
$markFailed = $conn->prepare(
    'UPDATE broadcast_recipients SET status = ?, last_error = ? WHERE id = ?'
);

$formattedDate = formatDate($job['exam_date']);
$vars = ['exam_date' => $formattedDate];
$sentThisBatch = 0;
$failedThisBatch = 0;
$attempted = 0;

foreach ($candidates as $r) {
    // Pace the burst of per-message SMTP connections — the relay throttled
    // us at ~90 rapid sends. Sleep between attempts, not before the first.
    if ($attempted > 0) {
        usleep(BROADCAST_SEND_DELAY_MS * 1000);
    }

    // Atomic claim: if another request (browser POST retry, second tab)
    // already took this row, affected_rows is 0 — skip it, no duplicate.
    $takeStmt->bind_param('i', $r['id']);
    $takeStmt->execute();
    if ($takeStmt->affected_rows !== 1) {
        continue;
    }
    $attempted++;

    $vars['full_name'] = $r['full_name'];
    $renderedSubject = _substituteTemplateVars($job['subject'], $vars);
    $renderedBody    = _substituteTemplateVars($job['body'], $vars);

    $sendError = null;
    try {
        $ok = sendEmail($r['email'], $renderedSubject, $renderedBody, $r['registration_id'], 'broadcast');
    } catch (Throwable $e) {
        error_log('Broadcast send failed for ' . $r['email'] . ': ' . $e->getMessage());
        $ok = false;
        $sendError = $e->getMessage();
    }

    // Record the outcome BEFORE anything else can go wrong, so a mid-batch
    // death never re-sends an already-delivered email on the next resume.
    if ($ok) {
        $sentThisBatch++;
        $markSent->bind_param('i', $r['id']);
        $markSent->execute();
    } else {
        $failedThisBatch++;
        $failureStatus = 'failed';
        $failureReason = $sendError ?? 'SMTP send failed — see the Emails page for the error';
        $markFailed->bind_param('sss', $failureStatus, $failureReason, $r['id']);
        $markFailed->execute();
    }
}

$takeStmt->close();
$markSent->close();
$markFailed->close();

// --- Authoritative recount after the batch ---
$counts     = $recount($broadcastId);
$total      = $counts['total'];
$totalSent  = $counts['sent'];
$totalFailed = $counts['failed'];
$remaining  = $counts['remaining'];
$processed  = $totalSent + $totalFailed;

// --- Completion: nothing left to send ---
$isDone = $remaining === 0;

// --- Circuit breaker: every claimed send this request failed. Stop the
// chain (SMTP problem) but keep the job resumable via the Resume button.
$breakerTripped = !$isDone && $attempted > 0 && $sentThisBatch === 0 && $failedThisBatch > 0;

if ($isDone) {
    unset($_SESSION['be_batch']);
    $finishJob($broadcastId, $job, $counts);
}

if ($breakerTripped) {
    // Do NOT set finished_at — the job stays resumable.
    unset($_SESSION['be_batch']);
    $msg = "Sending stopped: the last {$failedThisBatch} attempt" . ($failedThisBatch === 1 ? '' : 's')
        . " all failed (SMTP problem). {$totalSent} of {$total} sent, {$remaining} remaining."
        . ' Use the Resume button on this page later to continue.';
    setFlashMessage($msg, 'error');
    header('Location: ' . $redirectUrl);
    exit;
}

// --- Save state and render progress page ---
$_SESSION['be_batch'] = ['broadcast_id' => $broadcastId];

session_write_close();

$pct = $total > 0 ? round(($processed / $total) * 100) : 100;
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
        .adopt { font-size:13px; color:#4a5568; background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; margin-bottom:16px; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($adoptNotice !== null): ?>
            <div class="adopt"><?php echo e($adoptNotice); ?></div>
        <?php endif; ?>
        <h2>Sending broadcast…</h2>
        <div class="count"><?php echo $totalSent; ?> sent<?php echo $totalFailed ? ', ' . $totalFailed . ' failed' : ''; ?></div>
        <div class="sub"><?php echo $processed; ?> of <?php echo $total; ?> processed</div>
        <div class="bar-bg"><div class="bar" style="width:<?php echo $pct; ?>%"></div></div>
        <p class="note">Do not close this page. Already-sent recipients are recorded, so if this page is interrupted you can safely resume.</p>
    </div>
    <form id="continue-form" method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="continue_batch" value="1">
    </form>
    <script>setTimeout(function(){ document.getElementById('continue-form').submit(); }, 1000);</script>
</body>
</html>
