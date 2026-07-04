<?php
/**
 * Upload or remove the per-exam-date "Exam Guide" PDF. When set, this
 * PDF is attached to every admission-ticket email sent for that exam.
 *
 * POST /admin/api/admission-tickets/guide.php
 *   csrf_token, exam_date_id
 *   action: 'upload' (default) | 'delete'
 *   guide_pdf: file upload (only for action=upload)
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Guide upload requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$examDateId = trim($_POST['exam_date_id'] ?? '');
if ($examDateId === '') {
    setFlashMessage('Exam date is required', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    setFlashMessage('Database connection failed', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

// Verify exam_date exists and grab the current guide path.
$stmt = $conn->prepare("SELECT id, exam_date, guide_pdf_path FROM exam_dates WHERE id = ?");
$stmt->bind_param('s', $examDateId);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exam) {
    setFlashMessage('Exam date not found', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$targetPath = UPLOAD_PATH . 'guides/' . $examDateId . '.pdf';

$action = $_POST['action'] ?? 'upload';

// --- Delete ---------------------------------------------------------
if ($action === 'delete') {
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }
    $stmt = $conn->prepare("UPDATE exam_dates SET guide_pdf_path = NULL WHERE id = ?");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $stmt->close();

    try {
        logAudit('remove_exam_guide', 'exam_dates', $examDateId);
    } catch (Throwable $e) {}

    setFlashMessage('Exam guide removed. Future admission-ticket emails for this exam date will not include an attachment.', 'success');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

// --- Upload ---------------------------------------------------------
$validation = validateFileUpload(
    $_FILES['guide_pdf'] ?? [],
    ['application/pdf']
);
if (!$validation['valid']) {
    setFlashMessage('Exam guide: ' . $validation['error'], 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

if (!is_dir(dirname($targetPath))) {
    mkdir(dirname($targetPath), 0755, true);
}

// Remove old guide if it exists (always same filename per exam_date_id).
if (is_file($targetPath)) {
    @unlink($targetPath);
}

if (!move_uploaded_file($_FILES['guide_pdf']['tmp_name'], $targetPath)) {
    setFlashMessage('Failed to save exam guide file on the server', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

// Resolve absolute path so the runtime check at send-time matches.
$absPath = realpath($targetPath) ?: $targetPath;

$stmt = $conn->prepare("UPDATE exam_dates SET guide_pdf_path = ? WHERE id = ?");
$stmt->bind_param('ss', $absPath, $examDateId);
$stmt->execute();
$stmt->close();

try {
    logAudit('upload_exam_guide', 'exam_dates', $examDateId);
} catch (Throwable $e) {}

setFlashMessage('Exam guide uploaded. It will be attached to every admission-ticket email for ' . formatDate($exam['exam_date']) . '.', 'success');
header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
exit;
