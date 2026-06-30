<?php
/**
 * Get next exam date from database
 *
 * This endpoint queries the exam_dates table for the next upcoming exam
 * and returns the exam details in JSON format.
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
        LIMIT 2
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("Query preparation failed: " . $conn->error, 'error');
        errorResponse('Database error', 500);
    }

    $stmt->bind_param('s', $effective_start_date);
    if (!$stmt->execute()) {
        logActivity("Query execution failed: " . $stmt->error, 'error');
        errorResponse('Database error', 500);
    }

    $result = $stmt->get_result();
    $exam = $result->fetch_assoc();
    $following_exam = $result->fetch_assoc();

    if ($exam) {
        // Calculate registration status based on today's date
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
        if (!$exam_date_obj) {
            logActivity("Invalid exam_date format: " . $exam['exam_date'], 'error');
            errorResponse('Invalid exam date format', 500);
        }
        $formatted_exam_date = $exam_date_obj->format('F j, Y');

        // Format registration deadline for display
        $deadline_obj = DateTime::createFromFormat('Y-m-d', $registration_deadline);
        if (!$deadline_obj) {
            logActivity("Invalid deadline format: " . $registration_deadline, 'error');
            errorResponse('Invalid deadline format', 500);
        }
        $formatted_deadline = $deadline_obj->format('F j, Y');

        // Build registration note for the following (next-to-next) exam
        $next_exam_note = null;
        $next_exam_short_date = null;
        if ($following_exam && !empty($following_exam['exam_date']) && !empty($following_exam['registration_deadline'])) {
            $following_exam_obj = DateTime::createFromFormat('Y-m-d', $following_exam['exam_date']);
            $following_deadline_ts = strtotime($following_exam['registration_deadline']);
            if ($following_exam_obj && $following_deadline_ts !== false) {
                $following_exam_short = $following_exam_obj->format('F j');
                $next_exam_short_date = $following_exam_short;
                if ($today_timestamp > $following_deadline_ts) {
                    $next_exam_note = 'Registration for ' . $following_exam_short . ' exam is closed.';
                } else {
                    $following_days = ceil(($following_deadline_ts - $today_timestamp) / (60 * 60 * 24));
                    $next_exam_note = 'Registration for ' . $following_exam_short . ' exam closes in ' . $following_days . ' days.';
                }
            }
        }

        // Send success response
        successResponse([
            'exam_date' => $formatted_exam_date,
            'registration_deadline' => $formatted_deadline,
            'registration_status' => $registration_status,
            'next_exam_note' => $next_exam_note,
            'next_exam_short_date' => $next_exam_short_date,
            'levels' => $exam['levels'] ? explode(',', $exam['levels']) : []
        ], 'Exam date retrieved successfully');

    } else {
        // No upcoming exams found
        successResponse([], 'No upcoming exams scheduled');
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    logActivity("Exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
