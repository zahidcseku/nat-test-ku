<?php
/**
 * Mark selected score-report disposals as incorrect, and unstage them
 * so they can be reviewed and re-sent.
 *
 * Super-admin only. Manual cleanup action — mirror of
 * /api/admission-tickets/mark-incorrect.php for the score_reports table.
 *
 * POST /admin/api/scores/mark-incorrect.php
 *   csrf_token
 *   exam_date_id
 *   score_ids[]: array of score_reports.id
 *
 * Sets send_status='staged', emailed_at=NULL, last_error=explanatory
 * note, incorrect_disposal_at=NOW(), incorrect_disposal_by=<user>.
 * Cleared again on next successful send (lib/score-staging.php).
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Mark-incorrect requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}
if (!isSuperAdmin()) {
    setFlashMessage('Only super admins can mark disposals incorrect.', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}

$examDateId = trim($_POST['exam_date_id'] ?? '');
$raw        = $_POST['score_ids'] ?? [];
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$username   = $_SESSION['username'] ?? 'super admin';

if ($examDateId === '') {
    setFlashMessage('Exam date is required', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php');
    exit;
}
if (!is_array($raw) || empty($raw)) {
    setFlashMessage('No scores selected. Tick the checkbox next to each row first.', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

$ids = array_map('intval', array_filter($raw, fn($v) => (int) $v > 0));
if (empty($ids)) {
    setFlashMessage('No valid score IDs in selection', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    setFlashMessage('Database connection failed', 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

$note = 'Marked as incorrect disposal by ' . $username . ' on ' . date('Y-m-d H:i:s');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));

$sql = "UPDATE score_reports
        SET send_status         = 'staged',
            emailed_at           = NULL,
            last_error           = ?,
            incorrect_disposal_at = NOW(),
            incorrect_disposal_by = ?
        WHERE exam_date_id = ?
          AND id IN ($placeholders)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    setFlashMessage('Prepare failed: ' . $conn->error, 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

// 3 leading placeholders (s note, i user_id, s exam_date_id) + one i per id.
$bindTypes = 'sis' . $types;
$params    = array_merge([$bindTypes, $note, $userId, $examDateId], $ids);
$stmt->bind_param(...$params);
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    setFlashMessage('Update failed: ' . $err, 'error');
    header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
    exit;
}
$affected = $stmt->affected_rows;
$stmt->close();

try {
    logAudit(
        'mark_score_report_incorrect',
        'score_reports',
        null,
        null,
        ['exam_date_id' => $examDateId, 'score_ids' => $ids, 'marked_by' => $userId, 'affected' => $affected]
    );
} catch (Throwable $e) {
    error_log('score mark-incorrect audit failed: ' . $e->getMessage());
}

setFlashMessage(
    "Marked {$affected} score report(s) as incorrect disposal and reset to staged.",
    $affected > 0 ? 'success' : 'error'
);
header('Location: ' . BASE_URL . '/pages/scores.php?exam_date_id=' . urlencode($examDateId));
exit;
