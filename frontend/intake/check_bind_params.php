<?php
/**
 * Check if all required variables are set before bind_param
 */

define('INTAKE_SERVICE', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validate.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simulate POST data (matching what the form sends)
$testData = [
    'full_name' => 'Md zahidul islam',
    'email' => 'zahidislam@appliedparticletechnology.com',
    'mobile' => '01876652567',
    'address' => 'test address',
    'dob' => '2009/01/01',
    'gender' => 'male',
    'nationality' => 'Bangladeshi',
    'exam_levels' => ['N3', 'N4'],
    'total_amount' => 8000,
    'test_date' => '2026/07/11',
    'payment_method' => 'offline',
    'website' => ' '
];

// Validate
$validation = validateRegistrationData($testData);
$data = $validation['data'];

// Simulate honeypot check
$honeypotCheck = checkHoneypot($testData);

// Check all required variables
$required_vars = [
    'data[full_name]' => $data['full_name'] ?? 'MISSING',
    'data[email]' => $data['email'] ?? 'MISSING',
    'data[mobile]' => $data['mobile'] ?? 'MISSING',
    'data[address]' => $data['address'] ?? 'MISSING',
    'data[dob]' => $data['dob'] ?? 'MISSING',
    'data[gender]' => $data['gender'] ?? 'MISSING',
    'data[nationality]' => $data['nationality'] ?? 'MISSING',
    'data[payment_method]' => $data['payment_method'] ?? 'MISSING',
    'data[exam_level]' => $data['exam_level'] ?? 'MISSING',
    'data[total_amount]' => $data['total_amount'] ?? 'MISSING',
    'data[test_date]' => $data['test_date'] ?? 'MISSING',
    'honeypotCheck[value]' => $honeypotCheck['value'] ?? 'MISSING',
];

$missing = array_filter($required_vars, function($val) {
    return $val === 'MISSING';
});

echo json_encode([
    'all_vars_present' => empty($missing),
    'missing_vars' => array_keys($missing),
    'all_vars' => $required_vars,
    'validation' => $validation
], JSON_PRETTY_PRINT);
