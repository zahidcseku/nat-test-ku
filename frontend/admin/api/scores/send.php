<?php
/**
 * Send staged score reports — either the rows the admin selected
 * (score_ids[]) or every staged row for the exam (send_all=1).
 *
 * POST /admin/api/scores/send.php
 *   csrf_token
 *   exam_date_id
 *   action: 'send_selected' | 'send_all'
 *   score_ids[]: array of score_reports.id (only for send_selected)
 *   batch_mode: '1' to return JSON instead of redirecting (AJAX caller)
 *
 * Batch mode: processes at most BATCH_SIZE reports per request and
 * returns JSON {sent, failed, errors, processed, remaining}. Same
 * pattern as admission-tickets/send.php — prevents Apache timeouts.
 */

require_once __DIR__ . '/../../auth/middleware.php';

/** @var int Max reports per HTTP request in batch mode. */
const BATCH_SIZE = 15;

$isBatch = ($_POST['batch_mode'] ?? '') === '1';

$jsonError = function (string $message) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isBatch) { $jsonError('Send requires POST'); }
    setFlashMessage('Send requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if ($isBatch) { $jsonError('Invalid CSRF token'); }
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}

$examDateId = trim($_POST['exam_date_id'] ?? '');
$action     = $_POST['action'] ?? '';
$sentBy     = (int) ($_SESSION['user_id'] ?? 0);

if ($examDateId === '') {
    if ($isBatch) { $jsonError('Exam date is required'); }
    setFlashMessage('Exam date is required', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}

require_once __DIR__ . '/../../lib/score-staging.php';

$conn = getDbConnection();

if ($action === 'send_all') {
    $stmt = $conn->prepare("
        SELECT id FROM score_reports
        WHERE exam_date_id = ? AND send_status IN ('staged','failed')
        ORDER BY id ASC
    ");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $ids  = array_column($res->fetch_all(MYSQLI_ASSOC), 'id');
    $stmt->close();
} else {
    $raw = $_POST['score_ids'] ?? [];
    if (!is_array($raw) || empty($raw)) {
        $msg = 'No scores selected. Tick the checkbox next to each row, or use "Send All Staged".';
        if ($isBatch) { $jsonError($msg); }
        setFlashMessage($msg, 'error');
        header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
        exit;
    }
    $ids = array_map('intval', array_filter($raw, fn($v) => (int) $v > 0));
    if (empty($ids)) {
        if ($isBatch) { $jsonError('No valid score IDs in selection'); }
        setFlashMessage('No valid score IDs in selection', 'error');
        header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
        exit;
    }
}

// In batch mode, process only BATCH_SIZE per request.
$batchIds  = $ids;
$remaining = 0;
if ($isBatch && count($ids) > BATCH_SIZE) {
    $batchIds  = array_slice($ids, 0, BATCH_SIZE);
    $remaining = count($ids) - count($batchIds);
}

set_time_limit(120);

try {
    $result = sendScoreReports($batchIds, $sentBy);
} catch (Throwable $e) {
    error_log('Score report send FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if ($isBatch) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage(), 'sent' => 0, 'failed' => count($batchIds)]);
        exit;
    }
    setFlashMessage('Send failed: ' . $e->getMessage(), 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

// For send_all batch mode: recount remaining staged/failed rows.
if ($isBatch && $action === 'send_all') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM score_reports
        WHERE exam_date_id = ? AND send_status IN ('staged','failed')
    ");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $remaining = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

if ($isBatch) {
    header('Content-Type: application/json');
    echo json_encode([
        'sent'      => $result['sent'],
        'failed'    => $result['failed'],
        'errors'    => array_slice($result['errors'], 0, 3),
        'processed' => count($batchIds),
        'remaining' => $remaining,
    ]);
    exit;
}

$attempted = count($ids);
$msg = "Sent {$result['sent']} of {$attempted} score report(s).";
if ($result['failed'] > 0) {
    $msg .= " {$result['failed']} failed.";
    if (!empty($result['errors'])) {
        $msg .= ' First error: ' . implode(' | ', array_slice($result['errors'], 0, 3));
        if (count($result['errors']) > 3) {
            $msg .= ' (+' . (count($result['errors']) - 3) . ' more)';
        }
    }
}
setFlashMessage($msg, $result['failed'] > 0 ? 'error' : 'success');
header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
exit;
