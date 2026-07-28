<?php
/**
 * Certificate Request — Identity Verification
 *
 * Examinee picks a past exam date and submits reg_no + full_name + dob.
 * Eligibility gate: the reg_no must appear in score_reports for the
 * chosen exam date (joined to registrations via registration_sheet_numbers).
 *
 * Returns the registration id + pre-fill data (name, email, mobile) so
 * the form can populate the shipping-address step. Never reveals which
 * field was wrong — same generic-error posture as application-lookup.php.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lookup-lib.php';

// Initialize security (rate limiting, headers, multipart enforcement)
initSecurity();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $examDateId = is_string($_POST['exam_date_id'] ?? null) ? trim($_POST['exam_date_id']) : '';
    $regNo      = is_string($_POST['reg_no']      ?? null) ? trim($_POST['reg_no'])      : '';
    $nameInput  = is_string($_POST['full_name']   ?? null) ? $_POST['full_name']         : '';
    $dobInput   = is_string($_POST['dob']         ?? null) ? $_POST['dob']               : '';

    $name = canonicalLookupName($nameInput);
    $dobOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dobInput, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);

    // UUID format for exam_date_id
    $examDateIdOk = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $examDateId) === 1;

    if ($regNo === '' || $name === '' || !$dobOk || !$examDateIdOk) {
        logActivity('Certificate verify rejected: incomplete input from ' . hashIp(getClientIp()), 'info');
        errorResponse('No matching examinee found for this exam', 404);
    }

    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Service temporarily unavailable', 500);
    }

    // Eligibility: reg_no -> registration -> score_reports row for this exam date.
    $stmt = $conn->prepare("
        SELECT r.id, r.full_name, r.email, r.mobile
        FROM registrations r
        INNER JOIN registration_sheet_numbers rsn ON rsn.registration_id = r.id
        INNER JOIN score_reports sr ON sr.reg_no = rsn.reg_no AND sr.exam_date_id = ?
        WHERE rsn.reg_no = ?
          AND r.dob = ?
          AND LOWER(r.full_name) = LOWER(?)
        LIMIT 1
    ");
    if (!$stmt) {
        logActivity('Prepare failed (certificate-verify): ' . $conn->error, 'error');
        errorResponse('Service temporarily unavailable', 500);
    }
    $stmt->bind_param('ssss', $examDateId, $regNo, $dobInput, $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        logActivity('Certificate verify: no match for ' . hashIp(getClientIp()), 'info');
        errorResponse('No matching examinee found for this exam', 404);
    }

    logActivity('Certificate verify: match served for reg_no=' . $regNo . ' from ' . hashIp(getClientIp()), 'info');

    successResponse([
        'registration_id' => $row['id'],
        'full_name'       => html_entity_decode($row['full_name'], ENT_QUOTES, 'UTF-8'),
        'email'           => $row['email'],
        'mobile'          => $row['mobile'],
    ], 'Examinee verified');

} catch (Throwable $e) {
    logActivity('Certificate verify exception: ' . $e->getMessage(), 'error');
    errorResponse('Service temporarily unavailable', 500);
}
