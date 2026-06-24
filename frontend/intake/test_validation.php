<?php
/**
 * Test validation function with exam_levels
 */

define('INTAKE_SERVICE', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validate.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simulate POST data
$testData = [
    'full_name' => 'Test User',
    'email' => 'test@example.com',
    'mobile' => '01712345678',
    'address' => 'Test Address',
    'dob' => '2000/01/01',
    'gender' => 'Male',
    'nationality' => 'Bangladeshi',
    'exam_levels' => ['N3', 'N4'], // Array of levels
    'total_amount' => 8000,
    'test_date' => '2026/07/11',
    'payment_method' => 'bank'
];

$result = validateRegistrationData($testData);

echo json_encode([
    'input' => $testData,
    'validation_result' => $result,
    'has_exam_level_in_data' => isset($result['data']['exam_level']),
    'exam_level_value' => $result['data']['exam_level'] ?? 'NOT SET',
    'has_total_amount_in_data' => isset($result['data']['total_amount']),
    'total_amount_value' => $result['data']['total_amount'] ?? 'NOT SET'
], JSON_PRETTY_PRINT);
