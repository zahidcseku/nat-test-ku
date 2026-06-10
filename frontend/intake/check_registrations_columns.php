<?php
/**
 * Check registrations table structure
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

    // Get all columns
    $result = $conn->query("SHOW COLUMNS FROM registrations");

    if ($result) {
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }

        echo json_encode([
            'success' => true,
            'table' => 'registrations',
            'column_count' => count($columns),
            'columns' => $columns,
            'has_payment_receipt' => in_array('payment_receipt_filename', $columns),
            'has_total_amount' => in_array('total_amount', $columns),
            'has_honeypot_value' => in_array('honeypot_value', $columns)
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
