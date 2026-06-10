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
        logActivity("IPN verification failed: " . json_encode($ipnData), 'security');
        errorResponse('IPN verification failed', 403);
    }

    // Extract transaction details
    $transactionId = $ipnData['tran_id'] ?? '';
    $amount = $ipnData['amount'] ?? '0';
    $currency = $ipnData['currency'] ?? 'BDT';
    $status = $ipnData['status'] ?? '';
    $cardType = $ipnData['card_type'] ?? '';
    $bankTranId = $ipnData['bank_tran_id'] ?? '';
    $cardAmount = $ipnData['card_amount'] ?? '0';
    $storeAmount = $ipnData['store_amount'] ?? '0';

    // Get database connection
    $conn = getDbConnection();
    if (!$conn) {
        logActivity("Database connection failed in IPN handler", 'error');
        errorResponse('Database error', 500);
    }

    // Find registration by transaction ID
    $stmt = $conn->prepare("
        SELECT id, email, full_name, total_amount_paid, payment_status
        FROM registrations
        WHERE id = ?
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

    // Validate amount
    if ((float)$registration['total_amount_paid'] !== (float)$cardAmount) {
        logActivity("Amount mismatch for transaction {$transactionId}. Expected: {$registration['total_amount_paid']}, Got: {$cardAmount}", 'security');
        errorResponse('Amount validation failed', 400);
    }

    // Process payment status
    $newStatus = 'unpaid';
    $paymentMethodDetail = 'other';

    if ($status === 'SUCCESS') {
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

        logActivity("Payment successful for transaction {$transactionId}, amount: {$cardAmount} {$currency}");

    } elseif ($status === 'FAILED') {
        $newStatus = 'failed';
        logActivity("Payment failed for transaction {$transactionId}");

    } else {
        logActivity("Unknown payment status for transaction {$transactionId}: {$status}", 'warning');
        errorResponse('Unknown payment status', 400);
    }

    // Update registration
    $updateStmt = $conn->prepare("
        UPDATE registrations
        SET payment_status = ?,
            sslcommerz_transaction_id = ?,
            payment_method_detail = ?,
            payment_time = NOW(),
            payment_ipn_received = TRUE
        WHERE id = ?
    ");

    $updateStmt->bind_param(
        'ssss',
        $newStatus,
        $bankTranId,
        $paymentMethodDetail,
        $transactionId
    );

    if (!$updateStmt->execute()) {
        logActivity("Failed to update payment status for transaction {$transactionId}: " . $updateStmt->error, 'error');
        errorResponse('Database update failed', 500);
    }

    $updateStmt->close();
    $conn->close();

    // Send confirmation email for successful payments
    if ($newStatus === 'paid') {
        // Email will be sent by admin review process
        logActivity("Payment confirmation queued for transaction {$transactionId}");
    }

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
