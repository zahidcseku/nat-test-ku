<?php
/**
 * Export Registrations to CSV
 */

require_once __DIR__ . '/../../auth/middleware.php';

$conn = getDbConnection();

// Get filter parameters (same as registrations page)
$status = $_GET['status'] ?? '';
$examDate = $_GET['exam_date'] ?? '';
$examLevel = $_GET['exam_level'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$where = ['1=1'];
$params = [];
$types = '';

if (!empty($status)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
    $types .= 's';
}

if (!empty($examDate)) {
    $where[] = 'r.test_date = ?';
    $params[] = $examDate;
    $types .= 's';
}

if (!empty($examLevel)) {
    $where[] = 'r.exam_level = ?';
    $params[] = $examLevel;
    $types .= 's';
}

if (!empty($dateFrom)) {
    $where[] = 'DATE(r.submitted_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if (!empty($dateTo)) {
    $where[] = 'DATE(r.submitted_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereClause = implode(' AND ', $where);

// Get registrations
$query = "
    SELECT
        r.id,
        r.full_name,
        r.email,
        r.mobile,
        r.address,
        r.dob,
        r.gender,
        r.nationality,
        r.exam_level,
        r.test_date,
        r.payment_method,
        r.status,
        r.submitted_at,
        r.reviewed_at,
        r.rejection_reasons
    FROM registrations r
    WHERE $whereClause
    ORDER BY r.submitted_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="registrations_' . date('Y-m-d') . '.csv"');

// Create CSV file
$output = fopen('php://output', 'w');

// CSV headers
fputcsv($output, [
    'ID',
    'Full Name',
    'Email',
    'Mobile',
    'Address',
    'Date of Birth',
    'Gender',
    'Nationality',
    'Exam Level',
    'Test Date',
    'Payment Method',
    'Status',
    'Submitted At',
    'Reviewed At',
    'Rejection Reasons'
]);

// CSV data
foreach ($registrations as $reg) {
    fputcsv($output, [
        $reg['id'],
        $reg['full_name'],
        $reg['email'],
        $reg['mobile'],
        $reg['address'],
        $reg['dob'],
        $reg['gender'],
        $reg['nationality'],
        $reg['exam_level'],
        $reg['test_date'],
        $reg['payment_method'],
        $reg['status'],
        $reg['submitted_at'],
        $reg['reviewed_at'],
        $reg['rejection_reasons']
    ]);
}

fclose($output);

// Log export
logAudit('export_registrations', 'registrations', null, null, ['count' => count($registrations)]);
