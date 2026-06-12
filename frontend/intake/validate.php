<?php
/**
 * NAT-TEST Intake Service - Input Validation
 *
 * Provides validation and sanitization functions for all input fields.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * Validate email address
 */
function validateEmail($email) {
    $email = trim($email);
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'valid' => false,
            'error' => 'Invalid email address format',
            'sanitized' => ''
        ];
    }

    // Additional check for reasonable length
    if (strlen($email) > 255) {
        return [
            'valid' => false,
            'error' => 'Email address too long',
            'sanitized' => ''
        ];
    }

    return [
        'valid' => true,
        'error' => null,
        'sanitized' => $email
    ];
}

/**
 * Validate mobile phone number
 * Accepts formats: +8801XXXXXXXXX, 01XXXXXXXXX
 */
function validateMobile($mobile) {
    $mobile = trim($mobile);
    $mobile = preg_replace('/[^0-9+]/', '', $mobile);

    // Bangladesh mobile number format
    // Accepts: +8801XXXXXXXXX or 01XXXXXXXXX
    if (preg_match('/^(\+880|0)?1[3-9]\d{8}$/', $mobile)) {
        // Normalize to +880 format
        if (strpos($mobile, '+') !== 0) {
            // Remove leading 0 if present, then add +880
            $mobile = ltrim($mobile, '0');
            $mobile = '+880' . $mobile;
        }

        return [
            'valid' => true,
            'error' => null,
            'sanitized' => $mobile
        ];
    }

    // International format (more lenient)
    if (preg_match('/^\+[1-9]\d{1,14}$/', $mobile)) {
        return [
            'valid' => true,
            'error' => null,
            'sanitized' => $mobile
        ];
    }

    return [
        'valid' => false,
        'error' => 'Invalid mobile number format. Use format: +8801XXXXXXXXX or 01XXXXXXXXX',
        'sanitized' => ''
    ];
}

/**
 * Validate date in YYYY/MM/DD format
 */
function validateDate($dateString) {
    $dateString = trim($dateString);

    // Check format YYYY/MM/DD
    if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $dateString)) {
        return [
            'valid' => false,
            'error' => 'Date must be in YYYY/MM/DD format',
            'sanitized' => ''
        ];
    }

    // Parse and validate date
    $parts = explode('/', $dateString);
    $year = (int)$parts[0];
    $month = (int)$parts[1];
    $day = (int)$parts[2];

    if (!checkdate($month, $day, $year)) {
        return [
            'valid' => false,
            'error' => 'Invalid date',
            'sanitized' => ''
        ];
    }

    // Check reasonable date range (not in future, not before 1900)
    $inputDate = strtotime("$year-$month-$day");
    $minDate = strtotime('1900-01-01');
    $maxDate = strtotime('+1 day'); // Allow today

    if ($inputDate < $minDate || $inputDate > $maxDate) {
        return [
            'valid' => false,
            'error' => 'Date out of valid range',
            'sanitized' => ''
        ];
    }

    // Convert to MySQL DATE format (YYYY-MM-DD)
    $mysqlDate = sprintf('%04d-%02d-%02d', $year, $month, $day);

    return [
        'valid' => true,
        'error' => null,
        'sanitized' => $mysqlDate
    ];
}

/**
 * Validate test date (future date for exam registration)
 * Allows dates from today up to 2 years in the future
 */
function validateTestDate($dateString) {
    $dateString = trim($dateString);

    // Check format YYYY/MM/DD
    if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $dateString)) {
        return [
            'valid' => false,
            'error' => 'Date must be in YYYY/MM/DD format',
            'sanitized' => ''
        ];
    }

    // Parse and validate date
    $parts = explode('/', $dateString);
    $year = (int)$parts[0];
    $month = (int)$parts[1];
    $day = (int)$parts[2];

    if (!checkdate($month, $day, $year)) {
        return [
            'valid' => false,
            'error' => 'Invalid date',
            'sanitized' => ''
        ];
    }

    // Check reasonable date range for test dates (not in past, within 2 years)
    $inputDate = strtotime("$year-$month-$day");
    $minDate = strtotime('today'); // Test dates must be today or future
    $maxDate = strtotime('+2 years'); // Allow up to 2 years ahead

    if ($inputDate < $minDate) {
        return [
            'valid' => false,
            'error' => 'Test date cannot be in the past',
            'sanitized' => ''
        ];
    }

    if ($inputDate > $maxDate) {
        return [
            'valid' => false,
            'error' => 'Test date too far in the future (maximum 2 years)',
            'sanitized' => ''
        ];
    }

    // Convert to MySQL DATE format (YYYY-MM-DD)
    $mysqlDate = sprintf('%04d-%02d-%02d', $year, $month, $day);

    return [
        'valid' => true,
        'error' => null,
        'sanitized' => $mysqlDate
    ];
}

/**
 * Validate required text field
 */
function validateRequired($field, $fieldName, $minLength = 1, $maxLength = 1000) {
    $value = trim($field);

    if (empty($value)) {
        return [
            'valid' => false,
            'error' => "$fieldName is required",
            'sanitized' => ''
        ];
    }

    if (strlen($value) < $minLength) {
        return [
            'valid' => false,
            'error' => "$fieldName must be at least $minLength characters",
            'sanitized' => ''
        ];
    }

    if (strlen($value) > $maxLength) {
        return [
            'valid' => false,
            'error' => "$fieldName must not exceed $maxLength characters",
            'sanitized' => ''
        ];
    }

    // Prevent XSS
    $sanitized = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    return [
        'valid' => true,
        'error' => null,
        'sanitized' => $sanitized
    ];
}

