<?php
/**
 * Get past exam dates eligible for certificate requests.
 *
 * Returns exam dates that have already occurred AND have at least one
 * staged/sent score report (the eligibility gate for requesting a
 * certificate). Public, no PII.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $conn = getDbConnection();
    if (!$conn) {
        logActivity('Database connection failed (get_past_exam_dates)', 'error');
        errorResponse('Database connection failed', 500);
    }

    // Past exams with at least one staged or sent score report.
    $query = "
        SELECT DISTINCT ed.id, ed.exam_date
        FROM exam_dates ed
        INNER JOIN score_reports sr ON sr.exam_date_id = ed.id
        WHERE ed.exam_date < CURDATE()
          AND sr.send_status IN ('staged', 'sent')
        ORDER BY ed.exam_date DESC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity('Prepare failed (get_past_exam_dates): ' . $conn->error, 'error');
        errorResponse('Database error', 500);
    }
    if (!$stmt->execute()) {
        logActivity('Execute failed (get_past_exam_dates): ' . $stmt->error, 'error');
        errorResponse('Database error', 500);
    }

    $result = $stmt->get_result();
    $exams = [];
    while ($row = $result->fetch_assoc()) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $row['exam_date']);
        if (!$dateObj) {
            continue;
        }
        $exams[] = [
            'id'      => $row['id'],
            'value'   => $row['exam_date'],
            'display' => $dateObj->format('F j, Y'),
        ];
    }
    $stmt->close();
    $conn->close();

    successResponse(['exams' => $exams, 'count' => count($exams)], 'Past exam dates retrieved');

} catch (Exception $e) {
    logActivity('Exception (get_past_exam_dates): ' . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
