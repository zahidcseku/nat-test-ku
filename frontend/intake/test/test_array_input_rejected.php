<?php
/**
 * Array-typed POST input (e.g. full_name[]=x) must produce a clean
 * validation failure, never a TypeError from trim() on a non-string.
 * PHP populates $_POST values as strings OR arrays, so every field a
 * client controls can arrive as an array.
 * Run: php frontend/intake/test/test_array_input_rejected.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/../security.php';

function basePost(array $overrides = []): array {
    return array_merge([
        'full_name' => 'Test Applicant',
        'email' => 'test@example.com',
        'mobile' => '01712345678',
        'address' => '123 Test Road, Khulna',
        'dob' => '2000/01/15',
        'nationality' => 'Bangladeshi',
        'payment_method' => 'online',
        'exam_levels' => ['N1'],
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

// Each string field submitted as an array must yield a normal validation
// error on that field — not throw.
$stringFields = [
    'full_name', 'email', 'mobile', 'address', 'dob', 'nationality',
    'payment_method', 'test_date', 'id_document_type', 'id_document_number',
];

foreach ($stringFields as $field) {
    try {
        $v = validateRegistrationData(basePost([$field => ['injected']]));
        $check(
            "array $field rejected as validation error",
            $v['valid'] === false && isset($v['errors'][$field])
        );
    } catch (Throwable $e) {
        $check("array $field rejected as validation error (threw " . get_class($e) . ")", false);
    }
}

// Nested array inside exam_levels (exam_levels[0][]=x) must not throw either.
try {
    $v = validateRegistrationData(basePost(['exam_levels' => [['N1']]]));
    $check(
        'nested array in exam_levels rejected as validation error',
        $v['valid'] === false && isset($v['errors']['exam_levels'])
    );
} catch (Throwable $e) {
    $check('nested array in exam_levels rejected as validation error (threw ' . get_class($e) . ')', false);
}

// Every string field an array at once — still a clean failure.
try {
    $allArrays = basePost(array_fill_keys($stringFields, ['x']));
    $v = validateRegistrationData($allArrays);
    $check('all-array payload rejected cleanly', $v['valid'] === false);
} catch (Throwable $e) {
    $check('all-array payload rejected cleanly (threw ' . get_class($e) . ')', false);
}

// Honeypot check runs before validation in register.php — an array
// 'website' value must not fatal there.
try {
    $h = checkHoneypot(['website' => ['bot']]);
    $check('array honeypot value handled without throwing', is_array($h) && isset($h['tripped']));
} catch (Throwable $e) {
    $check('array honeypot value handled without throwing (threw ' . get_class($e) . ')', false);
}

// Regression guard: a normal valid submission still passes.
try {
    $v = validateRegistrationData(basePost());
    $check('valid string submission still accepted', $v['valid'] === true);
} catch (Throwable $e) {
    $check('valid string submission still accepted (threw ' . get_class($e) . ')', false);
}

exit($pass ? 0 : 1);
