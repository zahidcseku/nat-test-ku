<?php
/**
 * Logging version - saves debug info to file
 */

define('INTAKE_SERVICE', true);

// Start debug log
$logFile = __DIR__ . '/logs/debug_' . date('Y-m-d_H-i-s') . '.json';
$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'post_fields' => array_keys($_POST ?? []),
    'files' => array_keys($_FILES ?? []),
    'server_info' => [
        'php_version' => PHP_VERSION,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]
];

// Save to log file
file_put_contents($logFile, json_encode($debug, JSON_PRETTY_PRINT));

// Return simple response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Debug info logged to: ' . basename($logFile),
    'log_file' => $logFile
]);
?>
