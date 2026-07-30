<?php
/**
 * Get available exam dates for registration dropdown
 *
 * This endpoint returns all upcoming exam dates that are available
 * for registration, filtered by the center opening date.
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

    // Get all exams (we'll filter to 3 in PHP)
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
    $all_exams = [];

    // Fetch all exams first
    while ($row = $result->fetch_assoc()) {
        $all_exams[] = $row;
    }

    $exams = [];
    $exams_to_show = [];

    // If no exams found, return empty
    if (!empty($all_exams)) {
        // Find the starting point: first exam with deadline strictly in the
        // future. Uses ">" (not ">=") to match get_next_exam.php — on the
        // deadline day itself, registration is considered closed and the
        // dropdown should advance to the next exam.
        $start_index = 0;
        $found_upcoming = false;
        for ($i = 0; $i < count($all_exams); $i++) {
            if ($all_exams[$i]['registration_deadline'] > $today) {
                $start_index = $i;
                $found_upcoming = true;
                break;
            }
        }

        // If all deadlines have passed, start from the beginning (show first 3)
        if (!$found_upcoming) {
            $start_index = 0;
        }

        // Get 3 exams starting from the first one with upcoming deadline
        $exams_to_show = array_slice($all_exams, $start_index, 3);
    }

    // Process only the 3 exams to show
    foreach ($exams_to_show as $row) {
        // Format exam date for display
        $exam_date_obj = DateTime::createFromFormat('Y-m-d', $row['exam_date']);
        if (!$exam_date_obj) {
            continue;
        }

        $formatted_date = $exam_date_obj->format('F j, Y');

        // Get available levels for this exam
        $levels = $row['levels'] ? explode(',', $row['levels']) : [];

        // Build per-level cap/paid/is_full info (cap fetched separately below).
        $level_caps = [];
        foreach ($levels as $lvl) {
            $level_caps[$lvl] = ['cap' => null, 'paid' => 0, 'is_full' => false];
        }

        // Per-level caps (separate query — keeps the main query portable).
        $capStmt = $conn->prepare("SELECT level, registration_cap FROM exam_levels WHERE exam_date_id = ?");
        if ($capStmt) {
            $capStmt->bind_param('s', $row['id']);
            $capStmt->execute();
            $capResult = $capStmt->get_result();
            while ($capRow = $capResult->fetch_assoc()) {
                $level_caps[$capRow['level']]['cap'] = $capRow['registration_cap'] !== null
                    ? (int)$capRow['registration_cap']
                    : null;
            }
            $capStmt->close();
        }

        // Paid counts per level. registrations.exam_level is a comma-separated
        // string (e.g. '1Q/N1,2Q/N2'), so we explode in PHP rather than rely
        // on FIND_IN_SET for every level.
        $paidStmt = $conn->prepare(
            "SELECT exam_level FROM registrations
              WHERE test_date = ? AND payment_status = 'paid'"
        );
        if ($paidStmt) {
            $paidStmt->bind_param('s', $row['exam_date']);
            $paidStmt->execute();
            $paidResult = $paidStmt->get_result();
            $counts = [];
            while ($paidRow = $paidResult->fetch_assoc()) {
                foreach (explode(',', $paidRow['exam_level']) as $lvl) {
                    $lvl = trim($lvl);
                    if ($lvl !== '') {
                        $counts[$lvl] = ($counts[$lvl] ?? 0) + 1;
                    }
                }
            }
            $paidStmt->close();
            foreach ($counts as $lvl => $cnt) {
                if (isset($level_caps[$lvl])) {
                    $level_caps[$lvl]['paid'] = $cnt;
                }
            }
        }

        // Mark full levels: only when a finite cap is set AND paid >= cap.
        foreach ($level_caps as $lvl => &$info) {
            if ($info['cap'] !== null && $info['paid'] >= $info['cap']) {
                $info['is_full'] = true;
            }
        }
        unset($info);

        $exams[] = [
            'id' => $row['id'],
            'value' => $row['exam_date'],
            'display' => $formatted_date,
            'deadline' => $row['registration_deadline'],
            'levels' => $levels,
            'level_caps' => $level_caps,
        ];
    }

    $stmt->close();
    $conn->close();

    // Send success response
    successResponse([
        'exams' => $exams,
        'count' => count($exams)
    ], 'Exam dates retrieved successfully');

} catch (Exception $e) {
    logActivity("Exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
