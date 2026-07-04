<?php
/**
 * Stage admission tickets from an xlsx + zip upload.
 *
 * POST /admin/api/admission-tickets/upload.php
 *   csrf_token, exam_date_id, exam_date (YYYY-MM-DD)
 *   xlsx_file   (Examinee List.xlsx — columns: ID, RegNumber)
 *   tickets_zip (zip of <ID>.pdf files)
 *
 * Redirects back to /admin/pages/admission-tickets.php with a flash.
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Upload requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$examDateId = trim($_POST['exam_date_id'] ?? '');
$examDate   = trim($_POST['exam_date']     ?? '');

if ($examDateId === '' || $examDate === '') {
    setFlashMessage('Please select an exam date', 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

// Validate both uploads.
$xlsxValidation = validateFileUpload(
    $_FILES['xlsx_file'] ?? [],
    [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',            // some servers detect xlsx as zip
        'application/octet-stream',   // some servers give up and use this
    ]
);
if (!$xlsxValidation['valid']) {
    setFlashMessage('Examinee List.xlsx: ' . $xlsxValidation['error'], 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

$zipValidation = validateFileUpload(
    $_FILES['tickets_zip'] ?? [],
    ['application/zip', 'application/x-zip-compressed']
);
if (!$zipValidation['valid']) {
    setFlashMessage('Tickets ZIP: ' . $zipValidation['error'], 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php');
    exit;
}

require_once __DIR__ . '/../../lib/ticket-staging.php';

$createdBy = (int) ($_SESSION['user_id'] ?? 0);

$result = stageTicketsFromUpload(
    $_FILES['xlsx_file']['tmp_name'],
    $_FILES['tickets_zip']['tmp_name'],
    $examDateId,
    $examDate,
    $createdBy
);

if (!$result['success']) {
    $msg = 'Staging failed: ' . implode('; ', $result['errors']);
    if (!empty($result['warnings'])) {
        $msg .= ' — warnings: ' . implode('; ', array_slice($result['warnings'], 0, 5));
        if (count($result['warnings']) > 5) {
            $msg .= ' (+' . (count($result['warnings']) - 5) . ' more)';
        }
    }
    setFlashMessage($msg, 'error');
    header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
    exit;
}

$msg = "Staged {$result['staged']} ticket(s).";
if (!empty($result['warnings'])) {
    $msg .= ' Warnings: ' . implode('; ', array_slice($result['warnings'], 0, 5));
    if (count($result['warnings']) > 5) {
        $msg .= ' (+' . (count($result['warnings']) - 5) . ' more)';
    }
}
setFlashMessage($msg, 'success');
header('Location: ' . BASE_URL . '/pages/admission-tickets.php?exam_date_id=' . urlencode($examDateId));
exit;
