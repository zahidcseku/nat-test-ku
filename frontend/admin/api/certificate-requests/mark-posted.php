<?php
/**
 * Mark a certificate request as posted.
 *
 * POST /admin/api/certificate-requests/mark-posted.php
 *   csrf_token
 *   id                certificate_requests.id
 *   tracking_number   optional
 *
 * Sets certificate_status='posted', posted_at=NOW(), posted_by=session,
 * stores tracking_number, and fires the certificate_posted email to
 * the examinee. Mirrors the CSRF + redirect posture of api/scores/send.php.
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('Mark posted requires POST', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}

require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../lib/email-templates.php';

$id        = trim($_POST['id'] ?? '');
$tracking  = trim($_POST['tracking_number'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
    setFlashMessage('Invalid request id', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    setFlashMessage('Database connection failed', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}

// Load the row, verify state, and pull the fields needed for the email.
$stmt = $conn->prepare("
    SELECT cr.id, cr.registration_id, cr.exam_date_id, cr.reg_no, cr.payment_status,
           cr.certificate_status, cr.recipient_name, cr.recipient_phone,
           cr.house_street, cr.area_thana, cr.district, cr.postal_code,
           r.full_name, r.email, ed.exam_date
    FROM certificate_requests cr
    LEFT JOIN registrations r ON r.id = cr.registration_id
    LEFT JOIN exam_dates ed   ON ed.id = cr.exam_date_id
    WHERE cr.id = ?
    LIMIT 1
");
$stmt->bind_param('s', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    setFlashMessage('Certificate request not found', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}

if ($row['payment_status'] !== 'paid') {
    setFlashMessage('Cannot mark unpaid request as posted', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}
if ($row['certificate_status'] === 'posted') {
    setFlashMessage('Certificate already marked posted', 'info');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}

$userId   = (int) ($_SESSION['user_id'] ?? 0);
$trackVal = $tracking !== '' ? substr($tracking, 0, 100) : null;

$update = $conn->prepare("
    UPDATE certificate_requests
    SET certificate_status = 'posted',
        posted_at = NOW(),
        posted_by = ?,
        tracking_number = ?
    WHERE id = ?
      AND certificate_status = 'requested'
");
$update->bind_param('iss', $userId, $trackVal, $id);
$ok = $update->execute();
$affected = $update->affected_rows;
$update->close();

if (!$ok || $affected === 0) {
    setFlashMessage('Could not update certificate status (already posted?)', 'error');
    header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
    exit;
}

// Audit trail.
logAudit(
    'mark_certificate_posted',
    'certificate_requests',
    $id,
    ['certificate_status' => 'requested', 'payment_status' => $row['payment_status']],
    ['certificate_status' => 'posted', 'posted_by' => $userId, 'tracking_number' => $trackVal]
);

// Send certificate_posted email.
$dateObj   = DateTime::createFromFormat('Y-m-d', $row['exam_date'] ?? '');
$examDateDisplay = $dateObj ? $dateObj->format('F j, Y') : ($row['exam_date'] ?? '');
$fullName = html_entity_decode($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8');

$trackingBlock = $trackVal
    ? '<strong>Tracking Number:</strong> ' . htmlspecialchars($trackVal, ENT_QUOTES, 'UTF-8') . '<br>'
    : '';

$mail = renderEmailTemplate('certificate_posted', [
    'full_name'      => $fullName,
    'reg_no'         => $row['reg_no'],
    'exam_date'      => $examDateDisplay,
    'recipient_name' => $row['recipient_name'],
    'house_street'   => $row['house_street'],
    'area_thana'     => $row['area_thana'],
    'district'       => $row['district'],
    'postal_code'    => $row['postal_code'] ?? '',
    'tracking_block' => $trackingBlock,
]);

if (!empty($mail['subject']) && !empty($row['email'])) {
    sendEmail($row['email'], $mail['subject'], $mail['body'], $row['registration_id'], 'certificate_posted');
}

$msg = 'Certificate marked as posted.';
if (!empty($row['email'])) {
    $msg .= ' Email sent to ' . $row['email'];
}
setFlashMessage($msg, 'success');
header('Location: ' . BASE_URL . '/pages/certificate-requests.php');
exit;
