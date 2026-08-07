<?php
/**
 * Mark selected admission-ticket disposals as incorrect, and unstage
 * them so they can be reviewed and re-sent.
 *
 * Super-admin only. Manual cleanup action — typically used when a
 * ticket was emailed to the wrong recipient (e.g. due to a data bug
 * like the reg_no period collision), and an admin wants to flag it
 * for the record and put it back to 'staged' without losing history.
 *
 * POST /admin/api/admission-tickets/mark-incorrect.php
 *   csrf_token
 *   exam_date_id
 *   ticket_ids[]: array of admission_tickets.id
 *
 * Sets send_status='staged', emailed_at=NULL, last_error=explanatory
 * note, incorrect_disposal_at=NOW(), incorrect_disposal_by=<user>.
 * The incorrect_disposal_* columns are cleared again on the next
 * successful send (see lib/ticket-staging.php sendTickets()).
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Mark-incorrect requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}
if (!isSuperAdmin()) {
    setFlashMessage('Only super admins can mark disposals incorrect.', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$examDateId = trim($_POST['exam_date_id'] ?? '');
$raw        = $_POST['ticket_ids'] ?? [];
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$username   = $_SESSION['username'] ?? 'super admin';

if ($examDateId === '') {
    setFlashMessage('Exam date is required', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}
if (!is_array($raw) || empty($raw)) {
    setFlashMessage('No tickets selected. Tick the checkbox next to each row first.', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

$ids = array_map('intval', array_filter($raw, fn($v) => (int) $v > 0));
if (empty($ids)) {
    setFlashMessage('No valid ticket IDs in selection', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    setFlashMessage('Database connection failed', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

// Scope the UPDATE to this exam_date_id so a tampered ticket_id can't
// affect a different exam's rows.
$note = 'Marked as incorrect disposal by ' . $username . ' on ' . date('Y-m-d H:i:s');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));

// UPDATE with parameterized IN clause. Bind: note, exam_date_id, then ids.
$sql = "UPDATE admission_tickets
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
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

// Bind params: note (s), user_id (i), exam_date_id (s), then ids (i each)
$bindTypes = 'siss' . $types;
$params    = array_merge([$bindTypes, $note, $userId, $examDateId], $ids);
$stmt->bind_param(...$params);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

try {
    logAudit(
        'mark_admission_ticket_incorrect',
        'admission_tickets',
        null,
        null,
        ['exam_date_id' => $examDateId, 'ticket_ids' => $ids, 'marked_by' => $userId, 'affected' => $affected]
    );
} catch (Throwable $e) {
    error_log('mark-incorrect audit failed: ' . $e->getMessage());
}

setFlashMessage(
    "Marked {$affected} ticket(s) as incorrect disposal and reset to staged.",
    $affected > 0 ? 'success' : 'error'
);
header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
exit;