/**
 * Validate enum field
 */
function validateEnum($value, $fieldName, $allowedValues, $caseInsensitive = false) {
    $value = trim($value);

    // Check if value matches (with optional case-insensitivity)
    $isValid = false;
    $matchedValue = $value;

    foreach ($allowedValues as $allowed) {
        if ($caseInsensitive) {
            if (strcasecmp($value, $allowed) === 0) {
                $isValid = true;
                $matchedValue = $allowed; // Return the properly cased version
                break;
            }
        } else {
            if ($value === $allowed) {
                $isValid = true;
                $matchedValue = $allowed;
                break;
            }
        }
    }

    if (!$isValid) {
        return [
            'valid' => false,
            'error' => "$fieldName must be one of: " . implode(', ', $allowedValues),
            'sanitized' => ''
        ];
    }

    return [
        'valid' => true,
        'error' => null,
        'sanitized' => $matchedValue
    ];
}

/**
 * Validate complete registration data
 */
function validateRegistrationData($data) {
    $errors = [];
    $sanitized = [];

    // Step 1: Personal Information
    $nameResult = validateRequired($data['full_name'] ?? '', 'Full name', 2, 255);
    if (!$nameResult['valid']) {
        $errors['full_name'] = $nameResult['error'];
    } else {
        $sanitized['full_name'] = $nameResult['sanitized'];
    }

    $emailResult = validateEmail($data['email'] ?? '');
    if (!$emailResult['valid']) {
        $errors['email'] = $emailResult['error'];
    } else {
        $sanitized['email'] = $emailResult['sanitized'];
    }

    $mobileResult = validateMobile($data['mobile'] ?? '');
    if (!$mobileResult['valid']) {
        $errors['mobile'] = $mobileResult['error'];
    } else {
        $sanitized['mobile'] = $mobileResult['sanitized'];
    }

    $addressResult = validateRequired($data['address'] ?? '', 'Address', 10, 1000);
    if (!$addressResult['valid']) {
        $errors['address'] = $addressResult['error'];
    } else {
        $sanitized['address'] = $addressResult['sanitized'];
    }

    $dobResult = validateDate($data['dob'] ?? '');
    if (!$dobResult['valid']) {
        $errors['dob'] = $dobResult['error'];
    } else {
        $sanitized['dob'] = $dobResult['sanitized'];
    }

    // Gender is no longer collected on the form (2026-06-12). The DB column
    // is NOT NULL, so every new registration stores 'other'. Any submitted
    // gender value is ignored.
    $sanitized['gender'] = 'other';

    $nationalityResult = validateRequired($data['nationality'] ?? '', 'Nationality', 2, 100);
    if (!$nationalityResult['valid']) {
        $errors['nationality'] = $nationalityResult['error'];
    } else {
        $sanitized['nationality'] = $nationalityResult['sanitized'];
    }

    // Step 2: Payment Method
    $paymentResult = validateEnum($data['payment_method'] ?? '', 'Payment method', ['online', 'offline']);
    if (!$paymentResult['valid']) {
        $errors['payment_method'] = $paymentResult['error'];
    } else {
        $sanitized['payment_method'] = $paymentResult['sanitized'];
    }

    // Step 3: Exam Details
    // Validate exam_levels (array of selected levels)
    if (!isset($data['exam_levels']) || !is_array($data['exam_levels'])) {
        $errors['exam_levels'] = 'Exam levels must be submitted as an array';
    } else {
        $levels = $data['exam_levels'];

        // Remove any empty values
        $levels = array_filter($levels, function($level) {
            return !empty(trim($level));
        });

        // Re-index array
        $levels = array_values($levels);

        // Validate minimum 1 level
        if (count($levels) < 1) {
            $errors['exam_levels'] = 'Please select at least one exam level';
        }

        // Validate maximum 5 levels
        if (count($levels) > 5) {
            $errors['exam_levels'] = 'Cannot select more than 5 levels';
        }

        // Validate each level value
        $validLevels = ['1Q', '2Q', '3Q', '4Q', '5Q'];
        foreach ($levels as $level) {
            $level = trim($level);
            if (!in_array($level, $validLevels, true)) {
                $errors['exam_levels'] = "Invalid level selected: $level";
                break;
            }
        }

        // Convert to comma-separated string for storage
        if (!isset($errors['exam_levels'])) {
            $sanitized['exam_level'] = implode(',', $levels);
            $sanitized['exam_levels_array'] = $levels;
        }
    }

    // Validate total_amount
    if (!isset($data['total_amount'])) {
        $errors['total_amount'] = 'Total amount is required';
    } else {
        $amount = intval($data['total_amount']);
        $levelCount = isset($sanitized['exam_levels_array']) ? count($sanitized['exam_levels_array']) : 0;
        $expectedAmount = $levelCount * 4000;

        // First, validate amount is greater than zero (always check)
        if ($amount <= 0) {
            $errors['total_amount'] = 'Total amount must be greater than zero';
        }
        // Then, verify calculation matches (only if levels are valid)
        elseif ($levelCount > 0 && $amount !== $expectedAmount) {
            $errors['total_amount'] = "Amount mismatch. Expected: $expectedAmount, Got: $amount";
        }

        if (!isset($errors['total_amount'])) {
            $sanitized['total_amount'] = $amount;
        }
    }

    $testDateResult = validateTestDate($data['test_date'] ?? '');
    if (!$testDateResult['valid']) {
        $errors['test_date'] = $testDateResult['error'];
    } else {
        $sanitized['test_date'] = $testDateResult['sanitized'];
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => $sanitized
    ];
}
