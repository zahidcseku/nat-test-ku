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

    // Get all exams for current system year (2026)
    $current_year = date('Y');

    // Query to get exam dates with levels for current year
    $query = "
        SELECT
            ed.id,
            ed.exam_date,
            ed.registration_deadline,
            GROUP_CONCAT(el.level ORDER BY el.level SEPARATOR ',') as levels
        FROM exam_dates ed
        LEFT JOIN exam_levels el ON ed.id = el.exam_date_id
        WHERE YEAR(ed.exam_date) = ?
        GROUP BY ed.id, ed.exam_date, ed.registration_deadline
        ORDER BY ed.exam_date ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("Query preparation failed: " . $conn->error, 'error');
        errorResponse('Database error', 500);
    }

    $stmt->bind_param('s', $current_year);
    if (!$stmt->execute()) {
        logActivity("Query execution failed: " . $stmt->error, 'error');
        errorResponse('Database error', 500);
    }

    $result = $stmt->get_result();
    $exams = [];

    // Get current date and center opening date
    $current_date = date('Y-m-d');
    $current_month = date('n'); // 1-12
    $current_year = date('Y');
    $center_opening = '2026-07-11';

    // Count exams from center opening
    $exam_count = 0;

    while ($row = $result->fetch_assoc()) {
        // Format dates
        $exam_date_obj = DateTime::createFromFormat('Y-m-d', $row['exam_date']);
        $deadline_obj = DateTime::createFromFormat('Y-m-d', $row['registration_deadline']);

        // Format month and day for display
        $exam_month = $exam_date_obj->format('F');
        $exam_day = $exam_date_obj->format('j');
        $exam_month_num = $exam_date_obj->format('n'); // 1-12
        $exam_year = $exam_date_obj->format('Y');
        $deadline_month = $deadline_obj->format('F');
        $deadline_day = $deadline_obj->format('j');
        $deadline_year = $deadline_obj->format('Y');
        $deadline_date = $row['registration_deadline'];

        // Determine registration status
        $status = '';
        $link = '';

        // Check if exam date has passed
        $exam_date_timestamp = strtotime($row['exam_date']);
        $current_timestamp = strtotime($current_date);
        $center_opening_timestamp = strtotime($center_opening);
        $two_weeks_ago = strtotime('-2 weeks', $current_timestamp);
        $deadline_timestamp = strtotime($deadline_date);

        if ($exam_date_timestamp < $center_opening_timestamp) {
            // Exam is before center opening
            if ($exam_date_timestamp < $two_weeks_ago) {
                // Exam was before center opening and more than 2 weeks ago - show results, no badge
                $status = '';
                $link = 'https://nat-test.jp/contents/result.html';
            } elseif ($current_timestamp > $deadline_timestamp) {
                // Registration deadline has passed
                $status = 'Registration Closed';
                $link = '';
            } else {
                // Registration deadline hasn't passed yet
                $status = 'Opening Soon';
                $link = '';
            }
        } elseif ($exam_date_timestamp < $current_timestamp) {
            // Exam date has passed (but after center opening) - closed
            $status = 'Closed';
            $link = 'https://nat-test.jp/contents/result.html';
        } else {
            // Exam date is on or after center opening - check registration status
            // First 3 exams from center opening are open
            if ($exam_count < 3) {
                $status = 'Open';
                $link = 'registration.html';
            } else {
                // Check if current date passed 2 weeks after exam date
                $two_weeks_after_exam = strtotime('+2 weeks', $exam_date_timestamp);

                if ($current_timestamp > $two_weeks_after_exam) {
                    // More than 2 weeks after exam - show results, no badge
                    $status = '';
                    $link = 'https://nat-test.ku.ac.bd/results.html';
                } elseif ($current_timestamp > $deadline_timestamp) {
                    // Registration deadline passed but not 2 weeks after exam
                    $status = 'Closed';
                    $link = '';
                } else {
                    // Registration still open
                    $status = 'Open';
                    $link = 'registration.html';
                }
            }
        }

        // Increment exam count for exams on/after center opening
        if ($exam_date_timestamp >= $center_opening_timestamp) {
            $exam_count++;
        }

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
            'registration_deadline_month' => $deadline_month,
            'registration_deadline_day' => $deadline_day,
            'registration_deadline_year' => $deadline_year,
            'levels' => $formatted_levels,
            'status' => $status,
            'link' => $link
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
