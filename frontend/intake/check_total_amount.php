<?php
/**
 * Check if total_amount column exists in registrations table
 */

define('INTAKE_SERVICE', true);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $conn = getDbConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }

    // Check if total_amount column exists
    $result = $conn->query("SHOW COLUMNS FROM registrations LIKE 'total_amount'");

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'exists' => true,
            'column' => $row
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => 'total_amount column does not exist'
        ]);
    }

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
