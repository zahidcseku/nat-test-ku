<?php
/**
 * Get next exam date from database
 *
 * This endpoint queries the exam_dates table for the next upcoming exam
 * and returns the exam details in JSON format.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Database configuration - inline for this public endpoint
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'nat_test_intake';
$DB_USER = getenv('DB_USER') ?: 'intake_user';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_CHARSET = 'utf8mb4';

try {
    // Create database connection
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    if ($conn->connect_error) {
        throw new Exception('Database connection failed');
    }

    $conn->set_charset($DB_CHARSET);

    // Get the next exam date (today or future)
    $today = date('Y-m-d');

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
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $exam = $result->fetch_assoc();

    if ($exam) {
        // Calculate registration status
        $registration_deadline = $exam['registration_deadline'];
        $deadline_timestamp = strtotime($registration_deadline);
        $today_timestamp = strtotime($today);

        if ($today_timestamp > $deadline_timestamp) {
            $registration_status = "Registration closed";
        } else {
            $days_until_deadline = ceil(($deadline_timestamp - $today_timestamp) / (60 * 60 * 24));
            $registration_status = "Registration closes in " . $days_until_deadline . " days";
        }

        // Format exam date for display
        $exam_date_obj = DateTime::createFromFormat('Y-m-d', $exam['exam_date']);
        $formatted_exam_date = $exam_date_obj->format('F j, Y');

        // Format registration deadline for display
        $deadline_obj = DateTime::createFromFormat('Y-m-d', $registration_deadline);
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
