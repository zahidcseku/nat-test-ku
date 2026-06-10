<?php
/**
 * Payment Retry Lookup Endpoint
 *
 * Allows users and admin to check payment status and generate retry links
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';

// Allow both GET and POST
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    // Get lookup parameters
    $email = $_GET['email'] ?? $_POST['email'] ?? '';
    $registrationId = $_GET['registration_id'] ?? $_POST['registration_id'] ?? '';

    if (empty($email) && empty($registrationId)) {
        errorResponse('Email or registration ID required', 400);
    }

    // Get database connection
    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Database connection failed', 500);
    }

    // Build query based on lookup parameter
    if (!empty($registrationId)) {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, base_amount, transaction_fee, total_amount_paid,
                   payment_status, payment_retry_token, payment_retry_expires,
                   exam_level, test_date
            FROM registrations
            WHERE id = ?
        ");
        $stmt->bind_param('s', $registrationId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, base_amount, transaction_fee, total_amount_paid,
                   payment_status, payment_retry_token, payment_retry_expires,
                   exam_level, test_date
            FROM registrations
            WHERE email = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->bind_param('s', $email);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        errorResponse('Registration not found', 404);
    }

    $registration = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    // Check if retry is possible
    $canRetry = false;
    $retryLink = null;

    if ($registration['payment_status'] === 'unpaid' || $registration['payment_status'] === 'failed') {
        $canRetry = true;

        // Check if retry token is expired
        if (!empty($registration['payment_retry_token'])) {
            $expiresAt = strtotime($registration['payment_retry_expires']);
            $now = time();

            if ($expiresAt > $now) {
                // Generate retry link (will be used by SSLCommerz session creation)
                $retryLink = SITE_URL . '/payment-retry.html?token=' . $registration['payment_retry_token'];
            } else {
                // Generate new retry token
                $newToken = generateRetryToken();
                $newExpiry = generateRetryExpiry();

                $conn = getDbConnection();
                $updateStmt = $conn->prepare("
                    UPDATE registrations
                    SET payment_retry_token = ?, payment_retry_expires = ?
                    WHERE id = ?
                ");
                $updateStmt->bind_param('sss', $newToken, $newExpiry, $registration['id']);
                $updateStmt->execute();
                $updateStmt->close();
                $conn->close();

                $retryLink = SITE_URL . '/payment-retry.html?token=' . $newToken;
            }
        }
    }

    // Return registration details
    $responseData = [
        'found' => true,
        'registration_id' => $registration['id'],
        'full_name' => $registration['full_name'],
        'email' => $registration['email'],
        'base_amount' => (float)$registration['base_amount'],
        'transaction_fee' => (float)$registration['transaction_fee'],
        'total_amount' => (float)$registration['total_amount_paid'],
        'payment_status' => $registration['payment_status'],
        'can_retry' => $canRetry,
        'retry_link' => $retryLink,
        'expires_at' => $registration['payment_retry_expires']
    ];

    successResponse($responseData, 'Registration found');

} catch (Exception $e) {
    logActivity("Retry lookup exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
