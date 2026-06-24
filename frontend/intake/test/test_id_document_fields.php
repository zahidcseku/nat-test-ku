<?php
/**
 * ID document type & number validation (lenient):
 * - type required, passport|national_id
 * - number required, 4-30 chars, letters/digits only after stripping
 *   spaces and hyphens, stored uppercase
 * Run: php frontend/intake/test/test_id_document_fields.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../validate.php';

function basePost(array $overrides = []): array {
    return array_merge([
        'full_name' => 'Test Applicant',
        'email' => 'test@example.com',
        'mobile' => '01712345678',
        'address' => '123 Test Road, Khulna',
        'dob' => '2000/01/15',
        'nationality' => 'Bangladeshi',
        'payment_method' => 'online',
        'exam_levels' => ['1Q/N1'],
        'total_amount' => 4000,
        'test_date' => '2026/08/15',
        'id_document_type' => 'passport',
        'id_document_number' => 'AB1234567',
    ], $overrides);
}

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// Valid passport
$v = validateRegistrationData(basePost());
$check('valid passport submission accepted', $v['valid'] === true);
$check('passport type stored', ($v['data']['id_document_type'] ?? null) === 'passport');
$check('passport number stored', ($v['data']['id_document_number'] ?? null) === 'AB1234567');

// Valid national ID
$v = validateRegistrationData(basePost(['id_document_type' => 'national_id', 'id_document_number' => '1234567890123']));
$check('valid national_id submission accepted', $v['valid'] === true);
$check('national_id type stored', ($v['data']['id_document_type'] ?? null) === 'national_id');

// Normalization: spaces/hyphens stripped, uppercased
$v = validateRegistrationData(basePost(['id_document_number' => 'ab-12 34']));
$check('spaces/hyphens stripped + uppercased', ($v['data']['id_document_number'] ?? null) === 'AB1234');

// Missing type
$v = validateRegistrationData(basePost(['id_document_type' => '']));
$check('missing type rejected', $v['valid'] === false && isset($v['errors']['id_document_type']));

// Invalid type
$v = validateRegistrationData(basePost(['id_document_type' => 'driving_license']));
$check('invalid type rejected', $v['valid'] === false && isset($v['errors']['id_document_type']));

// Missing number
$v = validateRegistrationData(basePost(['id_document_number' => '']));
$check('missing number rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Too short (3 chars after stripping)
$v = validateRegistrationData(basePost(['id_document_number' => 'A-1 2']));
$check('too-short number rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Too long (31 chars)
$v = validateRegistrationData(basePost(['id_document_number' => str_repeat('A', 31)]));
$check('too-long number rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Illegal characters survive stripping
$v = validateRegistrationData(basePost(['id_document_number' => 'AB@1234']));
$check('illegal characters rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Gender behavior unchanged (regression guard)
$v = validateRegistrationData(basePost());
$check("gender still defaults to 'other'", ($v['data']['gender'] ?? null) === 'other');

exit($pass ? 0 : 1);
