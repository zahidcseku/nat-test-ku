<?php
// Simple test - does logging work?
$testFile = __DIR__ . '/logs/test_log.txt';
$result = file_put_contents($testFile, 'Test at ' . date('Y-m-d H:i:s'));

header('Content-Type: application/json');
echo json_encode([
    'success' => $result !== false,
    'test_file' => $testFile,
    'file_exists' => file_exists($testFile),
    'logs_writable' => is_writable(__DIR__ . '/logs'),
    'error' => $result === false ? 'Cannot write to logs' : null
]);
?>
