<?php
/**
 * Payment Retry Session Endpoint
 *
 * Creates a fresh SSLCommerz payment session for an existing unpaid/failed
 * registration, identified by its payment retry token. Returns only the
 * gateway redirect URL — no registration data is exposed.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payment-gateway.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $token = $_POST['token'] ?? '';

    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        errorResponse('Invalid retry token', 400);
    }

    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Database connection failed', 500);
    }

    $stmt = $conn->prepare("
        SELECT id, full_name, email, mobile, address, payment_status,
               total_amount_paid, payment_retry_expires
        FROM registrations
        WHERE payment_retry_token = ?
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        errorResponse('Registration not found', 404);
    }

    $registration = $result->fetch_assoc();
    $stmt->close();

    if ($registration['payment_status'] === 'paid') {
        errorResponse('Payment already completed', 400);
    }

    if ($registration['payment_status'] !== 'unpaid' && $registration['payment_status'] !== 'failed') {
        errorResponse('Payment cannot be retried for this registration', 400);
    }

    if (strtotime($registration['payment_retry_expires']) <= time()) {
        errorResponse('Retry link has expired. Please look up your registration to get a new one.', 410);
    }

    // Create a fresh gateway session with a new transaction ID
    $sslcz = new SSLCommerz();
    $sslczTranId = 'NAT' . date('YmdHis') . substr(md5(uniqid($registration['id'], true)), 0, 8);

    $sslczResponse = $sslcz->createPayment([
        'total_amount' => $registration['total_amount_paid'],
        'currency' => 'BDT',
        'tran_id' => $sslczTranId,
        'cus_name' => $registration['full_name'],
        'cus_email' => $registration['email'],
        'cus_phone' => $registration['mobile'],
        'cus_add1' => $registration['address']
    ]);

    if ($sslczResponse['status'] !== 'SUCCESS' || empty($sslczResponse['GatewayPageURL'])) {
        logActivity("Retry session creation failed for registration {$registration['id']}: " . $sslczResponse['error'], 'error');
        errorResponse('Could not start payment session. Please try again later.', 502);
    }

    // Point the registration at the new transaction so the IPN can find it
    $updateStmt = $conn->prepare("
        UPDATE registrations
        SET sslcommerz_transaction_id = ?,
            sslcommerz_session_id = ?,
            payment_retry_count = payment_retry_count + 1
        WHERE id = ?
          AND payment_status <> 'paid'
    ");
    $sessionKey = $sslczResponse['sessionkey'];
    $updateStmt->bind_param('sss', $sslczTranId, $sessionKey, $registration['id']);

    if (!$updateStmt->execute()) {
        logActivity("Failed to store retry transaction for registration {$registration['id']}: " . $updateStmt->error, 'error');
        errorResponse('Database update failed', 500);
    }
    $updateStmt->close();
    $conn->close();

    logActivity("Retry payment session created for registration {$registration['id']}, transaction {$sslczTranId}");

    successResponse([
        'redirect_url' => $sslczResponse['GatewayPageURL']
    ], 'Payment session created');

} catch (Exception $e) {
    logActivity("Retry session exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
