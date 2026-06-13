<?php
/**
 * Applicant Self-Service Application Lookup
 *
 * POST full_name + mobile + dob; all three must match one registration.
 * Returns the submitted data (never images/file paths). A deliberate,
 * documented exception to the no-HTTP-reads rule, protected by:
 * POST-only, generic errors, session rate limiting, attempt logging.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lookup-lib.php';

// Initialize security (rate limiting, headers)
initSecurity();

// Only allow POST requests (keeps personal data out of URLs/access logs)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $nameInput = is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '';
    $mobileInput = is_string($_POST['mobile'] ?? null) ? $_POST['mobile'] : '';
    $dobInput = is_string($_POST['dob'] ?? null) ? $_POST['dob'] : '';

    $name = canonicalLookupName($nameInput);
    $dobOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dobInput, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);

    if ($name === '' || normalizeBdMobile($mobileInput) === '' || !$dobOk) {
        logActivity('Application lookup rejected: incomplete input from ' . hashIp(getClientIp()), 'info');
        errorResponse('No application found with those details', 404);
    }

    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Service temporarily unavailable', 500);
    }

    $stmt = $conn->prepare("
        SELECT id, full_name, email, mobile, address, dob, nationality,
               id_document_type, id_document_number, exam_level, test_date,
               total_amount_paid, payment_method, payment_status, approved,
               submitted_at, payment_retry_token
        FROM registrations
        WHERE dob = ? AND LOWER(full_name) = LOWER(?)
        ORDER BY submitted_at DESC
    ");
    $stmt->bind_param('ss', $dobInput, $name);
    $stmt->execute();
    $result = $stmt->get_result();

    $idTypeLabels = ['passport' => 'Passport', 'national_id' => 'National ID'];
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        if (!mobilesMatch($row['mobile'], $mobileInput)) {
            continue;
        }
        $entry = [
            'id' => $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'mobile' => $row['mobile'],
            'address' => $row['address'],
            'dob' => $row['dob'],
            'nationality' => $row['nationality'],
            'id_document' => trim(($idTypeLabels[$row['id_document_type']] ?? '—') . ' · ' . ($row['id_document_number'] ?? '')),
            'exam_level' => $row['exam_level'],
            'test_date' => $row['test_date'],
            'total_amount' => (float)$row['total_amount_paid'],
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'approved' => (bool)$row['approved'],
            'submitted_at' => $row['submitted_at'],
            'is_upcoming' => isUpcomingTestDate($row['test_date']),
        ];
        if (in_array($row['payment_status'], ['unpaid', 'failed'], true) && !empty($row['payment_retry_token'])) {
            $entry['retry_link'] = SITE_URL . '/payment-retry.html?token=' . $row['payment_retry_token'];
        }
        $applications[] = $entry;
    }
    $stmt->close();

    if (empty($applications)) {
        logActivity('Application lookup: no match for ' . hashIp(getClientIp()), 'info');
        errorResponse('No application found with those details', 404);
    }

    logActivity('Application lookup: ' . count($applications) . ' match(es) served to ' . hashIp(getClientIp()), 'info');
    successResponse(['applications' => $applications], 'Application(s) found');

} catch (Throwable $e) {
    logActivity('Application lookup exception: ' . $e->getMessage(), 'error');
    errorResponse('Service temporarily unavailable', 500);
}
