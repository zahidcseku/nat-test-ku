<?php
/**
 * SSLCommerz IPN (Instant Payment Notification) Handler
 *
 * Receives server-to-server callbacks from SSLCommerz when payment status changes
 * Updates registration payment status in database
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payment-gateway.php';
require_once __DIR__ . '/mailer.php';

// Log IPN received
logActivity("IPN webhook received from IP: " . $_SERVER['REMOTE_ADDR'], 'info');

try {
    // Get POST data
    $ipnData = $_POST;

    if (empty($ipnData)) {
        logActivity("IPN received with empty POST data", 'warning');
        errorResponse('No data received', 400);
    }

    // Initialize SSLCommerz
    $sslcz = new SSLCommerz();

    // Verify IPN authenticity
    if (!$sslcz->verifyIPN($ipnData)) {
        logActivity("IPN verification failed for tran_id: " . ($ipnData['tran_id'] ?? 'unknown'), 'security');
        errorResponse('IPN verification failed', 403);
    }

    // Extract transaction details
    $transactionId = $ipnData['tran_id'] ?? '';
    $currency = $ipnData['currency'] ?? 'BDT';
    $status = $ipnData['status'] ?? '';
    $cardType = $ipnData['card_type'] ?? '';
    $bankTranId = $ipnData['bank_tran_id'] ?? '';

    // Dispatch by tran_id prefix: 'CRT' -> certificate_requests, else -> registrations.
    // The certificate handler mirrors the registrations flow but updates a
    // different table and fires the certificate_requested email.
    if (strpos($transactionId, 'CRT') === 0) {
        require_once __DIR__ . '/certificate-ipn-handler.php';
        handleCertificateIPN($ipnData, $sslcz, $transactionId, $status, $bankTranId, $currency, $cardType);
        return; // handleCertificateIPN exits via successResponse/errorResponse
    }

    // Get database connection
    $conn = getDbConnection();
    if (!$conn) {
        logActivity("Database connection failed in IPN handler", 'error');
        errorResponse('Database error', 500);
    }

    // Find registration by SSLCommerz transaction ID
    $stmt = $conn->prepare("
        SELECT id, email, full_name, mobile, address, dob, nationality,
               id_document_type, id_document_number, exam_level, test_date,
               total_amount_paid, payment_method, payment_status,
               payment_retry_token
        FROM registrations
        WHERE sslcommerz_transaction_id = ?
    ");
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        logActivity("Registration not found for transaction ID: {$transactionId}", 'warning');
        errorResponse('Registration not found', 404);
    }

    $registration = $result->fetch_assoc();
    $stmt->close();

    // Check for duplicate IPN (idempotency)
    if ($registration['payment_status'] === 'paid') {
        logActivity("Duplicate IPN received for paid transaction: {$transactionId}", 'info');
        successResponse([], 'Already processed');
    }

    // Process payment status
    // SSLCommerz IPN sends: VALID (paid), FAILED, CANCELLED, UNATTEMPTED, EXPIRED
    $newStatus = 'unpaid';
    $paymentMethodDetail = 'other';

    if ($status === 'VALID' || $status === 'VALIDATED') {
        // Authoritative server-side check: confirm the transaction directly
        // with SSLCommerz before trusting the IPN payload
        $valId = $ipnData['val_id'] ?? '';
        if ($valId === '') {
            logActivity("IPN missing val_id for transaction {$transactionId}", 'security');
            errorResponse('Missing validation ID', 400);
        }

        $validation = $sslcz->validateTransaction($valId);

        if ($validation['status'] !== 'VALID' && $validation['status'] !== 'VALIDATED') {
            logActivity("Validation API rejected transaction {$transactionId}: status={$validation['status']} error={$validation['error']}", 'security');
            errorResponse('Transaction validation failed', 400);
        }

        if ($validation['tran_id'] !== $transactionId) {
            logActivity("Validation API tran_id mismatch. IPN: {$transactionId}, API: {$validation['tran_id']}", 'security');
            errorResponse('Transaction validation failed', 400);
        }

        // All charges are in BDT — reject anything else before the amount check
        $validatedCurrency = $validation['currency'] !== '' ? $validation['currency'] : $currency;
        if ($validatedCurrency !== 'BDT') {
            logActivity("Unexpected currency for transaction {$transactionId}: {$validatedCurrency}", 'security');
            errorResponse('Currency validation failed', 400);
        }

        // Validate amount against what we charged (1 BDT tolerance for
        // gateway rounding, per SSLCommerz integration guidance)
        if (abs((float)$validation['amount'] - (float)$registration['total_amount_paid']) > 1.0) {
            logActivity("Amount mismatch for transaction {$transactionId}. Expected: {$registration['total_amount_paid']}, Validated: {$validation['amount']}", 'security');
            errorResponse('Amount validation failed', 400);
        }

        // Prefer validated values over the raw IPN payload
        $cardType = $validation['card_type'] !== '' ? $validation['card_type'] : $cardType;
        $bankTranId = $validation['bank_tran_id'] !== '' ? $validation['bank_tran_id'] : $bankTranId;

        $newStatus = 'paid';

        // Map card type to payment method detail
        $cardTypeLower = strtolower($cardType);
        if (strpos($cardTypeLower, 'bkash') !== false) {
            $paymentMethodDetail = 'bkash';
        } elseif (strpos($cardTypeLower, 'nagad') !== false) {
            $paymentMethodDetail = 'nagad';
        } elseif (strpos($cardTypeLower, 'rocket') !== false) {
            $paymentMethodDetail = 'rocket';
        } elseif (strpos($cardTypeLower, 'visa') !== false || strpos($cardTypeLower, 'master') !== false) {
            $paymentMethodDetail = 'card';
        } elseif (strpos($cardTypeLower, 'amex') !== false) {
            $paymentMethodDetail = 'card';
        }

        logActivity("Payment successful for transaction {$transactionId}, amount: {$validation['amount']} {$currency}");

    } elseif ($status === 'FAILED' || $status === 'CANCELLED' || $status === 'EXPIRED' || $status === 'UNATTEMPTED') {
        $newStatus = 'failed';
        logActivity("Payment {$status} for transaction {$transactionId}");

    } else {
        logActivity("Unknown payment status for transaction {$transactionId}: {$status}", 'warning');
        errorResponse('Unknown payment status', 400);
    }

    // Update registration (keep sslcommerz_transaction_id intact — it is the lookup key;
    // the bank's own reference goes in its dedicated column)
    $updateStmt = $conn->prepare("
        UPDATE registrations
        SET payment_status = ?,
            sslcommerz_bank_transaction_id = ?,
            payment_method_detail = ?,
            payment_time = NOW(),
            payment_ipn_received = TRUE
        WHERE id = ?
          AND payment_status <> 'paid'
    ");

    $updateStmt->bind_param(
        'ssss',
        $newStatus,
        $bankTranId,
        $paymentMethodDetail,
        $registration['id']
    );

    if (!$updateStmt->execute()) {
        logActivity("Failed to update payment status for transaction {$transactionId}: " . $updateStmt->error, 'error');
        errorResponse('Database update failed', 500);
    }

    if ($updateStmt->affected_rows === 0) {
        logActivity("IPN update matched no rows for registration {$registration['id']} (transaction {$transactionId}) — not found or already paid", 'warning');
    }

    $updateStmt->close();

    // Send confirmation email for successful payments (before closing the
    // connection so the email_log insert can reuse it). The payment status
    // is already committed; a mail failure only logs a warning.
    if ($newStatus === 'paid') {
        sendRegistrationEmail([
            'id' => $registration['id'],
            'full_name' => $registration['full_name'],
            'email' => $registration['email'],
            'mobile' => $registration['mobile'],
            'address' => $registration['address'],
            'dob' => $registration['dob'],
            'nationality' => $registration['nationality'],
            'id_document_type' => $registration['id_document_type'],
            'id_document_number' => $registration['id_document_number'],
            'exam_level' => $registration['exam_level'],
            'test_date' => $registration['test_date'],
            'total_amount' => $registration['total_amount_paid'],
            'payment_method' => $registration['payment_method'],
            'payment_status' => 'paid',
            'retry_token' => $registration['payment_retry_token'],
            'has_receipt' => false,
            'bank_tran_id' => $bankTranId,
        ], 'payment_confirmation');
    }

    $conn->close();

    // Log successful IPN processing
    logActivity("✅ IPN processed successfully for transaction {$transactionId}");

    // Return success to SSLCommerz
    successResponse([
        'transaction_id' => $transactionId,
        'status' => $newStatus
    ], 'IPN processed successfully');

} catch (Exception $e) {
    logActivity("IPN Exception: " . $e->getMessage(), 'error');
    errorResponse('IPN processing error', 500);
}
