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
    $all_exams = [];
    $current_date = date('Y-m-d');
    $current_month = date('n'); // 1-12
    $current_year = date('Y');
    $center_opening = '2026-07-11';

    // First, fetch all exams to determine the 3-month window
    while ($row = $result->fetch_assoc()) {
        $all_exams[] = $row;
    }

    // Find the first exam on or after center opening
    $first_open_exam_index = -1;
    foreach ($all_exams as $index => $exam) {
        $exam_timestamp = strtotime($exam['exam_date']);
        $center_opening_timestamp = strtotime($center_opening);
        if ($exam_timestamp >= $center_opening_timestamp) {
            $first_open_exam_index = $index;
            break;
        }
    }

    // Determine which 3 exams should be open based on current date
    $open_exams = [];
    if ($first_open_exam_index >= 0) {
        // Start with first 3 exams after center opening
        $open_exams = [$first_open_exam_index, $first_open_exam_index + 1, $first_open_exam_index + 2];

        // Check if we need to shift the window forward
        // Find the first open exam whose registration deadline has passed
        foreach ($open_exams as $exam_index) {
            if (isset($all_exams[$exam_index])) {
                $deadline_timestamp = strtotime($all_exams[$exam_index]['registration_deadline']);
                $current_timestamp = strtotime($current_date);

                if ($current_timestamp > $deadline_timestamp) {
                    // This exam's deadline has passed, shift window forward
                    // Remove this exam from open_exams and add the next one
                    $open_exams = array_values(array_filter($open_exams, function($idx) use ($exam_index) {
                        return $idx !== $exam_index;
                    }));

                    // Add the next exam after the current window
                    $max_index = max($open_exams);
                    if (isset($all_exams[$max_index + 1])) {
                        $open_exams[] = $max_index + 1;
                    }
                    break; // Only shift one exam at a time
                }
            }
        }
    }

    $exams = [];

    // Process each exam with the updated logic
    foreach ($all_exams as $index => $row) {
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
        $two_weeks_after_exam = strtotime('+2 weeks', $exam_date_timestamp);

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
        } elseif ($current_timestamp > $two_weeks_after_exam) {
            // More than 2 weeks after exam - show results, no badge
            $status = '';
            $link = 'https://nat-test.ku.ac.bd/results.html';
        } elseif ($current_timestamp > $deadline_timestamp) {
            // Registration deadline has passed but exam cycle still active.
            // Checked before window membership so exams that just got shifted
            // out of the active window still show "Closed" instead of
            // "Opening Soon".
            $status = 'Closed';
            $link = '';
        } elseif (in_array($index, $open_exams)) {
            // This exam is in the current 3-month registration window and
            // registration is still open (deadline check above already handled
            // the passed-deadline case).
            $status = 'Open';
            $link = 'https://nat-test.ku.ac.bd/registration.html';
        } else {
            // Future exams not in the 3-month window
            $status = 'Opening Soon';
            $link = '';
        }

        // Get available levels
        $levels = $row['levels'] ? explode(',', $row['levels']) : [];

        // Map level codes to display format
        $level_mapping = [
            '1Q/N1' => '1Q/N1',
            '2Q/N2' => '2Q/N2',
            '3Q/N3' => '3Q/N3',
            '4Q/N4' => '4Q/N4',
            '5Q/N5' => '5Q/N5'
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
