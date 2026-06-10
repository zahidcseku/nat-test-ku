<?php
/**
 * Debug POST data to see what JavaScript is sending
 */

define('INTAKE_SERVICE', true);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Log all incoming data
$debug_data = [
    'POST' => $_POST,
    'FILES' => array_keys($_FILES),
    'has_exam_levels_array' => isset($_POST['exam_levels']),
    'has_exam_level_string' => isset($_POST['exam_level']),
    'has_total_amount' => isset($_POST['total_amount']),
    'exam_levels_raw' => $_POST['exam_levels'] ?? 'not set',
    'exam_level_raw' => $_POST['exam_level'] ?? 'not set',
    'total_amount_raw' => $_POST['total_amount'] ?? 'not set',
];

// If exam_levels is set, show its structure
if (isset($_POST['exam_levels'])) {
    $debug_data['exam_levels_type'] = gettype($_POST['exam_levels']);
    $debug_data['exam_levels_is_array'] = is_array($_POST['exam_levels']);
    if (is_array($_POST['exam_levels'])) {
        $debug_data['exam_levels_values'] = $_POST['exam_levels'];
    }
}

echo json_encode($debug_data, JSON_PRETTY_PRINT);
