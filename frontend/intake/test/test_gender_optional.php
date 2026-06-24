<?php
/**
 * Gender must not be required and must always be stored as 'other'
 * (form no longer collects it; DB column is NOT NULL).
 * Run: php frontend/intake/test/test_gender_optional.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../validate.php';

$base = [
    'full_name' => 'Test Applicant',
    'email' => 'test@example.com',
    'mobile' => '01712345678',
    'address' => '123 Test Road, Khulna',
    'dob' => '2000/01/15',
    'nationality' => 'Bangladeshi',
    'payment_method' => 'online',
    'exam_levels' => ['1Q/N1', '3Q/N3'],
    'total_amount' => 8000,
    'test_date' => '2026/08/15',
    'id_document_type' => 'passport',
    'id_document_number' => 'AB1234567',
];

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// 1. No gender field at all -> valid, stored as 'other'
$v = validateRegistrationData($base);
$check('submission without gender is valid', $v['valid'] === true);
if (!$v['valid']) echo '  errors: ' . json_encode($v['errors']) . "\n";
$check("gender defaults to 'other'", ($v['data']['gender'] ?? null) === 'other');

// 2. A submitted gender value is ignored
$withGender = $base;
$withGender['gender'] = 'male';
$v2 = validateRegistrationData($withGender);
$check('submission with gender still valid', $v2['valid'] === true);
$check("submitted gender ignored, stored as 'other'", ($v2['data']['gender'] ?? null) === 'other');

exit($pass ? 0 : 1);
