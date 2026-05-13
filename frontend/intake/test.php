<?php
/**
 * Test Endpoint for Intake Service
 * Use this to verify the service is working
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';

// Set headers
header('Content-Type: application/json');

// Test response
$response = [
    'success' => true,
    'message' => 'Intake service is working!',
    'tests' => [
        'php_version' => PHP_VERSION,
        'extensions' => [
            'mysqli' => extension_loaded('mysqli'),
            'fileinfo' => extension_loaded('fileinfo'),
            'gd' => extension_loaded('gd')
        ],
        'directories' => [
            'uploads' => is_dir(UPLOAD_DIR),
            'uploads_writable' => is_writable(UPLOAD_DIR),
            'logs' => is_dir(__DIR__ . '/logs'),
            'logs_writable' => is_writable(__DIR__ . '/logs')
        ],
        'database' => [
            'connection' => getDbConnection() !== null,
            'host' => DB_HOST,
            'database' => DB_NAME
        ],
        'env_file' => file_exists(__DIR__ . '/.env'),
        'config_loaded' => file_exists(__DIR__ . '/config.php')
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>
