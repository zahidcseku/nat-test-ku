<?php
/**
 * Test database connection
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Log all errors for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $debug_info = [
        'step' => 'Starting',
        'db_host' => DB_HOST,
        'db_name' => DB_NAME,
        'db_user' => DB_USER,
        'has_password' => !empty(DB_PASS),
        'php_version' => phpversion(),
        'has_mysqli' => extension_loaded('mysqli'),
        'has_json' => extension_loaded('json')
    ];

    // Get database connection using the same method as register.php
    $debug_info['step'] = 'Testing connection';
    $conn = getDbConnection();

    if (!$conn) {
        $debug_info['step'] = 'Connection failed';
        $debug_info['connect_error'] = 'getDbConnection returned null';
        logActivity("Database connection failed", 'error');

        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed',
            'debug_info' => $debug_info
        ]);
        exit;
    }

    $debug_info['step'] = 'Connection successful';
    $debug_info['server_info'] = $conn->server_info;
    $debug_info['protocol_version'] = $conn->protocol_version;
    $debug_info['charset'] = DB_CHARSET;

    // Test query
    $debug_info['step'] = 'Testing tables';
    $tables_query = "SHOW TABLES";
    $tables_result = $conn->query($tables_query);

    if ($tables_result) {
        $tables = [];
        while ($row = $tables_result->fetch_array()) {
            $tables[] = $row[0];
        }
        $debug_info['tables'] = $tables;
        $debug_info['has_exam_dates_table'] = in_array('exam_dates', $tables);
        $debug_info['has_exam_levels_table'] = in_array('exam_levels', $tables);
        $debug_info['has_registrations_table'] = in_array('registrations', $tables);

        // Test exam_dates table structure
        if ($debug_info['has_exam_dates_table']) {
            $debug_info['step'] = 'Testing exam_dates structure';
            $columns_query = "SHOW COLUMNS FROM exam_dates";
            $columns_result = $conn->query($columns_query);
            if ($columns_result) {
                $columns = [];
                while ($row = $columns_result->fetch_array()) {
                    $columns[] = $row['Field'];
                }
                $debug_info['exam_dates_columns'] = $columns;
            }
        }

        // Count exam dates
        $debug_info['step'] = 'Counting exam dates';
        $count_query = "SELECT COUNT(*) as total FROM exam_dates WHERE exam_date >= '2026-07-11'";
        $count_result = $conn->query($count_query);
        if ($count_result) {
            $count_row = $count_result->fetch_assoc();
            $debug_info['exam_dates_count_after_july_11'] = (int)$count_row['total'];
        }

    } else {
        $debug_info['tables_error'] = $conn->error;
    }

    $conn->close();
    $debug_info['step'] = 'Success';
    logActivity("Database connection test successful");

    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful',
        'debug_info' => $debug_info
    ]);

} catch (Exception $e) {
    $debug_info['step'] = 'Exception';
    $debug_info['exception_message'] = $e->getMessage();
    logActivity("Database connection test failed: " . $e->getMessage(), 'error');

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => $debug_info
    ]);
}
