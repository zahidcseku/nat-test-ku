<?php
// Minimal test - no includes, no dependencies
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Minimal PHP test working',
    'php_version' => PHP_VERSION,
    'file' => __FILE__
]);
?>
