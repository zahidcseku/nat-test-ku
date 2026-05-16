<?php
/**
 * Get exam schedule with levels for schedule page
 *
 * This endpoint returns all exam dates with their registration periods
 * and available levels for the schedule page table.
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
    // Get database connection
    $conn = getDbConnection();
    if (!$conn) {
        logActivity("Database connection failed", 'error');
        errorResponse('Database connection failed', 500);
    }

    // Get exams on or after center opening date (July 11, 2026)
    $center_opening_date = '2026-07-11';
    $today = date('Y-m-d');

    // Query to get exam dates with levels
    $query = "
        SELECT
            ed.id,
            ed.exam_date,
            ed.registration_deadline,
            ed.registration_opening,
            GROUP_CONCAT(el.level ORDER BY el.level SEPARATOR ',') as levels
        FROM exam_dates ed
        LEFT JOIN exam_levels el ON ed.id = el.exam_date_id
        WHERE ed.exam_date >= ?
        GROUP BY ed.id, ed.exam_date, ed.registration_deadline, ed.registration_opening
        ORDER BY ed.exam_date ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("Query preparation failed: " . $conn->error, 'error');
        errorResponse('Database error', 500);
    }

    $stmt->bind_param('s', $center_opening_date);
    if (!$stmt->execute()) {
        logActivity("Query execution failed: " . $stmt->error, 'error');
        errorResponse('Database error', 500);
    }

    $result = $stmt->get_result();
    $exams = [];

    while ($row = $result->fetch_assoc()) {
        // Format dates
        $exam_date_obj = DateTime::createFromFormat('Y-m-d', $row['exam_date']);
        $deadline_obj = DateTime::createFromFormat('Y-m-d', $row['registration_deadline']);
        $opening_obj = DateTime::createFromFormat('Y-m-d', $row['registration_opening']);

        // Format month and day for display
        $exam_month = $exam_date_obj->format('F');
        $exam_day = $exam_date_obj->format('j');
        $deadline_day = $deadline_obj->format('F j');
        $deadline_year = $deadline_obj->format('Y');
        $opening_month = $opening_obj->format('F');
        $opening_day = $opening_obj->format('j');

        // Get available levels
        $levels = $row['levels'] ? explode(',', $row['levels']) : [];

        // Map level codes to display format
        $level_mapping = [
            '1Q' => '1Q',
            '2Q' => '2Q',
            '3Q' => '3Q',
            '4Q' => '4Q',
            '5Q' => '5Q'
        ];

        // Format levels for display
        $formatted_levels = [];
        foreach ($levels as $level) {
            if (isset($level_mapping[$level])) {
                $formatted_levels[] = $level_mapping[$level];
            }
        }

        $exams[] = [
            'id' => $row['id'],
            'exam_date' => $row['exam_date'],
            'exam_month' => $exam_month,
            'exam_day' => $exam_day,
            'registration_opening_month' => $opening_month,
            'registration_opening_day' => $opening_day,
            'registration_deadline_month' => $deadline_obj->format('F'),
            'registration_deadline_day' => $deadline_day,
            'registration_deadline_year' => $deadline_year,
            'levels' => $formatted_levels
        ];
    }

    $stmt->close();
    $conn->close();

    // Send success response
    successResponse([
        'exams' => $exams,
        'count' => count($exams)
    ], 'Exam schedule retrieved successfully');

} catch (Exception $e) {
    logActivity("Exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
