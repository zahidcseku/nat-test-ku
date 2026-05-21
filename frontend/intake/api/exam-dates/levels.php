<?php
/**
 * NAT-TEST Intake Service - Get Exam Levels API
 *
 * Returns available exam levels for a given exam date.
 * GET /api/exam-dates/levels.php?date=YYYY-MM-DD
 */

define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../../config.php';

// Set CORS headers
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate date parameter
$testDate = $_GET['date'] ?? '';
if (empty($testDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $testDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing date parameter']);
    exit;
}

try {
    $conn = getDbConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Find exam date ID
    $stmt = $conn->prepare("SELECT id FROM exam_dates WHERE exam_date = ?");
    $stmt->bind_param('s', $testDate);
    $stmt->execute();
    $result = $stmt->get_result();

    // If no exam date found, return empty levels array
    if ($result->num_rows === 0) {
        echo json_encode([
            'levels' => [],
            'exam_date' => $testDate,
            'count' => 0
        ]);
        $stmt->close();
        $conn->close();
        exit;
    }

    // Get exam date ID
    $dateRow = $result->fetch_assoc();
    $examDateId = $dateRow['id'];
    $stmt->close();

    // Get levels for this exam date
    $stmt = $conn->prepare("SELECT level FROM exam_levels WHERE exam_date_id = ? ORDER BY level");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $levelsResult = $stmt->get_result();

    // Build levels array
    $levels = [];
    while ($row = $levelsResult->fetch_assoc()) {
        $levels[] = $row['level'];
    }

    $stmt->close();
    $conn->close();

    // Return success response
    echo json_encode([
        'levels' => $levels,
        'exam_date' => $testDate,
        'count' => count($levels)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('Error in levels.php: ' . $e->getMessage());
}
