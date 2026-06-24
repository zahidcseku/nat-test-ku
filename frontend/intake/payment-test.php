<?php
/**
 * Payment Gateway Test Endpoint
 *
 * Creates a minimal, clearly-marked test registration charged at 10 BDT per
 * module (instead of 4000) and starts a real SSLCommerz session, so the full
 * payment chain — session creation, gateway, browser return, IPN, status
 * update, retry lookup — can be verified in deployment for pennies.
 *
 * Disabled unless PAYMENT_TEST_KEY is set in .env; every request must
 * present the matching key. Test rows are marked with the
 * '[PAYMENT TEST]' name prefix for easy identification and cleanup:
 *   DELETE FROM registrations WHERE full_name LIKE '[PAYMENT TEST]%';
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payment-gateway.php';

// Test module fee in BDT (production uses 4000)
const TEST_MODULE_FEE = 10;

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

// Endpoint is disabled unless a test key is configured
$configuredKey = getenv('PAYMENT_TEST_KEY') ?: '';
if ($configuredKey === '') {
    errorResponse('Not found', 404);
}

$providedKey = $_POST['key'] ?? '';
if (!hash_equals($configuredKey, $providedKey)) {
    logActivity('Payment test endpoint: invalid access key from ' . getClientIp(), 'security');
    errorResponse('Invalid access key', 403);
}

try {
    // Minimal input validation
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $levels = (int)($_POST['levels'] ?? 1);

    $errors = [];
    if ($name === '' || mb_strlen($name) < 2) {
        $errors['full_name'] = 'Name is required (2+ characters)';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Valid email is required';
    }
    if (!preg_match('/^01[0-9]{9}$/', $mobile)) {
        $errors['mobile'] = 'Valid BD mobile number required (e.g. 01712345678)';
    }
    if ($levels < 1 || $levels > 5) {
        $errors['levels'] = 'Levels must be between 1 and 5';
    }
    if (!empty($errors)) {
        errorResponse('Validation failed', 400, $errors);
    }

    // Test charge: 10 BDT per module. The applicant pays the base only —
    // SSLCommerz's commission comes out of the merchant settlement.
    $baseAmount = $levels * TEST_MODULE_FEE;
    $transactionFee = 0.0;
    $totalAmount = $baseAmount;

    $id = generateUuid();
    $testName = '[PAYMENT TEST] ' . $name;
    $sslczTranId = 'NAT' . date('YmdHis') . substr(md5(uniqid($id, true)), 0, 8);

    // Create the gateway session first — on failure report the error and
    // store nothing (this is a diagnostic tool; surface what went wrong)
    $sslcz = new SSLCommerz();
    $sslczResponse = $sslcz->createPayment([
        'total_amount' => $totalAmount,
        'currency' => 'BDT',
        'tran_id' => $sslczTranId,
        'cus_name' => $testName,
        'cus_email' => $email,
        'cus_phone' => $mobile,
        'cus_add1' => 'Payment gateway test'
    ]);

    if ($sslczResponse['status'] !== 'SUCCESS' || empty($sslczResponse['GatewayPageURL'])) {
        logActivity('Payment test: gateway session creation failed: ' . $sslczResponse['error'], 'error');
        errorResponse('Gateway session creation failed: ' . $sslczResponse['error'], 502);
    }

    // Persist a minimal test registration so the IPN can find and update it
    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Database connection failed', 500);
    }

    $stmt = $conn->prepare("
        INSERT INTO registrations (
            id, full_name, email, mobile, address, dob, gender, nationality,
            payment_method, exam_level, total_amount, test_date,
            photo_filename, photo_storage_path, photo_size_bytes,
            id_filename, id_storage_path, id_size_bytes,
            payment_receipt_filename, payment_receipt_storage_path, payment_receipt_size_bytes,
            submitted_at, ip_hash, user_agent, honeypot_tripped, honeypot_value,
            approved, approved_at, approved_by, created_at,
            payment_status, sslcommerz_transaction_id, sslcommerz_session_id, base_amount, transaction_fee, total_amount_paid, payment_retry_token, payment_retry_expires
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        logActivity('Payment test: prepare failed: ' . $conn->error, 'error');
        errorResponse('Database error', 500);
    }

    $address = 'Payment test — not a real registration';
    $dob = '2000-01-01';
    $gender = 'other';
    $nationality = 'Test';
    $paymentMethod = 'online';
    $examLevel = implode(',', array_slice(['N1', 'N2', 'N3', 'N4', 'N5'], 0, $levels));
    $totalAmountInt = $baseAmount;
    $testDate = date('Y-m-d', strtotime('+30 days'));
    $placeholder = 'TEST';
    $zero = 0;
    $rName = null;
    $rPath = null;
    $rSize = null;
    $now = date('Y-m-d H:i:s');
    $ipHash = hashIp(getClientIp());
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $hpTripped = 0;
    $hpValue = '';
    $approved = 0;
    $approvedAt = null;
    $approvedBy = null;
    $paymentStatus = 'unpaid';
    $sessionKey = $sslczResponse['sessionkey'];
    $retryToken = generateRetryToken();
    $retryExpires = generateRetryExpiry();

    $stmt->bind_param(
        'ssssssssssisssissississsisissssssdddss',
        $id,
        $testName,
        $email,
        $mobile,
        $address,
        $dob,
        $gender,
        $nationality,
        $paymentMethod,
        $examLevel,
        $totalAmountInt,
        $testDate,
        $placeholder,
        $placeholder,
        $zero,
        $placeholder,
        $placeholder,
        $zero,
        $rName,
        $rPath,
        $rSize,
        $now,
        $ipHash,
        $userAgent,
        $hpTripped,
        $hpValue,
        $approved,
        $approvedAt,
        $approvedBy,
        $now,
        $paymentStatus,
        $sslczTranId,
        $sessionKey,
        $baseAmount,
        $transactionFee,
        $totalAmount,
        $retryToken,
        $retryExpires
    );

    if (!$stmt->execute()) {
        logActivity('Payment test: insert failed: ' . $stmt->error, 'error');
        errorResponse('Failed to save test registration', 500);
    }
    $stmt->close();
    $conn->close();

    logActivity("Payment test registration created: ID={$id}, transaction {$sslczTranId}, amount {$totalAmount} BDT");

    successResponse([
        'id' => $id,
        'tran_id' => $sslczTranId,
        'levels' => $levels,
        'base_amount' => $baseAmount,
        'transaction_fee' => $transactionFee,
        'total_amount' => $totalAmount,
        'redirect_url' => $sslczResponse['GatewayPageURL'],
        'status_check_url' => SITE_URL . '/payment-retry.html?token=' . $retryToken
    ], 'Test payment session created. Redirecting to gateway...');

} catch (Exception $e) {
    logActivity('Payment test exception: ' . $e->getMessage(), 'error');
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
