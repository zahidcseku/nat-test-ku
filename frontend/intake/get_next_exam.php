<?php
/**
 * Get next exam date from database
 *
 * This endpoint queries the exam_dates table for the next upcoming exam
 * and returns the exam details in JSON format.
 */

// Define service constant to allow config.php inclusion
define('INTAKE_SERVICE', true);

// Load configuration and database connection
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    // Create database connection using the same method as register.php
    $conn = getDbConnection();

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Get the next exam date (today or future, but not before July 11, 2026)
    $today = date('Y-m-d');
    $center_opening_date = '2026-07-11';

    // Use the later of today or the center opening date
    $effective_start_date = (strtotime($today) > strtotime($center_opening_date)) ? $today : $center_opening_date;

    $query = "
        SELECT
            ed.id,
            ed.exam_date,
            ed.registration_deadline,
            GROUP_CONCAT(el.level ORDER BY el.level SEPARATOR ',') as levels
        FROM exam_dates ed
        LEFT JOIN exam_levels el ON ed.id = el.exam_date_id
        WHERE ed.exam_date >= ?
        GROUP BY ed.id, ed.exam_date, ed.registration_deadline
        ORDER BY ed.exam_date ASC
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Query preparation failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $effective_start_date);
    if (!$stmt->execute()) {
        throw new Exception('Query execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $exam = $result->fetch_assoc();

    if ($exam) {
        // Calculate registration status
        $registration_deadline = $exam['registration_deadline'];
        $deadline_timestamp = strtotime($registration_deadline);
        $effective_timestamp = strtotime($effective_start_date);

        if ($effective_timestamp > $deadline_timestamp) {
            $registration_status = "Registration closed";
        } else {
            $days_until_deadline = ceil(($deadline_timestamp - $effective_timestamp) / (60 * 60 * 24));
            $registration_status = "Registration closes in " . $days_until_deadline . " days";
        }

        // Format exam date for display
        $exam_date_obj = DateTime::createFromFormat('Y-m-d', $exam['exam_date']);
        if (!$exam_date_obj) {
            throw new Exception('Invalid exam_date format');
        }
        $formatted_exam_date = $exam_date_obj->format('F j, Y');

        // Format registration deadline for display
        $deadline_obj = DateTime::createFromFormat('Y-m-d', $registration_deadline);
        if (!$deadline_obj) {
            throw new Exception('Invalid deadline format');
        }
        $formatted_deadline = $deadline_obj->format('F j, Y');

        echo json_encode([
            'success' => true,
            'data' => [
                'exam_date' => $formatted_exam_date,
                'registration_deadline' => $formatted_deadline,
                'registration_status' => $registration_status,
                'levels' => $exam['levels'] ? explode(',', $exam['levels']) : []
            ]
        ]);
    } else {
        // No upcoming exams found
        echo json_encode([
            'success' => false,
            'message' => 'No upcoming exams scheduled'
        ]);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
