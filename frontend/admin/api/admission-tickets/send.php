<?php
/**
 * Send staged admission tickets — either the rows the admin selected
 * (ticket_ids[]) or every staged ticket for the exam (send_all=1).
 *
 * POST /admin/api/admission-tickets/send.php
 *   csrf_token
 *   exam_date_id
 *   action: 'send_selected' | 'send_all'
 *   ticket_ids[]: array of admission_tickets.id (only for send_selected)
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Send requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$examDateId = trim($_POST['exam_date_id'] ?? '');
$action     = $_POST['action'] ?? '';
$sentBy     = (int) ($_SESSION['user_id'] ?? 0);

if ($examDateId === '') {
    setFlashMessage('Exam date is required', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

require_once __DIR__ . '/../../lib/ticket-staging.php';

$conn = getDbConnection();

if ($action === 'send_all') {
    // Send every staged (or previously-failed) ticket for this exam.
    $stmt = $conn->prepare("
        SELECT id FROM admission_tickets
        WHERE exam_date_id = ? AND send_status IN ('staged','failed')
        ORDER BY id ASC
    ");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $ids  = array_column($res->fetch_all(MYSQLI_ASSOC), 'id');
    $stmt->close();
} else {
    // Send only the selected rows.
    $raw = $_POST['ticket_ids'] ?? [];
    if (!is_array($raw) || empty($raw)) {
        setFlashMessage('No tickets selected. Tick the checkbox next to each row, or use "Send All Staged".', 'error');
        header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
        exit;
    }
    $ids = array_map('intval', array_filter($raw, fn($v) => (int) $v > 0));
    if (empty($ids)) {
        setFlashMessage('No valid ticket IDs in selection', 'error');
        header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
        exit;
    }
}

$result = sendTickets($ids, $sentBy);

$attempted = count($ids);
$msg = "Sent {$result['sent']} of {$attempted} ticket(s).";
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
header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
exit;
