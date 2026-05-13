<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'php_version' => PHP_VERSION,
    'message' => 'PHP is working!'
]);
?>
